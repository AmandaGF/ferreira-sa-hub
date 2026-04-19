<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
echo "=== Usuários ativos ===\n\n";
$rows = $pdo->query("SELECT id, name, email, role FROM users WHERE is_active = 1 ORDER BY role, name")->fetchAll();
foreach ($rows as $r) {
    echo sprintf("  #%-3d | %-30s | %-35s | %s\n", $r['id'], $r['name'], $r['email'], $r['role']);
}
echo "\nTotal: " . count($rows) . "\n";
