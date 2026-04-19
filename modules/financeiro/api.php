<?php
/**
 * Ferreira & Sá Hub — API Financeiro (Asaas)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { http_response_code(403); echo json_encode(array('error'=>'Acesso negado')); exit; }

require_once __DIR__ . '/../../core/asaas_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('financeiro')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('financeiro')); }

$action = $_POST['action'] ?? '';
$pdo = db();

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
