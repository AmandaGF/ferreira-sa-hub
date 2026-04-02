<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAGNÓSTICO PERMISSÕES — " . date('Y-m-d H:i:s') . " ===\n\n";

// 1. Tabela existe?
echo "--- 1. TABELA user_permissions ---\n";
try {
    $rows = $pdo->query("SELECT * FROM user_permissions ORDER BY user_id, module")->fetchAll();
    echo "Total registros: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo sprintf("  user_id=%d | module=%-30s | allowed=%d\n", $r['user_id'], $r['module'], $r['allowed']);
    }
    if (!$rows) echo "  (VAZIA — nenhum override salvo)\n";
} catch (Exception $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
    echo "  Tabela provavelmente não existe!\n";
}

// 2. Usuários e seus roles
echo "\n--- 2. USUÁRIOS ATIVOS ---\n";
$users = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY id")->fetchAll();
foreach ($users as $u) {
    echo sprintf("  #%d %-30s role=%-12s\n", $u['id'], $u['name'], $u['role']);
}

// 3. Para cada usuário com override, mostrar permissão efetiva
echo "\n--- 3. PERMISSÃO EFETIVA (usuários com overrides) ---\n";
$defaults = _permission_defaults();
$usersWithOverrides = $pdo->query("SELECT DISTINCT user_id FROM user_permissions")->fetchAll();
if (!$usersWithOverrides) {
    echo "  Nenhum override cadastrado\n";
}
foreach ($usersWithOverrides as $uo) {
    $uid = (int)$uo['user_id'];
    $userStmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
    $userStmt->execute(array($uid));
    $u = $userStmt->fetch();
    if (!$u) continue;

    echo "\n  " . $u['name'] . " (role=" . $u['role'] . "):\n";

    $ovStmt = $pdo->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ?");
    $ovStmt->execute(array($uid));
    foreach ($ovStmt->fetchAll() as $ov) {
        $defaultAllowed = isset($defaults[$ov['module']]) && in_array($u['role'], $defaults[$ov['module']], true);
        $effectiveAllowed = (bool)$ov['allowed'];
        $arrow = $defaultAllowed ? ($effectiveAllowed ? 'PERMITIDO→PERMITIDO (redundante)' : 'PERMITIDO→BLOQUEADO') : ($effectiveAllowed ? 'BLOQUEADO→LIBERADO' : 'BLOQUEADO→BLOQUEADO (redundante)');
        echo sprintf("    %-30s default=%-9s override=%-9s => %s\n",
            $ov['module'],
            $defaultAllowed ? 'PERMITIDO' : 'BLOQUEADO',
            $effectiveAllowed ? 'PERMITIDO' : 'BLOQUEADO',
            $arrow
        );
    }
}

// 4. Sidebar: quais módulos usam can_access vs roles diretas
echo "\n--- 4. SIDEBAR: VERIFICAÇÃO ---\n";
echo "  A sidebar verifica: se o módulo está em _permission_defaults(), usa can_access().\n";
echo "  Se NÃO está em _permission_defaults(), usa in_array(role, roles).\n";
echo "  Módulos nos defaults: " . implode(', ', array_keys($defaults)) . "\n";

echo "\n=== FIM ===\n";
