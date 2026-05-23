<?php
/**
 * cron/ia_classificar.php — Classifica urgência de andamentos importados
 * por e-mail PJe. Roda assincronamente em relação ao email_monitor_cron.
 *
 * Para cada andamento novo (tipo_origem='email_pje' e urgencia_ia IS NULL),
 * pede ao Claude Haiku uma classificação em 1 palavra: urgente / normal / info.
 *
 * Limite por execução (LIMITE_BATCH) evita estourar timeout HTTP e quota.
 * Andamentos urgentes podem ser destacados na UI (badge vermelho) e entrar
 * no painel de alertas do dia — UI separada.
 *
 * Uso (cPanel cron, 4x/dia):
 *   curl -s "https://ferreiraesa.com.br/conecta/cron/ia_classificar.php?key=fsa-hub-deploy-2026"
 *
 * Fire-and-forget pelo email_monitor_cron também é seguro (lock próprio
 * impede execuções sobrepostas).
 */

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403); exit('Negado.');
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_ia.php';

@set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');
echo "=== IA — Classificação de Andamentos ===\n";
echo date('d/m/Y H:i:s') . "\n\n";

if (!ia_feature_ativa('classif_andamento')) {
    echo "Feature desligada (ia_feature_classif_andamento_enabled=0). Saindo.\n";
    exit;
}

// Lock simples por flag em configuracoes — evita 2 execuções concorrentes.
$pdo = db();
$lockKey = 'ia_classif_lock';
try {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
    $st->execute(array($lockKey));
    $lockVal = (string)$st->fetchColumn();
    if ($lockVal && (time() - (int)$lockVal) < 300) {  // lock vivo nos últimos 5min
        echo "[lock] Outra execução em andamento (lock de " . (time()-(int)$lockVal) . "s). Saindo.\n";
        exit;
    }
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array($lockKey, (string)time()));
} catch (Exception $e) {}
register_shutdown_function(function() use ($pdo, $lockKey) {
    try { $pdo->prepare("UPDATE configuracoes SET valor='' WHERE chave=?")->execute(array($lockKey)); } catch (Exception $e) {}
});

$LIMITE = 50;
$st = $pdo->prepare(
    "SELECT id, descricao, tipo, data_andamento
       FROM case_andamentos
      WHERE tipo_origem = 'email_pje' AND (urgencia_ia IS NULL OR urgencia_ia = '')
      ORDER BY id DESC
      LIMIT $LIMITE"
);
$st->execute();
$ands = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Encontrados " . count($ands) . " andamento(s) para classificar (limite $LIMITE).\n\n";

if (!$ands) { echo "Nada a fazer.\n"; exit; }

// System prompt comum a todas as chamadas — cacheado.
$system = "Você é uma assistente jurídica do escritório Ferreira & Sá Advocacia. "
        . "Vai receber a descrição de UM andamento processual e deve responder APENAS "
        . "com UMA das três palavras (sem mais nada):\n\n"
        . "- urgente  — quando exige ação rápida do escritório nos próximos 1-5 dias úteis "
        . "(intimação com prazo, decisão que demanda recurso/cumprimento, citação, despacho "
        . "que ordena algo, expedição de mandado, audiência marcada, suspensão por inércia).\n"
        . "- normal   — andamento que faz parte do trâmite mas não é crítico no curto prazo "
        . "(juntada de petição/documento da outra parte, conclusão para julgamento, ato ordinatório).\n"
        . "- info     — andamento meramente informativo, sem ação esperada "
        . "(distribuição inicial, arquivamento definitivo após trânsito, baixa, publicação de "
        . "DJE genérica, registro automático do sistema).\n\n"
        . "Responda APENAS uma das três palavras, em minúsculo, sem ponto final, sem explicação.";

$insOk = 0; $insErro = 0; $custoTotal = 0.0;
$stUpd = $pdo->prepare("UPDATE case_andamentos SET urgencia_ia = ? WHERE id = ?");

foreach ($ands as $a) {
    $desc = trim((string)$a['descricao']);
    if (mb_strlen($desc) > 800) $desc = mb_substr($desc, 0, 800) . '…';
    $user = "Tipo: " . ($a['tipo'] ?: '—') . "\nDescrição: " . $desc;

    $r = ia_chamar(
        'classif_andamento',
        'claude-haiku-4-5',
        $system,
        array(array('role' => 'user', 'content' => $user)),
        array(
            'user_id'      => null,   // disparo automático
            'max_tokens'   => 10,
            'temperature'  => 0.0,    // determinístico
            'contexto'     => 'andamento#' . $a['id'],
            'cache_system' => true,
        )
    );

    if (!$r['ok']) {
        $insErro++;
        echo "  [erro] #{$a['id']}: " . $r['erro'] . "\n";
        continue;
    }

    $resp = strtolower(trim((string)$r['texto']));
    // Normaliza: pega a primeira ocorrência de uma das 3 palavras válidas
    $valor = null;
    foreach (array('urgente', 'normal', 'info') as $opt) {
        if (strpos($resp, $opt) !== false) { $valor = $opt; break; }
    }
    if (!$valor) $valor = 'normal';  // fallback conservador

    $stUpd->execute(array($valor, (int)$a['id']));
    $insOk++;
    $custoTotal += (float)$r['custo_brl'];

    $icon = $valor === 'urgente' ? '🔴' : ($valor === 'info' ? '⚪' : '🟢');
    echo "  $icon #{$a['id']} → {$valor}  (R$ " . number_format($r['custo_brl'], 4, ',', '.') . ")\n";
}

echo "\n=== Resumo ===\n";
echo "Classificados:  $insOk\n";
echo "Erros:          $insErro\n";
echo "Custo total:    R$ " . number_format($custoTotal, 4, ',', '.') . "\n";
echo "Restantes (próxima rodada): " . max(0, (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE tipo_origem='email_pje' AND (urgencia_ia IS NULL OR urgencia_ia='')")->fetchColumn()) . "\n";
