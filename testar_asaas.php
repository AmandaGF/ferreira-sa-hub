<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

$pdo = db();
$stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('asaas_api_key','asaas_env')");
$cfg = array();
foreach ($stmt->fetchAll() as $r) $cfg[$r['chave']] = $r['valor'];

$apiKey = $cfg['asaas_api_key'] ?? '';
$env    = $cfg['asaas_env'] ?? 'sandbox';
$bases = $env === 'production'
    ? array('https://api.asaas.com/api/v3', 'https://api.asaas.com/v3', 'https://www.asaas.com/api/v3')
    : array('https://sandbox.asaas.com/api/v3', 'https://api-sandbox.asaas.com/v3');

echo "=== Testar endpoints Asaas ({$env}) ===\n";
echo "Chave: " . substr($apiKey, 0, 14) . "...\n\n";

function testar($url, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('code' => $code, 'body' => $body);
}

$headers = array('access_token: ' . $apiKey, 'Content-Type: application/json', 'User-Agent: FerreiraSaHub/1.0');

$endpoints = array('/customers?limit=1', '/finance/balance', '/myAccount/balance', '/payments?limit=1');

foreach ($bases as $base) {
    echo "######## BASE: {$base} ########\n";
    foreach ($endpoints as $ep) {
        $r = testar($base . $ep, $headers);
        echo "--- GET {$ep} ---\n";
        echo "HTTP {$r['code']}\n";
        echo "Body: " . substr($r['body'], 0, 200) . "\n";
        if ($r['code'] === 200) echo "✅ FUNCIONOU!\n";
        echo "\n";
    }
    echo "\n";
}
