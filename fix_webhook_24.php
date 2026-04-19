<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
ini_set('display_errors', '1');

$ddd = $_GET['ddd'] ?? '24';
echo "=== CHECAR + CONSERTAR WEBHOOK DDD {$ddd} ===\n\n";

// 1) Ver últimas 15 linhas do log pra saber se está recebendo mensagens
$logFile = APP_ROOT . '/files/zapi_webhook.log';
if (is_readable($logFile)) {
    $lines = file($logFile);
    $tail = array_slice($lines, -30);
    $doDDD = array();
    foreach ($tail as $l) if (strpos($l, "[{$ddd}]") !== false) $doDDD[] = trim($l);
    echo "Últimas " . count($doDDD) . " entradas do log com [{$ddd}]:\n";
    foreach (array_slice($doDDD, -10) as $l) echo "  {$l}\n";
    echo "\n";
} else {
    echo "(log inacessível em {$logFile})\n\n";
}

// 2) Reconfigurar os 4 webhooks no Z-API
$inst = zapi_get_instancia($ddd);
$cfg = zapi_get_config();
$base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$webhookUrl = "https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero={$ddd}";

$headers = array('Content-Type: application/json');
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

$webhooks = array(
    'update-webhook-received'     => 'Ao receber mensagem',
    'update-webhook-delivery'     => 'Status da mensagem',
    'update-webhook-connected'    => 'Ao conectar',
    'update-webhook-disconnected' => 'Ao desconectar',
);

echo "Reconfigurando webhooks via PUT:\n";
foreach ($webhooks as $endpoint => $nome) {
    $ch = curl_init($base . '/' . $endpoint);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode(array('value' => $webhookUrl)),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
    ));
    $r = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "  {$nome}: HTTP {$code} — " . substr($r, 0, 120) . "\n";
}

echo "\n=== FIM ===\n";
echo "Manda uma mensagem pro número {$ddd} e recarrega a tela do WhatsApp no Hub.\n";
