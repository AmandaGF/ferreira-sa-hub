<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG Luiz pessoal canal 24 ===\n\n";

// Todas as conversas canal 24 com telefone Luiz OU nome "Luiz"
echo "--- Conversas canal 24 com 'luiz' ou tel ---\n";
$r = $pdo->query("SELECT id, telefone, chat_lid, nome_contato, atendente_id, status, ultima_msg_em,
    (SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = zapi_conversas.id) AS msgs
    FROM zapi_conversas
    WHERE canal = '24' AND (
        telefone LIKE '%999816600%' OR telefone LIKE '%4999816600%' OR telefone LIKE '%83223715561634%'
        OR LOWER(nome_contato) LIKE '%luiz%' OR chat_lid LIKE '%83223715561634%'
    )
    ORDER BY ultima_msg_em DESC LIMIT 20")->fetchAll();
foreach ($r as $c) {
    echo sprintf("  #%d tel=%s chat_lid=%s nome='%s' atend=%s [%s] msgs=%d ult=%s\n",
        $c['id'], $c['telefone'], $c['chat_lid'] ?: '-', $c['nome_contato'] ?: '?',
        $c['atendente_id'] ?: '-', $c['status'], $c['msgs'], $c['ultima_msg_em']);
}

// Log webhook canal 24 nas últimas 3h — busca por qualquer entrada do Luiz (telefone ou @lid)
echo "\n--- Log webhook canal 24 (Luiz — últimos 60 min) ---\n";
$log = __DIR__ . '/files/zapi_webhook.log';
if (file_exists($log)) {
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    $tail = array_slice($lines, -3000);
    $corte = strtotime('-60 minutes');
    $filtradas = array();
    foreach ($tail as $l) {
        if (strpos($l, '[24]') === false) continue;
        // Extrai timestamp
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $l, $m)) {
            $ts = strtotime($m[1]);
            if ($ts < $corte) continue;
        }
        if (stripos($l, 'luiz') !== false || strpos($l, '5524999816600') !== false || strpos($l, '83223715561634') !== false) {
            $filtradas[] = $l;
        }
    }
    echo "  " . count($filtradas) . " linhas relevantes\n";
    foreach (array_slice($filtradas, -20) as $l) echo "  " . mb_substr($l, 0, 280, 'UTF-8') . "\n";
}

// Últimas 5 msgs recebidas no canal 24 (geral)
echo "\n--- Últimas 10 msgs RECEBIDAS no canal 24 (qualquer contato) ---\n";
$r = $pdo->query("SELECT m.id, m.created_at, m.zapi_message_id, co.nome_contato, co.telefone, co.id AS conv_id, LEFT(m.conteudo, 50) AS p
    FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE co.canal = '24' AND m.direcao = 'recebida'
    ORDER BY m.id DESC LIMIT 10")->fetchAll();
foreach ($r as $m) {
    $min = (int)((time() - strtotime($m['created_at'])) / 60);
    echo sprintf("  #%d %s (há %dmin) conv#%d %s (%s) — %s\n", $m['id'], $m['created_at'], $min, $m['conv_id'], $m['nome_contato'] ?: '?', $m['telefone'], trim($m['p']));
}
