<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
ini_set('display_errors','1'); error_reporting(E_ALL);
@session_start();
$_SESSION['user'] = array('id'=>1,'name'=>'Amanda','role'=>'admin');
header('Content-Type: text/plain; charset=utf-8');
register_shutdown_function(function(){ $e=error_get_last(); if($e && in_array($e['type'],array(E_ERROR,E_PARSE,E_COMPILE_ERROR))) echo "\n>>> FATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n"; });
ob_start();
try { require __DIR__.'/modules/whatsapp/conversas_novas.php'; $h=ob_get_clean(); echo "OK render ".strlen($h)." bytes\n"; }
catch (Throwable $e){ @ob_end_clean(); echo ">>> EXC: ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine()."\n"; }
