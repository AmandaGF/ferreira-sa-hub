<?php
/**
 * Sincronizar cobranças do Asaas (modo rápido — últimos 30 dias).
 * Evita timeout: usa /payments paginado com filtro de data,
 * sem iterar por cliente.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { redirect(url('modules/dashboard/')); }

require_once __DIR__ . '/../../core/asaas_helper.php';
$pdo = db();

set_time_limit(120);
ignore_user_abort(true);

$cfg = asaas_config();
if (!$cfg['key']) { flash_set('error', 'Asaas não configurado.'); redirect(module_url('financeiro')); }

// Filtro: pagamentos criados ou atualizados nos últimos 30 dias
$desde = date('Y-m-d', strtotime('-30 days'));

// Mapa asaas_customer_id → client_id (pra vincular)
$map = array();
foreach ($pdo->query("SELECT id, asaas_customer_id FROM clients WHERE asaas_customer_id IS NOT NULL")->fetchAll() as $r) {
    $map[$r['asaas_customer_id']] = (int)$r['id'];
}

$offset = 0; $limit = 100; $inserted = 0; $updated = 0; $paginas = 0;
while ($paginas < 10) { // no máximo 1000 cobranças no sync rápido
    $resp = asaas_request('GET', '/payments?limit=' . $limit . '&offset=' . $offset . '&dateCreated[ge]=' . $desde);
    if (!$resp || !isset($resp['data'])) break;
    $lista = $resp['data']; if (empty($lista)) break;

    foreach ($lista as $p) {
        $payId = $p['id'] ?? ''; if (!$payId) continue;
        $custId = $p['customer'] ?? '';
        $clientId = $map[$custId] ?? null;

        $stmt = $pdo->prepare(
            "INSERT INTO asaas_cobrancas
               (client_id, asaas_payment_id, asaas_customer_id, descricao, valor,
                vencimento, status, forma_pagamento, data_pagamento, valor_pago,
                link_boleto, invoice_url, ultima_sync, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
               client_id = VALUES(client_id), asaas_customer_id = VALUES(asaas_customer_id),
               descricao = VALUES(descricao), valor = VALUES(valor), vencimento = VALUES(vencimento),
               status = VALUES(status), forma_pagamento = VALUES(forma_pagamento),
               data_pagamento = VALUES(data_pagamento), valor_pago = VALUES(valor_pago),
               link_boleto = VALUES(link_boleto), invoice_url = VALUES(invoice_url),
               ultima_sync = NOW()"
        );
        $stmt->execute(array(
            $clientId, $payId, $custId, mb_substr($p['description'] ?? '', 0, 250),
            $p['value'] ?? 0, $p['dueDate'] ?? date('Y-m-d'), $p['status'] ?? 'PENDING',
            $p['billingType'] ?? null, $p['paymentDate'] ?? null, $p['netValue'] ?? null,
            $p['bankSlipUrl'] ?? null, $p['invoiceUrl'] ?? null,
        ));
        if ($stmt->rowCount() === 1) $inserted++; else $updated++;
    }

    $offset += $limit;
    $paginas++;
    if (!($resp['hasMore'] ?? false)) break;
}

flash_set('success', "Sincronização rápida concluída! {$inserted} novas, {$updated} atualizadas (últimos 30 dias).");
redirect(module_url('financeiro'));
