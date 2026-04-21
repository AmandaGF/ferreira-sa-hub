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

// Vincular TODAS as cobranças de um cliente a um processo específico (bulk)
// Opcionalmente filtra por status (só pendentes, só vencidas, etc)
if ($action === 'vincular_case_bulk') {
    header('Content-Type: application/json');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $caseId   = (int)($_POST['case_id'] ?? 0);
    $apenas   = $_POST['apenas'] ?? 'todas'; // todas | sem_vinculo | pendentes_vencidas
    if (!$clientId) { echo json_encode(array('error' => 'client_id obrigatório')); exit; }

    // Valida que o caso pertence ao cliente (quando caseId > 0)
    if ($caseId > 0) {
        $chk = $pdo->prepare("SELECT id FROM cases WHERE id = ? AND client_id = ?");
        $chk->execute(array($caseId, $clientId));
        if (!$chk->fetch()) { echo json_encode(array('error' => 'Processo não pertence a este cliente')); exit; }
    }

    $where = "client_id = ?";
    $params = array($clientId);
    if ($apenas === 'sem_vinculo') {
        $where .= " AND (case_id IS NULL OR case_id = 0)";
    } elseif ($apenas === 'pendentes_vencidas') {
        $where .= " AND status IN ('PENDING', 'OVERDUE')";
    }

    // Atualiza em asaas_cobrancas
    $sql = "UPDATE asaas_cobrancas SET case_id = ? WHERE $where";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(array($caseId ?: null), $params));
    $atualizadas = $stmt->rowCount();

    // Sincroniza em honorarios_cobranca (pelos asaas_payment_id dos registros afetados)
    try {
        $pdo->prepare("UPDATE honorarios_cobranca hc
                       JOIN asaas_cobrancas ac ON BINARY ac.asaas_payment_id = BINARY hc.asaas_payment_id
                       SET hc.case_id = ?
                       WHERE ac.$where")
            ->execute(array_merge(array($caseId ?: null), $params));
    } catch (Exception $e) {}

    audit_log('asaas_vincular_case_bulk', 'clients', $clientId, "case_id={$caseId}, apenas={$apenas}, atualizadas={$atualizadas}");
    echo json_encode(array('ok' => true, 'atualizadas' => $atualizadas));
    exit;
}

// ═══ Ações sobre cobranças existentes (AJAX) ═══
// Padrão: retorna JSON; recebe cobranca_id (id da tabela asaas_cobrancas)

if ($action === 'cobranca_cancelar') {
    header('Content-Type: application/json');
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }
    $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { echo json_encode(array('error' => 'Cobrança não encontrada')); exit; }
    if (in_array($cob['status'], array('CANCELED','REFUNDED'), true)) {
        echo json_encode(array('error' => 'Cobrança já está ' . asaas_status_label($cob['status']))); exit;
    }
    if (in_array($cob['status'], array('RECEIVED','CONFIRMED','RECEIVED_IN_CASH'), true)) {
        echo json_encode(array('error' => 'Cobrança já foi paga — não pode ser cancelada. Use "Estornar" no painel do Asaas se necessário.')); exit;
    }
    $resp = cancelar_cobranca_asaas($cob['asaas_payment_id']);
    if (isset($resp['error'])) { echo json_encode(array('error' => $resp['error'])); exit; }
    audit_log('cobranca_cancelada', 'asaas_cobrancas', $cobId, 'Payment: ' . $cob['asaas_payment_id']);
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'cobranca_alterar_vencimento') {
    header('Content-Type: application/json');
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $novaData = $_POST['nova_data'] ?? '';
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData)) { echo json_encode(array('error' => 'Data inválida')); exit; }
    $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { echo json_encode(array('error' => 'Cobrança não encontrada')); exit; }
    if (!in_array($cob['status'], array('PENDING','OVERDUE'), true)) {
        echo json_encode(array('error' => 'Só é possível alterar vencimento de cobrança pendente ou vencida. Status atual: ' . asaas_status_label($cob['status']))); exit;
    }
    $resp = alterar_vencimento_asaas($cob['asaas_payment_id'], $novaData);
    if (isset($resp['error'])) { echo json_encode(array('error' => $resp['error'])); exit; }
    audit_log('cobranca_vencto_alterado', 'asaas_cobrancas', $cobId, 'de ' . $cob['vencimento'] . ' → ' . $novaData);
    echo json_encode(array('ok' => true, 'nova_data' => $novaData));
    exit;
}

if ($action === 'cobranca_dar_baixa') {
    header('Content-Type: application/json');
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $dataPagto = $_POST['data_pagamento'] ?? '';
    $valorRaw = $_POST['valor'] ?? '';
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPagto)) { echo json_encode(array('error' => 'Data de pagamento inválida')); exit; }
    // Aceita "1234,56" ou "1234.56"
    $valor = (float)str_replace(',', '.', str_replace('.', '', $valorRaw));
    // Se não informou, tenta usar o valor da cobrança
    $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { echo json_encode(array('error' => 'Cobrança não encontrada')); exit; }
    if ($valor <= 0) $valor = (float)$cob['valor'];
    if (!in_array($cob['status'], array('PENDING','OVERDUE'), true)) {
        echo json_encode(array('error' => 'Só é possível dar baixa em cobrança pendente ou vencida. Status atual: ' . asaas_status_label($cob['status']))); exit;
    }
    $resp = baixar_cobranca_asaas($cob['asaas_payment_id'], $dataPagto, $valor);
    if (isset($resp['error'])) { echo json_encode(array('error' => $resp['error'])); exit; }
    audit_log('cobranca_baixa_manual', 'asaas_cobrancas', $cobId, "R$ " . number_format($valor,2,',','.') . " em " . $dataPagto);
    echo json_encode(array('ok' => true, 'valor' => $valor, 'data' => $dataPagto));
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

        // Processo é OBRIGATÓRIO — toda cobrança deve estar vinculada a um caso específico.
        if (!$caseId) {
            flash_set('error', 'Selecione o processo vinculado à cobrança.');
            redirect(module_url('financeiro', 'cliente.php?id=' . $clientId));
        }
        // Validar que o processo pertence ao cliente
        $chkCase = $pdo->prepare("SELECT id FROM cases WHERE id = ? AND client_id = ?");
        $chkCase->execute(array($caseId, $clientId));
        if (!$chkCase->fetchColumn()) {
            flash_set('error', 'Processo não pertence a este cliente. Selecione um válido.');
            redirect(module_url('financeiro', 'cliente.php?id=' . $clientId));
        }

        // Vincular cliente no Asaas (se ainda não vinculado)
        $vinculo = vincular_cliente_asaas($clientId);
        if (isset($vinculo['error'])) {
            flash_set('error', 'Erro ao vincular cliente no Asaas: ' . $vinculo['error']);
            redirect(module_url('financeiro'));
        }
        $asaasCustomerId = $vinculo['id'];

        if ($tipo === 'parcelado') {
            // Parcelamento fixo: N boletos/pix/cartão com fim definido
            $resp = criar_parcelamento_asaas($asaasCustomerId, $valor, $numParcelas, $vencimento, $descricao, $formaPag);
            if (isset($resp['error'])) {
                flash_set('error', 'Erro Asaas: ' . $resp['error']);
                redirect(module_url('financeiro'));
            }
            // Salvar contrato (tipo fixo com N parcelas)
            $pdo->prepare(
                "INSERT INTO contratos_financeiros (client_id, case_id, tipo_honorario, valor_total, num_parcelas, valor_parcela, forma_pagamento, data_fechamento, created_by)
                 VALUES (?, ?, 'entrada_parcelas', ?, ?, ?, ?, CURDATE(), ?)"
            )->execute(array($clientId, $caseId, $valor * $numParcelas, $numParcelas, $valor, strtolower($formaPag), current_user_id()));
            // Sincroniza as N parcelas criadas
            sync_cobrancas_cliente($clientId, $asaasCustomerId);
            // Vincula ao processo as parcelas recém criadas (sem case_id ainda)
            $pdo->prepare("UPDATE asaas_cobrancas SET case_id = ? WHERE client_id = ? AND case_id IS NULL")
                ->execute(array($caseId, $clientId));
            flash_set('success', "Parcelamento criado! $numParcelas × R$ " . number_format($valor, 2, ',', '.') . " (" . strtoupper($formaPag) . ")");

        } elseif ($tipo === 'recorrente') {
            // Assinatura recorrente: mensal, SEM FIM (ou até maxPayments)
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
            // Vincular ao processo TODAS as parcelas dessa assinatura que acabaram de ser criadas sem case_id
            $pdo->prepare("UPDATE asaas_cobrancas SET case_id = ? WHERE client_id = ? AND case_id IS NULL")
                ->execute(array($caseId, $clientId));
            flash_set('success', "Assinatura criada! $numParcelas parcelas de R$ " . number_format($valor, 2, ',', '.'));

        } else {
            // Cobrança única
            $resp = criar_cobranca_asaas($asaasCustomerId, $valor, $vencimento, $descricao, $formaPag);
            if (isset($resp['error'])) {
                flash_set('error', 'Erro Asaas: ' . $resp['error']);
                redirect(module_url('financeiro'));
            }

            // Salvar no cache — já com case_id vinculado
            $pdo->prepare(
                "INSERT INTO asaas_cobrancas (client_id, case_id, contrato_id, asaas_payment_id, asaas_customer_id, descricao, valor, vencimento, status, forma_pagamento, link_boleto, invoice_url)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'PENDING', ?, ?, ?)"
            )->execute(array(
                $clientId, $caseId, $resp['id'], $asaasCustomerId, $descricao, $valor, $vencimento,
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
