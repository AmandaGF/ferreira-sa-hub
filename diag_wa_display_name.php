<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Users ativos com wa_display_name preenchido ===\n";
$st = $pdo->query("SELECT id, name, wa_display_name, role
                   FROM users
                   WHERE is_active = 1
                     AND wa_display_name IS NOT NULL AND wa_display_name != ''
                   ORDER BY name");
foreach ($st as $u) {
    $bate = mb_strtolower($u['name']) === mb_strtolower($u['wa_display_name'])
         || mb_strpos(mb_strtolower($u['name']), mb_strtolower($u['wa_display_name'])) !== false;
    $marca = $bate ? '✓ ok' : '⚠️ DIVERGENTE';
    echo "  #{$u['id']} | {$u['name']} | wa_display='{$u['wa_display_name']}' | role={$u['role']} | $marca\n";
}
