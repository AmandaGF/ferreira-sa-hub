<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Achar conv pelo telefone +55 13 99724-3820
$st = $pdo->query("SELECT * FROM zapi_conversas WHERE telefone LIKE '%99724%3820%' OR telefone LIKE '%997243820%' OR chat_lid LIKE '%99724%' ORDER BY id DESC LIMIT 5");
$convs = $st->fetchAll();
echo "=== Conversas com 99724-3820 ===\n";
foreach ($convs as $c) {
    echo "id={$c['id']} | tel={$c['telefone']} | nome=" . ($c['nome_contato'] ?? '-') . " | client_id=" . ($c['client_id'] ?? '-') . " | canal={$c['canal']}\n";
}
if (empty($convs)) exit("Sem conv. \n");

$convId = $convs[0]['id'];

// Áudios da conv (últimos 5)
echo "\n=== Últimos áudios enviados (conv {$convId}) ===\n";
$st = $pdo->prepare("SELECT * FROM zapi_mensagens WHERE conversa_id = ? AND tipo = 'audio' AND direcao = 'enviada' ORDER BY id DESC LIMIT 5");
$st->execute(array($convId));
foreach ($st->fetchAll() as $m) {
    echo "\n--- msg id={$m['id']} ---\n";
    echo "criada_em={$m['created_at']}\n";
    echo "status=" . ($m['status'] ?? '-') . "\n";
    echo "zapi_message_id=" . ($m['zapi_message_id'] ?? '<vazio>') . "\n";
    echo "arquivo_url=" . ($m['arquivo_url'] ?? '<vazio>') . "\n";
    echo "arquivo_mime=" . ($m['arquivo_mime'] ?? '-') . "\n";
    echo "arquivo_tamanho=" . ($m['arquivo_tamanho'] ?? '-') . " bytes\n";

    // Verifica se arquivo físico existe
    $url = $m['arquivo_url'] ?? '';
    if ($url && strpos($url, '/files/whatsapp/') !== false) {
        $nome = basename(parse_url($url, PHP_URL_PATH));
        $path = __DIR__ . '/files/whatsapp/' . urldecode($nome);
        if (file_exists($path)) {
            echo "arquivo físico: existe (" . filesize($path) . " bytes, modificado " . date('Y-m-d H:i:s', filemtime($path)) . ")\n";
        } else {
            echo "arquivo físico: NÃO EXISTE em " . $path . "\n";
        }
    }

    // Z-API response
    if (!empty($m['zapi_response'])) {
        echo "zapi_response: " . substr($m['zapi_response'], 0, 300) . "\n";
    }
}

// Webhook log mais recente da conv
echo "\n\n=== Últimos 5 webhook logs da conv {$convId} ===\n";
try {
    $st = $pdo->prepare("SELECT id, tipo_evento, estrategia_match, conversa_id, created_at, LEFT(payload_json, 400) as p FROM zapi_webhook_log WHERE conversa_id = ? ORDER BY id DESC LIMIT 5");
    $st->execute(array($convId));
    foreach ($st->fetchAll() as $log) {
        echo "log {$log['id']} {$log['created_at']} | tipo={$log['tipo_evento']} | estrategia={$log['estrategia_match']}\n";
        echo "  payload: " . $log['p'] . "\n\n";
    }
} catch (Exception $e) { echo "log indisponível: " . $e->getMessage() . "\n"; }

// Hora atual + delta
echo "\n=== Hora atual: " . date('Y-m-d H:i:s') . " ===\n";
