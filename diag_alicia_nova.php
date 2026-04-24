<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== A) Conversas com telefone 5524998137649 ou 24998137649 ===\n";
$q = $pdo->query("SELECT id, canal, telefone, client_id, nome_contato, chat_lid, status, created_at, updated_at FROM zapi_conversas WHERE telefone LIKE '%24998137649%' OR telefone LIKE '%998137649%'");
foreach ($q->fetchAll() as $r) {
    echo "conv {$r['id']} canal={$r['canal']} tel={$r['telefone']} client={$r['client_id']} lid={$r['chat_lid']} status={$r['status']} created={$r['created_at']} updated={$r['updated_at']}\n";
}

echo "\n=== B) Todas as mensagens 'Vamos liberar para você o acesso à plataforma' ===\n";
$q = $pdo->query("SELECT id, conversa_id, direcao, created_at, enviado_por_id, status, zapi_message_id FROM zapi_mensagens WHERE conteudo LIKE '%Vamos liberar para você o acesso à plataforma%' ORDER BY id DESC LIMIT 20");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} conv={$r['conversa_id']} [{$r['direcao']}] {$r['created_at']} por={$r['enviado_por_id']} status={$r['status']} zapi_id={$r['zapi_message_id']}\n";
}

echo "\n=== C) Conversas criadas HOJE (2026-04-24) ===\n";
$q = $pdo->query("SELECT id, canal, telefone, client_id, chat_lid, nome_contato, status, created_at FROM zapi_conversas WHERE DATE(created_at) = CURDATE() ORDER BY id DESC");
foreach ($q->fetchAll() as $r) {
    echo "conv {$r['id']} canal={$r['canal']} tel={$r['telefone']} client={$r['client_id']} lid={$r['chat_lid']} nome='{$r['nome_contato']}' status={$r['status']} created={$r['created_at']}\n";
}

echo "\n=== D) Últimas 20 msgs ENVIADAS hoje ===\n";
$q = $pdo->query("SELECT m.id, m.conversa_id, c.telefone AS conv_tel, c.client_id, cli.name AS cli_name, c.chat_lid, m.created_at, SUBSTR(COALESCE(m.conteudo,''),1,80) AS preview FROM zapi_mensagens m JOIN zapi_conversas c ON c.id=m.conversa_id LEFT JOIN clients cli ON cli.id=c.client_id WHERE m.direcao='enviada' AND DATE(m.created_at) = CURDATE() ORDER BY m.id DESC LIMIT 20");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} conv={$r['conversa_id']} tel={$r['conv_tel']} client={$r['client_id']} ({$r['cli_name']}) lid={$r['chat_lid']} {$r['created_at']}: {$r['preview']}\n";
}

echo "\n=== E) Chave chat_lid de Alícia se existir ===\n";
$q = $pdo->query("SELECT id, canal, telefone, chat_lid, client_id, status FROM zapi_conversas WHERE chat_lid LIKE '%@lid%' AND client_id = 331");
foreach ($q->fetchAll() as $r) {
    echo "conv {$r['id']} canal={$r['canal']} tel={$r['telefone']} lid={$r['chat_lid']} client={$r['client_id']} status={$r['status']}\n";
}
