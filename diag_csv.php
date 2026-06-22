<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@session_start(); $_SESSION['user']=array('id'=>1,'name'=>'A','role'=>'admin');
$_GET=array('gran'=>'mes','export'=>'csv'); ob_start(); require __DIR__.'/modules/whatsapp/conversas_novas.php'; $c=ob_get_clean();
file_put_contents('php://stderr',''); 
echo "=== CSV tabela (mes) — 6 linhas ===\n"; $L=explode("\n",$c); foreach(array_slice($L,0,6) as $l) echo "  ".trim($l)."\n";
