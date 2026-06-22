<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@session_start(); $_SESSION['user']=array('id'=>1,'name'=>'A','role'=>'admin');
$_GET=array('gran'=>'dia','dia'=>'2026-05-18');
ob_start(); require __DIR__.'/modules/whatsapp/conversas_novas.php'; $h=ob_get_clean();
header('Content-Type: text/plain; charset=utf-8');
$p=strpos($h,'id="leads"'); $sec=substr($h,$p, 2600);
// pega as primeiras linhas <tr> da tabela de leads
$sec=preg_replace('/\s+/',' ',$sec);
if(preg_match_all('/<tr> <td>(\d\d)<\/td><td[^>]*>([^<]{0,20})[^<]*<\/td><td>([^<]+)<\/td><td>([^<]+)<\/td><td>([^<]+)<\/td>/u',$sec,$m,PREG_SET_ORDER)){
  foreach(array_slice($m,0,6) as $r) echo "canal={$r[1]} | {$r[2]} | 1amsg={$r[3]} | resp={$r[4]} | tempo={$r[5]}\n";
} else { echo "(não casou o padrão; trecho:)\n".substr($sec,0,400)."\n"; }
