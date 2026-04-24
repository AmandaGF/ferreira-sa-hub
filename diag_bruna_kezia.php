<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== A) Cliente BRUNA TEIXEIRA / KEZIA ===\n";
$q = $pdo->query("SELECT id, name, phone, birth_date FROM clients WHERE name LIKE '%BRUNA%TEIXEIRA%NASCIMENTO%' OR name LIKE '%KEZIA%' ORDER BY name");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} {$r['name']} tel={$r['phone']} nasc={$r['birth_date']}\n";
}

echo "\n=== B) Conversa da BRUNA (tel 22 98110-4150) ===\n";
$q = $pdo->query("SELECT id, canal, telefone, chat_lid, client_id, nome_contato, status, updated_at FROM zapi_conversas WHERE telefone LIKE '%98110%4150%' OR telefone LIKE '%2298110%' OR telefone LIKE '%98110415%'");
foreach ($q->fetchAll() as $r) {
    echo "conv #{$r['id']} canal={$r['canal']} tel={$r['telefone']} lid={$r['chat_lid']} client={$r['client_id']} nome='{$r['nome_contato']}' status={$r['status']} updated={$r['updated_at']}\n";
}

echo "\n=== C) Últimas 10 msgs enviadas hoje às 09:00-09:10 ===\n";
$q = $pdo->query("SELECT m.id, m.conversa_id, m.zapi_message_id, m.enviado_por_id, m.enviado_por_bot, m.created_at, c.telefone AS conv_tel, c.client_id, cli.name AS cli_name, SUBSTR(m.conteudo, 1, 60) AS preview FROM zapi_mensagens m JOIN zapi_conversas c ON c.id=m.conversa_id LEFT JOIN clients cli ON cli.id=c.client_id WHERE m.direcao='enviada' AND m.created_at BETWEEN '2026-04-24 08:55:00' AND '2026-04-24 09:30:00' ORDER BY m.id");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} conv={$r['conversa_id']} tel={$r['conv_tel']} client={$r['client_id']} ({$r['cli_name']}) zapi={$r['zapi_message_id']} por={$r['enviado_por_id']}/bot={$r['enviado_por_bot']} {$r['created_at']}\n";
    echo "    preview: {$r['preview']}\n";
}

echo "\n=== D) birthday_greetings enviados hoje ===\n";
$q = $pdo->query("SELECT bg.id, bg.client_id, bg.sent_at, cli.name, cli.phone FROM birthday_greetings bg LEFT JOIN clients cli ON cli.id=bg.client_id WHERE DATE(bg.sent_at) = CURDATE() ORDER BY bg.id");
foreach ($q->fetchAll() as $r) {
    echo "bg#{$r['id']} client={$r['client_id']} ({$r['name']}) tel={$r['phone']} sent={$r['sent_at']}\n";
}

echo "\n=== E) TODAS conversas com msgs hoje entre 08:55-09:30 ===\n";
$q = $pdo->query("SELECT DISTINCT c.id, c.canal, c.telefone, c.chat_lid, c.client_id, cli.name FROM zapi_mensagens m JOIN zapi_conversas c ON c.id=m.conversa_id LEFT JOIN clients cli ON cli.id=c.client_id WHERE m.direcao='enviada' AND m.created_at BETWEEN '2026-04-24 08:55:00' AND '2026-04-24 09:30:00'");
foreach ($q->fetchAll() as $r) {
    echo "conv #{$r['id']} canal={$r['canal']} tel={$r['telefone']} lid={$r['chat_lid']} client={$r['client_id']} ({$r['name']})\n";
}
