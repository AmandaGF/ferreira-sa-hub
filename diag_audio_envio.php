<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "==== Últimos 20 envios de áudio (mais recentes primeiro) ====\n\n";
try {
    $rows = $pdo->query("SELECT * FROM zapi_envio_audio_log ORDER BY id DESC LIMIT 20")->fetchAll();
    if (empty($rows)) { echo "(nenhum envio registrado ainda — Amanda precisa mandar 1 áudio pra começarmos a coletar)\n"; }
    foreach ($rows as $r) {
        echo str_pad($r['criado_em'], 21) . " | DDD={$r['ddd']} | tel=" . str_pad($r['telefone'] ?: '?', 14) . " | ";
        echo "input=" . str_pad($r['input_type'] ?: '?', 18) . " | ";
        echo "ext=" . str_pad($r['extensao'] ?: '?', 6) . " | ";
        echo "mime=" . str_pad($r['mime_detectado'] ?: '?', 22) . " | ";
        echo "modo=" . str_pad($r['modo'] ?: '?', 14) . " | ";
        echo "bytes=" . str_pad((string)$r['tamanho_bytes'], 8) . " | ";
        echo "http=" . str_pad((string)$r['zapi_http'], 4) . " | ";
        echo "msg_id=" . str_pad($r['zapi_msg_id'] ?: '-', 26) . "\n";
        if ($r['zapi_data_resumo'] && strlen($r['zapi_data_resumo']) > 5) {
            echo "    └─ resp: " . substr($r['zapi_data_resumo'], 0, 200) . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "(tabela ainda não existe — vai ser criada no próximo envio de áudio)\n";
}

echo "\n==== Últimos 10 áudios em zapi_mensagens (saídos do canal 24) ====\n\n";
try {
    $rows = $pdo->query("SELECT m.id, m.created_at, m.zapi_message_id, m.tipo, m.conteudo, c.telefone, c.canal, c.nome_contato
                         FROM zapi_mensagens m
                         LEFT JOIN zapi_conversas c ON c.id = m.conversa_id
                         WHERE m.tipo='audio' AND m.direcao='enviada'
                         ORDER BY m.id DESC LIMIT 10")->fetchAll();
    foreach ($rows as $r) {
        echo "  msg_id={$r['id']} | {$r['created_at']} | canal={$r['canal']} | tel={$r['telefone']} | nome=" . substr($r['nome_contato'] ?? '', 0, 25) . " | zapi={$r['zapi_message_id']}\n";
        echo "      conteudo: " . substr($r['conteudo'] ?? '', 0, 100) . "\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
