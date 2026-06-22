<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db(); $D='2026-05-18';
echo "=== $D : total por canal/grupo ===\n";
foreach($pdo->query("SELECT canal, COALESCE(eh_grupo,0) g, COUNT(*) c FROM zapi_conversas WHERE DATE(created_at)='$D' GROUP BY canal, g")->fetchAll() as $r) echo "  canal={$r['canal']} grupo={$r['g']}: {$r['c']}\n";
echo "\n=== distribuição por HORA:MIN (cluster = importação?) canal 21 ===\n";
foreach($pdo->query("SELECT DATE_FORMAT(created_at,'%H:%i') hm, COUNT(*) c FROM zapi_conversas WHERE DATE(created_at)='$D' AND canal='21' GROUP BY hm ORDER BY c DESC LIMIT 12")->fetchAll() as $r) echo "  {$r['hm']}: {$r['c']}\n";
echo "\n=== amostra (15) canal 21 ===\n";
foreach($pdo->query("SELECT id, telefone, created_at, status, client_id, lead_id, LEFT(nome_contato,22) n FROM zapi_conversas WHERE DATE(created_at)='$D' AND canal='21' ORDER BY created_at LIMIT 15")->fetchAll() as $r) echo "  #{$r['id']} {$r['created_at']} tel={$r['telefone']} st={$r['status']} cli={$r['client_id']} lead={$r['lead_id']} {$r['n']}\n";
echo "\n=== a 1a msg de cada conversa do dia eh recebida (lead) ou enviada (nos)? canal 21 ===\n";
$rows=$pdo->query("SELECT co.id, (SELECT m.direcao FROM zapi_mensagens m WHERE m.conversa_id=co.id ORDER BY m.id ASC LIMIT 1) primeira FROM zapi_conversas co WHERE DATE(co.created_at)='$D' AND co.canal='21'")->fetchAll();
$rec=0;$env=0;$vazio=0; foreach($rows as $r){ if($r['primeira']==='recebida')$rec++; elseif($r['primeira']==='enviada')$env++; else $vazio++; }
echo "  1a recebida (lead falou): $rec | 1a enviada (nos falamos): $env | sem msg: $vazio\n";
echo "\n=== telefones duplicados no dia (canal 21) ===\n";
foreach($pdo->query("SELECT telefone, COUNT(*) c FROM zapi_conversas WHERE DATE(created_at)='$D' AND canal='21' GROUP BY telefone HAVING c>1 ORDER BY c DESC LIMIT 8")->fetchAll() as $r) echo "  {$r['telefone']}: {$r['c']}x\n";
