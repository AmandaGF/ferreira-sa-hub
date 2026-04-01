<?php
/**
 * Webhook Asaas — recebe notificações de pagamento em tempo real
 * URL: ferreiraesa.com.br/conecta/modules/financeiro/webhook.php
 * Configurar em: app.asaas.com → Integrações → Webhooks
 */

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

header('Content-Type: application/json');

// Autenticação via token no header (configurar no Asaas)
$webhookToken = '';
try {
    $pdo = db();
    $row = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'asaas_webhook_token' LIMIT 1")->fetch();
    if ($row) $webhookToken = $row['valor'];
} catch (Exception $e) {}

if ($webhookToken) {
    $receivedToken = isset($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']) ? $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] : '';
    if (!$receivedToken) $receivedToken = isset($_GET['token']) ? $_GET['token'] : '';
    if ($receivedToken !== $webhookToken) {
        http_response_code(401);
        echo json_encode(array('error' => 'Unauthorized'));
        exit;
    }
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['event'])) {
    http_response_code(400);
    echo json_encode(array('error' => 'Invalid payload'));
    exit;
}

$event = $data['event'];
$payment = isset($data['payment']) ? $data['payment'] : null;

if (!$payment || !isset($payment['id'])) {
    http_response_code(200);
    echo json_encode(array('ok' => true, 'msg' => 'No payment data'));
    exit;
}

$pdo = db();
$paymentId = $payment['id'];

try {
    switch ($event) {
        case 'PAYMENT_RECEIVED':
        case 'PAYMENT_CONFIRMED':
            $pdo->prepare(
                "UPDATE asaas_cobrancas SET status = ?, data_pagamento = ?, valor_pago = ?, ultima_sync = NOW() WHERE asaas_payment_id = ?"
            )->execute(array(
                $payment['status'] ?: 'RECEIVED',
                $payment['paymentDate'] ?: date('Y-m-d'),
                isset($payment['netValue']) ? $payment['netValue'] : $payment['value'],
                $paymentId,
            ));
            break;

        case 'PAYMENT_OVERDUE':
            $pdo->prepare("UPDATE asaas_cobrancas SET status = 'OVERDUE', ultima_sync = NOW() WHERE asaas_payment_id = ?")->execute(array($paymentId));
            break;

        case 'PAYMENT_DELETED':
        case 'PAYMENT_REFUNDED':
            $status = ($event === 'PAYMENT_DELETED') ? 'CANCELED' : 'REFUNDED';
            $pdo->prepare("UPDATE asaas_cobrancas SET status = ?, ultima_sync = NOW() WHERE asaas_payment_id = ?")->execute(array($status, $paymentId));
            break;
    }
} catch (Exception $e) {
    error_log('Webhook Asaas ERRO: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(array('ok' => true));
