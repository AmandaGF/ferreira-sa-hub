<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

$pdo = db();

echo "=== Diagnóstico WhatsApp DDD 24 ===\n\n";

// 1. Últimas 15 mensagens do DDD 24
echo "--- Últimas 15 mensagens do DDD 24 (banco) ---\n";
$rows = $pdo->query("
    SELECT m.id, m.direcao, m.tipo, m.created_at, m.status,
           LEFT(m.conteudo, 60) AS preview,
           co.telefone, co.nome_contato
    FROM zapi_mensagens m
    JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE co.canal = '24'
    ORDER BY m.id DESC LIMIT 15
")->fetchAll();
foreach ($rows as $r) {
    echo sprintf("  #%s %-8s %-12s %s  tel=%s  %s\n",
        $r['id'], $r['direcao'], $r['tipo'], $r['created_at'],
        $r['telefone'], substr($r['preview'] ?? '', 0, 60));
}

// 2. Últimas 20 linhas BRUTAS do log (tudo, não só status)
echo "\n--- Últimas 20 linhas do log webhook (DDD 24) ---\n";
$logFile = APP_ROOT . '/files/zapi_webhook.log';
if (is_readable($logFile)) {
    $lines = file($logFile);
    $todDDD24 = array();
    foreach ($lines as $l) if (strpos($l, '[24]') !== false) $todDDD24[] = trim($l);
    foreach (array_slice($todDDD24, -20) as $l) {
        // Só o tipo de evento pra resumir
        if (preg_match('/"type":"([^"]+)"/', $l, $m)) echo "  " . substr($l, 0, 22) . " type=" . $m[1] . "\n";
        else echo "  " . substr($l, 0, 150) . "...\n";
    }
}

// 3. Breakdown de tipos recebidos
echo "\n--- Tipos de eventos nas últimas 48h (DDD 24) ---\n";
$logTail = array_slice(file($logFile), -500);
$tipos = array();
foreach ($logTail as $l) {
    if (strpos($l, '[24]') === false) continue;
    if (preg_match('/"type":"([^"]+)"/', $l, $m)) {
        $tipos[$m[1]] = ($tipos[$m[1]] ?? 0) + 1;
    }
}
foreach ($tipos as $t => $n) echo "  {$t}: {$n}\n";

// 4. Status da instância direto no Z-API
echo "\n--- Status atual Z-API DDD 24 ---\n";
$inst = zapi_get_instancia('24');
$cfg = zapi_get_config();
$url = rtrim($cfg['base_url'],'/').'/'.$inst['instancia_id'].'/token/'.$inst['token'].'/status';
$ch = curl_init($url);
$h = array(); if ($cfg['client_token']) $h[] = 'Client-Token: '.$cfg['client_token'];
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$h,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>10));
$r = curl_exec($ch); curl_close($ch);
echo "  {$r}\n";

// 5. Ler configuração atual de webhook received
echo "\n--- URL do webhook 'received' no Z-API agora ---\n";
$endpoints = array('webhook-received', 'webhook/received');
foreach ($endpoints as $ep) {
    $url = rtrim($cfg['base_url'],'/').'/'.$inst['instancia_id'].'/token/'.$inst['token'].'/'.$ep;
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$h,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>10));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    echo "  GET /{$ep} → HTTP {$code}: " . substr($r, 0, 200) . "\n";
}
