<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Procurando Amanda, Luiz Eduardo e Rodrigo Gustavo ===\n\n";

$rows = $pdo->query("SELECT id, name, email, role, active FROM users ORDER BY name")->fetchAll();
foreach ($rows as $r) {
    echo sprintf("id=%-3d  %-30s  %-40s  role=%-12s active=%d\n",
        $r['id'], $r['name'], $r['email'], $r['role'], $r['active']);
}
