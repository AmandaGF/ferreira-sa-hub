<?php
/**
 * Ferreira & Sá Hub — API Financeiro (Asaas)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { http_response_code(403); echo json_encode(array('error'=>'Acesso negado')); exit; }

require_once __DIR__ . '/../../core/asaas_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('financeiro')); }

$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if (!validate_csrf()) {
    if ($isAjax) {
        header('Content-Type: application/json', true, 403);
        echo json_encode(array('error' => 'Token CSRF expirado — recarregue a página e tente de novo', 'csrf_expired' => true));
        exit;
    }
    flash_set('error', 'Token inválido.');
    redirect(module_url('financeiro'));
}

$action = $_POST['action'] ?? '';
$pdo = db();

// Vincular / desvincular cobrança a um processo (case_id)
if ($action === 'vincular_case') {
    header('Content-Type: application/json');
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $caseId = (int)($_POST['case_id'] ?? 0);
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }

    // Se caseId > 0, validar que o caso pertence ao mesmo cliente da cobrança
    if ($caseId > 0) {
        $chk = $pdo->prepare("SELECT cs.id FROM cases cs JOIN asaas_cobrancas ac ON ac.client_id = cs.client_id WHERE ac.id = ? AND cs.id = ?");
        $chk->execute(array($cobId, $caseId));
        if (!$chk->fetch()) { echo json_encode(array('error' => 'Processo não pertence a este cliente')); exit; }
    }
    $pdo->prepare("UPDATE asaas_cobrancas SET case_id = ? WHERE id = ?")
        ->execute(array($caseId ?: null, $cobId));
    // Sincroniza honorarios_cobranca se existir entrada
    $pdo->prepare("UPDATE honorarios_cobranca SET case_id = ? WHERE asaas_payment_id = (SELECT asaas_payment_id FROM asaas_cobrancas WHERE id = ?)")
        ->execute(array($caseId ?: null, $cobId));
    audit_log('asaas_vincular_case', 'asaas_cobrancas', $cobId, "case_id={$caseId}");
    echo json_encode(array('ok' => true));
    exit;
}

// Criar cobrança Asaas a partir de um lead da Planilha Comercial
// (botão 💰 Cobrar no pipeline/index.php)
if ($action === 'criar_cobranca_lead') {
    header('Content-Type: application/json');
    $leadId = (int)($_POST['lead_id'] ?? 0);
    if (!$leadId) { echo json_encode(array('error' => 'lead_id obrigatório')); exit; }

    $l = $pdo->prepare("SELECT pl.*, c.id AS client_id_real, c.name AS client_name, c.cpf, c.asaas_customer_id
                        FROM pipeline_leads pl
                        LEFT JOIN clients c ON c.id = pl.client_id
                        WHERE pl.id = ?");
    $l->execute(array($leadId));
    $lead = $l->fetch();
    if (!$lead) { echo json_encode(array('error' => 'Lead não encontrado')); exit; }
    if (!$lead['client_id_real']) { echo json_encode(array('error' => 'Lead não vinculado a cliente. Vincule primeiro pelo cadastro.')); exit; }
    if (!$lead['cpf']) { echo json_encode(array('error' => 'Cliente sem CPF cadastrado. Atualize no CRM antes de criar cobrança.')); exit; }

    // Valor: usa honorarios_cents ou estimated_value_cents
    $valorCents = (int)($lead['honorarios_cents'] ?: ($lead['estimated_value_cents'] ?? 0));
    if ($valorCents <= 0) { echo json_encode(array('error' => 'Valor dos honorários não informado — preencha a coluna "Honorários (R$)".')); exit; }
    $valor = $valorCents / 100;

    $venc = $lead['vencimento_parcela'] ?? '';
    // Aceita YYYY-MM-DD ou DD/MM/YYYY
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc)) { $vencIso = $venc; }
    elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $venc, $m)) { $vencIso = $m[3] . '-' . $m[2] . '-' . $m[1]; }
    else { echo json_encode(array('error' => 'Data de 1º vencimento inválida — preencha a coluna "Vencto 1ª" (formato DD/MM/AAAA).')); exit; }

    $formaTxt = mb_strtoupper(trim($lead['forma_pagamento'] ?? ''));
    if (!$formaTxt) { echo json_encode(array('error' => 'Forma de pagamento não informada — escolha na coluna "Pgto".')); exit; }

    // Vincular cliente no Asaas se ainda não vinculado
    if (empty($lead['asaas_customer_id'])) {
        $vinc = vincular_cliente_asaas((int)$lead['client_id_real']);
        if (isset($vinc['error'])) { echo json_encode(array('error' => 'Falha ao vincular cliente no Asaas: ' . $vinc['error'])); exit; }
        $asaasCustomerId = $vinc['id'];
    } else {
        $asaasCustomerId = $lead['asaas_customer_id'];
    }

    // Descrição automática
    $descBase = 'Honorários advocatícios';
    if (!empty($lead['case_type'])) $descBase .= ' — ' . $lead['case_type'];
    $descBase .= ' (' . $lead['client_name'] . ')';

    // Mapeia forma de pagamento → cobrança única vs subscription + billingType
    // CARTÃO DE CRÉDITO → cobrança única CREDIT_CARD
    // CRÉDITO RECORRENTE → subscription CREDIT_CARD (parcelado mensal)
    // PIX RECORRENTE → subscription PIX
    // BOLETO → cobrança única BOLETO
    // À VISTA → cobrança única UNDEFINED (cliente escolhe qualquer forma)
    $numParcelas = (int)($lead['num_parcelas'] ?? 1);
    if ($numParcelas < 1) $numParcelas = 1;

    $recorrente = false;
    $billingType = 'UNDEFINED';
    if (strpos($formaTxt, 'CARTÃO DE CRÉDITO') !== false || $formaTxt === 'CARTAO DE CREDITO') {
        $billingType = 'CREDIT_CARD';
    } elseif (strpos($formaTxt, 'CRÉDITO RECORRENTE') !== false || $formaTxt === 'CREDITO RECORRENTE') {
        $billingType = 'CREDIT_CARD'; $recorrente = true;
    } elseif (strpos($formaTxt, 'PIX RECORRENTE') !== false) {
        $billingType = 'PIX'; $recorrente = true;
    } elseif ($formaTxt === 'BOLETO') {
        $billingType = 'BOLETO';
    } elseif (strpos($formaTxt, 'VISTA') !== false) {
        $billingType = 'UNDEFINED';
    }

    try {
        if ($recorrente) {
            if ($numParcelas < 2) $numParcelas = 12; // se não informou, assume 12 meses
            $diaVenc = (int)date('d', strtotime($vencIso));
            $resp = criar_assinatura_asaas($asaasCustomerId, $valor, $diaVenc, $numParcelas, $descBase, $billingType);
        } else {
            $resp = criar_cobranca_asaas($asaasCustomerId, $valor, $vencIso, $descBase, $billingType);
        }
        if (isset($resp['error'])) {
            echo json_encode(array('error' => 'Asaas recusou: ' . (is_array($resp['error']) ? json_encode($resp['error']) : $resp['error'])));
            exit;
        }
        $asaasId = $resp['id'] ?? null;
        $invoiceUrl = $resp['invoiceUrl'] ?? ($resp['invoiceUrl'] ?? null);

        // Persiste em asaas_cobrancas se for cobrança única (subscriptions geram payments automaticamente no webhook)
        if (!$recorrente && $asaasId) {
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO asaas_cobrancas (client_id, asaas_payment_id, asaas_customer_id, descricao, valor, vencimento, status, forma_pagamento, invoice_url, ultima_sync)
                     VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?, ?, NOW())"
                )->execute(array($lead['client_id_real'], $asaasId, $asaasCustomerId, $descBase, $valor, $vencIso, $billingType, $invoiceUrl));
            } catch (Exception $e) {}
        }

        audit_log('asaas_cobranca_lead', 'lead', $leadId, 'Cobrança criada (' . ($recorrente ? 'subscription ' . $numParcelas . 'x' : 'avulsa') . ') — ' . $billingType . ' — R$ ' . number_format($valor, 2, ',', '.'));

        echo json_encode(array(
            'ok' => true,
            'asaas_id' => $asaasId,
            'invoice_url' => $invoiceUrl,
            'recorrente' => $recorrente,
            'msg' => $recorrente
                ? 'Assinatura criada com sucesso (' . $numParcelas . 'x R$ ' . number_format($valor, 2, ',', '.') . ').'
                : 'Cobrança criada com sucesso — R$ ' . number_format($valor, 2, ',', '.') . ' · venc ' . date('d/m/Y', strtotime($vencIso)),
        ));
        exit;
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Erro interno: ' . $e->getMessage()));
        exit;
    }
}

switch ($action) {
    case 'criar_cobranca':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'unica';
        // Converter "1.500,00" → 1500.00
        $valorRaw = $_POST['valor'] ?? '0';
        $valor = (float)str_replace(array('.', ','), array('', '.'), preg_replace('/[^\d,.]/', '', $valorRaw));
        $vencimento = $_POST['vencimento'] ?? '';
        $descricao = clean_str($_POST['descricao'] ?? 'Honorários Advocatícios', 250);
        $formaPag = $_POST['forma_pagamento'] ?? 'PIX';
        $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
        $numParcelas = (int)($_POST['num_parcelas'] ?? 12);
        $diaVenc = (int)($_POST['dia_vencimento'] ?? 10);

        if (!$clientId || $valor < 5 || !$vencimento) {
            flash_set('error', 'Preencha cliente, valor (mín R$5) e vencimento.');
            redirect(module_url('financeiro'));
        }

        // Vincular cliente no Asaas (se ainda não vinculado)
        $vinculo = vincular_cliente_asaas($clientId);
        if (isset($vinculo['error'])) {
            flash_set('error', 'Erro ao vincular cliente no Asaas: ' . $vinculo['error']);
            redirect(module_url('financeiro'));
        }
        $asaasCustomerId = $vinculo['id'];

        if ($tipo === 'recorrente') {
            // Criar assinatura
            $resp = criar_assinatura_asaas($asaasCustomerId, $valor, $diaVenc, $numParcelas, $descricao, $formaPag);
            if (isset($resp['error'])) {
                flash_set('error', 'Erro Asaas: ' . $resp['error']);
                redirect(module_url('financeiro'));
            }

            // Salvar contrato
            $pdo->prepare(
                "INSERT INTO contratos_financeiros (client_id, case_id, tipo_honorario, valor_total, num_parcelas, valor_parcela, dia_vencimento, forma_pagamento, data_fechamento, asaas_subscription_id, created_by)
                 VALUES (?, ?, 'entrada_parcelas', ?, ?, ?, ?, ?, CURDATE(), ?, ?)"
            )->execute(array($clientId, $caseId, $valor * $numParcelas, $numParcelas, $valor, $diaVenc, strtolower($formaPag), $resp['id'], current_user_id()));

            // Sincronizar parcelas criadas
            sync_cobrancas_cliente($clientId, $asaasCustomerId);
            flash_set('success', "Assinatura criada! $numParcelas parcelas de R$ " . number_format($valor, 2, ',', '.'));

        } else {
            // Cobrança única
            $resp = criar_cobranca_asaas($asaasCustomerId, $valor, $vencimento, $descricao, $formaPag);
            if (isset($resp['error'])) {
                flash_set('error', 'Erro Asaas: ' . $resp['error']);
                redirect(module_url('financeiro'));
            }

            // Salvar no cache
            $pdo->prepare(
                "INSERT INTO asaas_cobrancas (client_id, contrato_id, asaas_payment_id, asaas_customer_id, descricao, valor, vencimento, status, forma_pagamento, link_boleto, invoice_url)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, 'PENDING', ?, ?, ?)"
            )->execute(array(
                $clientId, $resp['id'], $asaasCustomerId, $descricao, $valor, $vencimento,
                strtolower($formaPag),
                isset($resp['bankSlipUrl']) ? $resp['bankSlipUrl'] : null,
                isset($resp['invoiceUrl']) ? $resp['invoiceUrl'] : null,
            ));

            // Salvar contrato
            $pdo->prepare(
                "INSERT INTO contratos_financeiros (client_id, case_id, tipo_honorario, valor_total, num_parcelas, valor_parcela, forma_pagamento, data_fechamento, created_by)
                 VALUES (?, ?, 'fixo', ?, 1, ?, ?, CURDATE(), ?)"
            )->execute(array($clientId, $caseId, $valor, $valor, strtolower($formaPag), current_user_id()));

            $linkMsg = '';
            if (isset($resp['invoiceUrl'])) $linkMsg = "\n\nLink: " . $resp['invoiceUrl'];
            flash_set('success', "Cobrança criada! R$ " . number_format($valor, 2, ',', '.') . " vencimento " . date('d/m/Y', strtotime($vencimento)) . $linkMsg);
        }

        audit_log('cobranca_criada', 'financeiro', $clientId, "R$ " . number_format($valor, 2, ',', '.') . " - $descricao");
        redirect(module_url('financeiro', 'cliente.php?id=' . $clientId));
        break;

    case 'cancelar_cobranca':
        $cobId = (int)($_POST['cobranca_id'] ?? 0);
        $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
        $cob->execute(array($cobId));
        $cob = $cob->fetch();
        if (!$cob) { flash_set('error', 'Cobrança não encontrada.'); redirect(module_url('financeiro')); }

        $resp = asaas_delete('/payments/' . $cob['asaas_payment_id']);
        if (isset($resp['error'])) {
            flash_set('error', 'Erro ao cancelar: ' . $resp['error']);
        } else {
            $pdo->prepare("UPDATE asaas_cobrancas SET status = 'CANCELED' WHERE id = ?")->execute(array($cobId));
            audit_log('cobranca_cancelada', 'financeiro', $cob['client_id'], "Payment: " . $cob['asaas_payment_id']);
            flash_set('success', 'Cobrança cancelada.');
        }
        redirect(module_url('financeiro', 'cliente.php?id=' . $cob['client_id']));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('financeiro'));
}
