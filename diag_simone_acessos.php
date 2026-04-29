<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_auth.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "==== Simone: dados do user ====\n";
$st = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE name LIKE '%simone%' OR email LIKE '%simone%' ORDER BY id");
$st->execute();
$users = $st->fetchAll();
foreach ($users as $u) {
    echo "  user_id={$u['id']} | {$u['name']} | role={$u['role']} | is_active={$u['is_active']} | email={$u['email']}\n";
}
if (empty($users)) { echo "  (nenhum)\n"; exit; }

$simoneId = (int)$users[0]['id'];
$simoneRole = $users[0]['role'];

echo "\n==== Overrides em user_permissions (id=$simoneId) ====\n";
$st = $pdo->prepare("SELECT module, allowed FROM user_permissions WHERE user_id = ? ORDER BY module");
$st->execute([$simoneId]);
$overrides = $st->fetchAll();
$overrideMap = [];
foreach ($overrides as $o) {
    $overrideMap[$o['module']] = (int)$o['allowed'];
    echo "  " . str_pad($o['module'], 30) . " allowed=" . $o['allowed'] . "\n";
}

echo "\n==== Resultado de can_access() pra cada modulo ====\n";
// Reflete _permission_defaults — pega a lista interna
$defaults = function_exists('_permission_defaults') ? _permission_defaults() : [];
$todos = array_unique(array_merge(array_keys($defaults), array_keys($overrideMap)));
sort($todos);

$liberados = [];
$bloqueados = [];
foreach ($todos as $mod) {
    // Replica a lógica de can_access (sem precisar do user logado)
    $temOverride = isset($overrideMap[$mod]);
    $defaultRoles = isset($defaults[$mod]) ? $defaults[$mod] : [];
    $defaultPermitido = in_array($simoneRole, $defaultRoles, true);
    if ($temOverride) {
        $podeAcessar = ($overrideMap[$mod] === 1);
        $fonte = "override=" . $overrideMap[$mod];
    } else {
        $podeAcessar = $defaultPermitido;
        $fonte = "default(" . implode(',', $defaultRoles) . ")";
    }
    $marca = $podeAcessar ? '✅' : '❌';
    if ($podeAcessar) {
        $liberados[] = "  $marca " . str_pad($mod, 30) . " [$fonte]";
    } else {
        $bloqueados[] = "  $marca " . str_pad($mod, 30) . " [$fonte]";
    }
}
echo "\n--- LIBERADOS (" . count($liberados) . ") ---\n";
foreach ($liberados as $l) echo $l . "\n";
echo "\n--- BLOQUEADOS (" . count($bloqueados) . ") ---\n";
foreach ($bloqueados as $b) echo $b . "\n";

echo "\n==== Colunas de Kanban escondidas pra Simone ====\n";
try {
    $st = $pdo->prepare("SELECT kanban_modulo, column_key FROM user_kanban_hidden_columns WHERE user_id = ?");
    $st->execute([$simoneId]);
    foreach ($st->fetchAll() as $r) {
        echo "  {$r['kanban_modulo']} → coluna '{$r['column_key']}' OCULTA\n";
    }
} catch (Exception $e) { echo "  (tabela não existe ou erro: " . $e->getMessage() . ")\n"; }
