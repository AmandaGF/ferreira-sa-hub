<?php
/**
 * Cron — Classificação estruturada de publicações DJEN com IA Haiku
 *
 * O cron principal cron/djen_monitor.php já roda Claude pra gerar
 * resumo_ia + orientacao_ia (texto natural). Este cron complementa
 * EXTRAINDO campos ESTRUTURADOS desses textos:
 *   - tipo_recurso (ex: "Recurso Inominado", "Apelação", "Embargos", "Sem prazo")
 *   - dias_uteis (int 5/10/15/etc, ou 0 se sem prazo)
 *   - parte_responsavel ('autor'/'reu'/'ambos'/'ciencia')
 *
 * Útil pra filtrar publicações no Kanban de Intimações, montar dashboards
 * de "tipos de prazos da semana", etc.
 *
 * Killswitch (default OFF):
 *   configuracoes.ia_feature_djen_classif_estruturada_enabled = '1' liga.
 *
 * Comando cron (cPanel HTTP):
 *   curl https://ferreiraesa.com.br/conecta/cron/djen_classificar_estruturado.php?key=fsa-hub-deploy-2026
 *
 * Sugestão de horário: 1× por dia, 9h (depois do djen_monitor terminar).
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_ia.php';

if (php_sapi_name() !== 'cli') {
    $key = $_GET['key'] ?? '';
    if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
}
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "=== DJEN — Classificação estruturada — " . date('d/m/Y H:i') . " ===\n\n";

// Killswitch
if (!ia_feature_ativa('djen_classif_estruturada')) {
    echo "Feature desligada (configuracoes.ia_feature_djen_classif_estruturada_enabled != '1'). Saindo.\n";
    exit;
}

// Self-heal: colunas estruturadas (idempotente)
$alters = array(
    "ALTER TABLE case_publicacoes ADD COLUMN tipo_recurso VARCHAR(80) NULL",
    "ALTER TABLE case_publicacoes ADD COLUMN dias_uteis_classif TINYINT NULL",
    "ALTER TABLE case_publicacoes ADD COLUMN parte_classif VARCHAR(20) NULL",
    "ALTER TABLE case_publicacoes ADD COLUMN classif_em DATETIME NULL",
    "ALTER TABLE djen_pending ADD COLUMN tipo_recurso VARCHAR(80) NULL",
    "ALTER TABLE djen_pending ADD COLUMN dias_uteis_classif TINYINT NULL",
    "ALTER TABLE djen_pending ADD COLUMN parte_classif VARCHAR(20) NULL",
    "ALTER TABLE djen_pending ADD COLUMN classif_em DATETIME NULL",
);
foreach ($alters as $sql) { try { $pdo->exec($sql); } catch (Exception $e) {} }

$LIMIT_BATCH = 30; // máximo de publicações a processar por execução (controle de custo)
$MODELO = 'claude-haiku-4-5-20251001';

$systemPrompt =
    "Você é um paralegal experiente. Recebe o resumo e a orientação que outra IA já gerou pra uma publicação DJEN. "
  . "Sua única tarefa é EXTRAIR 3 CAMPOS ESTRUTURADOS, sem reanalisar o teor:\n\n"
  . "1) tipo_recurso (string curta, máximo 40 chars): nome do recurso/manifestação esperada. Exemplos:\n"
  . "   - 'Recurso Inominado' (JEC)\n"
  . "   - 'Apelação' (Justiça Comum)\n"
  . "   - 'Embargos de Declaração'\n"
  . "   - 'Contestação'\n"
  . "   - 'Manifestação' (genérico)\n"
  . "   - 'Recurso Ordinário' (Trabalho)\n"
  . "   - 'Agravo de Instrumento'\n"
  . "   - 'Sem prazo' (atos ordinatórios, ciência sem prazo do advogado)\n\n"
  . "2) dias_uteis (int 0-90): prazo em dias úteis. Use 0 quando não há prazo do advogado.\n\n"
  . "3) parte_responsavel: quem deve agir. Valores válidos:\n"
  . "   - 'autor' (nossa parte é autor e precisa agir)\n"
  . "   - 'reu' (nossa parte é réu/requerido e precisa agir)\n"
  . "   - 'ambos' (ambas as partes podem agir)\n"
  . "   - 'ciencia' (ciência simples, sem ação esperada)\n\n"
  . "Responda EXCLUSIVAMENTE em JSON válido, sem markdown:\n"
  . '{"tipo_recurso":"...","dias_uteis":0,"parte_responsavel":"..."}';

function processarLote($pdo, $tabela, $modelo, $systemPrompt, $limit) {
    $sql = "SELECT id, tipo_publicacao, resumo_ia, orientacao_ia
            FROM {$tabela}
            WHERE classif_em IS NULL
              AND resumo_ia IS NOT NULL AND resumo_ia <> ''
              AND orientacao_ia IS NOT NULL AND orientacao_ia <> ''
            ORDER BY id DESC
            LIMIT {$limit}";
    // case_publicacoes não tem resumo_ia → ajuste de colunas no fallback
    try {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        echo "  [{$tabela}] erro query: " . $e->getMessage() . "\n";
        return array(0, 0);
    }
    if (!$rows) { echo "  [{$tabela}] 0 publicações pendentes.\n"; return array(0, 0); }

    $ok = 0; $err = 0;
    foreach ($rows as $r) {
        $userMsg = "Tipo de publicação: " . ($r['tipo_publicacao'] ?: '?') . "\n"
                 . "Resumo: " . $r['resumo_ia'] . "\n"
                 . "Orientação: " . $r['orientacao_ia'];

        $resp = ia_chamar(
            'djen_classif_estruturada',
            $modelo,
            $systemPrompt,
            array(array('role' => 'user', 'content' => $userMsg)),
            array('max_tokens' => 200, 'temperature' => 0.1, 'cache_system' => true)
        );

        if (empty($resp['ok']) || empty($resp['texto'])) {
            $err++;
            echo "  ✗ {$tabela} #{$r['id']}: " . ($resp['erro'] ?? 'sem texto') . "\n";
            continue;
        }
        $txt = trim($resp['texto']);
        if (preg_match('/\{[\s\S]*\}/', $txt, $m)) $txt = $m[0];
        $j = json_decode($txt, true);
        if (!is_array($j) || !isset($j['tipo_recurso'], $j['dias_uteis'], $j['parte_responsavel'])) {
            $err++;
            echo "  ✗ {$tabela} #{$r['id']}: JSON inválido — " . mb_substr($txt, 0, 80) . "\n";
            continue;
        }
        $tipo  = mb_substr((string)$j['tipo_recurso'], 0, 80);
        $dias  = max(0, min(90, (int)$j['dias_uteis']));
        $parte = (string)$j['parte_responsavel'];
        if (!in_array($parte, array('autor','reu','ambos','ciencia'), true)) $parte = 'ciencia';

        try {
            $pdo->prepare(
                "UPDATE {$tabela}
                 SET tipo_recurso = ?, dias_uteis_classif = ?, parte_classif = ?, classif_em = NOW()
                 WHERE id = ?"
            )->execute(array($tipo, $dias, $parte, $r['id']));
            $ok++;
            echo "  ✓ {$tabela} #{$r['id']}: {$tipo} ({$dias}d, {$parte})\n";
        } catch (Exception $e) {
            $err++;
            echo "  ✗ {$tabela} #{$r['id']}: " . $e->getMessage() . "\n";
        }
    }
    return array($ok, $err);
}

echo "\n--- case_publicacoes ---\n";
list($okCp, $errCp) = processarLote($pdo, 'case_publicacoes', $MODELO, $systemPrompt, $LIMIT_BATCH);

echo "\n--- djen_pending ---\n";
list($okDp, $errDp) = processarLote($pdo, 'djen_pending', $MODELO, $systemPrompt, $LIMIT_BATCH);

echo "\n=== Total OK: " . ($okCp + $okDp) . " | Erros: " . ($errCp + $errDp) . " ===\n";
