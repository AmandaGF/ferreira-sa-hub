<?php
/**
 * Script pra limpar dados de teste criados HOJE vinculados a Luiz Eduardo.
 * Cancela cobranças no Asaas, remove do cache local, apaga contratos_financeiros,
 * leads e casos criados hoje vinculados a esse cliente.
 *
 * USO:
 *   1. Dry-run (mostra o que VAI apagar):
 *      /conecta/limpar_testes_luiz.php?key=fsa-hub-deploy-2026
 *   2. Executar de fato:
 *      /conecta/limpar_testes_luiz.php?key=fsa-hub-deploy-2026&confirm=SIM_APAGAR
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/asaas_helper.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$confirm = ($_GET['confirm'] ?? '') === 'SIM_APAGAR';
$hoje = date('Y-m-d');

echo "=== LIMPEZA DE TESTES — Luiz Eduardo ===\n";
echo "Data: $hoje\n";
echo "Modo: " . ($confirm ? '🔴 EXECUTANDO APAGAMENTO' : '👁️ DRY-RUN (nada será apagado)') . "\n\n";

// Descobrir o(s) client_id do Luiz Eduardo
$clientes = $pdo->query("SELECT id, name, cpf FROM clients WHERE name LIKE '%Luiz Eduardo%' ORDER BY id")->fetchAll();
if (empty($clientes)) { echo "Nenhum cliente 'Luiz Eduardo' encontrado.\n"; exit; }
echo "Clientes encontrados:\n";
foreach ($clientes as $cl) echo "  #{$cl['id']} — {$cl['name']} (CPF {$cl['cpf']})\n";
$clientIds = array_column($clientes, 'id');
$inCli = implode(',', array_map('intval', $clientIds));
echo "\n";

// ─── 1. Cobranças criadas HOJE (asaas_cobrancas) ───
$cob = $pdo->query(
    "SELECT id, asaas_payment_id, valor, vencimento, status, created_at
     FROM asaas_cobrancas
     WHERE client_id IN ($inCli) AND DATE(created_at) = '$hoje'
     ORDER BY id DESC"
)->fetchAll();
echo "[1] Cobranças criadas hoje (asaas_cobrancas): " . count($cob) . "\n";
foreach ($cob as $c) {
    echo "    - #{$c['id']} | {$c['asaas_payment_id']} | R$ {$c['valor']} | venc {$c['vencimento']} | status {$c['status']}\n";
}

// ─── 2. Contratos financeiros criados HOJE ───
$contratos = $pdo->query(
    "SELECT id, tipo_honorario, valor_total, num_parcelas, asaas_subscription_id, data_fechamento, created_at
     FROM contratos_financeiros
     WHERE client_id IN ($inCli) AND DATE(created_at) = '$hoje'
     ORDER BY id DESC"
)->fetchAll();
echo "\n[2] Contratos financeiros criados hoje: " . count($contratos) . "\n";
foreach ($contratos as $c) {
    echo "    - #{$c['id']} | {$c['tipo_honorario']} | R$ {$c['valor_total']} em {$c['num_parcelas']}x";
    if ($c['asaas_subscription_id']) echo " | assinatura {$c['asaas_subscription_id']}";
    echo "\n";
}

// ─── 3. Casos (processos) criados HOJE ───
$casos = $pdo->query(
    "SELECT id, title, case_number, status, created_at
     FROM cases
     WHERE client_id IN ($inCli) AND DATE(created_at) = '$hoje'
     ORDER BY id DESC"
)->fetchAll();
echo "\n[3] Processos criados hoje: " . count($casos) . "\n";
foreach ($casos as $c) {
    echo "    - #{$c['id']} | {$c['title']} | status {$c['status']}" . ($c['case_number'] ? " | nº {$c['case_number']}" : '') . "\n";
}

// ─── 4. Leads criados HOJE ───
$leads = $pdo->query(
    "SELECT id, name, stage, created_at, converted_at, linked_case_id
     FROM pipeline_leads
     WHERE client_id IN ($inCli) AND DATE(created_at) = '$hoje'
     ORDER BY id DESC"
)->fetchAll();
echo "\n[4] Leads criados hoje: " . count($leads) . "\n";
foreach ($leads as $l) {
    echo "    - #{$l['id']} | {$l['name']} | stage {$l['stage']}\n";
}

// ─── 5. Baixas (audit_log de cobranca_baixa_manual de hoje) ───
$baixas = $pdo->query(
    "SELECT al.id, al.created_at, al.details, al.entity_id AS cobranca_id, ac.asaas_payment_id, ac.valor, ac.status
     FROM audit_log al
     LEFT JOIN asaas_cobrancas ac ON ac.id = al.entity_id
     WHERE al.action = 'cobranca_baixa_manual'
       AND DATE(al.created_at) = '$hoje'
       AND ac.client_id IN ($inCli)
     ORDER BY al.id DESC"
)->fetchAll();
echo "\n[5] Baixas manuais dadas hoje: " . count($baixas) . "\n";
foreach ($baixas as $b) {
    echo "    - audit#{$b['id']} cobranca#{$b['cobranca_id']} ({$b['asaas_payment_id']}) | {$b['details']}\n";
}

echo "\n================================================\n";

if (!$confirm) {
    echo "\n👁️ DRY-RUN apenas. Para apagar de verdade, acrescente &confirm=SIM_APAGAR na URL.\n";
    echo "AÇÕES que serão executadas ao confirmar:\n";
    echo "  a) Cada cobrança ATIVA no Asaas (PENDING/OVERDUE) será CANCELADA via API\n";
    echo "  b) Registros de asaas_cobrancas acima serão DELETADOS\n";
    echo "  c) Contratos financeiros acima serão DELETADOS\n";
    echo "  d) Casos criados hoje serão DELETADOS (e suas tasks/docs)\n";
    echo "  e) Leads criados hoje serão DELETADOS\n";
    echo "  (Clientes NÃO serão apagados — só dados transacionais)\n";
    exit;
}

// ══════════ EXECUÇÃO REAL ══════════
echo "🔴 EXECUTANDO...\n\n";
$pdo->beginTransaction();
try {
    // a) Cancelar cobranças ativas no Asaas
    $cancelAsaas = 0; $errosAsaas = 0;
    foreach ($cob as $c) {
        if (in_array($c['status'], array('PENDING','OVERDUE'), true) && $c['asaas_payment_id']) {
            $r = asaas_delete('/payments/' . urlencode($c['asaas_payment_id']));
            if (isset($r['error'])) {
                echo "  ⚠️ Erro cancelando {$c['asaas_payment_id']}: {$r['error']}\n";
                $errosAsaas++;
            } else {
                $cancelAsaas++;
            }
        }
    }
    echo "  a) $cancelAsaas cobranças canceladas no Asaas ($errosAsaas erros)\n";

    // a2) Cancelar assinaturas recorrentes criadas hoje
    $cancelSubs = 0;
    foreach ($contratos as $ct) {
        if ($ct['asaas_subscription_id']) {
            $r = asaas_delete('/subscriptions/' . urlencode($ct['asaas_subscription_id']));
            if (!isset($r['error'])) $cancelSubs++;
            else echo "  ⚠️ Erro cancelando assinatura {$ct['asaas_subscription_id']}: {$r['error']}\n";
        }
    }
    echo "  a2) $cancelSubs assinaturas canceladas no Asaas\n";

    // b) Apagar asaas_cobrancas locais
    $delCob = $pdo->prepare("DELETE FROM asaas_cobrancas WHERE client_id IN ($inCli) AND DATE(created_at) = ?");
    $delCob->execute(array($hoje));
    echo "  b) {$delCob->rowCount()} cobranças removidas do cache local\n";

    // c) Apagar contratos_financeiros
    $delCtr = $pdo->prepare("DELETE FROM contratos_financeiros WHERE client_id IN ($inCli) AND DATE(created_at) = ?");
    $delCtr->execute(array($hoje));
    echo "  c) {$delCtr->rowCount()} contratos financeiros removidos\n";

    // d) Apagar casos (cascade em documentos_pendentes, case_tasks, case_andamentos etc via FK ou manual)
    foreach ($casos as $caso) {
        $cid = (int)$caso['id'];
        try { $pdo->exec("DELETE FROM documentos_pendentes WHERE case_id = $cid"); } catch (Exception $e) {}
        try { $pdo->exec("DELETE FROM case_tasks WHERE case_id = $cid"); } catch (Exception $e) {}
        try { $pdo->exec("DELETE FROM case_andamentos WHERE case_id = $cid"); } catch (Exception $e) {}
        try { $pdo->exec("DELETE FROM case_partes WHERE case_id = $cid"); } catch (Exception $e) {}
        $pdo->exec("DELETE FROM cases WHERE id = $cid");
    }
    echo "  d) " . count($casos) . " processos removidos (com suas tasks/docs/andamentos)\n";

    // e) Apagar leads (e document_history vinculado se tiver)
    foreach ($leads as $l) {
        $lid = (int)$l['id'];
        try { $pdo->exec("DELETE FROM document_history WHERE lead_id = $lid"); } catch (Exception $e) {}
        $pdo->exec("DELETE FROM pipeline_leads WHERE id = $lid");
    }
    echo "  e) " . count($leads) . " leads removidos\n";

    $pdo->commit();
    echo "\n✅ LIMPEZA CONCLUÍDA.\n";
    audit_log_simple($pdo, 'limpeza_testes_luiz', count($cob) + count($casos) + count($leads) . ' registros');
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ ERRO — ROLLBACK executado: " . $e->getMessage() . "\n";
    exit(1);
}

function audit_log_simple($pdo, $action, $details) {
    try {
        $pdo->prepare("INSERT INTO audit_log (user_id, action, entity, details, created_at) VALUES (NULL, ?, 'system', ?, NOW())")
            ->execute(array($action, $details));
    } catch (Exception $e) {}
}
