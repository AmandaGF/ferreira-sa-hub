<?php
// Diag global: varre TODOS os clientes do Hub que tem CPF e checa se
// existem cobrancas Asaas nao sincronizadas (incluindo customers duplicados
// no Asaas com mesmo CPF — bug Thais Rodrigues, 26/05/2026).
//
// Modo dry-run (default): so reporta o que esta fora de sincronia.
// Modo aplicar: /conecta/diag_asaas_global.php?key=...&aplicar=1
//   -> faz UPSERT das cobrancas faltantes em todos os clientes afetados

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/asaas_helper.php';
$pdo = db();

@ini_set('max_execution_time', 600);
set_time_limit(600);

header('Content-Type: text/plain; charset=utf-8');

$aplicar = !empty($_GET['aplicar']);
$so_id   = (int)($_GET['client_id'] ?? 0); // se quiser testar 1 cliente

echo "=== DIAG ASAAS GLOBAL " . ($aplicar ? '(MODO APLICAR)' : '(dry-run)') . " ===\n\n";

// Pega todos clientes com CPF ou asaas_customer_id
$sql = "SELECT id, name, cpf, asaas_customer_id FROM clients WHERE (cpf IS NOT NULL AND cpf != '') OR (asaas_customer_id IS NOT NULL AND asaas_customer_id != '')";
if ($so_id) $sql .= " AND id = " . $so_id;
$sql .= " ORDER BY id";
$cls = $pdo->query($sql)->fetchAll();

echo "Clientes a analisar: " . count($cls) . "\n\n";

$totalNovas = 0; $clientesAfetados = 0; $erros = 0;

$upsert = $pdo->prepare(
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

$stmtCheck = $pdo->prepare("SELECT 1 FROM asaas_cobrancas WHERE asaas_payment_id = ? LIMIT 1");

foreach ($cls as $cli) {
    $cid = (int)$cli['id'];
    $cpfLimpo = preg_replace('/\D/', '', $cli['cpf'] ?? '');

    // Monta lista de customers a consultar
    $custIds = array();
    if (!empty($cli['asaas_customer_id'])) $custIds[$cli['asaas_customer_id']] = true;
    if (strlen($cpfLimpo) >= 11) {
        $rc = asaas_request('GET', '/customers?cpfCnpj=' . urlencode($cpfLimpo) . '&limit=20');
        if ($rc && !empty($rc['data'])) {
            foreach ($rc['data'] as $c) { if (!empty($c['id'])) $custIds[$c['id']] = true; }
        }
    }
    if (empty($custIds)) continue;
    $custIds = array_keys($custIds);

    // Pra cada customer, busca payments
    $faltantes = 0; $totalCust = 0; $cobsFaltantes = array();
    foreach ($custIds as $custId) {
        $offset = 0; $paginas = 0;
        while ($paginas < 20) {
            $resp = asaas_request('GET', '/payments?customer=' . urlencode($custId) . '&limit=100&offset=' . $offset);
            if (!$resp || isset($resp['error'])) { $erros++; break; }
            $lista = $resp['data'] ?? array();
            if (empty($lista)) break;
            $totalCust += count($lista);
            foreach ($lista as $p) {
                if (empty($p['id'])) continue;
                $stmtCheck->execute(array($p['id']));
                if (!$stmtCheck->fetchColumn()) {
                    $faltantes++;
                    $cobsFaltantes[] = array('cust' => $custId, 'pay' => $p);
                }
            }
            $offset += 100; $paginas++;
            if (!($resp['hasMore'] ?? false)) break;
        }
    }

    if ($faltantes > 0) {
        $clientesAfetados++;
        $totalNovas += $faltantes;
        echo sprintf("#%-5d %s\n", $cid, $cli['name']);
        echo sprintf("       CPF=%s  customers Asaas=[%s]\n", $cli['cpf'] ?: '-', implode(', ', $custIds));
        echo sprintf("       %d cobranca(s) no Asaas  ·  %d FALTANTE(S) no Hub\n", $totalCust, $faltantes);

        if ($aplicar) {
            $ins = 0;
            foreach ($cobsFaltantes as $cf) {
                $p = $cf['pay'];
                try {
                    $upsert->execute(array(
                        $cid, $p['id'], $cf['cust'],
                        mb_substr((string)($p['description'] ?? ''), 0, 250),
                        $p['value'] ?? 0,
                        $p['dueDate'] ?? date('Y-m-d'),
                        $p['status'] ?? 'PENDING',
                        $p['billingType'] ?? null,
                        $p['paymentDate'] ?? null,
                        $p['netValue'] ?? null,
                        $p['bankSlipUrl'] ?? null,
                        $p['invoiceUrl'] ?? null,
                    ));
                    if ($upsert->rowCount() === 1) $ins++;
                } catch (Throwable $e) {
                    echo "       ERRO: " . $e->getMessage() . "\n";
                }
            }
            echo "       -> $ins novas inseridas no Hub\n";

            // Se cliente nao tinha asaas_customer_id ou tinha o duplicado, salva o
            // primeiro customer encontrado (mantem o original se ja tinha, so adiciona se vazio)
            if (empty($cli['asaas_customer_id']) && !empty($custIds[0])) {
                $pdo->prepare("UPDATE clients SET asaas_customer_id = ?, asaas_sincronizado = 1 WHERE id = ?")
                    ->execute(array($custIds[0], $cid));
                echo "       -> asaas_customer_id setado pra " . $custIds[0] . "\n";
            }
        }
        echo "\n";
    }
}

echo "\n=== RESUMO ===\n";
echo "Clientes com cobrancas faltando: $clientesAfetados\n";
echo "Total de cobrancas a sincronizar: $totalNovas\n";
echo "Erros de API Asaas: $erros\n";

if (!$aplicar && $clientesAfetados > 0) {
    echo "\nPra aplicar (UPSERT das faltantes): /conecta/diag_asaas_global.php?key=fsa-hub-deploy-2026&aplicar=1\n";
} elseif ($aplicar) {
    echo "\nAPLICADO. Pode verificar no /modules/financeiro/cobrancas.php\n";
    audit_log('asaas_sync_global', 'system', 0, "afetados={$clientesAfetados} novas={$totalNovas}");
}
