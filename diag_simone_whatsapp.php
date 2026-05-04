<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Simone (user_id, role) ===\n";
$st = $pdo->query("SELECT id, name, email, role, is_active FROM users WHERE name LIKE '%imone%'");
foreach ($st->fetchAll() as $r) echo "  #{$r['id']} {$r['name']} ({$r['email']}) role={$r['role']} ativo={$r['is_active']}\n";

echo "\n=== Permissoes da Simone hoje (user_permissions) ===\n";
try {
    $st = $pdo->query("SELECT user_id, module, allowed FROM user_permissions WHERE user_id IN (SELECT id FROM users WHERE name LIKE '%imone%') ORDER BY module");
    foreach ($st->fetchAll() as $r) echo "  user#{$r['user_id']}  {$r['module']}: " . ($r['allowed'] ? 'PERMITIDO' : 'BLOQUEADO') . "\n";
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }

echo "\n=== Conversas com 'gisele' no nome ===\n";
$st = $pdo->query("SELECT id, telefone, nome_contato, canal FROM zapi_conversas WHERE nome_contato LIKE '%isele%' ORDER BY id");
foreach ($st->fetchAll() as $r) echo "  conv#{$r['id']}  {$r['nome_contato']}  ({$r['telefone']})  canal={$r['canal']}\n";
