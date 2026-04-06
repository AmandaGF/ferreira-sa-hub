<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Debug Jhonatan ===\n\n";

$rows = $pdo->query("SELECT cs.id, cs.title, cs.status, cs.case_number, cs.distribution_date, cs.court, cs.updated_at, cs.kanban_oculto, c.name as client_name
FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id
WHERE c.name LIKE '%Jhonatan%' OR c.name LIKE '%Jonatan%'
ORDER BY cs.id DESC")->fetchAll();

echo "Casos encontrados: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "#{$r['id']} | {$r['title']} | status={$r['status']} | num={$r['case_number']} | dist_date={$r['distribution_date']} | court={$r['court']} | kanban_oculto={$r['kanban_oculto']} | updated={$r['updated_at']} | cliente={$r['client_name']}\n";
}

echo "\n=== Audit log recente ===\n";
$logs = $pdo->query("SELECT al.action, al.entity_type, al.entity_id, al.details, al.created_at, u.name
FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
WHERE al.details LIKE '%Jhonatan%' OR al.details LIKE '%Jonatan%' OR al.details LIKE '%pensao%' OR al.details LIKE '%pensão%'
ORDER BY al.created_at DESC LIMIT 10")->fetchAll();
foreach ($logs as $l) {
    echo "{$l['created_at']} | {$l['name']} | {$l['action']} | {$l['entity_type']}#{$l['entity_id']} | {$l['details']}\n";
}
