<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
ini_set('display_errors', '1');

$ddd = $_GET['ddd'] ?? '21';
$telTest = $_GET['tel'] ?? '';

$inst = zapi_get_instancia($ddd);
$cfg = zapi_get_config();
$base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$headers = array(); if ($cfg['client_token']) $headers[] = 'Client-Token: '.$cfg['client_token'];

function get($url, $h) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$h,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>30));
    $r = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return array('code' => $c, 'body' => $r, 'json' => json_decode($r, true));
}

echo "=== Descobrindo capacidade do histórico Z-API DDD {$ddd} ===\n\n";

// Se não passou telefone, pega o primeiro chat disponível
if (!$telTest) {
    $chats = get($base . '/chats?page=1&pageSize=5', $headers);
    if (!empty($chats['json'][0]['phone'])) {
        $telTest = $chats['json'][0]['phone'];
        echo "Usando telefone do primeiro chat: {$telTest}\n\n";
    } else {
        die("Sem chats disponíveis, passe &tel=XXX\n");
    }
}

// Teste 1: diferentes tamanhos
echo "═══ Teste 1: /chat-messages/{phone} com vários parâmetros ═══\n";
$tentativas = array(
    array('q' => 'size=10'),
    array('q' => 'size=50'),
    array('q' => 'size=100'),
    array('q' => 'size=500'),
    array('q' => 'size=1000'),
    array('q' => 'amount=100'),
    array('q' => 'amount=500'),
    array('q' => 'amount=1000'),
    array('q' => 'limit=100'),
    array('q' => 'page=1&size=50'),
    array('q' => 'page=2&size=50'),
    array('q' => 'offset=50&size=50'),
    array('q' => 'beforeDate=2024-01-01&size=50'),
);

foreach ($tentativas as $t) {
    $url = $base . '/chat-messages/' . $telTest . '?' . $t['q'];
    $r = get($url, $headers);
    $n = is_array($r['json']) ? count($r['json']) : 0;
    echo sprintf("  %-35s HTTP %d, retornou %d msgs\n", $t['q'], $r['code'], $n);
}

// Teste 2: endpoints alternativos
echo "\n═══ Teste 2: endpoints alternativos ═══\n";
$alternativos = array(
    '/messages', '/metadata/messages', '/messages?page=1&size=100',
    '/chat-messages/' . $telTest . '/load-older',
    '/chat-messages/' . $telTest . '/load-more',
    '/chat-history/' . $telTest,
    '/history/' . $telTest,
);
foreach ($alternativos as $ep) {
    $r = get($base . $ep, $headers);
    $preview = substr($r['body'], 0, 80);
    echo sprintf("  %-45s HTTP %d — %s\n", $ep, $r['code'], $preview);
}

// Teste 3: ver a primeira e última mensagem pra saber o range temporal
echo "\n═══ Teste 3: analisar resposta máxima ═══\n";
$max = get($base . '/chat-messages/' . $telTest . '?size=1000', $headers);
if (is_array($max['json'])) {
    $msgs = $max['json'];
    echo "Total retornado: " . count($msgs) . " mensagens\n";
    if (count($msgs) > 0) {
        $primeira = $msgs[0];
        $ultima = end($msgs);
        $tsP = $primeira['momment'] ?? $primeira['moment'] ?? 0;
        $tsU = $ultima['momment'] ?? $ultima['moment'] ?? 0;
        echo "Mensagem 1:  " . ($tsP > 0 ? date('Y-m-d H:i:s', $tsP/1000) : '?') . " → texto: " . mb_substr(($primeira['text']['message'] ?? ''), 0, 60) . "\n";
        echo "Mensagem N:  " . ($tsU > 0 ? date('Y-m-d H:i:s', $tsU/1000) : '?') . " → texto: " . mb_substr(($ultima['text']['message'] ?? ''), 0, 60) . "\n";
    }
}
