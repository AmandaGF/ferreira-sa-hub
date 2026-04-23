<?php
/**
 * configurar_nvoip_oneshot.php — salva credenciais + testa geração de token.
 * Apagar após uso.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_nvoip.php';

echo "=== CONFIGURAR Nvoip (one-shot) ===\n\n";

$napi      = 'a1dOS1VmRzhkTWdpamlYWU04TmZZMG1jbVJjQ2tpVk8=';
$numbersip = '552120385599';
$userToken = '88626d2f-3f35-11f1-bb15-0235a037e8f3';

echo "1) Salvando credenciais em configuracoes...\n";
nvoip_cfg_set('nvoip_napikey',    $napi);
nvoip_cfg_set('nvoip_numbersip',  $numbersip);
nvoip_cfg_set('nvoip_user_token', $userToken);
// Zera tokens antigos pra forçar geração nova
nvoip_cfg_set('nvoip_access_token',  '');
nvoip_cfg_set('nvoip_refresh_token', '');
nvoip_cfg_set('nvoip_token_expiry',  '');
echo "   ✓ napikey, numbersip, user_token salvos\n";
echo "   ✓ tokens OAuth antigos resetados\n\n";

echo "2) Gerando token OAuth pela primeira vez...\n";
// Limpa cache static do helper (gambiarra — o static ficou com valor vazio da leitura inicial)
// Vamos forçar via re-include/nova leitura
unset($GLOBALS['_nvoip_cfg_reset']);
// Chamada direta ignorando o cache
$user = $numbersip;
$pass = $userToken;
$ch = curl_init('https://api.nvoip.com.br/v2/oauth/token');
curl_setopt_array($ch, array(
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POSTFIELDS     => http_build_query(array(
        'username'   => $user,
        'password'   => $pass,
        'grant_type' => 'password',
    )),
    CURLOPT_HTTPHEADER => array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic TnZvaXBBcGlWMjpUblp2YVhCQmNHbFdNakl3TWpFPQ==',
    ),
    CURLOPT_SSL_VERIFYPEER => false,
));
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "   HTTP {$code}\n";
echo "   Resposta: " . substr($raw, 0, 400) . "\n\n";

$resp = json_decode($raw, true);
if (is_array($resp) && !empty($resp['access_token'])) {
    nvoip_cfg_set('nvoip_access_token',  $resp['access_token']);
    nvoip_cfg_set('nvoip_refresh_token', $resp['refresh_token'] ?? '');
    nvoip_cfg_set('nvoip_token_expiry',  date('Y-m-d H:i:s', time() + 82800));
    echo "   ✓ Token salvo. Primeiros 15 chars: " . substr($resp['access_token'], 0, 15) . "...\n";
    echo "   ✓ Expiry: " . date('Y-m-d H:i:s', time() + 82800) . "\n\n";
} else {
    echo "   ❌ Falha ao gerar token — confira napikey/numbersip/user_token\n";
    exit;
}

echo "3) Consultando saldo Nvoip...\n";
$saldo = nvoip_consultar_saldo();
echo "   " . json_encode($saldo, JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== PRONTO! ===\n";
echo "Nvoip configurada. Acesse Admin → 📞 Nvoip (VoIP) no Hub pra gerenciar ramais.\n";
echo "APAGAR ESTE ARQUIVO APÓS USO (credenciais hardcoded).\n";
