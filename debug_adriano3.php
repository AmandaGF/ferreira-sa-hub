<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Audit log completo do caso #669 ===\n\n";
$logs = $pdo->query("SELECT al.action, al.entity_type, al.entity_id, al.details, al.created_at, u.name
FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
WHERE al.entity_id = 669 AND al.entity_type = 'case'
ORDER BY al.created_at DESC LIMIT 20")->fetchAll();
foreach ($logs as $l) {
    echo "{$l['created_at']} | {$l['name']} | {$l['action']} | {$l['details']}\n";
}

echo "\n=== Todos os cases do cliente do Adriano Matheus ===\n";
$r = $pdo->query("SELECT cs.id, cs.title, cs.status, cs.case_number, cs.court, cs.comarca, cs.kanban_oculto
FROM cases cs JOIN clients c ON c.id = cs.client_id
WHERE c.name LIKE '%Adriano Matheus%'
ORDER BY cs.id")->fetchAll();
foreach ($r as $row) {
    echo "#{$row['id']} | {$row['title']} | status={$row['status']} | num={$row['case_number']} | court={$row['court']} | comarca={$row['comarca']} | oculto={$row['kanban_oculto']}\n";
}

echo "\n=== Cases arquivados recentemente (últimas 24h) ===\n";
$arch = $pdo->query("SELECT id, title, status, case_number, kanban_oculto, updated_at FROM cases WHERE (status = 'arquivado' OR kanban_oculto = 1) AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY updated_at DESC LIMIT 10")->fetchAll();
foreach ($arch as $a) {
    echo "#{$a['id']} | {$a['title']} | status={$a['status']} | num={$a['case_number']} | oculto={$a['kanban_oculto']} | {$a['updated_at']}\n";
}
