<?php
/**
 * ver_peticoes_log.php — diagnóstico TEMPORÁRIO da Fábrica de Petições.
 * Lê o último response salvo em uploads/peticao_last_response.json e
 * tenta o ping na API Anthropic pra confirmar conectividade.
 *
 * Acesso: ?key=fsa-hub-deploy-2026
 *
 * APAGAR DEPOIS.
 */

if (!isset($_GET['key']) || $_GET['key'] !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Acesso negado.');
}
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';

echo "=== Diagnóstico Fábrica de Petições ===\n\n";

// 1. Chave da API
echo "1. ANTHROPIC_API_KEY:\n";
if (!defined('ANTHROPIC_API_KEY')) {
    echo "   NÃO DEFINIDA em config.php\n";
} else {
    $k = ANTHROPIC_API_KEY;
    $masked = strlen($k) > 12 ? substr($k, 0, 8) . str_repeat('*', strlen($k) - 12) . substr($k, -4) : '(curta demais)';
    echo "   Definida (mascarada): $masked\n";
    echo "   Comprimento: " . strlen($k) . " chars\n";
}
echo "\n";

// 2. Último response salvo
$logFile = __DIR__ . '/uploads/peticao_last_response.json';
echo "2. Último response salvo: $logFile\n";
if (file_exists($logFile)) {
    echo "   Existe (modificado em: " . date('Y-m-d H:i:s', filemtime($logFile)) . ")\n";
    $content = file_get_contents($logFile);
    if (strlen($content) > 4000) {
        echo "   Conteúdo (primeiros 4000 chars):\n" . substr($content, 0, 4000) . "\n... [truncado]\n";
    } else {
        echo "   Conteúdo:\n$content\n";
    }
} else {
    echo "   NÃO existe — significa que o último curl_exec falhou ANTES de chegar a salvar (timeout ou erro de rede).\n";
}
echo "\n";

// 3. Teste de conectividade direto (sem prompt — só ping na API)
echo "3. Teste rápido de conectividade api.anthropic.com:\n";
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(array(
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 10,
        'messages'   => array(array('role' => 'user', 'content' => 'ping')),
    )),
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/json',
        'x-api-key: ' . (defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ''),
        'anthropic-version: 2023-06-01',
    ),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_SSL_VERIFYPEER => false,
));
$t0   = microtime(true);
$resp = curl_exec($ch);
$t1   = microtime(true);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
$errno = curl_errno($ch);
curl_close($ch);

echo "   Tempo: " . number_format(($t1 - $t0) * 1000, 0) . "ms\n";
echo "   HTTP code: $code\n";
echo "   curl_errno: $errno\n";
echo "   curl_error: " . ($err !== '' ? $err : '(nenhum)') . "\n";
if ($resp) {
    if (strlen($resp) > 600) $resp = substr($resp, 0, 600) . '... [truncado]';
    echo "   Response: $resp\n";
} else {
    echo "   Response: (vazio)\n";
}

// 4. Server info
echo "\n4. PHP / Server:\n";
echo "   PHP version: " . PHP_VERSION . "\n";
echo "   cURL version: " . curl_version()['version'] . "\n";
echo "   SSL version: " . curl_version()['ssl_version'] . "\n";
echo "   max_execution_time: " . ini_get('max_execution_time') . "s\n";
echo "   memory_limit: " . ini_get('memory_limit') . "\n";

echo "\n=== Fim ===\n";
