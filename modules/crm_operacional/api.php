<?php
/**
 * CRM Operacional — endpoints AJAX.
 *   salvar_obs       — grava observação + data de follow-up de uma conversa
 *   definir_status   — 'aquecendo' | 'resolvido' | NULL
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('crm_operacional');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'error' => 'Método inválido'));
    exit;
}
if (!validate_csrf()) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Token CSRF inválido'));
    exit;
}

require_once __DIR__ . '/../../core/functions_crm_operacional.php';
$pdo = db();
crm_op_self_heal($pdo);

$action = $_POST['action'] ?? '';

if ($action === 'salvar_obs') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if ($convId <= 0) { echo json_encode(array('ok' => false, 'error' => 'Conversa inválida')); exit; }
    $obs  = trim($_POST['observacao'] ?? '');
    $prox = trim($_POST['proximo_followup'] ?? '');
    $proxVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $prox) ? $prox : null;

    $pdo->prepare("INSERT INTO crm_operacional_obs (conversa_id, observacao, proximo_followup, atualizado_por)
                   VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE observacao = VALUES(observacao),
                                           proximo_followup = VALUES(proximo_followup),
                                           atualizado_por = VALUES(atualizado_por)")
        ->execute(array($convId, $obs !== '' ? $obs : null, $proxVal, current_user_id()));

    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'definir_status') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if ($convId <= 0) { echo json_encode(array('ok' => false, 'error' => 'Conversa inválida')); exit; }
    $status = $_POST['status'] ?? '';
    if (!in_array($status, array('aquecendo', 'resolvido', ''), true)) {
        echo json_encode(array('ok' => false, 'error' => 'Status inválido')); exit;
    }
    $statusVal = $status !== '' ? $status : null;

    $pdo->prepare("INSERT INTO crm_operacional_obs (conversa_id, status, atualizado_por)
                   VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE status = VALUES(status), atualizado_por = VALUES(atualizado_por)")
        ->execute(array($convId, $statusVal, current_user_id()));

    echo json_encode(array('ok' => true));
    exit;
}

echo json_encode(array('ok' => false, 'error' => 'Ação desconhecida'));
