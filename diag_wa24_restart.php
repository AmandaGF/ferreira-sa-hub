<?php
/**
 * Reinicia a sessão Z-API canal 24 sem precisar abrir o painel da Z-API.
 * Endpoint: /restart-session (preserva login — não precisa escanear QR de novo).
 *
 * Uso:
 *   curl https://ferreiraesa.com.br/conecta/diag_wa24_restart.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Forbidden.');
}

header('Content-Type: text/plain; charset=utf-8');

$inst = zapi_get_instancia('24');
if (!$inst) { exit("Instância 24 não configurada.\n"); }

$cfg = zapi_get_config();
$base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
$headers = array();
if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

echo "=== Restart Z-API canal 24 ===\n\n";

// 1. Status atual
echo "1. Status ANTES do restart:\n";
$ch = curl_init($base . '/status');
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>false));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "   HTTP $code · resp: " . substr($resp, 0, 300) . "\n\n";

// 2. Tenta endpoint /restart-session (preserva login)
echo "2. Chamando /restart-session (preserva login)...\n";
$ch = curl_init($base . '/restart-session');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_SSL_VERIFYPEER => false,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);
echo "   HTTP $code · resp: " . substr($resp, 0, 500) . ($err ? " · cURL: $err" : '') . "\n\n";

// 3. Aguarda 8s e verifica status de novo
echo "3. Aguardando 8s pra reconectar...\n";
sleep(8);

$ch = curl_init($base . '/status');
curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>false));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "   HTTP $code · resp: " . substr($resp, 0, 300) . "\n\n";

echo "=== Fim ===\n";
echo "Se o status agora mostra 'connected:true' SEM erro, a sessão foi reiniciada\n";
echo "com sucesso e os próximos envios devem funcionar normalmente.\n";
echo "\n";
echo "Se ainda mostra 'You are already connected.' ou status anormal:\n";
echo "  → Ir manualmente em https://app.z-api.io\n";
echo "  → Selecionar instância DDD 24\n";
echo "  → Clicar em 'Desconectar' depois 'Reconectar' (escanear QR de novo)\n";
