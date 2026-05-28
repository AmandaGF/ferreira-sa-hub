<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Diag Gildson — andamentos ===\n\n";

// 1. Acha processo(s) do Gildson de convivência
$rows = $pdo->query(
    "SELECT cs.id, cs.title, cs.client_id, cs.case_number, cs.case_type, cs.status, c.name AS client_name
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     WHERE (c.name LIKE '%Gildson%' OR cs.title LIKE '%Gildson%' OR cs.title LIKE '%onviv%')
     ORDER BY cs.id DESC LIMIT 10"
)->fetchAll();

if (empty($rows)) { echo "Nenhum processo do Gildson encontrado.\n"; exit; }

foreach ($rows as $cs) {
    echo "─── Caso #" . $cs['id'] . " ───\n";
    echo "  Title: " . $cs['title'] . "\n";
    echo "  Client: " . ($cs['client_name'] ?? '-') . " (id " . ($cs['client_id'] ?? '-') . ")\n";
    echo "  Tipo: " . ($cs['case_type'] ?? '-') . "  · Status: " . ($cs['status'] ?? '-') . "\n";
    echo "  CNJ: " . ($cs['case_number'] ?? '-') . "\n";

    // Andamentos por origem
    $st = $pdo->prepare("SELECT tipo_origem, COUNT(*) AS qtd FROM case_andamentos WHERE case_id = ? GROUP BY tipo_origem ORDER BY qtd DESC");
    $st->execute(array($cs['id']));
    $orig = $st->fetchAll();
    echo "  Andamentos por origem:\n";
    if (empty($orig)) echo "    (nenhum)\n";
    foreach ($orig as $o) echo "    " . str_pad((string)($o['tipo_origem'] ?: '(NULL)'), 25, ' ') . " " . $o['qtd'] . "\n";

    // Ultimos 8 andamentos
    $st2 = $pdo->prepare("SELECT id, data_andamento, tipo, tipo_origem, LEFT(descricao, 80) AS preview, created_by, created_at, visivel_cliente FROM case_andamentos WHERE case_id = ? ORDER BY created_at DESC LIMIT 10");
    $st2->execute(array($cs['id']));
    $ands = $st2->fetchAll();
    echo "  Ultimos 10 andamentos (por created_at):\n";
    if (empty($ands)) echo "    (nenhum)\n";
    foreach ($ands as $a) {
        echo "    #" . str_pad((string)$a['id'], 6, ' ') . " data=" . ($a['data_andamento'] ?: '-') . " tipo=" . str_pad((string)($a['tipo'] ?: '?'), 14, ' ')
            . " origem=" . str_pad((string)($a['tipo_origem'] ?: '?'), 22, ' ')
            . " vis=" . ($a['visivel_cliente'] ?: 0) . " criado=" . $a['created_at'] . " por=" . ($a['created_by'] ?: '-') . "\n";
        echo "      '" . trim((string)$a['preview']) . "'\n";
    }

    // Audit log com 'import' nesse case
    echo "  Audit log (import*) nesse caso:\n";
    $st3 = $pdo->prepare(
        "SELECT id, action, user_id, details, created_at FROM audit_log
         WHERE entity_type = 'case' AND entity_id = ?
           AND (action LIKE 'andamentos_import%' OR action LIKE '%importar%' OR action LIKE '%lote%')
         ORDER BY id DESC LIMIT 5"
    );
    $st3->execute(array($cs['id']));
    $aud = $st3->fetchAll();
    if (empty($aud)) echo "    (nenhum)\n";
    foreach ($aud as $a) {
        echo "    #" . $a['id'] . " [" . $a['created_at'] . "] user=" . $a['user_id'] . " action=" . $a['action'] . "\n";
        echo "      " . substr((string)$a['details'], 0, 200) . "\n";
    }
    echo "\n";
}

// Audit log recente de imports (qualquer caso, ultimas 2h)
echo "\n=== Audit log 'andamentos_import*' nas ultimas 6h (qualquer caso) ===\n";
$st4 = $pdo->query(
    "SELECT id, action, user_id, entity_id, details, created_at FROM audit_log
     WHERE action LIKE 'andamentos_import%' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
     ORDER BY id DESC LIMIT 20"
);
$rec = $st4->fetchAll();
if (empty($rec)) echo "  (nenhum)\n";
foreach ($rec as $a) {
    echo "  #" . $a['id'] . " [" . $a['created_at'] . "] user=" . $a['user_id'] . " action=" . $a['action'] . " case=" . $a['entity_id'] . "\n";
    echo "    " . substr((string)$a['details'], 0, 300) . "\n";
}
