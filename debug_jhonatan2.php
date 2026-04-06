<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Audit log para cases 657, 658, 675 ===\n\n";
$logs = $pdo->query("SELECT al.action, al.entity_type, al.entity_id, al.details, al.created_at, u.name
FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
WHERE (al.entity_id IN (657, 658, 675) AND al.entity_type = 'case')
ORDER BY al.created_at DESC LIMIT 20")->fetchAll();
foreach ($logs as $l) {
    echo "{$l['created_at']} | {$l['name']} | {$l['action']} | {$l['entity_type']}#{$l['entity_id']} | {$l['details']}\n";
}

echo "\n=== Pipeline leads vinculados ===\n";
$leads = $pdo->query("SELECT id, name, stage, linked_case_id, client_id FROM pipeline_leads WHERE linked_case_id IN (657, 658, 675) ORDER BY id")->fetchAll();
foreach ($leads as $l) {
    echo "Lead #{$l['id']} | {$l['name']} | stage={$l['stage']} | linked_case={$l['linked_case_id']}\n";
}
