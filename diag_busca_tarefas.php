<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/modules/tarefas/index.php';
$cont = file_get_contents($f);
echo "Arquivo: $f\n";
echo "filemtime: " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
echo "size: " . strlen($cont) . " bytes\n\n";
echo (strpos($cont, 'id="fBusca"') !== false) ? "OK: id=fBusca presente\n" : "FALTA: id=fBusca ausente!\n";
echo (strpos($cont, 'reloadDebounce') !== false) ? "OK: reloadDebounce presente\n" : "FALTA: reloadDebounce ausente!\n";

$api = __DIR__ . '/modules/tarefas/api.php';
$capi = file_get_contents($api);
echo "\nAPI filemtime: " . date('Y-m-d H:i:s', filemtime($api)) . "\n";
echo (strpos($capi, "\$_GET['q']") !== false) ? "OK: \$_GET['q'] presente em api.php\n" : "FALTA: \$_GET['q']!\n";
