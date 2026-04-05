<?php
/**
 * Ferreira & Sá Conecta — API pública para formulários
 * Recebe JSON via POST e salva em form_submissions via process_form_submission()
 *
 * Endpoints:
 *   POST /conecta/publico/api_form.php
 *   Content-Type: application/json
 *   Body: { "form_type": "...", "client_name": "...", "client_phone": "...", ... }
 *
 * Resposta: { "ok": true, "protocol": "...", "submission_id": 123 }
 */

header('Content-Type: application/json; charset=utf-8');

// CORS para permitir chamadas dos formulários em ferreiraesa.com.br
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'message' => 'Método não permitido. Use POST.'));
    exit;
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/form_handler.php';

// Ler JSON do body
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'JSON inválido.'));
    exit;
}

// form_type obrigatório
$formType = isset($data['form_type']) ? trim($data['form_type']) : '';
if (!$formType) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'Campo form_type é obrigatório.'));
    exit;
}

// Tipos válidos
$tiposValidos = array(
    'convivencia', 'gastos_pensao', 'despesas_mensais', 'calculadora_lead', 'cadastro_cliente',
    'divorcio', 'alimentos', 'resp_civil', 'usucapiao', 'inventario',
    'investigacao_paternidade'
);
if (!in_array($formType, $tiposValidos)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'Tipo de formulário inválido: ' . $formType));
    exit;
}

// Extrair dados do cliente (campos padronizados)
$clientName  = isset($data['client_name']) ? trim($data['client_name']) : '';
$clientPhone = isset($data['client_phone']) ? trim($data['client_phone']) : '';
$clientEmail = isset($data['client_email']) ? trim($data['client_email']) : '';

// Aliases comuns
if (!$clientName)  $clientName  = isset($data['nome']) ? trim($data['nome']) : (isset($data['nome_responsavel']) ? trim($data['nome_responsavel']) : '');
if (!$clientPhone) $clientPhone = isset($data['whatsapp']) ? trim($data['whatsapp']) : (isset($data['celular']) ? trim($data['celular']) : (isset($data['telefone']) ? trim($data['telefone']) : ''));
if (!$clientEmail) $clientEmail = isset($data['email']) ? trim($data['email']) : '';

// Precisa de pelo menos nome ou telefone
if (!$clientName && !$clientPhone) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'message' => 'Informe ao menos nome ou telefone.'));
    exit;
}

// Dados extras do cliente (para CRM)
$clientData = array(
    'name'  => $clientName,
    'phone' => $clientPhone,
    'email' => $clientEmail,
);

// Campos extras que podem vir no payload
$extraFields = array('cpf', 'rg', 'birth_date', 'profession', 'marital_status',
    'address_street', 'address_city', 'address_state', 'address_zip',
    'gender', 'has_children', 'children_names');
foreach ($extraFields as $f) {
    if (isset($data[$f]) && $data[$f] !== '') {
        $clientData[$f] = $data[$f];
    }
}

// Payload completo (todo o JSON recebido)
$payloadJson = json_encode($data, JSON_UNESCAPED_UNICODE);

try {
    $result = process_form_submission($formType, $clientData, $payloadJson);

    echo json_encode(array(
        'ok'            => true,
        'protocol'      => $result['protocol'],
        'submission_id' => $result['submission_id'],
        'client_id'     => $result['client_id'],
        'message'       => 'Formulário recebido com sucesso!',
    ));
} catch (Exception $e) {
    error_log('api_form.php ERRO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('ok' => false, 'message' => 'Erro interno ao processar formulário.'));
}
