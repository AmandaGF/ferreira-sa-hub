<?php
// Mostra os modulos que um usuario consegue acessar hoje:
// /conecta/diag_permissoes_usuario.php?key=fsa-hub-deploy-2026&nome=Simone

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_auth.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$nome = trim($_GET['nome'] ?? 'Simone');

$st = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE name LIKE ? ORDER BY id");
$st->execute(array('%' . $nome . '%'));
$users = $st->fetchAll();

if (!$users) { echo "Nenhum usuario encontrado com nome '$nome'.\n"; exit; }

foreach ($users as $u) {
    echo "=== USUARIO #{$u['id']}: {$u['name']} ===\n";
    echo "Email: " . ($u['email'] ?: '(vazio)') . "\n";
    echo "Role:  {$u['role']}" . ($u['is_active'] ? '' : ' [INATIVO]') . "\n\n";

    if (!$u['is_active']) { echo "(usuario inativo, nao loga)\n\n"; continue; }
    if ($u['role'] === 'admin') { echo "[ADMIN] -> tem acesso a TUDO automaticamente\n\n"; continue; }

    // Overrides em user_permissions
    $stO = $pdo->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ?");
    $stO->execute(array($u['id']));
    $overrides = array();
    foreach ($stO->fetchAll() as $r) {
        $overrides[$r['module']] = (int)$r['allowed'];
    }
    if ($overrides) {
        echo "Overrides individuais (user_permissions):\n";
        foreach ($overrides as $m => $a) {
            echo "  " . str_pad($m, 28) . " => " . ($a ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
        }
        echo "\n";
    } else {
        echo "(sem overrides individuais — usa apenas defaults do role)\n\n";
    }

    // Aplica logica do can_access
    $defaults = _permission_defaults();
    $role = $u['role'];
    $autorizadosFin = array(1, 3, 6);
    $autorizadosDash = array(1, 3, 6);

    $temAcesso = array();
    $semAcesso = array();

    foreach ($defaults as $mod => $rolesPermitidos) {
        // Whitelist rigida pro financeiro/dashboard (independe de role/override)
        if ($mod === 'financeiro' || $mod === 'faturamento') {
            $ok = in_array((int)$u['id'], $autorizadosFin, true);
        } elseif ($mod === 'dashboard') {
            $ok = in_array((int)$u['id'], $autorizadosDash, true);
        } elseif (isset($overrides[$mod])) {
            $ok = (bool)$overrides[$mod]; // override individual
        } else {
            $ok = in_array($role, $rolesPermitidos, true); // default do role
        }
        if ($ok) $temAcesso[] = $mod; else $semAcesso[] = $mod;
    }

    echo "MODULOS QUE ACESSA HOJE (" . count($temAcesso) . "):\n";
    foreach ($temAcesso as $m) echo "  + $m\n";
    echo "\nMODULOS BLOQUEADOS (" . count($semAcesso) . "):\n";
    foreach ($semAcesso as $m) echo "  - $m\n";
    echo "\n";
}
