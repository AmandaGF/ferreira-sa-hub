<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_nvoip.php';

echo "=== DIAG chamada Nvoip — " . date('Y-m-d H:i:s') . " ===\n\n";

$token = nvoip_get_token();
echo "access_token: " . ($token ? 'OK (' . substr($token, 0, 15) . '...)' : 'FALHOU') . "\n\n";

if (!$token) exit;

$caller = '140912001';
$called = '24999816600'; // numero do Luiz (mesmo teste da Amanda)

echo "POST https://api.nvoip.com.br/v2/calls/\n";
echo "Body: " . json_encode(array('caller' => $caller, 'called' => $called)) . "\n\n";

$ch = curl_init('https://api.nvoip.com.br/v2/calls/');
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 40,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_POSTFIELDS     => json_encode(array('caller' => $caller, 'called' => $called)),
    CURLOPT_HTTPHEADER     => array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
    ),
    CURLOPT_SSL_VERIFYPEER => false,
));
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

echo "HTTP {$code}\n";
echo "Curl err: " . ($err ?: '(nenhum)') . "\n";
echo "Resposta bruta:\n{$raw}\n\n";

echo "=== FIM ===\n";
