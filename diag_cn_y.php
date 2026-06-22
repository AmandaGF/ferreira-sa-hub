<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
ini_set('display_errors','1'); error_reporting(E_ALL); @session_start();
$_SESSION['user']=array('id'=>1,'name'=>'A','role'=>'admin');
header('Content-Type: text/plain; charset=utf-8');
register_shutdown_function(function(){$e=error_get_last();if($e&&in_array($e['type'],array(E_ERROR,E_PARSE,E_COMPILE_ERROR)))echo "\n>>>FATAL {$e['message']} @ {$e['line']}\n";});
$_GET=array('gran'=>'dia','dia'=>'2026-05-18'); $t0=microtime(true);
ob_start(); try{ require __DIR__.'/modules/whatsapp/conversas_novas.php'; $h=ob_get_clean();
  echo "OK ".strlen($h)."b em ".round(microtime(true)-$t0,2)."s\n";
  echo "tem secao leads? ".(strpos($h,'Leads de 18/05/2026')!==false?'sim':'nao')."\n";
  if(preg_match_all('/<td>(\d{2}:\d{2}|—)<\/td>/u',$h,$m)) echo "horarios 1a msg (amostra): ".implode(', ',array_slice($m[1],0,6))."\n";
}catch(Throwable $e){@ob_end_clean();echo "EXC ".$e->getMessage()."\n";}
