<?php
/**
 * Regenera os andamentos gerados pelo módulo de ofícios no formato NOVO
 * (sem dados sensíveis: e-mail/telefone RH, dados bancários, CPF).
 *
 * URL: /conecta/atualizar_andamentos_oficios.php?key=fsa-hub-deploy-2026
 * Opcional: &case_id=X pra atualizar só um caso específico
 *           &confirm=SIM_APAGAR pra executar (default = dry-run)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$caseId = (int)($_GET['case_id'] ?? 0);
$confirm = ($_GET['confirm'] ?? '') === 'SIM_APAGAR';

echo "=== Regenerar andamentos de ofícios — formato novo ===\n";
echo "Caso: " . ($caseId ?: 'todos') . " · Modo: " . ($confirm ? 'EXECUTAR' : 'DRY-RUN') . "\n\n";

$where = "1=1";
$params = array();
if ($caseId) { $where = "case_id = ?"; $params[] = $caseId; }

$ofs = $pdo->prepare("SELECT * FROM oficios_enviados WHERE $where ORDER BY id");
$ofs->execute($params);
$oficios = $ofs->fetchAll();

echo "Ofícios encontrados: " . count($oficios) . "\n\n";

$atualizados = 0;
foreach ($oficios as $o) {
    if (!$o['case_id']) { echo "  - Ofício #{$o['id']} sem case_id — pulando\n"; continue; }

    // Monta descrição nova no formato limpo
    $linhas = array();
    $linhas[] = '📬 Ofício #' . $o['id'] . ' enviado ao empregador — desconto de pensão em folha';
    $linhas[] = '• Empresa: ' . $o['empregador'] . ($o['empresa_cnpj'] ? ' (CNPJ ' . $o['empresa_cnpj'] . ')' : '');
    if (!empty($o['funcionario_nome'])) {
        $linhas[] = '• Funcionário: ' . $o['funcionario_nome']
            . ($o['funcionario_cargo'] ? ' — ' . $o['funcionario_cargo'] : '')
            . ($o['funcionario_matricula'] ? ' (matrícula ' . $o['funcionario_matricula'] . ')' : '');
    }
    $linhas[] = '• Forma de envio: ' . strtoupper($o['plataforma'] ?: 'email');
    if ($o['data_envio']) $linhas[] = '• Data do envio: ' . date('d/m/Y', strtotime($o['data_envio']));
    if (!empty($o['observacoes'])) $linhas[] = '• Obs: ' . $o['observacoes'];
    $descNova = implode("\n", $linhas);

    // Busca andamento correspondente: mesmo case_id + tipo='oficio' + data_andamento próxima ao envio
    $findA = $pdo->prepare(
        "SELECT id, descricao FROM case_andamentos
         WHERE case_id = ? AND tipo = 'oficio'
           AND (descricao LIKE ? OR descricao LIKE ?)
         ORDER BY id DESC LIMIT 1"
    );
    $findA->execute(array(
        $o['case_id'],
        '%' . $o['empregador'] . '%',
        'Ofício #' . $o['id'] . '%'
    ));
    $and = $findA->fetch();

    if (!$and) {
        echo "  - Ofício #{$o['id']} ({$o['empregador']}) — nenhum andamento correspondente\n";
        continue;
    }
    if ($and['descricao'] === $descNova) {
        echo "  ≡ Andamento #{$and['id']} (ofício #{$o['id']}) — já está no formato novo\n";
        continue;
    }

    echo "  → Ofício #{$o['id']} ({$o['empregador']}) / andamento #{$and['id']}\n";
    echo "    ANTES: " . str_replace("\n", " ⏎ ", mb_substr($and['descricao'], 0, 120)) . "...\n";
    echo "    DEPOIS: " . str_replace("\n", " ⏎ ", mb_substr($descNova, 0, 120)) . "...\n";
    if ($confirm) {
        $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?")->execute(array($descNova, $and['id']));
        $atualizados++;
    }
}

echo "\n" . ($confirm ? "✅ Atualizados: $atualizados" : "👁️ DRY-RUN — use &confirm=SIM_APAGAR pra executar") . "\n";
