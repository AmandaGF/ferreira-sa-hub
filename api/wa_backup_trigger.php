<?php
/**
 * ============================================================
 * api/wa_backup_trigger.php — Trigger HTTP do backup WhatsApp
 * ============================================================
 *
 * PROPÓSITO:
 *   Plano B pra quando cron CLI não está disponível na hospedagem.
 *   Endpoint HTTP protegido por key que dispara o backup de arquivos
 *   WhatsApp em background, retornando JSON imediato.
 *
 *   Chama cron/wa_backup_arquivos.php em background dentro do mesmo
 *   processo PHP (via require_once após fastcgi_finish_request).
 *
 * USO NO CRON DO cPANEL:
 *   Command (2:30 da madrugada todo dia):
 *     curl -s "https://ferreiraesa.com.br/conecta/api/wa_backup_trigger.php?key=fsa-hub-deploy-2026"
 *
 * RETORNO:
 *   JSON imediato { "ok": true, "msg": "..." }
 *   O backup continua rodando em background por 30s-3min.
 * ============================================================
 */

while (ob_get_level() > 0) @ob_end_clean();

$key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'erro' => 'Chave inválida'));
    exit;
}

$respJson = json_encode(array(
    'ok'  => true,
    'msg' => 'Backup WhatsApp disparado. Roda em background.',
    'iniciado_em' => date('Y-m-d H:i:s'),
));

ignore_user_abort(true);
@set_time_limit(0);
@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');
header('Connection: close');
header('Content-Length: ' . strlen($respJson));
echo $respJson;

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}

// ============================================================
// Em background: roda o backup e guarda log em files/wa_backup.log
// ============================================================
define('WA_BACKUP_FROM_TRIGGER', true);

ob_start();
try {
    require __DIR__ . '/../cron/wa_backup_arquivos.php';
} catch (Throwable $e) {
    echo "\n[EXCEPTION] " . $e->getMessage() . "\n";
}
$output = ob_get_clean();

$logDir = __DIR__ . '/../files';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@file_put_contents(
    $logDir . '/wa_backup.log',
    "\n=== " . date('Y-m-d H:i:s') . " (via trigger HTTP) ===\n" . $output,
    FILE_APPEND
);
