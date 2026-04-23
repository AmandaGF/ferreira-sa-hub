<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG users — " . date('Y-m-d H:i:s') . " ===\n\n";

$r = $pdo->query("SELECT id, name, role, setor, email, LENGTH(name) AS name_len, is_active FROM users WHERE is_active = 1 ORDER BY id")->fetchAll();
echo "Users ativos: " . count($r) . "\n\n";
foreach ($r as $u) {
    echo sprintf("#%d '%s' (len=%d) role=%s setor='%s' email=%s\n",
        $u['id'], $u['name'], $u['name_len'], $u['role'], $u['setor'] ?? '', $u['email']);
}
