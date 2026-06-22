<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "Usuárias 'Maria':\n";
foreach ($pdo->query("SELECT id, name, role, is_active FROM users WHERE name LIKE '%Maria%' ORDER BY id")->fetchAll() as $u) {
    echo "  #{$u['id']} {$u['name']} — role={$u['role']} ativo={$u['is_active']}\n";
}
echo "\nOverrides individuais de crm_comercial (user_permissions):\n";
try {
    $r = $pdo->query("SELECT up.user_id, u.name, up.allowed FROM user_permissions up JOIN users u ON u.id=up.user_id WHERE up.module='crm_comercial'")->fetchAll();
    if (!$r) echo "  (nenhum — todos seguem o default = todos os papéis)\n";
    foreach ($r as $x) echo "  #{$x['user_id']} {$x['name']} allowed={$x['allowed']}\n";
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }
