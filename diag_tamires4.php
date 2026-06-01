<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
set_time_limit(60);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== COLUNAS de zapi_conversas ==\n";
foreach ($pdo->query("SHOW COLUMNS FROM zapi_conversas")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  {$c['Field']} ({$c['Type']})\n";
}
echo "\n== AMOSTRA: ultimas 3 conversas ==\n";
foreach ($pdo->query("SELECT * FROM zapi_conversas ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ---\n";
    foreach ($r as $k => $v) {
        if (is_string($v) && strlen($v) > 80) $v = substr($v, 0, 80) . '...';
        echo "  $k = " . var_export($v, true) . "\n";
    }
}
