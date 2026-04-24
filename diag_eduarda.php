<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== A) Cliente EDUARDA DO NASCIMENTO PIMENTA ===\n";
$q = $pdo->prepare("SELECT id, name, phone, birth_date FROM clients WHERE name LIKE ? OR name LIKE ?");
$q->execute(array('%EDUARDA%NASCIMENTO%PIMENTA%', '%EDUARDA%PIMENTA%'));
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} {$r['name']} tel={$r['phone']} nasc={$r['birth_date']}\n";
}

echo "\n=== B) Conversa com telefone 2197369-8089 ou 21973698089 ===\n";
$q = $pdo->query("SELECT id, canal, telefone, client_id, chat_lid, nome_contato, status, created_at, updated_at FROM zapi_conversas WHERE telefone LIKE '%973698089%' OR telefone LIKE '%97369%8089%' OR telefone LIKE '%21973698089%'");
foreach ($q->fetchAll() as $r) {
    echo "conv #{$r['id']} canal={$r['canal']} tel={$r['telefone']} client={$r['client_id']} lid={$r['chat_lid']} nome='{$r['nome_contato']}' status={$r['status']} updated={$r['updated_at']}\n";
}

echo "\n=== C) Conversas vinculadas ao client EDUARDA ===\n";
$q = $pdo->query("SELECT c.id AS conv, c.canal, c.telefone, c.client_id, c.chat_lid, c.nome_contato, c.status FROM zapi_conversas c JOIN clients cli ON cli.id = c.client_id WHERE cli.name LIKE '%EDUARDA%PIMENTA%'");
foreach ($q->fetchAll() as $r) {
    echo "conv #{$r['conv']} canal={$r['canal']} tel={$r['telefone']} client={$r['client_id']} lid={$r['chat_lid']} nome='{$r['nome_contato']}' status={$r['status']}\n";
}

echo "\n=== D) Últimas 10 msgs da conv com telefone 21973698089 ===\n";
$q = $pdo->query("SELECT m.id, m.conversa_id, m.direcao, m.created_at, m.enviado_por_id, m.enviado_por_bot, SUBSTR(COALESCE(m.conteudo,''),1,90) AS preview FROM zapi_mensagens m JOIN zapi_conversas c ON c.id=m.conversa_id WHERE c.telefone LIKE '%973698089%' OR c.telefone LIKE '%21973698089%' ORDER BY m.id DESC LIMIT 15");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} conv={$r['conversa_id']} [{$r['direcao']}] {$r['created_at']} por={$r['enviado_por_id']} bot={$r['enviado_por_bot']}: {$r['preview']}\n";
}

echo "\n=== E) Quem mais tem o mesmo chat_lid ou telefone parecido? ===\n";
$q = $pdo->query("SELECT c.id AS conv, c.canal, c.telefone, c.client_id, c.chat_lid, cli.name AS cliente FROM zapi_conversas c LEFT JOIN clients cli ON cli.id=c.client_id WHERE c.telefone LIKE '%973698089%' OR c.chat_lid IN (SELECT chat_lid FROM zapi_conversas WHERE telefone LIKE '%21973698089%' OR telefone LIKE '%973698089%')");
foreach ($q->fetchAll() as $r) {
    echo "conv #{$r['conv']} canal={$r['canal']} tel={$r['telefone']} client={$r['client_id']} ({$r['cliente']}) lid={$r['chat_lid']}\n";
}

echo "\n=== F) Todas as conversas cujo chat_lid É CONHECIDO ===\n";
echo "(chat_lid diferente de NULL/vazio — inventariar quem atrela)\n";
$q = $pdo->query("SELECT c.id, c.canal, c.telefone, c.chat_lid, c.client_id, cli.name AS cliente FROM zapi_conversas c LEFT JOIN clients cli ON cli.id=c.client_id WHERE c.chat_lid IS NOT NULL AND c.chat_lid != '' ORDER BY c.chat_lid");
$porLid = array();
foreach ($q->fetchAll() as $r) {
    $porLid[$r['chat_lid']][] = $r;
}
$colisoes = array_filter($porLid, function($arr){ return count($arr) > 1; });
echo "Total de chat_lid usados: " . count($porLid) . "\n";
echo "chat_lid com MAIS DE UMA conversa (colisão): " . count($colisoes) . "\n";
foreach ($colisoes as $lid => $arr) {
    echo "\n[$lid]\n";
    foreach ($arr as $r) echo "  conv #{$r['id']} canal={$r['canal']} tel={$r['telefone']} client={$r['client_id']} ({$r['cliente']})\n";
}
