<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
echo "=== Marias ativas em users ===\n";
foreach ($pdo->query("SELECT id, name, role, email FROM users WHERE is_active = 1 AND name LIKE '%Maria%' ORDER BY name") as $u) {
    echo "  #{$u['id']} | {$u['name']} | role={$u['role']} | {$u['email']}\n";
}
