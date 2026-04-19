<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
ini_set('display_errors', '1');

echo "=== DIAG Z-API DDD 24 ===\n\n";

$inst = zapi_get_instancia('24');
$cfg = zapi_get_config();

echo "Instance ID: {$inst['instancia_id']}\n";
echo "Token configurado: " . ($inst['token'] ? 'SIM' : 'NAO') . "\n";
echo "Client-Token conta: " . ($cfg['client_token'] ? 'SIM' : 'NAO') . "\n\n";

// 1. Status
echo "--- /status ---\n";
$url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/status';
$ch = curl_init($url);
$h = array();
if ($cfg['client_token']) $h[] = 'Client-Token: ' . $cfg['client_token'];
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $h,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 10,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP {$code}\n";
echo "Resposta: {$resp}\n\n";

// 2. Webhook config atual (verificar se está lá)
echo "--- /webhooks (ver configs) ---\n";
$url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/webhooks';
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $h,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_TIMEOUT => 10,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTP {$code}\n";
echo "Resposta: {$resp}\n";
