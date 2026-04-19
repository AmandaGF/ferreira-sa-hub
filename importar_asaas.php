<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
set_time_limit(900);
ignore_user_abort(true);
ob_implicit_flush(true);
@ob_end_flush();

$pdo = db();
$fase = $_GET['fase'] ?? 'tudo'; // clientes | cobrancas | tudo

echo "=== Importar histórico Asaas (fase={$fase}) ===\n\n";

// Config
$rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('asaas_api_key','asaas_env')")->fetchAll();
$cfg = array();
foreach ($rows as $r) $cfg[$r['chave']] = $r['valor'];
$apiKey = $cfg['asaas_api_key'] ?? '';
$env    = $cfg['asaas_env'] ?? 'sandbox';
if (!$apiKey) { die("ERRO: chave Asaas não configurada\n"); }
$base = $env === 'production' ? 'https://api.asaas.com/v3' : 'https://sandbox.asaas.com/api/v3';
echo "Ambiente: {$env}\nBase: {$base}\n\n";

function asaas_get($url, $apiKey) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => array('access_token: ' . $apiKey, 'Content-Type: application/json'),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $b = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($c < 200 || $c >= 300) return null;
    return json_decode($b, true);
}

function digits($s) { return preg_replace('/\D/', '', (string)$s); }

// ─────────── FASE 1: CLIENTES ───────────
if ($fase === 'clientes' || $fase === 'tudo') {
    echo "━━━ FASE 1: CLIENTES ━━━\n";
    $offset = 0; $limit = 100;
    $vinculados = 0; $criados = 0; $ja = 0; $total = 0;

    while (true) {
        $data = asaas_get($base . '/customers?limit=' . $limit . '&offset=' . $offset, $apiKey);
        if (!$data || empty($data['data'])) break;
        $lista = $data['data'];
        $total += count($lista);
        echo "Lote offset={$offset} — " . count($lista) . " clientes...\n";

        foreach ($lista as $c) {
            $asaasId = $c['id'] ?? ''; if (!$asaasId) continue;
            $nome    = $c['name'] ?? '';
            $cpf     = digits($c['cpfCnpj'] ?? '');
            $email   = strtolower(trim($c['email'] ?? ''));
            $phone   = digits($c['mobilePhone'] ?? ($c['phone'] ?? ''));

            // Já vinculado?
            $exist = $pdo->prepare("SELECT id FROM clients WHERE asaas_customer_id = ? LIMIT 1");
            $exist->execute(array($asaasId));
            if ($exist->fetch()) { $ja++; continue; }

            // Tenta match por CPF/CNPJ (normalizado) OU email
            $match = null;
            if ($cpf) {
                $q = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cpf,''),'.',''),'-',''),'/',''),' ','') = ? LIMIT 1");
                $q->execute(array($cpf));
                $match = $q->fetchColumn() ?: null;
            }
            if (!$match && $email) {
                $q = $pdo->prepare("SELECT id FROM clients WHERE LOWER(email) = ? LIMIT 1");
                $q->execute(array($email));
                $match = $q->fetchColumn() ?: null;
            }

            if ($match) {
                $pdo->prepare("UPDATE clients SET asaas_customer_id = ?, asaas_sincronizado = 1 WHERE id = ?")
                    ->execute(array($asaasId, $match));
                $vinculados++;
            } else {
                $pdo->prepare(
                    "INSERT INTO clients (name, cpf, email, phone, source, notes, asaas_customer_id, asaas_sincronizado, created_at)
                     VALUES (?, ?, ?, ?, 'asaas_import', 'Importado do Asaas em " . date('d/m/Y') . "', ?, 1, NOW())"
                )->execute(array($nome, $cpf ?: null, $email ?: null, $phone ?: null, $asaasId));
                $criados++;
            }
        }

        $offset += $limit;
        if (!($data['hasMore'] ?? false)) break;
    }

    echo "\nTotal processado: {$total}\n";
    echo " ✓ Já estavam vinculados: {$ja}\n";
    echo " ✓ Vinculados a clients existentes: {$vinculados}\n";
    echo " ✓ Novos clients criados: {$criados}\n\n";
}

// ─────────── FASE 2: COBRANÇAS ───────────
if ($fase === 'cobrancas' || $fase === 'tudo') {
    echo "━━━ FASE 2: COBRANÇAS ━━━\n";
    $offset = (int)($_GET['offset'] ?? 0);
    $maxPages = (int)($_GET['max_pages'] ?? 0); // 0 = sem limite
    $limit = 100;
    $inseridas = 0; $atualizadas = 0; $total = 0;
    $pagina = 0;
    echo "Iniciando em offset={$offset}, max_pages=" . ($maxPages ?: 'ilimitado') . "\n";

    // Mapa asaas_customer_id → client_id (pra associar rápido)
    $map = array();
    foreach ($pdo->query("SELECT id, asaas_customer_id FROM clients WHERE asaas_customer_id IS NOT NULL")->fetchAll() as $r) {
        $map[$r['asaas_customer_id']] = (int)$r['id'];
    }
    echo "Mapa de " . count($map) . " clientes Asaas vinculados.\n";

    while (true) {
        $data = asaas_get($base . '/payments?limit=' . $limit . '&offset=' . $offset, $apiKey);
        if (!$data || empty($data['data'])) break;
        $lista = $data['data'];
        $total += count($lista);
        echo "Lote offset={$offset} — " . count($lista) . " pagamentos...\n";

        foreach ($lista as $p) {
            $payId  = $p['id'] ?? ''; if (!$payId) continue;
            $custId = $p['customer'] ?? '';
            $clientId = $map[$custId] ?? null;

            $dueDate = $p['dueDate'] ?? date('Y-m-d');
            $payDate = $p['paymentDate'] ?? null;
            $status  = $p['status'] ?? 'PENDING';
            $valor   = $p['value'] ?? 0;
            $valorPg = $p['netValue'] ?? null;
            $desc    = mb_substr($p['description'] ?? '', 0, 250);
            $bt      = $p['billingType'] ?? null;
            $invUrl  = $p['invoiceUrl'] ?? null;
            $boleto  = $p['bankSlipUrl'] ?? null;
            $pix     = isset($p['pixQrCode']) ? ($p['pixQrCode']['encodedImage'] ?? null) : null;
            if (!$pix) $pix = $p['pixTransaction']['qrCode']['payload'] ?? null;

            // INSERT ... ON DUPLICATE KEY UPDATE (chave: asaas_payment_id UNIQUE)
            $stmt = $pdo->prepare(
                "INSERT INTO asaas_cobrancas
                   (client_id, asaas_payment_id, asaas_customer_id, descricao, valor,
                    vencimento, status, forma_pagamento, data_pagamento, valor_pago,
                    link_boleto, invoice_url, ultima_sync, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                   client_id = VALUES(client_id),
                   asaas_customer_id = VALUES(asaas_customer_id),
                   descricao = VALUES(descricao),
                   valor = VALUES(valor),
                   vencimento = VALUES(vencimento),
                   status = VALUES(status),
                   forma_pagamento = VALUES(forma_pagamento),
                   data_pagamento = VALUES(data_pagamento),
                   valor_pago = VALUES(valor_pago),
                   link_boleto = VALUES(link_boleto),
                   invoice_url = VALUES(invoice_url),
                   ultima_sync = NOW()"
            );
            $stmt->execute(array(
                $clientId, $payId, $custId, $desc, $valor,
                $dueDate, $status, $bt, $payDate, $valorPg,
                $boleto, $invUrl,
            ));
            // rowCount 1=insert, 2=update no MySQL ON DUPLICATE
            if ($stmt->rowCount() === 1) $inseridas++;
            else $atualizadas++;
        }

        $offset += $limit;
        $pagina++;
        if (!($data['hasMore'] ?? false)) { echo "[FIM — sem mais dados]\n"; break; }
        if ($maxPages && $pagina >= $maxPages) { echo "[PARADA — atingiu max_pages={$maxPages}, próximo offset={$offset}]\n"; break; }
    }

    echo "\nTotal processado neste lote: {$total}\n";
    echo " ✓ Novas inseridas: {$inseridas}\n";
    echo " ✓ Atualizadas (já existiam): {$atualizadas}\n";
    echo " → Próximo offset pra continuar: {$offset}\n\n";
}

echo "=== FIM ===\n";
