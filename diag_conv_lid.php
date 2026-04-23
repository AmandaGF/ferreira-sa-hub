<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();
echo "=== conversas com telefone @lid ===\n\n";
$r = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato, client_id, eh_grupo, ultima_msg_em FROM zapi_conversas WHERE telefone LIKE '%@lid%' ORDER BY ultima_msg_em DESC LIMIT 50")->fetchAll();
foreach ($r as $c) {
    echo "#{$c['id']} canal={$c['canal']} tel={$c['telefone']} chat_lid=" . ($c['chat_lid'] ?: 'NULL') . " nome={$c['nome_contato']} cli={$c['client_id']} grupo={$c['eh_grupo']} ult={$c['ultima_msg_em']}\n";
}
echo "\n--- conversa #674 especifica ---\n";
$r674 = $pdo->query("SELECT * FROM zapi_conversas WHERE id = 674")->fetch();
if (!$r674) echo "Nao existe #674\n";
else print_r($r674);
echo "\n--- conversa #263 (a certa) ---\n";
$r263 = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato, client_id FROM zapi_conversas WHERE id = 263")->fetch();
if (!$r263) echo "Nao existe #263\n";
else print_r($r263);
