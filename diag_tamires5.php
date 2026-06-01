<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
set_time_limit(60);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

// Variantes do telefone
$variantes = array('24999242710','5524999242710','554999242710');

echo "== 1) BUSCA por telefone EXATO em zapi_conversas ==\n";
foreach ($variantes as $tel) {
    $st = $pdo->prepare("SELECT id, telefone, nome_contato, canal, status, atendente_id, nao_lidas, ultima_msg_em, eh_grupo FROM zapi_conversas WHERE telefone = ?");
    $st->execute(array($tel));
    $rs = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "  '$tel' -> " . count($rs) . " encontrado\n";
    foreach ($rs as $r) {
        echo "    id={$r['id']} canal={$r['canal']} status={$r['status']} atendente={$r['atendente_id']} nao_lidas={$r['nao_lidas']} eh_grupo={$r['eh_grupo']} nome={$r['nome_contato']} ultima={$r['ultima_msg_em']}\n";
    }
}

echo "\n== 2) BUSCA por terminacao 999242710 ==\n";
$st = $pdo->prepare("SELECT id, telefone, nome_contato, canal, status, atendente_id, nao_lidas, ultima_msg_em, eh_grupo, chat_lid FROM zapi_conversas WHERE telefone LIKE ? ORDER BY id DESC");
$st->execute(array('%999242710%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['id']} tel={$r['telefone']} canal={$r['canal']} status={$r['status']} atendente={$r['atendente_id']} nao_lidas={$r['nao_lidas']} eh_grupo={$r['eh_grupo']} chat_lid={$r['chat_lid']} nome={$r['nome_contato']} ultima={$r['ultima_msg_em']}\n";
}

echo "\n== 3) BUSCA por nome contendo 'amir' (Tamires) ==\n";
$st = $pdo->prepare("SELECT id, telefone, nome_contato, canal, status, atendente_id, nao_lidas, ultima_msg_em FROM zapi_conversas WHERE nome_contato LIKE ? ORDER BY id DESC LIMIT 10");
$st->execute(array('%amir%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  id={$r['id']} tel={$r['telefone']} canal={$r['canal']} status={$r['status']} nome={$r['nome_contato']} ultima={$r['ultima_msg_em']}\n";
}

echo "\n== 4) MENSAGENS raw contendo 24999242710 ultimas 7d ==\n";
$st = $pdo->prepare("SELECT id, conversa_id, direcao, status, criada_em, SUBSTR(raw_payload, 1, 200) as p FROM zapi_mensagens WHERE raw_payload LIKE ? AND criada_em > DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY id DESC LIMIT 10");
$st->execute(array('%24999242710%'));
$msgs = $st->fetchAll(PDO::FETCH_ASSOC);
echo "  total: " . count($msgs) . "\n";
foreach ($msgs as $m) {
    echo "  msg id={$m['id']} conv={$m['conversa_id']} dir={$m['direcao']} status={$m['status']} em={$m['criada_em']}\n";
}

echo "\n== 5) CLIENTE por telefone ==\n";
$st = $pdo->prepare("SELECT id, name, phone, created_at FROM clients WHERE phone LIKE ?");
$st->execute(array('%999242710%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  id={$c['id']} nome={$c['name']} tel={$c['phone']} criado={$c['created_at']}\n";
}

echo "\n== 6) LEAD por telefone ==\n";
$st = $pdo->prepare("SELECT id, nome, telefone, etapa, created_at FROM pipeline_leads WHERE telefone LIKE ? ORDER BY id DESC LIMIT 5");
$st->execute(array('%999242710%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  id={$c['id']} nome={$c['nome']} tel={$c['telefone']} etapa={$c['etapa']} criado={$c['created_at']}\n";
}
