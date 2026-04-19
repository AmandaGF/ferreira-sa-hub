<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';
ini_set('display_errors', '1');

echo "=== Ativar 'notify sent by me' nos 2 DDDs ===\n\n";

foreach (array('21', '24') as $ddd) {
    echo "━━━ DDD {$ddd} ━━━\n";
    $inst = zapi_get_instancia($ddd);
    $cfg = zapi_get_config();
    $base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
    $webhookUrl = "https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero={$ddd}";

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    // Tentar vários endpoints conhecidos do Z-API
    $tentativas = array(
        // 1. Endpoint específico pra notify sent by me
        array('url' => $base . '/update-notify-sent-by-me', 'body' => array('value' => true)),
        // 2. Webhook separado pra mensagens enviadas
        array('url' => $base . '/update-webhook-message-send', 'body' => array('value' => $webhookUrl)),
        // 3. Send webhook
        array('url' => $base . '/update-send-webhook', 'body' => array('value' => $webhookUrl)),
        // 4. Webhook presence (nem sempre é isso mas testa)
        array('url' => $base . '/update-webhook-send', 'body' => array('value' => $webhookUrl)),
        // 5. Webhook received com flag notifySentByMe no payload
        array('url' => $base . '/update-webhook-received', 'body' => array('value' => $webhookUrl, 'notifySentByMe' => true)),
    );

    foreach ($tentativas as $t) {
        $ch = curl_init($t['url']);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($t['body']),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 10,
        ));
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $endpoint = preg_replace('/^.+\//', '', $t['url']);
        $marker = $code === 200 ? '✅' : '❌';
        echo "  {$marker} {$endpoint} → HTTP {$code} — " . substr($r, 0, 150) . "\n";
    }
    echo "\n";
}

echo "=== FIM ===\n";
echo "Se algum endpoint retornou 200 com value:true, o notify-sent-by-me foi ativado.\n";
echo "Teste mandando mensagem do celular e depois rode: /conecta/ver_fromme.php?key=fsa-hub-deploy-2026\n";
