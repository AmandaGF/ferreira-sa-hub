<?php
/**
 * Sync Asaas em MASSA — percorre TODOS os clientes com asaas_customer_id
 * (ou que tenham CPF que casa com customer Asaas) e roda o mesmo motor do
 * sync_cliente que ja existe em modules/financeiro/api.php.
 *
 * Modos:
 *   ?key=fsa-hub-deploy-2026                                  -> simular (default)
 *   ?key=fsa-hub-deploy-2026&modo=executar                    -> aplicar
 *   ?key=fsa-hub-deploy-2026&modo=executar&apenas=cpf         -> so clientes COM cpf
 *   ?key=fsa-hub-deploy-2026&modo=executar&pular_recente=1    -> pula clientes com ultima_sync < 24h
 *   ?key=fsa-hub-deploy-2026&modo=executar&desde=N            -> processa a partir do N-esimo (continuar timeout)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/asaas_helper.php';
require_once __DIR__ . '/core/functions_utils.php';

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: text/html; charset=utf-8');
@ob_implicit_flush(true);
while (@ob_end_flush()) {}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$modo = ($_GET['modo'] ?? 'simular') === 'executar' ? 'executar' : 'simular';
$apenasCpf = isset($_GET['apenas']) && $_GET['apenas'] === 'cpf';
$pularRecente = isset($_GET['pular_recente']) && $_GET['pular_recente'] === '1';
$desde = max(0, (int)($_GET['desde'] ?? 0));
$maxClientesPorRun = max(10, (int)($_GET['max'] ?? 200));

echo '<!doctype html><meta charset="utf-8">';
echo '<style>
body{font-family:system-ui,sans-serif;background:#f8f4ef;color:#052228;padding:1.5rem;max-width:1100px;margin:0 auto;}
h1{color:#052228;border-bottom:3px solid #B87333;padding-bottom:.5rem;}
.modo{padding:.7rem 1rem;border-radius:8px;font-weight:700;text-align:center;margin-bottom:1rem;}
.modo-s{background:#fef3c7;color:#92400e;border:2px solid #f59e0b;}
.modo-e{background:#fee2e2;color:#7f1d1d;border:2px solid #dc2626;}
.linha{padding:.45rem .65rem;border-bottom:1px solid #e5e7eb;font-size:.82rem;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap;}
.ok{color:#065f46;}.fail{color:#7f1d1d;}.skip{color:#92400e;}
.bd{font-weight:700;}
code{background:#e5e7eb;padding:1px 5px;border-radius:3px;font-size:.78rem;}
.resumo{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin-top:1.5rem;}
.kpis{display:grid;grid-template-columns:repeat(5,1fr);gap:.6rem;margin-bottom:1rem;}
.kpi{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:.6rem;text-align:center;}
.kpi-v{font-size:1.4rem;font-weight:800;color:#052228;}
.kpi-l{font-size:.7rem;color:#6b7280;text-transform:uppercase;letter-spacing:.4px;}
</style>';

echo '<h1>Sync Asaas — TODOS os clientes</h1>';
echo '<div class="modo modo-' . ($modo === 'executar' ? 'e' : 's') . '">';
echo $modo === 'executar' ? '⚠️ EXECUTANDO — chamando Asaas API pra cada cliente' : '📋 SIMULANDO — apenas lista o que seria processado';
echo '</div>';

// Self-heal: coluna ultima_sync_em em clients (pra pular_recente)
try { $pdo->exec("ALTER TABLE clients ADD COLUMN asaas_ultima_sync_em DATETIME NULL"); } catch (Exception $e) {}

// Pega clientes elegiveis
$where = array("(asaas_customer_id IS NOT NULL AND asaas_customer_id != '')");
if ($apenasCpf) $where[] = "(cpf IS NOT NULL AND cpf != '')";
if ($pularRecente) {
    $where[] = "(asaas_ultima_sync_em IS NULL OR asaas_ultima_sync_em < DATE_SUB(NOW(), INTERVAL 24 HOUR))";
}
$whereSql = implode(' AND ', $where);

$st = $pdo->query("SELECT id, name, cpf, asaas_customer_id, asaas_ultima_sync_em FROM clients WHERE $whereSql ORDER BY id");
$todos = $st->fetchAll();
$totalElegiveis = count($todos);
echo '<p><strong>' . $totalElegiveis . '</strong> clientes elegíveis (com asaas_customer_id' . ($pularRecente ? ', não sincronizados nas últimas 24h' : '') . ').</p>';

if ($desde > 0) echo '<p style="color:#92400e;">⏩ Pulando os ' . $desde . ' primeiros (continuação de run anterior).</p>';
echo '<p style="color:#6b7280;font-size:.85rem;">Processando até <code>' . $maxClientesPorRun . '</code> por run. Se chegar no limite, abrir continuação com <code>&desde=' . ($desde + $maxClientesPorRun) . '</code>.</p>';

$processados = 0; $skipados = 0; $erros = 0; $totalApi = 0; $novas = 0; $atualizadas = 0;
$slice = array_slice($todos, $desde, $maxClientesPorRun);

echo '<hr><div id="log">';

foreach ($slice as $idx => $cli) {
    $posReal = $desde + $idx + 1;
    echo '<div class="linha">';
    echo '<span class="bd">[' . $posReal . '/' . $totalElegiveis . ']</span>';
    echo ' <code>#' . $cli['id'] . '</code> ';
    echo '<span style="flex:1;min-width:200px;">' . htmlspecialchars(mb_substr($cli['name'], 0, 50)) . '</span>';
    echo '<code>' . htmlspecialchars($cli['asaas_customer_id']) . '</code>';

    if ($modo === 'simular') {
        echo ' <span class="skip">— simular, não chama API</span>';
        echo '</div>';
        flush();
        continue;
    }

    // Coleta customers Asaas pelo CPF tambem (cinto+suspensorio)
    $custIds = array($cli['asaas_customer_id'] => true);
    if (!empty($cli['cpf'])) {
        $cpfLimpo = preg_replace('/\D/', '', $cli['cpf']);
        if (strlen($cpfLimpo) >= 11) {
            try {
                $rc = asaas_request('GET', '/customers?cpfCnpj=' . urlencode($cpfLimpo) . '&limit=20');
                if ($rc && !empty($rc['data'])) {
                    foreach ($rc['data'] as $c) if (!empty($c['id'])) $custIds[$c['id']] = true;
                }
            } catch (Exception $e) {}
        }
    }
    $custIds = array_keys($custIds);

    $cliInserted = 0; $cliUpdated = 0; $cliApi = 0; $erroMsg = '';
    try {
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
        foreach ($custIds as $cId) {
            $offset = 0; $limit = 100; $paginas = 0;
            while ($paginas < 20) {
                $resp = asaas_request('GET', '/payments?customer=' . urlencode($cId) . '&limit=' . $limit . '&offset=' . $offset);
                if (!$resp || isset($resp['error'])) {
                    $erroMsg = 'API: ' . ($resp['error'] ?? 'sem resposta');
                    break 2;
                }
                $lista = $resp['data'] ?? array();
                if (empty($lista)) break;
                $cliApi += count($lista);
                foreach ($lista as $p) {
                    $payId = $p['id'] ?? ''; if (!$payId) continue;
                    $upsert->execute(array(
                        $cli['id'], $payId, $cId,
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
                    if ($upsert->rowCount() === 1) $cliInserted++;
                    else $cliUpdated++;
                }
                $offset += $limit;
                $paginas++;
                if (!($resp['hasMore'] ?? false)) break;
            }
        }
        $pdo->prepare("UPDATE clients SET asaas_ultima_sync_em = NOW() WHERE id = ?")->execute(array($cli['id']));
    } catch (Exception $e) {
        $erroMsg = $e->getMessage();
    }

    if ($erroMsg) {
        echo ' <span class="fail">✗ ' . htmlspecialchars($erroMsg) . '</span>';
        $erros++;
    } else {
        $novas += $cliInserted;
        $atualizadas += $cliUpdated;
        $totalApi += $cliApi;
        $processados++;
        echo ' <span class="ok">✓ API ' . $cliApi . ' · +' . $cliInserted . ' novas · ↻' . $cliUpdated . ' atualiz.</span>';
        if (count($custIds) > 1) echo ' <span style="color:#6b7280;font-size:.72rem;">(' . count($custIds) . ' customers)</span>';
    }
    echo '</div>';
    flush();

    // Throttle leve pra nao explodir Asaas
    if ($modo === 'executar') usleep(150000); // 150ms entre clientes
}

echo '</div>';

echo '<div class="resumo"><h3>Resumo</h3>';
echo '<div class="kpis">';
echo '<div class="kpi"><div class="kpi-v">' . $processados . '</div><div class="kpi-l">Processados</div></div>';
echo '<div class="kpi"><div class="kpi-v">' . $totalApi . '</div><div class="kpi-l">Cobranças API</div></div>';
echo '<div class="kpi"><div class="kpi-v" style="color:#059669;">+' . $novas . '</div><div class="kpi-l">Novas inseridas</div></div>';
echo '<div class="kpi"><div class="kpi-v" style="color:#0e7490;">↻' . $atualizadas . '</div><div class="kpi-l">Atualizadas</div></div>';
echo '<div class="kpi"><div class="kpi-v" style="color:' . ($erros ? '#dc2626' : '#6b7280') . ';">' . $erros . '</div><div class="kpi-l">Erros</div></div>';
echo '</div>';

$proximoDesde = $desde + $maxClientesPorRun;
if ($proximoDesde < $totalElegiveis && $modo === 'executar') {
    $urlNext = '?key=fsa-hub-deploy-2026&modo=executar&desde=' . $proximoDesde . ($pularRecente ? '&pular_recente=1' : '') . ($apenasCpf ? '&apenas=cpf' : '') . '&max=' . $maxClientesPorRun;
    echo '<p style="margin-top:1rem;">📌 Ainda faltam <strong>' . ($totalElegiveis - $proximoDesde) . ' clientes</strong>. Continue com: <a href="' . htmlspecialchars($urlNext) . '" style="background:#052228;color:#fff;padding:6px 12px;border-radius:6px;text-decoration:none;font-weight:600;">Próximos ' . min($maxClientesPorRun, $totalElegiveis - $proximoDesde) . ' →</a></p>';
}

if ($modo === 'simular') {
    echo '<p style="margin-top:1rem;background:#fef3c7;padding:.6rem .9rem;border-radius:6px;color:#92400e;">📋 Simulação. Use <code>?modo=executar</code> pra aplicar.</p>';
} else if ($processados > 0) {
    try { audit_log('asaas_sync_global', 'clients', null, "lote desde=$desde processados=$processados novas=$novas upd=$atualizadas erros=$erros"); } catch (Exception $e) {}
}
echo '</div>';
