<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG convs Sharlon + Luiz Eduardo — " . date('Y-m-d H:i:s') . " ===\n\n";

// SHARLON — tel 5512997688947
echo "--- SHARLON (tel 5512997688947) ---\n";
$r = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato, atendente_id, status, eh_grupo, ultima_msg_em, created_at
    FROM zapi_conversas WHERE telefone LIKE '%2997688947%' OR nome_contato LIKE '%sharlon%' OR nome_contato LIKE '%Sharlon%'
    ORDER BY ultima_msg_em DESC")->fetchAll();
foreach ($r as $c) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$c['id']}")->fetchColumn();
    echo "  #{$c['id']} canal={$c['canal']} tel={$c['telefone']} nome='{$c['nome_contato']}' atend=" . ($c['atendente_id'] ?: '-') . " [{$c['status']}] grupo={$c['eh_grupo']} msgs={$n} ult={$c['ultima_msg_em']} criada={$c['created_at']}\n";
}

// LUIZ EDUARDO pessoal — tel 5524999816600
echo "\n--- LUIZ EDUARDO (tel 5524999816600) ---\n";
$r = $pdo->query("SELECT id, canal, telefone, chat_lid, nome_contato, atendente_id, status, eh_grupo, ultima_msg_em, created_at
    FROM zapi_conversas WHERE telefone LIKE '%999816600%' OR telefone LIKE '%4999816600%'
    ORDER BY ultima_msg_em DESC")->fetchAll();
foreach ($r as $c) {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = {$c['id']}")->fetchColumn();
    echo "  #{$c['id']} canal={$c['canal']} tel={$c['telefone']} nome='{$c['nome_contato']}' atend=" . ($c['atendente_id'] ?: '-') . " [{$c['status']}] grupo={$c['eh_grupo']} msgs={$n} ult={$c['ultima_msg_em']} criada={$c['created_at']}\n";
}

// Verifica conv #2 especificamente
echo "\n--- CONV #2 (Sharlon — esperado após mescla) ---\n";
$conv = $pdo->query("SELECT * FROM zapi_conversas WHERE id = 2")->fetch();
if (!$conv) echo "  NÃO EXISTE!\n";
else {
    print_r($conv);
    echo "\nÚltimas 20 msgs:\n";
    $m = $pdo->query("SELECT id, created_at, direcao, enviado_por_id, tipo, LEFT(conteudo, 60) AS p FROM zapi_mensagens WHERE conversa_id = 2 ORDER BY id DESC LIMIT 20")->fetchAll();
    foreach ($m as $mm) echo "  #{$mm['id']} {$mm['created_at']} [{$mm['direcao']}] u={$mm['enviado_por_id']} [{$mm['tipo']}] {$mm['p']}\n";
}

// Verifica conv #97 especificamente
echo "\n--- CONV #97 (Luiz Eduardo — esperado) ---\n";
$conv = $pdo->query("SELECT * FROM zapi_conversas WHERE id = 97")->fetch();
if (!$conv) echo "  NÃO EXISTE!\n";
else {
    print_r($conv);
    echo "\nÚltimas 20 msgs:\n";
    $m = $pdo->query("SELECT id, created_at, direcao, enviado_por_id, tipo, LEFT(conteudo, 60) AS p FROM zapi_mensagens WHERE conversa_id = 97 ORDER BY id DESC LIMIT 20")->fetchAll();
    foreach ($m as $mm) echo "  #{$mm['id']} {$mm['created_at']} [{$mm['direcao']}] u={$mm['enviado_por_id']} [{$mm['tipo']}] {$mm['p']}\n";
}
