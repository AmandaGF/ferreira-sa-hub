<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$pad = '%24999242710%';
echo "PROBE 1: COUNT em zapi_conversas com padrao $pad\n";
$st = $pdo->prepare("SELECT COUNT(*) FROM zapi_conversas WHERE contato_telefone LIKE ?");
$st->execute(array($pad));
echo "  resultado: " . (int)$st->fetchColumn() . "\n";
echo "\nPROBE 2: COUNT em qualquer conversa\n";
echo "  total: " . (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas")->fetchColumn() . "\n";
echo "\nPROBE 3: ultimas 5 conversas (qualquer)\n";
$st = $pdo->query("SELECT id, contato_nome, contato_telefone, canal, status, ultima_msg_em FROM zapi_conversas ORDER BY id DESC LIMIT 5");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  id={$c['id']} tel={$c['contato_telefone']} canal={$c['canal']} status={$c['status']} ultima={$c['ultima_msg_em']} nome={$c['contato_nome']}\n";
}
echo "\nPROBE 4: busca Tamires por NOME parcial\n";
$st = $pdo->prepare("SELECT id, contato_nome, contato_telefone, canal, status, ultima_msg_em FROM zapi_conversas WHERE contato_nome LIKE ? ORDER BY id DESC LIMIT 10");
$st->execute(array('%amir%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  id={$c['id']} tel={$c['contato_telefone']} canal={$c['canal']} status={$c['status']} ultima={$c['ultima_msg_em']} nome={$c['contato_nome']}\n";
}
echo "\nPROBE 5: busca por digitos exatos sem mascara\n";
$st = $pdo->query("SELECT id, contato_nome, contato_telefone, canal FROM zapi_conversas WHERE REPLACE(REPLACE(REPLACE(REPLACE(contato_telefone,'+',''),'-',''),' ',''),'(','') LIKE '%24999242710%' OR REPLACE(REPLACE(REPLACE(REPLACE(contato_telefone,'+',''),'-',''),' ',''),')','') LIKE '%24999242710%'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  id={$c['id']} tel={$c['contato_telefone']} canal={$c['canal']} nome={$c['contato_nome']}\n";
}
echo "\nFIM\n";
