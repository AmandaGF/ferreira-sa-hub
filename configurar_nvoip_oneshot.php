<?php
/**
 * configurar_nvoip_oneshot.php — corrige numbersip + testa OAuth + saldo. Apagar após uso.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_nvoip.php';

echo "=== CONFIGURAR Nvoip v2 (com User SIP correto) ===\n\n";

$napi      = 'a1dOS1VmRzhkTWdpamlYWU04TmZZMG1jbVJjQ2tpVk8=';
$numbersip = '140912001';
$userToken = '88626d2f-3f35-11f1-bb15-0235a037e8f3';

echo "Salvando credenciais:\n";
echo "  napikey     = " . substr($napi, 0, 10) . "...\n";
echo "  numbersip   = {$numbersip}\n";
echo "  user_token  = " . substr($userToken, 0, 10) . "...\n\n";

nvoip_cfg_set('nvoip_napikey',       $napi);
nvoip_cfg_set('nvoip_numbersip',     $numbersip);
nvoip_cfg_set('nvoip_user_token',    $userToken);
nvoip_cfg_set('nvoip_access_token',  '');
nvoip_cfg_set('nvoip_refresh_token', '');
nvoip_cfg_set('nvoip_token_expiry',  '');

echo "Testando OAuth...\n";
$ch = curl_init('https://api.nvoip.com.br/v2/oauth/token');
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query(array(
        'username'   => $numbersip,
        'password'   => $userToken,
        'grant_type' => 'password',
    )),
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic TnZvaXBBcGlWMjpUblp2YVhCQmNHbFdNakl3TWpFPQ==',
    ),
    CURLOPT_SSL_VERIFYPEER => false,
));
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "  HTTP {$code}\n";
echo "  Resposta: " . substr($raw, 0, 500) . "\n\n";

$resp = json_decode($raw, true);
if (!is_array($resp) || empty($resp['access_token'])) {
    echo "❌ Ainda não funcionou. Me envie o HTTP/resposta acima.\n";
    exit;
}

nvoip_cfg_set('nvoip_access_token',  $resp['access_token']);
nvoip_cfg_set('nvoip_refresh_token', $resp['refresh_token'] ?? '');
nvoip_cfg_set('nvoip_token_expiry',  date('Y-m-d H:i:s', time() + 82800));
echo "✓ access_token: " . substr($resp['access_token'], 0, 20) . "...\n";
echo "✓ expires_in: " . ($resp['expires_in'] ?? '?') . "s\n";
echo "✓ expiry salvo: " . date('Y-m-d H:i:s', time() + 82800) . "\n\n";

echo "Consultando saldo...\n";
$ch2 = curl_init('https://api.nvoip.com.br/v2/balance');
curl_setopt_array($ch2, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $resp['access_token']),
    CURLOPT_SSL_VERIFYPEER => false,
));
$rawS = curl_exec($ch2);
$codeS = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);
echo "  HTTP {$codeS}\n";
echo "  " . trim($rawS) . "\n\n";

echo "=== PRONTO! Nvoip CONFIGURADA ===\n";
echo "APAGAR este arquivo e os tokens expostos no chat.\n";
