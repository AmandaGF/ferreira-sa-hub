<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
ini_set('display_errors','1'); error_reporting(E_ALL); @session_start();
$_SESSION['user']=array('id'=>1,'name'=>'A','role'=>'admin');
header('Content-Type: text/plain; charset=utf-8');
register_shutdown_function(function(){$e=error_get_last();if($e&&in_array($e['type'],array(E_ERROR,E_PARSE,E_COMPILE_ERROR)))echo "\n>>>FATAL {$e['message']} @ {$e['line']}\n";});
$casos=array(array('gran'=>'mes'),array('gran'=>'dia'),array('gran'=>'dia','dia'=>'2026-05-18'));
foreach($casos as $g){ $_GET=$g; $t0=microtime(true); ob_start(); try{ require __DIR__.'/modules/whatsapp/conversas_novas.php'; $h=ob_get_clean(); $tag=$g['gran'].(isset($g['dia'])?(' dia='.$g['dia']):''); echo "[$tag] OK ".strlen($h)."b em ".round(microtime(true)-$t0,2)."s\n"; }catch(Throwable $e){@ob_end_clean();echo "EXC ".$e->getMessage()." @ ".$e->getLine()."\n";} }
