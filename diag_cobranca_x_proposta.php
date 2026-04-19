<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$clientId = (int)($_GET['client_id'] ?? 917);
echo "=== Diagnóstico — Cliente id={$clientId} ===\n";

$c = $pdo->prepare("SELECT id, name, cpf, asaas_customer_id FROM clients WHERE id = ?");
$c->execute(array($clientId));
$cli = $c->fetch();
if (!$cli) { die("Cliente não encontrado\n"); }
echo "Cliente: {$cli['name']} (CPF {$cli['cpf']}, Asaas: {$cli['asaas_customer_id']})\n\n";

echo "━━━ 1. asaas_cobrancas (tabela Asaas) ━━━\n";
$asaas = $pdo->prepare("SELECT asaas_payment_id, descricao, valor, valor_pago, vencimento, status FROM asaas_cobrancas WHERE client_id = ? ORDER BY vencimento ASC");
$asaas->execute(array($clientId));
$ac = $asaas->fetchAll();
$totalAsaasVencido = 0; $totalAsaasPendente = 0; $totalAsaasPago = 0;
foreach ($ac as $r) {
    $v = (float)$r['valor'];
    $vp = (float)$r['valor_pago'];
    echo sprintf("  %-20s %-30s  %-10s  venc=%s  val=R$ %-9s  pago=R$ %-9s\n",
        $r['asaas_payment_id'], mb_substr($r['descricao']??'', 0, 30), $r['status'],
        $r['vencimento'], number_format($v,2,',','.'), number_format($vp,2,',','.'));
    if ($r['status'] === 'OVERDUE') $totalAsaasVencido += $v;
    elseif ($r['status'] === 'PENDING') $totalAsaasPendente += $v;
    elseif (in_array($r['status'], array('RECEIVED','CONFIRMED','RECEIVED_IN_CASH'), true)) $totalAsaasPago += ($vp ?: $v);
}
echo sprintf("\n  TOTAL Asaas VENCIDO:  R$ %s\n", number_format($totalAsaasVencido,2,',','.'));
echo sprintf("  TOTAL Asaas PENDENTE: R$ %s\n", number_format($totalAsaasPendente,2,',','.'));
echo sprintf("  TOTAL Asaas PAGO:     R$ %s\n", number_format($totalAsaasPago,2,',','.'));

echo "\n━━━ 2. honorarios_cobranca (tabela do Kanban) ━━━\n";
$hc = $pdo->prepare("SELECT id, asaas_payment_id, tipo_debito, valor_total, valor_pago, vencimento, status FROM honorarios_cobranca WHERE client_id = ? ORDER BY vencimento ASC");
$hc->execute(array($clientId));
$hcRows = $hc->fetchAll();
$totalHcAberto = 0; $totalHcMulta = 0; $totalHcJuros = 0;
foreach ($hcRows as $r) {
    $saldo = (float)$r['valor_total'] - (float)$r['valor_pago'];
    $dias = (int)((time() - strtotime($r['vencimento'])) / 86400);
    $multa = $dias > 0 ? $saldo * 0.20 : 0;
    $juros = $dias > 0 ? $saldo * 0.01 * ($dias/30) : 0;
    echo sprintf("  #%-4s %-28s  %-15s  venc=%s  saldo=R$ %-9s  atraso=%sd  multa=R$ %-8s  juros=R$ %-8s\n",
        $r['id'], mb_substr($r['tipo_debito']??'', 0, 28), $r['status'],
        $r['vencimento'], number_format($saldo,2,',','.'), $dias,
        number_format($multa,2,',','.'), number_format($juros,2,',','.'));
    if (!in_array($r['status'], array('pago','cancelado'), true)) {
        $totalHcAberto += $saldo;
        $totalHcMulta += $multa;
        $totalHcJuros += $juros;
    }
}
echo sprintf("\n  TOTAL HC NOMINAL:    R$ %s\n", number_format($totalHcAberto,2,',','.'));
echo sprintf("  TOTAL HC MULTA 20%%:  R$ %s\n", number_format($totalHcMulta,2,',','.'));
echo sprintf("  TOTAL HC JUROS 1%%:   R$ %s\n", number_format($totalHcJuros,2,',','.'));
echo sprintf("  TOTAL HC ATUALIZADO: R$ %s\n", number_format($totalHcAberto + $totalHcMulta + $totalHcJuros,2,',','.'));

echo "\n━━━ 3. Comparação ━━━\n";
echo sprintf("Asaas VENCIDO:  R$ %s\n", number_format($totalAsaasVencido,2,',','.'));
echo sprintf("Kanban ABERTO:  R$ %s (diferença: R$ %s)\n",
    number_format($totalHcAberto,2,',','.'),
    number_format(abs($totalAsaasVencido - $totalHcAberto),2,',','.'));

echo "\n━━━ 4. Cruzamento por asaas_payment_id ━━━\n";
$asaasIds = array_column($ac, 'asaas_payment_id');
$hcIds = array_column($hcRows, 'asaas_payment_id');
$naoImportadas = array_diff(array_filter($asaasIds), array_filter($hcIds));
$semMatchAsaas = array_diff(array_filter($hcIds), array_filter($asaasIds));
echo "Cobranças Asaas que NÃO estão no Kanban: " . count($naoImportadas) . "\n";
foreach ($naoImportadas as $id) echo "  - {$id}\n";
echo "Entradas no Kanban que NÃO têm match no Asaas: " . count($semMatchAsaas) . "\n";
foreach ($semMatchAsaas as $id) echo "  - {$id}\n";

// Entradas HC sem asaas_payment_id
$hcManuais = array_filter($hcRows, function($r){ return empty($r['asaas_payment_id']); });
echo "\nEntradas manuais no Kanban (sem asaas_payment_id): " . count($hcManuais) . "\n";
foreach ($hcManuais as $r) echo "  #{$r['id']} {$r['tipo_debito']} — R$ {$r['valor_total']} venc {$r['vencimento']}\n";
