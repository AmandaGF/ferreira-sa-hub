<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Conversa com telefone 250543024922777 ===\n";
$q = $pdo->prepare("SELECT id, canal, telefone, chat_lid, client_id, nome_contato, status, created_at, updated_at FROM zapi_conversas WHERE telefone LIKE '%250543024922777%' OR telefone LIKE '%250543%' OR chat_lid LIKE '%250543%'");
$q->execute();
foreach ($q->fetchAll() as $r) {
    echo "conv #{$r['id']} canal={$r['canal']} tel={$r['telefone']} lid={$r['chat_lid']} client={$r['client_id']} nome='{$r['nome_contato']}' status={$r['status']} updated={$r['updated_at']}\n";
}

echo "\n=== Últimas 15 msgs dessa conv (pra descobrir quem é pela transcrição) ===\n";
$q = $pdo->prepare("SELECT m.id, m.direcao, m.tipo, m.created_at, SUBSTR(COALESCE(m.conteudo,m.transcricao,''), 1, 200) AS preview FROM zapi_mensagens m JOIN zapi_conversas c ON c.id=m.conversa_id WHERE c.telefone LIKE '%250543024922777%' ORDER BY m.id DESC LIMIT 15");
$q->execute();
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} [{$r['direcao']}/{$r['tipo']}] {$r['created_at']}: {$r['preview']}\n\n";
}
