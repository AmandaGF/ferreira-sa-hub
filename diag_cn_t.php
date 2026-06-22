<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
ini_set('display_errors','1'); error_reporting(E_ALL); @session_start();
$_SESSION['user']=array('id'=>1,'name'=>'A','role'=>'admin');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php'; $pdo=db();
echo "18/05 canal21 — leads (1a recebida): ".(int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas co WHERE DATE(created_at)='2026-05-18' AND canal='21' AND COALESCE(eh_grupo,0)=0 AND (SELECT mm.direcao FROM zapi_mensagens mm WHERE mm.conversa_id=co.id ORDER BY mm.id ASC LIMIT 1)='recebida'")->fetchColumn()."\n";
foreach(array('mes'=>14) as $g=>$x){ $_GET=array('gran'=>$g); $t0=microtime(true); ob_start(); require __DIR__.'/modules/whatsapp/conversas_novas.php'; $h=ob_get_clean(); echo "[$g] OK ".strlen($h)."b em ".round(microtime(true)-$t0,2)."s\n"; }
