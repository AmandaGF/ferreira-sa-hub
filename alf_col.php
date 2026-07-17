<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "COLUNAS zapi_mensagens:\n";
foreach ($pdo->query("DESCRIBE zapi_mensagens") as $r) echo "  " . $r['Field'] . " (" . $r['Type'] . ")\n";
