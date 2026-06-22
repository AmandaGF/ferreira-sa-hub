<?php
/**
 * CRM Comercial — endpoints AJAX.
 *   salvar_obs        — grava observação + data de follow-up de um lead (todos do time)
 *   preview_cobranca  — relatório DRY (não envia nada) de quem seria cobrado agora (gestão)
 *   testar_grupo      — envia 1 mensagem de teste no grupo configurado (gestão)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('crm_comercial');
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

require_once __DIR__ . '/../../core/functions_comercial.php';
$pdo = db();
comercial_self_heal($pdo);

$action = $_POST['action'] ?? '';

if ($action === 'salvar_obs') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if ($convId <= 0) { echo json_encode(array('ok' => false, 'error' => 'Conversa inválida')); exit; }
    $obs  = trim($_POST['observacao'] ?? '');
    $prox = trim($_POST['proximo_followup'] ?? '');
    $proxVal = preg_match('/^\d{4}-\d{2}-\d{2}$/', $prox) ? $prox : null;
    $leadId  = (int)($_POST['lead_id'] ?? 0);
    $leadVal = $leadId > 0 ? $leadId : null;

    $pdo->prepare("INSERT INTO comercial_lead_obs (conversa_id, lead_id, observacao, proximo_followup, atualizado_por)
                   VALUES (?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE observacao = VALUES(observacao),
                                           proximo_followup = VALUES(proximo_followup),
                                           atualizado_por = VALUES(atualizado_por)")
        ->execute(array($convId, $leadVal, $obs !== '' ? $obs : null, $proxVal, current_user_id()));

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
    $leadId  = (int)($_POST['lead_id'] ?? 0);
    $leadVal = $leadId > 0 ? $leadId : null;

    $pdo->prepare("INSERT INTO comercial_lead_obs (conversa_id, lead_id, status, atualizado_por)
                   VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE status = VALUES(status), atualizado_por = VALUES(atualizado_por)")
        ->execute(array($convId, $leadVal, $statusVal, current_user_id()));

    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'preview_cobranca') {
    if (!has_min_role('gestao')) { http_response_code(403); echo json_encode(array('ok' => false, 'error' => 'Sem permissão')); exit; }
    $rep = comercial_rodar_cobranca($pdo, array('dry' => true, 'forcar_horario' => true, 'ignorar_ativo' => true));
    echo json_encode(array('ok' => true, 'rep' => $rep));
    exit;
}

if ($action === 'testar_grupo') {
    if (!has_min_role('gestao')) { http_response_code(403); echo json_encode(array('ok' => false, 'error' => 'Sem permissão')); exit; }
    $cfg = comercial_cfg($pdo);
    if (empty($cfg['grupo_id'])) { echo json_encode(array('ok' => false, 'error' => 'Configure o ID do grupo primeiro.')); exit; }
    $canal = $cfg['grupo_canal'] ? $cfg['grupo_canal'] : '21';
    $res = zapi_send_text($canal, $cfg['grupo_id'], "Fala povo, sou eu, Jorge, vindo aqui só para testar uma nova funcionalidade! TMJ e bora!");
    echo json_encode(array('ok' => !empty($res['ok']), 'error' => isset($res['erro']) ? $res['erro'] : ''));
    exit;
}

echo json_encode(array('ok' => false, 'error' => 'Ação desconhecida'));
