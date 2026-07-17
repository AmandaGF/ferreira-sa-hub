<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
foreach ($pdo->query("SELECT id, name, role FROM users WHERE name LIKE '%Alfredo%' OR name LIKE '%Neves%'") as $r) {
    printf("  #%d %-30s role=%s\n", $r['id'], $r['name'], $r['role']);
}
$st = $pdo->prepare("SELECT responsible_user_id, u.name FROM cases c LEFT JOIN users u ON u.id=c.responsible_user_id WHERE c.id=776");
$st->execute();
$r = $st->fetch();
echo "\nCase 776 responsavel: user #" . ($r['responsible_user_id']?:'-') . " " . ($r['name']?:'sem responsavel') . "\n";
