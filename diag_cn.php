<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__.'/core/config.php'; require_once __DIR__.'/core/database.php';
$pdo=db();
echo "colunas zapi_conversas relevantes:\n";
foreach($pdo->query("SHOW COLUMNS FROM zapi_conversas")->fetchAll(PDO::FETCH_ASSOC) as $c){ if(in_array($c['Field'],array('id','canal','created_at','telefone','eh_grupo','status'))) echo "  {$c['Field']} ({$c['Type']})\n"; }
echo "\ntotal por canal + faixa de datas:\n";
foreach($pdo->query("SELECT canal, COUNT(*) c, MIN(created_at) mn, MAX(created_at) mx FROM zapi_conversas GROUP BY canal")->fetchAll() as $r) echo "  canal={$r['canal']}: {$r['c']} ({$r['mn']} .. {$r['mx']})\n";
