<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
ini_set('display_errors', '1');

$ddd = $_GET['ddd'] ?? '24';
echo "=== Reconfigurar webhooks DDD {$ddd} via Z-API ===\n\n";

$inst = zapi_get_instancia($ddd);
$cfg = zapi_get_config();
$base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$webhookUrl = "https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero={$ddd}";

$headers = array('Content-Type: application/json');
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

function zapi_put($url, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'resp' => $resp);
}

// Z-API tem endpoints PUT específicos pra cada webhook
$webhooks = array(
    'update-webhook-received'             => 'Ao receber mensagem',
    'update-webhook-delivery'             => 'Status da mensagem',
    'update-webhook-connected'            => 'Ao conectar',
    'update-webhook-disconnected'         => 'Ao desconectar',
);

foreach ($webhooks as $endpoint => $nome) {
    echo "--- {$nome} ({$endpoint}) ---\n";
    $r = zapi_put($base . '/' . $endpoint, $headers, array('value' => $webhookUrl));
    echo "HTTP {$r['code']}: " . substr($r['resp'], 0, 150) . "\n\n";
}

echo "=== FIM ===\n";
echo "\nDepois, manda uma mensagem pro número {$ddd} e checa o log em /ver_webhook_log.php\n";
