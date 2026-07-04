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
 * Criar PARCELAMENTO no Asaas (N boletos/pix/cartão com fim definido).
 * Diferente de assinatura: gera exatamente $numParcelas cobranças com vencimento mensal
 * a partir de $primeiroVenc. Ideal pra honorários fechados.
 *
 * $valor pode ser total OU por parcela (define via $modoValor = 'total' | 'parcela').
 *   - 'total':  Asaas divide o valor em N parcelas (totalValue)
 *   - 'parcela': Asaas multiplica pelo N (installmentValue)
 */
function criar_parcelamento_asaas($asaasCustomerId, $valor, $numParcelas, $primeiroVenc, $descricao, $formaPagamento = 'BOLETO', $modoValor = 'parcela') {
    $billingType = strtoupper($formaPagamento);
    if (!in_array($billingType, array('BOLETO', 'PIX', 'CREDIT_CARD', 'UNDEFINED'))) $billingType = 'UNDEFINED';

    $numParcelas = max(2, min(60, (int)$numParcelas));
    $valor = (float)$valor;

    $data = array(
        'customer' => $asaasCustomerId,
        'billingType' => $billingType,
        'installmentCount' => $numParcelas,
        'dueDate' => $primeiroVenc,
        'description' => $descricao,
    );
    if ($modoValor === 'total') {
        $data['totalValue'] = $valor;
    } else {
        $data['installmentValue'] = $valor;
    }
    return asaas_post('/payments', $data);
}

/**
 * Criar assinatura recorrente no Asaas
 */
function criar_assinatura_asaas($asaasCustomerId, $valor, $diaVenc, $numParcelas, $descricao, $formaPagamento = 'PIX', $primeiroVenc = null) {
    $billingType = strtoupper($formaPagamento);
    if (!in_array($billingType, array('BOLETO', 'PIX', 'CREDIT_CARD', 'UNDEFINED'))) $billingType = 'UNDEFINED';

    // 1ª cobrança: PRIORIZA a data escolhida pelo usuário (campo "Vencimento").
    // Antes o sistema ignorava essa data e recalculava só pelo "Dia venc." — bug
    // relatado (usuário marcava 01/09 e ia 01/08). Só cai no cálculo por dia-do-mês
    // se a data não veio, é inválida ou já passou.
    if ($primeiroVenc && preg_match('/^\d{4}-\d{2}-\d{2}$/', $primeiroVenc)
        && strtotime($primeiroVenc) !== false && strtotime($primeiroVenc) >= strtotime('today')) {
        $nextDate = $primeiroVenc;
    } else {
        // Monta a 1ª cobrança no dia escolhido SEM estourar em meses curtos (fev etc):
        // se o dia não existe no mês, usa o último dia do mês. Asaas mantém o dia nos
        // meses seguintes (ajustando sozinho quando o mês não tem aquele dia).
        $diaVenc = max(1, min(31, (int)$diaVenc));
        $ano = (int)date('Y'); $mes = (int)date('n');
        $ultimo = (int)date('t', mktime(0, 0, 0, $mes, 1, $ano));
        $nextDate = sprintf('%04d-%02d-%02d', $ano, $mes, min($diaVenc, $ultimo));
        if (strtotime($nextDate) < strtotime('today')) {
            $mes++; if ($mes > 12) { $mes = 1; $ano++; }
            $ultimo = (int)date('t', mktime(0, 0, 0, $mes, 1, $ano));
            $nextDate = sprintf('%04d-%02d-%02d', $ano, $mes, min($diaVenc, $ultimo));
        }
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

// ─────────────────────────────────────────────────────────────────────────────
// Vínculo cobrança ↔ MÚLTIPLOS processos (combo: 1 contrato cobre 2+ processos).
// Tabela asaas_cobranca_cases guarda os processos EXTRAS; o primário fica em
// asaas_cobrancas.case_id. Ver migrar_cobranca_processos.php.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Processos EXTRAS vinculados a uma cobrança (não inclui o primário case_id).
 * @return int[] lista de case_id
 */
function cobranca_processos_extras($cobId) {
    $cobId = (int)$cobId;
    if (!$cobId) return array();
    try {
        $st = db()->prepare("SELECT case_id FROM asaas_cobranca_cases WHERE cobranca_id = ? ORDER BY id");
        $st->execute(array($cobId));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) { return array(); }
}

/**
 * Define os processos EXTRAS de uma cobrança (substitui os atuais).
 * Ignora o próprio primário (não duplica) e valida que cada caso pertence ao
 * mesmo cliente da cobrança. Retorna quantos ficaram vinculados.
 */
function cobranca_set_processos_extras($cobId, array $caseIds) {
    $cobId = (int)$cobId;
    if (!$cobId) return array('error' => 'cobranca_id inválido');
    $pdo = db();
    // Cliente + caso primário da cobrança
    $st = $pdo->prepare("SELECT client_id, case_id FROM asaas_cobrancas WHERE id = ?");
    $st->execute(array($cobId));
    $cob = $st->fetch();
    if (!$cob) return array('error' => 'Cobrança não encontrada');
    $clientId = (int)$cob['client_id'];
    $primario = (int)($cob['case_id'] ?? 0);

    // Sanitiza: inteiros positivos, únicos, tira o primário, valida cliente
    $limpos = array();
    foreach ($caseIds as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0 || $cid === $primario || isset($limpos[$cid])) continue;
        $chk = $pdo->prepare("SELECT 1 FROM cases WHERE id = ? AND client_id = ?");
        $chk->execute(array($cid, $clientId));
        if ($chk->fetchColumn()) $limpos[$cid] = true;
    }
    $limpos = array_keys($limpos);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM asaas_cobranca_cases WHERE cobranca_id = ?")->execute(array($cobId));
        if ($limpos) {
            $ins = $pdo->prepare("INSERT IGNORE INTO asaas_cobranca_cases (cobranca_id, case_id) VALUES (?, ?)");
            foreach ($limpos as $cid) $ins->execute(array($cobId, $cid));
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        return array('error' => 'Erro ao salvar vínculos: ' . $e->getMessage());
    }
    return array('ok' => true, 'extras' => $limpos);
}

/**
 * Aplica os processos EXTRAS a TODAS as cobranças de (cliente + caso primário).
 * Usado na criação de combo: cobrança única = 1 linha; parcelado/recorrente = N
 * parcelas — todas recebem os mesmos processos extras. Retorna nº de cobranças afetadas.
 */
function cobranca_extras_por_caso_primario($clientId, $primaryCaseId, array $extraCaseIds) {
    $clientId = (int)$clientId; $primaryCaseId = (int)$primaryCaseId;
    if (!$clientId || !$primaryCaseId || !$extraCaseIds) return 0;
    try {
        $st = db()->prepare("SELECT id FROM asaas_cobrancas WHERE client_id = ? AND case_id = ?");
        $st->execute(array($clientId, $primaryCaseId));
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) { return 0; }
    $n = 0;
    foreach ($ids as $cobId) { cobranca_set_processos_extras((int)$cobId, $extraCaseIds); $n++; }
    return $n;
}
