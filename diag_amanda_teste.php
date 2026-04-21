<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== USUÁRIOS COM 'teste' ou 'test' no nome/email ===\n\n";
foreach ($pdo->query("SELECT id, name, email, role, is_active FROM users WHERE name LIKE '%teste%' OR name LIKE '%test%' OR email LIKE '%teste%' OR email LIKE '%test%' ORDER BY id")->fetchAll() as $u) {
    echo sprintf("#%-3d [%s] name=[%s] email=[%s] role=[%s] active=%d\n",
        $u['id'], $u['is_active'] ? 'ativo' : 'INATIVO', $u['name'], $u['email'] ?: '-', $u['role'], $u['is_active']);
}

echo "\n=== TODOS os usuários ativos (pra conferir) ===\n";
foreach ($pdo->query("SELECT id, name, role, is_active FROM users WHERE is_active = 1 ORDER BY id")->fetchAll() as $u) {
    echo sprintf("  #%-3d %s (%s)\n", $u['id'], $u['name'], $u['role']);
}
