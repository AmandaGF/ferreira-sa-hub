<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Leads da Marisa ===\n";
$leads = $pdo->query("SELECT id, name, stage, client_id, linked_case_id, case_type, notes FROM pipeline_leads WHERE name LIKE '%Marisa%' ORDER BY id DESC")->fetchAll();
foreach ($leads as $l) {
    echo "Lead #{$l['id']} | stage={$l['stage']} | client={$l['client_id']} | linked_case={$l['linked_case_id']} | type={$l['case_type']} | notes=" . substr($l['notes'],0,80) . "\n";
}

echo "\n=== Cases da Marisa ===\n";
if (!empty($leads)) {
    $clientId = $leads[0]['client_id'];
    $cases = $pdo->prepare("SELECT id, title, case_type, status FROM cases WHERE client_id = ? ORDER BY id DESC");
    $cases->execute(array($clientId));
    foreach ($cases->fetchAll() as $c) {
        echo "Case #{$c['id']} | {$c['title']} | type={$c['case_type']} | status={$c['status']}\n";
    }
}
