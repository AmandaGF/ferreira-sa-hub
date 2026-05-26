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

// Helper: cria a cobranca a partir do payload do webhook quando ainda nao
// existe no Hub. Bug relatado pela Amanda 26/05/2026: "clientes estao pagando
// e nao estao vinculados" — webhook so fazia UPDATE; pagamento sem registro
// previo era silenciosamente ignorado.
function _fin_webhook_inserir_cobranca($pdo, $payment) {
    // Resolve client_id via customer (clients.asaas_customer_id) — pode ser NULL
    $clientId = null;
    if (!empty($payment['customer'])) {
        try {
            $st = $pdo->prepare("SELECT id FROM clients WHERE asaas_customer_id = ? LIMIT 1");
            $st->execute(array($payment['customer']));
            $clientId = $st->fetchColumn() ?: null;
        } catch (Exception $e) {}
    }
    // INSERT IGNORE pra evitar race condition (2 webhooks chegam pra mesma cobranca)
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO asaas_cobrancas
                (asaas_payment_id, asaas_customer_id, client_id, valor, valor_pago, vencimento,
                 data_pagamento, status, forma_pagamento, descricao, invoice_url, ultima_sync)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())"
        )->execute(array(
            $payment['id'],
            $payment['customer'] ?? null,
            $clientId,
            isset($payment['value']) ? $payment['value'] : 0,
            isset($payment['netValue']) ? $payment['netValue'] : (isset($payment['value']) ? $payment['value'] : 0),
            $payment['dueDate'] ?? null,
            $payment['paymentDate'] ?? null,
            $payment['status'] ?? 'RECEIVED',
            strtolower($payment['billingType'] ?? ''),
            substr((string)($payment['description'] ?? ''), 0, 250),
            $payment['invoiceUrl'] ?? null,
        ));
        @error_log('[asaas webhook] cobranca criada via webhook: ' . $payment['id'] . ' client_id=' . ($clientId ?: 'NULL'));
        return true;
    } catch (Exception $e) {
        @error_log('[asaas webhook insert] erro: ' . $e->getMessage());
        return false;
    }
}

try {
    switch ($event) {
        case 'PAYMENT_RECEIVED':
        case 'PAYMENT_CONFIRMED':
            $st = $pdo->prepare(
                "UPDATE asaas_cobrancas SET status = ?, data_pagamento = ?, valor_pago = ?, ultima_sync = NOW() WHERE asaas_payment_id = ?"
            );
            $st->execute(array(
                $payment['status'] ?: 'RECEIVED',
                $payment['paymentDate'] ?: date('Y-m-d'),
                isset($payment['netValue']) ? $payment['netValue'] : $payment['value'],
                $paymentId,
            ));
            // 0 rows = cobranca ainda nao existe no Hub. Cria agora pra nao
            // perder o pagamento — resolve client_id automaticamente.
            if ($st->rowCount() === 0) {
                _fin_webhook_inserir_cobranca($pdo, $payment);
            }
            break;

        case 'PAYMENT_CREATED':
        case 'PAYMENT_UPDATED':
            // Cobranca criada/atualizada no Asaas. Garante que o Hub conheca.
            $st = $pdo->prepare("SELECT id FROM asaas_cobrancas WHERE asaas_payment_id = ?");
            $st->execute(array($paymentId));
            if (!$st->fetchColumn()) {
                _fin_webhook_inserir_cobranca($pdo, $payment);
            } else {
                // Atualiza dados que possam ter mudado (valor, vencimento, status)
                $pdo->prepare(
                    "UPDATE asaas_cobrancas SET valor = ?, vencimento = ?, status = ?, ultima_sync = NOW()
                     WHERE asaas_payment_id = ?"
                )->execute(array(
                    isset($payment['value']) ? $payment['value'] : 0,
                    $payment['dueDate'] ?? null,
                    $payment['status'] ?? 'PENDING',
                    $paymentId,
                ));
            }
            break;

        case 'PAYMENT_OVERDUE':
            $st = $pdo->prepare("UPDATE asaas_cobrancas SET status = 'OVERDUE', ultima_sync = NOW() WHERE asaas_payment_id = ?");
            $st->execute(array($paymentId));
            if ($st->rowCount() === 0) {
                _fin_webhook_inserir_cobranca($pdo, $payment);
            }
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
