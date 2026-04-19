<?php
/**
 * Cron diário: garante que os webhooks Z-API dos 2 números (21 e 24) estão
 * configurados. Se algum caiu, reconfigura automaticamente.
 *
 * Por que existe: Z-API às vezes "perde" a configuração do webhook sozinho
 * (já aconteceu em 19/04/26 e em 18/04/26 com o DDD 24). Rodar 1x por dia
 * detecta e restaura silenciosamente.
 *
 * Agendar no cPanel: todo dia às 06:00
 *   curl -s "https://ferreiraesa.com.br/conecta/cron/zapi_health_check.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026' && PHP_SAPI !== 'cli') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_zapi.php';

$logFile = APP_ROOT . '/files/zapi_webhook.log';
$log = function($msg) use ($logFile) {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] [HEALTH] ' . $msg . "\n", FILE_APPEND);
    echo $msg . "\n";
};

$log("=== Z-API webhook health check ===");

foreach (array('21', '24') as $ddd) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) { $log("[{$ddd}] instância não configurada, pulando"); continue; }
    $cfg = zapi_get_config();
    $base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];
    $webhookUrl = "https://ferreiraesa.com.br/conecta/api/zapi_webhook.php?numero={$ddd}";

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $webhooks = array(
        'update-webhook-received'     => 'receber',
        'update-webhook-delivery'     => 'status',
        'update-webhook-connected'    => 'conectar',
        'update-webhook-disconnected' => 'desconectar',
    );

    $falhas = 0;
    foreach ($webhooks as $endpoint => $nome) {
        $ch = curl_init($base . '/' . $endpoint);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => json_encode(array('value' => $webhookUrl)),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 10,
        ));
        $r = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) { $falhas++; $log("[{$ddd}] {$nome}: HTTP {$code} — " . substr($r, 0, 80)); }
    }
    if ($falhas === 0) $log("[{$ddd}] todos os 4 webhooks re-afirmados OK");
}

$log("=== FIM ===\n");
