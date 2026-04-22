<?php
/**
 * ============================================================
 * api/claudin_trigger.php — Trigger HTTP do monitor DJEN
 * ============================================================
 *
 * PROPÓSITO:
 *   Plano B pra quando cron CLI não está disponível na hospedagem
 *   (TurboCloud). Endpoint HTTP protegido por key que dispara o
 *   claudin_executar() em background, retornando JSON imediato.
 *
 *   Mesma lógica do cron/djen_monitor.php, mas chamável via curl
 *   — exatamente o padrão que cron/zapi_aniversarios.php usa.
 *
 * USO NO CRON DO cPANEL:
 *   Command:
 *     curl -s "https://ferreiraesa.com.br/conecta/api/claudin_trigger.php?key=fsa-hub-deploy-2026&horario=auto"
 *
 *   Agendado:
 *     Minute: 2
 *     Hour: 8,19
 *     Day/Month: *
 *     Weekday: 1-5 (seg a sex)
 *
 * PARÂMETROS:
 *   key=fsa-hub-deploy-2026   (obrigatório — mesma key do deploy)
 *   horario=08|19|manual|auto (default: auto)
 *   data=YYYY-MM-DD           (obrigatório se horario=manual)
 *
 * RETORNO:
 *   JSON imediato { "ok": true, "msg": "...", "data_alvo": "..." }
 *   O robô continua rodando em background por 15s-3min.
 *
 * ============================================================
 */

while (ob_get_level() > 0) @ob_end_clean();

require_once __DIR__ . '/../core/database.php';

// ============================================================
// Autenticação
// ============================================================
$key = isset($_REQUEST['key']) ? $_REQUEST['key'] : '';
if ($key !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'erro' => 'Chave inválida'));
    exit;
}

// ============================================================
// Parse parâmetros
// ============================================================
$horario = isset($_REQUEST['horario']) ? $_REQUEST['horario'] : 'auto';
$dataParam = isset($_REQUEST['data']) ? $_REQUEST['data'] : null;

if (!in_array($horario, array('08', '19', 'manual', 'auto'), true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok' => false, 'erro' => 'horario inválido'));
    exit;
}

// Carrega config pra ter TIMEZONE e APP_ROOT
define('CLAUDIN_INCLUDED', true);
require_once __DIR__ . '/../cron/claudin_config.php';
date_default_timezone_set(TIMEZONE);

// Resolve horario=auto com base na hora local
if ($horario === 'auto') {
    $horario = ((int)date('H') < 12) ? '08' : '19';
}

// Calcula data-alvo
if ($horario === 'manual') {
    if (!$dataParam || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataParam)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array('ok' => false, 'erro' => 'horario=manual exige data=YYYY-MM-DD'));
        exit;
    }
    $dataAlvo = $dataParam;
} elseif ($horario === '08') {
    // Calcula dia útil anterior direto aqui (sem depender do djen_monitor ainda)
    $d = new DateTime(date('Y-m-d'));
    do {
        $d->modify('-1 day');
    } while ((int)$d->format('N') >= 6);
    $dataAlvo = $d->format('Y-m-d');
} else {
    $dataAlvo = date('Y-m-d');
}

// ============================================================
// Retorna JSON imediato + fecha conexão + roda em background
// ============================================================
$respJson = json_encode(array(
    'ok'       => true,
    'msg'      => 'Claudin disparado. Resultado aparece no dashboard em 15-180s.',
    'horario'  => $horario,
    'data_alvo' => $dataAlvo,
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
// A partir daqui, o cliente HTTP já recebeu a resposta.
// Rodamos o monitor no mesmo processo PHP em background.
// ============================================================
define('CLAUDIN_NO_AUTORUN', true);

try {
    require_once APP_ROOT . '/cron/djen_monitor.php';
    claudin_executar($horario, $dataAlvo);
} catch (Throwable $e) {
    @file_put_contents(
        APP_ROOT . '/cron/logs/claudin.log',
        '[' . date('Y-m-d H:i:s') . '] EXCEPTION trigger HTTP: ' . $e->getMessage() . "\n",
        FILE_APPEND
    );
    // Tenta gravar run de falha pra aparecer no dashboard
    try {
        $pdo = db();
        $pdo->prepare(
            "INSERT INTO claudin_runs
             (executado_em, data_alvo, horario, total_parsed, imported, duplicated, pending, errors,
              oabs_consultadas, tempo_execucao_segundos, status, payload_bytes, erro_texto)
             VALUES (NOW(), ?, ?, 0, 0, 0, 0, 1, '', 0, 'falha', 0, ?)"
        )->execute(array($dataAlvo, $horario, $e->getMessage()));
    } catch (Throwable $e2) {}
}
