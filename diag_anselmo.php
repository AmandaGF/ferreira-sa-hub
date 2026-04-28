<?php
ini_set('display_errors', '1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Buscar conversa do Anselmo
$st = $pdo->query("SELECT * FROM zapi_conversas WHERE nome_contato LIKE '%Anselmo%' OR telefone LIKE '%06231%'");
$convs = $st->fetchAll();
echo "=== Conversas encontradas: " . count($convs) . " ===\n";
foreach ($convs as $c) {
    echo "id={$c['id']} | tel={$c['telefone']} | lid=" . ($c['chat_lid'] ?? '-') . " | nome=" . ($c['nome_contato'] ?? '-') . " | client_id=" . ($c['client_id'] ?? '-') . " | canal={$c['canal']}\n";
}

if (empty($convs)) exit;

// Últimas msgs da conversa
$convId = $convs[0]['id'];
echo "\n=== Últimas 15 mensagens (conv {$convId}) ===\n";
$st = $pdo->prepare("SELECT id, direcao, tipo, LEFT(conteudo, 60) as txt, status, zapi_message_id, zapi_response, created_at FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 15");
$st->execute(array($convId));
foreach (array_reverse($st->fetchAll()) as $m) {
    echo "id={$m['id']} | {$m['direcao']} | {$m['tipo']} | status={$m['status']} | msg_id=" . ($m['zapi_message_id'] ?: '<vazio>') . " | {$m['created_at']} | {$m['txt']}\n";
    if ($m['direcao'] === 'enviada' && $m['zapi_response']) {
        $j = json_decode($m['zapi_response'], true);
        if ($j) echo "    response: " . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

// Phone-exists na Z-API pra esse número
echo "\n=== Validar número via Z-API /phone-exists ===\n";
require_once __DIR__ . '/core/functions_zapi.php';
$telConv = $convs[0]['telefone'];
echo "Tel da conv: {$telConv}\n";
$canal = $convs[0]['canal'] ?: '24';
$r = zapi_phone_exists($canal, $telConv);
echo "Resultado: " . json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

// Buscar nos clients
echo "\n=== Clients com 'Anselmo' ou phone parecido ===\n";
$st = $pdo->query("SELECT id, name, phone, whatsapp_lid FROM clients WHERE name LIKE '%Anselmo%' OR phone LIKE '%06231%'");
foreach ($st->fetchAll() as $c) {
    echo "id={$c['id']} | {$c['name']} | phone={$c['phone']} | lid=" . ($c['whatsapp_lid'] ?? '-') . "\n";
}
