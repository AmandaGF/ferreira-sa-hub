<?php
/**
 * Ferreira & Sá Hub — Helper Asaas API
 * Sandbox: https://sandbox.asaas.com/api/v3
 * Produção: https://api.asaas.com/api/v3
 */

function asaas_config() {
    static $cfg = null;
    if ($cfg) return $cfg;
    $pdo = db();
    $key = ''; $env = 'sandbox';
    try {
        $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('asaas_api_key','asaas_env')")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'asaas_api_key') $key = $r['valor'];
            if ($r['chave'] === 'asaas_env') $env = $r['valor'];
        }
    } catch (Exception $e) {}
    $base = ($env === 'production') ? 'https://api.asaas.com/v3' : 'https://sandbox.asaas.com/api/v3';
    $cfg = array('key' => $key, 'env' => $env, 'base' => $base);
    return $cfg;
}

function asaas_request($method, $endpoint, $data = null) {
    $cfg = asaas_config();
    if (!$cfg['key']) return array('error' => 'API key não configurada');

    $url = $cfg['base'] . $endpoint;
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'access_token: ' . $cfg['key'],
            'User-Agent: FES-Hub/1.0',
        ),
    ));

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return array('error' => $error, 'httpCode' => 0);

    $decoded = json_decode($response, true);
    if ($httpCode >= 400) {
        $errMsg = 'Erro Asaas';
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            $msgs = array();
            foreach ($decoded['errors'] as $e) { $msgs[] = isset($e['description']) ? $e['description'] : json_encode($e); }
            $errMsg = implode('; ', $msgs);
        }
        return array('error' => $errMsg, 'httpCode' => $httpCode, 'raw' => $decoded);
    }

    return $decoded ?: array();
}

function asaas_get($endpoint) { return asaas_request('GET', $endpoint); }
function asaas_post($endpoint, $data) { return asaas_request('POST', $endpoint, $data); }
function asaas_delete($endpoint) { return asaas_request('DELETE', $endpoint); }
function asaas_put($endpoint, $data) { return asaas_request('PUT', $endpoint, $data); }

function limpar_cpf($cpf) { return preg_replace('/\D/', '', $cpf); }

/**
 * Vincular cliente do portal ao Asaas (busca por CPF ou cria)
 */
function vincular_cliente_asaas($clientId) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute(array($clientId));
    $cliente = $stmt->fetch();
    if (!$cliente) return array('error' => 'Cliente não encontrado');

    // Já vinculado?
    if ($cliente['asaas_customer_id']) return array('id' => $cliente['asaas_customer_id'], 'ja_vinculado' => true);

    $cpf = limpar_cpf($cliente['cpf'] ?: '');
    if (strlen($cpf) < 11) return array('error' => 'CPF não cadastrado. Atualize o cadastro do cliente primeiro.');

    // Buscar no Asaas pelo CPF
    $resp = asaas_get('/customers?cpfCnpj=' . $cpf);
    if (isset($resp['error'])) return $resp;

    $asaasId = null;
    if (isset($resp['totalCount']) && $resp['totalCount'] > 0) {
        $asaasId = $resp['data'][0]['id'];
    } else {
        // Criar no Asaas
        $criar = asaas_post('/customers', array(
            'name' => $cliente['name'],
            'cpfCnpj' => $cpf,
            'email' => $cliente['email'] ?: null,
            'phone' => $cliente['phone'] ? preg_replace('/\D/', '', $cliente['phone']) : null,
            'address' => $cliente['address_street'] ?: null,
            'addressNumber' => null,
            'province' => null,
            'city' => $cliente['address_city'] ?: null,
            'state' => $cliente['address_state'] ?: null,
            'postalCode' => $cliente['address_zip'] ? preg_replace('/\D/', '', $cliente['address_zip']) : null,
            'notificationDisabled' => false,
        ));
        if (isset($criar['error'])) return $criar;
        $asaasId = $criar['id'];
    }

    // Salvar vínculo
    $pdo->prepare("UPDATE clients SET asaas_customer_id = ?, asaas_sincronizado = 1 WHERE id = ?")
        ->execute(array($asaasId, $clientId));

    return array('id' => $asaasId, 'novo' => true);
}

/**
 * Buscar cobranças do Asaas e atualizar cache local
 */
function sync_cobrancas_cliente($clientId, $asaasCustomerId) {
    $pdo = db();
    $resp = asaas_get('/payments?customer=' . $asaasCustomerId . '&limit=100');
    if (isset($resp['error'])) return $resp;

    $synced = 0;
    if (isset($resp['data'])) {
        foreach ($resp['data'] as $pay) {
            $pdo->prepare(
                "INSERT INTO asaas_cobrancas (client_id, asaas_payment_id, asaas_customer_id, descricao, valor, vencimento, status, forma_pagamento, data_pagamento, valor_pago, link_boleto, invoice_url, ultima_sync)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE status=VALUES(status), data_pagamento=VALUES(data_pagamento), valor_pago=VALUES(valor_pago), link_boleto=VALUES(link_boleto), invoice_url=VALUES(invoice_url), ultima_sync=NOW()"
            )->execute(array(
                $clientId,
                $pay['id'],
                $asaasCustomerId,
                $pay['description'] ?: null,
                $pay['value'],
                $pay['dueDate'],
                $pay['status'],
                $pay['billingType'] ?: null,
                isset($pay['paymentDate']) ? $pay['paymentDate'] : null,
                isset($pay['netValue']) ? $pay['netValue'] : null,
                isset($pay['bankSlipUrl']) ? $pay['bankSlipUrl'] : null,
                isset($pay['invoiceUrl']) ? $pay['invoiceUrl'] : null,
            ));
            $synced++;
        }
    }
    return array('synced' => $synced);
}

/**
 * Criar cobrança no Asaas
 */
function criar_cobranca_asaas($asaasCustomerId, $valor, $vencimento, $descricao, $formaPagamento = 'PIX') {
    $billingType = strtoupper($formaPagamento);
    if (!in_array($billingType, array('BOLETO', 'PIX', 'CREDIT_CARD', 'UNDEFINED'))) $billingType = 'UNDEFINED';

    $data = array(
        'customer' => $asaasCustomerId,
        'billingType' => $billingType,
        'value' => (float)$valor,
        'dueDate' => $vencimento,
        'description' => $descricao,
    );

    return asaas_post('/payments', $data);
}

/**
 * Criar assinatura recorrente no Asaas
 */
function criar_assinatura_asaas($asaasCustomerId, $valor, $diaVenc, $numParcelas, $descricao, $formaPagamento = 'PIX') {
    $billingType = strtoupper($formaPagamento);
    if (!in_array($billingType, array('BOLETO', 'PIX', 'CREDIT_CARD', 'UNDEFINED'))) $billingType = 'UNDEFINED';

    $nextDate = date('Y-m-') . str_pad($diaVenc, 2, '0', STR_PAD_LEFT);
    if (strtotime($nextDate) < time()) {
        $nextDate = date('Y-m-', strtotime('+1 month')) . str_pad($diaVenc, 2, '0', STR_PAD_LEFT);
    }

    $data = array(
        'customer' => $asaasCustomerId,
        'billingType' => $billingType,
        'value' => (float)$valor,
        'nextDueDate' => $nextDate,
        'cycle' => 'MONTHLY',
        'description' => $descricao,
        'maxPayments' => (int)$numParcelas,
    );

    return asaas_post('/subscriptions', $data);
}

/**
 * Cancelar cobrança no Asaas. Só funciona pra PENDING/OVERDUE.
 * Atualiza cache local (asaas_cobrancas) setando status='CANCELED' após sucesso.
 */
function cancelar_cobranca_asaas($paymentId) {
    if (!$paymentId) return array('error' => 'ID da cobrança ausente.');
    $resp = asaas_delete('/payments/' . urlencode($paymentId));
    if (isset($resp['error'])) return $resp;
    // Asaas retorna {deleted:true, id:"pay_xxx"} em sucesso
    try {
        db()->prepare("UPDATE asaas_cobrancas SET status='CANCELED', ultima_sync=NOW() WHERE asaas_payment_id = ?")
           ->execute(array($paymentId));
    } catch (Exception $e) {}
    return array('ok' => true, 'id' => $paymentId);
}

/**
 * Alterar data de vencimento de cobrança no Asaas (só PENDING/OVERDUE).
 * $novaData deve vir no formato YYYY-MM-DD.
 */
function alterar_vencimento_asaas($paymentId, $novaData) {
    if (!$paymentId) return array('error' => 'ID da cobrança ausente.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData)) return array('error' => 'Data inválida (use AAAA-MM-DD).');
    $resp = asaas_put('/payments/' . urlencode($paymentId), array('dueDate' => $novaData));
    if (isset($resp['error'])) return $resp;
    try {
        // Se estava OVERDUE e a nova data é futura, o Asaas volta pra PENDING automaticamente na próxima sync
        $novoStatus = (strtotime($novaData) >= strtotime('today')) ? 'PENDING' : 'OVERDUE';
        db()->prepare("UPDATE asaas_cobrancas SET vencimento=?, status=?, ultima_sync=NOW() WHERE asaas_payment_id = ?")
           ->execute(array($novaData, $novoStatus, $paymentId));
    } catch (Exception $e) {}
    return array('ok' => true, 'id' => $paymentId, 'due_date' => $novaData);
}

/**
 * Dar baixa manualmente (marcar como paga em dinheiro/transferência fora do Asaas).
 * $dataPagamento = YYYY-MM-DD; $valor = valor recebido (pode ser diferente do nominal, ex: desconto).
 */
function baixar_cobranca_asaas($paymentId, $dataPagamento, $valor) {
    if (!$paymentId) return array('error' => 'ID da cobrança ausente.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPagamento)) return array('error' => 'Data inválida (use AAAA-MM-DD).');
    $valor = (float)$valor;
    if ($valor <= 0) return array('error' => 'Valor deve ser maior que zero.');
    $resp = asaas_post('/payments/' . urlencode($paymentId) . '/receiveInCash', array(
        'paymentDate' => $dataPagamento,
        'value' => $valor,
        'notifyCustomer' => false,
    ));
    if (isset($resp['error'])) return $resp;
    try {
        db()->prepare("UPDATE asaas_cobrancas SET status='RECEIVED_IN_CASH', data_pagamento=?, valor_pago=?, ultima_sync=NOW() WHERE asaas_payment_id = ?")
           ->execute(array($dataPagamento, $valor, $paymentId));
    } catch (Exception $e) {}
    return array('ok' => true, 'id' => $paymentId, 'payment_date' => $dataPagamento, 'value' => $valor);
}

// Status labels e cores
function asaas_status_label($status) {
    $map = array(
        'PENDING' => 'Aguardando', 'RECEIVED' => 'Pago', 'CONFIRMED' => 'Confirmado',
        'OVERDUE' => 'Vencido', 'REFUNDED' => 'Reembolsado', 'CANCELED' => 'Cancelado',
        'RECEIVED_IN_CASH' => 'Pago (dinheiro)', 'REFUND_REQUESTED' => 'Reembolso solicitado',
    );
    return isset($map[$status]) ? $map[$status] : $status;
}

function asaas_status_cor($status) {
    $map = array(
        'PENDING' => '#f59e0b', 'RECEIVED' => '#059669', 'CONFIRMED' => '#059669',
        'OVERDUE' => '#dc2626', 'REFUNDED' => '#6b7280', 'CANCELED' => '#6b7280',
        'RECEIVED_IN_CASH' => '#059669', 'REFUND_REQUESTED' => '#d97706',
    );
    return isset($map[$status]) ? $map[$status] : '#888';
}
