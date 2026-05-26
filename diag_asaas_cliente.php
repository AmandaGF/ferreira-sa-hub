<?php
// Diag: estado da integracao Asaas pra um cliente
// Uso: /conecta/diag_asaas_cliente.php?key=fsa-hub-deploy-2026&nome=Thais

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('forbidden');
}

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/asaas_helper.php';
$pdo = db();

header('Content-Type: text/plain; charset=utf-8');

$nome = trim($_GET['nome'] ?? 'Thais Rodrigues');

echo "=== DIAG ASAAS: $nome ===\n\n";

// 1) Acha cliente no Hub
$st = $pdo->prepare("SELECT id, name, cpf, asaas_customer_id, asaas_sincronizado FROM clients WHERE name LIKE ? ORDER BY id");
$st->execute(array('%' . $nome . '%'));
$cls = $st->fetchAll();

if (!$cls) { echo "Nenhum cliente encontrado com nome '$nome'.\n"; exit; }

echo "Clientes no Hub:\n";
foreach ($cls as $c) {
    echo sprintf("  #%d  %s  CPF=%s  asaas_customer_id=%s  sync=%s\n",
        $c['id'], $c['name'], $c['cpf'] ?: '(vazio)', $c['asaas_customer_id'] ?: '(NULL)', $c['asaas_sincronizado'] ? 'sim' : 'nao');
}

$cli = $cls[0]; // pega o primeiro
$cid = (int)$cli['id'];
$cust = $cli['asaas_customer_id'];

echo "\n--- Analisando cliente #$cid ---\n\n";

// 2) Cobrancas no banco local
$st = $pdo->prepare("SELECT COUNT(*) FROM asaas_cobrancas WHERE client_id = ?");
$st->execute(array($cid));
echo "Cobrancas no Hub (asaas_cobrancas): " . $st->fetchColumn() . "\n";

// 3) Se nao tem asaas_customer_id, tenta vincular agora
if (!$cust && $cli['cpf']) {
    echo "\n[!] Cliente sem asaas_customer_id. Tentando vincular agora...\n";
    $r = vincular_cliente_asaas($cid);
    if (isset($r['error'])) {
        echo "    ERRO: " . $r['error'] . "\n";
    } else {
        echo "    OK: vinculado como " . $r['id'] . ($r['novo'] ?? false ? ' (criado novo)' : ($r['ja_vinculado'] ?? false ? ' (ja existia)' : ' (achou no Asaas)')) . "\n";
        $cust = $r['id'];
    }
}

if (!$cust) { echo "\nSem customer_id, nao da pra sincronizar cobrancas.\n"; exit; }

// 4a) Lista TODOS os customers no Asaas com o nome similar (achar duplicados)
echo "\n--- Customers no Asaas com nome '$nome' ---\n";
$resp = asaas_request('GET', '/customers?name=' . urlencode($nome) . '&limit=50');
if ($resp && isset($resp['data'])) {
    foreach ($resp['data'] as $c) {
        $vinculado = ($c['id'] === $cust) ? ' [VINCULADO AO HUB]' : '';
        echo sprintf("  %s  CPF=%s  Nome='%s'  Email=%s%s\n",
            $c['id'], $c['cpfCnpj'] ?? '?', $c['name'] ?? '?', $c['email'] ?? '-', $vinculado);
        // Quantas cobrancas tem cada um?
        $rp = asaas_request('GET', '/payments?customer=' . urlencode($c['id']) . '&limit=100');
        $qtd = $rp && isset($rp['data']) ? count($rp['data']) : 0;
        $totQtd = $rp && isset($rp['totalCount']) ? (int)$rp['totalCount'] : $qtd;
        echo "      -> $totQtd cobranca(s) no Asaas\n";
    }
} else {
    echo "  (erro ou sem resposta)\n";
}

// 4) Busca cobrancas no Asaas (sem filtro de data)
echo "\n--- Buscando cobrancas no Asaas para customer=$cust (atual vinculo) ---\n";
$offset = 0; $totalApi = 0; $paginas = 0; $todos = array();
while ($paginas < 20) {
    $resp = asaas_request('GET', '/payments?customer=' . urlencode($cust) . '&limit=100&offset=' . $offset);
    if (!$resp || isset($resp['error'])) { echo "  ERRO Asaas: " . ($resp['error'] ?? 'sem resposta') . "\n"; break; }
    $lista = $resp['data'] ?? array();
    if (empty($lista)) break;
    foreach ($lista as $p) {
        $todos[] = $p;
        $totalApi++;
    }
    if (!($resp['hasMore'] ?? false)) break;
    $offset += 100; $paginas++;
}

echo "  Total no Asaas: $totalApi cobrancas\n";
foreach ($todos as $p) {
    echo sprintf("    %s  %s  R$ %s  %s\n",
        substr($p['id'], 0, 12) . '...',
        $p['dueDate'] ?? '?',
        number_format((float)($p['value'] ?? 0), 2, ',', '.'),
        $p['status'] ?? '?'
    );
}

// 5) Pra cada uma, ja esta no Hub?
echo "\n--- Comparando com Hub ---\n";
$novas = 0; $jaTem = 0;
foreach ($todos as $p) {
    $payId = $p['id'] ?? '';
    if (!$payId) continue;
    $stmt = $pdo->prepare("SELECT id FROM asaas_cobrancas WHERE asaas_payment_id = ?");
    $stmt->execute(array($payId));
    if ($stmt->fetch()) $jaTem++; else $novas++;
}
echo "  Ja no Hub: $jaTem · Faltam sincronizar: $novas\n";

echo "\n=== FIM ===\n";
echo "\nPra sincronizar essas $novas faltantes, va em /conecta/modules/financeiro/cliente.php?id=$cid e clique '🔄 Sincronizar com Asaas'\n";
echo "Ou rode: /conecta/diag_asaas_cliente.php?key=fsa-hub-deploy-2026&nome=" . urlencode($nome) . "&forcar=1\n";

// 6) Forcar sync se ?forcar=1
if (!empty($_GET['forcar'])) {
    echo "\n[!] Modo forcar=1: aplicando UPSERT agora...\n";
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
    $ins = 0; $upd = 0;
    foreach ($todos as $p) {
        $payId = $p['id'] ?? ''; if (!$payId) continue;
        $upsert->execute(array(
            $cid, $payId, $cust,
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
        if ($upsert->rowCount() === 1) $ins++; else $upd++;
    }
    echo "    UPSERT OK: $ins novas, $upd atualizadas\n";
}
