<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== USUÁRIOS ===\n\n";
$users = $pdo->query("SELECT id, name, email, role, setor, is_active FROM users ORDER BY id")->fetchAll();
foreach ($users as $u) {
    $status = $u['is_active'] ? 'ATIVO' : 'INATIVO';
    echo "#{$u['id']} | {$u['name']} | {$u['email']} | role={$u['role']} | setor={$u['setor']} | {$status}\n";
}

echo "\n=== ROLES DISPONÍVEIS NO SISTEMA ===\n";
echo "admin    = acesso total\n";
echo "gestao   = acesso a tudo exceto gestão de usuários\n";
echo "colaborador = acesso limitado (seus casos, portal, dashboard)\n";
