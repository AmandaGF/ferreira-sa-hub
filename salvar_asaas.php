<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

$pdo = db();
echo "=== Salvar Asaas + testar ===\n\n";

$apiKey = $_GET['asaas_key'] ?? '';
$env    = in_array($_GET['asaas_env'] ?? '', array('sandbox','production'), true) ? $_GET['asaas_env'] : 'production';

if (!$apiKey) { echo "ERRO: passe &asaas_key=... na URL\n"; exit; }

$pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (
    chave VARCHAR(80) PRIMARY KEY,
    valor TEXT,
    atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$up = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
$up->execute(array('asaas_api_key', $apiKey));
$up->execute(array('asaas_env', $env));

echo "✓ Chave salva (" . substr($apiKey, 0, 14) . "...)\n";
echo "✓ Ambiente: {$env}\n\n";

// Testar conexão
$base = $env === 'production' ? 'https://api.asaas.com/v3' : 'https://sandbox.asaas.com/api/v3';
echo "Testando {$base}/customers?limit=1 ...\n";

$ch = curl_init($base . '/customers?limit=1');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => array('access_token: ' . $apiKey, 'Content-Type: application/json'),
    CURLOPT_SSL_VERIFYPEER => true,
));
$body = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP {$code}\n";
if ($err) echo "cURL err: {$err}\n";
echo "Body: " . substr($body, 0, 300) . "\n\n";

if ($code === 200) {
    $data = json_decode($body, true);
    $total = $data['totalCount'] ?? 0;
    echo "✅ CONEXÃO OK — conta ativa, {$total} clientes no Asaas\n";

    // Tenta saldo também
    $ch2 = curl_init($base . '/finance/balance');
    curl_setopt_array($ch2, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => array('access_token: ' . $apiKey),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $b2 = curl_exec($ch2);
    $c2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);
    if ($c2 === 200) {
        $d2 = json_decode($b2, true);
        if (isset($d2['balance'])) echo "💰 Saldo atual: R$ " . number_format($d2['balance'], 2, ',', '.') . "\n";
    }
} elseif ($code === 401) {
    echo "❌ CHAVE INVÁLIDA (401)\n";
    echo "Resposta: {$body}\n";
} else {
    echo "⚠ HTTP {$code}\n";
}
