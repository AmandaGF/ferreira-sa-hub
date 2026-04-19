<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');

// Pega a chave (preferência: banco, fallback: constante no config)
$apiKey = '';
try {
    $apiKey = db()->query("SELECT valor FROM configuracoes WHERE chave = 'anthropic_api_key'")->fetchColumn() ?: '';
} catch (Exception $e) {}
if (!$apiKey && defined('ANTHROPIC_API_KEY')) $apiKey = ANTHROPIC_API_KEY;

if (!$apiKey) { die("Chave Anthropic não configurada\n"); }

echo "=== Testar Anthropic API ===\n\n";
echo "Chave (primeiros 15 chars): " . substr($apiKey, 0, 15) . "...\n";
echo "Chave (últimos 6 chars):    ..." . substr($apiKey, -6) . "\n\n";

$body = array(
    'model' => 'claude-haiku-4-5-20251001',
    'max_tokens' => 50,
    'messages' => array(array('role' => 'user', 'content' => 'Diga apenas: OK, funcionou!')),
);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => array(
        'Content-Type: application/json',
        'anthropic-version: 2023-06-01',
        'x-api-key: ' . $apiKey,
    ),
    CURLOPT_POSTFIELDS     => json_encode($body),
    CURLOPT_SSL_VERIFYPEER => true,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP {$code}\n";
if ($err) echo "cURL erro: {$err}\n";
echo "Resposta:\n{$resp}\n\n";

if ($code === 200) {
    $data = json_decode($resp, true);
    echo "✅ CHAVE FUNCIONANDO. Claude respondeu: " . ($data['content'][0]['text'] ?? '?') . "\n";
} elseif ($code === 400 || $code === 402) {
    if (strpos($resp, 'credit') !== false || strpos($resp, 'balance') !== false) {
        echo "⚠️ CRÉDITO BAIXO. Verifique se o pagamento já foi processado.\n";
        echo "   Pode demorar 10-30 min pra Anthropic liberar após pagamento.\n";
    }
} elseif ($code === 401) {
    echo "❌ CHAVE INVÁLIDA.\n";
} else {
    echo "⚠️ HTTP {$code} inesperado.\n";
}
