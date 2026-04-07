<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Cases do Adriano Matheus ===\n\n";
$rows = $pdo->query("SELECT cs.id, cs.title, cs.status, cs.case_number, cs.kanban_oculto, cs.distribution_date, cs.updated_at, c.name
FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id
WHERE c.name LIKE '%Adriano%Matheus%' OR c.name LIKE '%Adriano%' AND c.name LIKE '%Matheus%'
ORDER BY cs.id DESC")->fetchAll();

echo "Encontrados: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "#{$r['id']} | {$r['title']} | status={$r['status']} | kanban_oculto={$r['kanban_oculto']} | dist={$r['distribution_date']} | updated={$r['updated_at']} | cliente={$r['name']}\n";
}

echo "\n=== Audit log recente ===\n";
$logs = $pdo->query("SELECT al.action, al.entity_type, al.entity_id, al.details, al.created_at, u.name
FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
WHERE al.details LIKE '%Adriano%' OR al.details LIKE '%Matheus%'
ORDER BY al.created_at DESC LIMIT 10")->fetchAll();
foreach ($logs as $l) {
    echo "{$l['created_at']} | {$l['name']} | {$l['action']} | {$l['entity_type']}#{$l['entity_id']} | {$l['details']}\n";
}
