<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diagnostico: Leads do cliente Suelen ===\n\n";

// Buscar cliente
$stmt = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%Suelen%'");
$clientes = $stmt->fetchAll();
foreach ($clientes as $c) {
    echo "Cliente #{$c['id']}: {$c['name']}\n";

    // Casos
    $casos = $pdo->prepare("SELECT id, title, status, case_number FROM cases WHERE client_id = ? ORDER BY id");
    $casos->execute(array($c['id']));
    echo "  Casos:\n";
    foreach ($casos->fetchAll() as $cs) {
        echo "    #{$cs['id']} | {$cs['title']} | status={$cs['status']} | num={$cs['case_number']}\n";
    }

    // Leads
    $leads = $pdo->prepare("SELECT id, name, stage, linked_case_id, client_id FROM pipeline_leads WHERE client_id = ? ORDER BY id");
    $leads->execute(array($c['id']));
    echo "  Leads:\n";
    foreach ($leads->fetchAll() as $l) {
        echo "    #{$l['id']} | {$l['name']} | stage={$l['stage']} | linked_case={$l['linked_case_id']} | client={$l['client_id']}\n";
    }

    // Comentarios
    $comments = $pdo->prepare("SELECT id, client_id, case_id, lead_id, LEFT(message,60) as msg FROM card_comments WHERE client_id = ? ORDER BY id");
    $comments->execute(array($c['id']));
    echo "  Comentarios:\n";
    foreach ($comments->fetchAll() as $cm) {
        echo "    #{$cm['id']} | client={$cm['client_id']} case={$cm['case_id']} lead={$cm['lead_id']} | {$cm['msg']}\n";
    }
    echo "\n";
}
