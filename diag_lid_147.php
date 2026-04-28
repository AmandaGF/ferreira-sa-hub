<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Encontrar conv com esse lid
$st = $pdo->query("SELECT * FROM zapi_conversas WHERE telefone LIKE '%147265905786930%' OR chat_lid LIKE '%147265905786930%'");
$convs = $st->fetchAll();
echo "=== Conversas com lid 147265905786930 ===\n";
foreach ($convs as $c) {
    echo "id={$c['id']} | tel={$c['telefone']} | chat_lid=" . ($c['chat_lid'] ?? '-') . " | client_id=" . ($c['client_id'] ?? '-') . " | nome=" . ($c['nome_contato'] ?? '-') . " | canal={$c['canal']} | revisao=" . ($c['precisa_revisao'] ?? 0) . " | motivo=" . ($c['motivo_revisao'] ?? '-') . "\n";
}
if (empty($convs)) exit("Nenhuma conv encontrada.\n");

$convId = $convs[0]['id'];
echo "\n=== Mensagens dessa conv ===\n";
$st = $pdo->prepare("SELECT id, direcao, tipo, LEFT(conteudo,60) as txt, status, zapi_message_id, created_at FROM zapi_mensagens WHERE conversa_id = ? ORDER BY id DESC LIMIT 10");
$st->execute(array($convId));
foreach (array_reverse($st->fetchAll()) as $m) {
    echo "id={$m['id']} | {$m['direcao']} | {$m['tipo']} | status=" . ($m['status'] ?? '-') . " | {$m['created_at']} | {$m['txt']}\n";
}

// Mais importante: o que diz o zapi_webhook_log sobre essa conv?
echo "\n=== Webhook log dessa conv (últimas chamadas) ===\n";
try {
    $st = $pdo->prepare("SELECT id, tipo_evento, estrategia_match, conversa_id, created_at, LEFT(payload_json, 400) as payload
                         FROM zapi_webhook_log WHERE conversa_id = ? OR payload_json LIKE '%147265905786930%'
                         ORDER BY id DESC LIMIT 10");
    $st->execute(array($convId));
    foreach ($st->fetchAll() as $log) {
        echo "log_id={$log['id']} | {$log['created_at']} | tipo={$log['tipo_evento']} | estrategia={$log['estrategia_match']} | conv={$log['conversa_id']}\n";
        echo "  payload: " . $log['payload'] . "\n";
    }
} catch (Exception $e) { echo "zapi_webhook_log erro: " . $e->getMessage() . "\n"; }

// Já foi rodada a migração? Verificar
echo "\n=== Era esperado ser pego pela varredura migrar_lid_bruto? ===\n";
$tel = $convs[0]['telefone'] ?? '';
$digitos = preg_replace('/\D/', '', $tel);
echo "Telefone na conv: '{$tel}'\n";
echo "Dígitos: '{$digitos}' (tamanho " . strlen($digitos) . ")\n";
echo "Tamanho > 14 (gatilho varredura)? " . (strlen($digitos) > 14 ? 'SIM' : 'NÃO') . "\n";
echo "Contém '@lid' no telefone? " . (strpos($tel, '@lid') !== false ? 'SIM' : 'NÃO') . "\n";

// Quando foi criada
echo "\n=== Quando essa conv foi criada (data da PRIMEIRA mensagem) ===\n";
$st = $pdo->prepare("SELECT MIN(created_at) FROM zapi_mensagens WHERE conversa_id = ?");
$st->execute(array($convId));
$primeira = $st->fetchColumn();
echo "Primeira msg: {$primeira}\n";
echo "Hora atual: " . date('Y-m-d H:i:s') . "\n";
