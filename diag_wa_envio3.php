<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();
function p($s){ echo $s . "\n"; }

p("== Colunas reais de zapi_mensagens ==");
$cols = $pdo->query("SHOW COLUMNS FROM zapi_mensagens")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) p("  {$c['Field']} {$c['Type']}");
