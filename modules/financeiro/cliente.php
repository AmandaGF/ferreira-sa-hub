<?php
/**
 * Ferreira & Sá Hub — Cobranças por Cliente (Asaas)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

// TEMP DIAG (04/07): com &debugkey=... mostra o erro na tela (só pra quem tem a chave)
if (($_GET['debugkey'] ?? '') === 'fsa-hub-deploy-2026') { error_reporting(E_ALL); ini_set('display_errors', '1'); }

// TEMP DIAG (04/07): captura erro fatal desta página pra rastrear o 500 no fluxo
// "R$ Cobrar". Remover depois. Grava em uploads/cliente_last_error.log (web-legível).
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        $linha = '[' . date('Y-m-d H:i:s') . "]\nMSG: " . $err['message'] . "\nFILE: " . $err['file'] . ':' . $err['line']
            . "\nGET: " . json_encode($_GET) . "\n\n----\n";
        // /files é gravável (o servidor guarda logs lá); uploads às vezes não é
        foreach (array(dirname(__DIR__, 2) . '/files', dirname(__DIR__, 2) . '/uploads', sys_get_temp_dir()) as $dir) {
            if (@file_put_contents($dir . '/cliente_last_error.log', $linha, FILE_APPEND) !== false) break;
        }
    }
});
// 30/06/2026 Amanda: financeiro POR CLIENTE liberado pra todos (era restrito a
// Amanda/Rodrigo/Luiz). Painel GERAL continua com can_access_financeiro().
if (!can_view_cliente_financeiro()) { redirect(url('modules/dashboard/')); }

require_once __DIR__ . '/../../core/asaas_helper.php';

$pdo = db();
$clientId = (int)($_GET['id'] ?? 0);

// Se veio de "R$ Cobrar" do pipeline (from_lead=X), resolve o client_id pelo lead
$fromLeadId = (int)($_GET['from_lead'] ?? 0);
if (!$clientId && $fromLeadId) {
    $st = $pdo->prepare("SELECT client_id FROM pipeline_leads WHERE id = ?");
    $st->execute(array($fromLeadId));
    $clientId = (int)$st->fetchColumn();
    if (!$clientId) { flash_set('error', 'Lead #' . $fromLeadId . ' não está vinculado a um cliente. Vincule primeiro.'); redirect(module_url('pipeline')); }
}

if (!$clientId) { flash_set('error', 'Cliente não informado.'); redirect(module_url('financeiro')); }
$abrirNovaCobranca = (($_GET['abrir_nova_cobranca'] ?? '') === '1');

// 30/06/2026 Amanda: tela liberada pra todos (consulta), mas AÇÕES (criar
// cobrança, sincronizar Asaas, alterar vencimento, dar baixa, cancelar,
// vincular em lote) continuam restritas a quem tem acesso ao financeiro full
// (Amanda/Rodrigo/Luiz). Demais usuários veem em modo só-leitura.
$_podeEditarFin = function_exists('can_access_financeiro') && can_access_financeiro();

// Filtro opcional por processo específico (quando vindo do caso_ver.php)
$fromCaseId = (int)($_GET['from_case'] ?? 0);
$filtroCase = null;
if ($fromCaseId) {
    $fc = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE id = ? AND client_id = ?");
    $fc->execute(array($fromCaseId, $clientId));
    $filtroCase = $fc->fetch();
    if (!$filtroCase) { $fromCaseId = 0; } // Não pertence ao cliente → ignora
}

$stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->execute(array($clientId));
$client = $stmt->fetch();
if (!$client) { flash_set('error', 'Cliente não encontrado.'); redirect(module_url('financeiro')); }

$pageTitle = 'Financeiro — ' . $client['name'];

// Vincular/sincronizar se tem CPF
$asaasId = $client['asaas_customer_id'] ?: null;
$vinculoErro = '';
if (!$asaasId && $client['cpf']) {
    $vinculo = vincular_cliente_asaas($clientId);
    if (isset($vinculo['error'])) { $vinculoErro = $vinculo['error']; }
    else { $asaasId = $vinculo['id']; }
}

// Sincronizar cobranças se vinculado
if ($asaasId) { sync_cobrancas_cliente($clientId, $asaasId); }

// Cobranças do cliente (com filtro opcional por case_id)
$cobrancas = array();
try {
    if ($fromCaseId) {
        // Inclui cobranças de combo vinculadas a este processo (asaas_cobranca_cases)
        $sqlCob = "SELECT * FROM asaas_cobrancas ac WHERE ac.client_id = ?
                     AND (ac.case_id = ? OR EXISTS(SELECT 1 FROM asaas_cobranca_cases jc WHERE jc.cobranca_id = ac.id AND jc.case_id = ?))
                   ORDER BY ac.vencimento DESC";
        $stmtCob = $pdo->prepare($sqlCob);
        $stmtCob->execute(array($clientId, $fromCaseId, $fromCaseId));
    } else {
        $sqlCob = "SELECT * FROM asaas_cobrancas WHERE client_id = ? ORDER BY vencimento DESC";
        $stmtCob = $pdo->prepare($sqlCob);
        $stmtCob->execute(array($clientId));
    }
    $cobrancas = $stmtCob->fetchAll();
} catch (Exception $e) {}

// Processos do cliente (pra vincular cobranças)
$processosCliente = array();
try {
    $stmtProc = $pdo->prepare("SELECT id, title, case_number, status FROM cases WHERE client_id = ? ORDER BY created_at DESC");
    $stmtProc->execute(array($clientId));
    $processosCliente = $stmtProc->fetchAll();
} catch (Exception $e) {}
// Mapa id→título curto e processos EXTRAS de cada cobrança (combo)
$procNome = array();
foreach ($processosCliente as $pr) { $procNome[(int)$pr['id']] = $pr['title'] ?: ('Processo #' . $pr['id']); }
$cobExtrasMap = array();
if (!empty($cobrancas)) {
    $idsCob = array_map(function($c){ return (int)$c['id']; }, $cobrancas);
    $inCob = implode(',', array_fill(0, count($idsCob), '?'));
    try {
        $stEx = $pdo->prepare("SELECT cobranca_id, case_id FROM asaas_cobranca_cases WHERE cobranca_id IN ($inCob)");
        $stEx->execute($idsCob);
        foreach ($stEx->fetchAll() as $r) { $cobExtrasMap[(int)$r['cobranca_id']][] = (int)$r['case_id']; }
    } catch (Exception $e) {}
}

// ═══ Pré-preencher modal Nova Cobrança ═══
// Busca lead mais recente do cliente pra trazer: valor, forma_pagamento, num_parcelas, vencimento, case_type
$preFill = array(
    'valor' => '', 'forma' => 'PIX', 'parcelas' => 1, 'tipo' => 'unica',
    'vencimento' => date('Y-m-d', strtotime('+3 days')), 'descricao' => 'Honorários Advocatícios',
    'case_id' => 0, 'modo_valor' => 'total',
);
try {
    $stmtLead = $pdo->prepare(
        "SELECT honorarios_cents, valor_acao, num_parcelas, forma_pagamento, vencimento_parcela, case_type, linked_case_id
         FROM pipeline_leads WHERE client_id = ? ORDER BY COALESCE(converted_at, created_at) DESC LIMIT 1"
    );
    $stmtLead->execute(array($clientId));
    $lead = $stmtLead->fetch();
    if ($lead) {
        // Valor: prefere honorarios_cents (numérico). Envia como VALOR TOTAL (modo_valor=total).
        if (!empty($lead['honorarios_cents'])) {
            $preFill['valor'] = number_format($lead['honorarios_cents'] / 100, 2, ',', '.');
        }
        $preFill['parcelas'] = max(1, (int)($lead['num_parcelas'] ?? 1));
        // Tipo deduzido: 1 parcela = única; 2+ = parcelada (nunca recorrente automático — Amanda escolhe)
        $preFill['tipo'] = $preFill['parcelas'] > 1 ? 'parcelado' : 'unica';
        // Forma pagamento: mapeia do texto do lead pro código Asaas
        $fp = mb_strtoupper($lead['forma_pagamento'] ?? '');
        if (strpos($fp, 'BOLETO') !== false) $preFill['forma'] = 'BOLETO';
        elseif (strpos($fp, 'PIX') !== false) $preFill['forma'] = 'PIX';
        elseif (strpos($fp, 'CARTÃO') !== false || strpos($fp, 'CARTAO') !== false || strpos($fp, 'CRÉDITO') !== false || strpos($fp, 'CREDITO') !== false) $preFill['forma'] = 'CREDIT_CARD';
        // Vencimento: se vencimento_parcela do lead for data válida, usa
        $vp = $lead['vencimento_parcela'] ?? '';
        if ($vp) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $vp)) $preFill['vencimento'] = $vp;
            elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $vp, $m)) $preFill['vencimento'] = $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Descrição: usa tipo de ação se houver
        if (!empty($lead['case_type'])) $preFill['descricao'] = 'Honorários — ' . $lead['case_type'];
        // Processo: se já tem linked_case_id, pré-seleciona. Senão, pega o primeiro processo ativo do cliente.
        if (!empty($lead['linked_case_id'])) {
            $preFill['case_id'] = (int)$lead['linked_case_id'];
        } elseif (!empty($processosCliente)) {
            $preFill['case_id'] = (int)$processosCliente[0]['id'];
        }
    } elseif (!empty($processosCliente)) {
        $preFill['case_id'] = (int)$processosCliente[0]['id'];
    }
} catch (Exception $e) {}

// Contratos (filtra por case_id se vindo do caso_ver.php)
$contratos = array();
try {
    if ($fromCaseId) {
        $stmtCt = $pdo->prepare(
            "SELECT cf.*, cs.title as case_title FROM contratos_financeiros cf LEFT JOIN cases cs ON cs.id = cf.case_id WHERE cf.client_id = ? AND cf.case_id = ? ORDER BY cf.created_at DESC"
        );
        $stmtCt->execute(array($clientId, $fromCaseId));
    } else {
        $stmtCt = $pdo->prepare(
            "SELECT cf.*, cs.title as case_title FROM contratos_financeiros cf LEFT JOIN cases cs ON cs.id = cf.case_id WHERE cf.client_id = ? ORDER BY cf.created_at DESC"
        );
        $stmtCt->execute(array($clientId));
    }
    $contratos = $stmtCt->fetchAll();
} catch (Exception $e) {}

// Resumo
$totalContratado = 0; $totalPago = 0; $totalPendente = 0; $totalVencido = 0;
foreach ($cobrancas as $c) {
    if (in_array($c['status'], array('RECEIVED','CONFIRMED','RECEIVED_IN_CASH'))) $totalPago += (float)($c['valor_pago'] ?: $c['valor']);
    elseif ($c['status'] === 'PENDING') $totalPendente += (float)$c['valor'];
    elseif ($c['status'] === 'OVERDUE') $totalVencido += (float)$c['valor'];
}
foreach ($contratos as $ct) { $totalContratado += (float)$ct['valor_total']; }

require_once APP_ROOT . '/templates/layout_start.php';
echo voltar_ao_processo_html();
?>

<style>
.fin-header { background:linear-gradient(135deg,#052228,#0d3640); border-radius:var(--radius-lg); padding:1.25rem 1.5rem; color:#fff; margin-bottom:1.25rem; }
.fin-header h2 { font-size:1.1rem; font-weight:800; margin-bottom:.25rem; }
.fin-header .meta { font-size:.8rem; color:rgba(255,255,255,.6); }
.fin-resumo { display:grid; grid-template-columns:repeat(4,1fr); gap:.75rem; margin-bottom:1.25rem; }
.fin-resumo-card { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.85rem 1rem; text-align:center; }
.fin-resumo-val { font-size:1.2rem; font-weight:800; }
.fin-resumo-label { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; }
.cob-item { display:flex; align-items:center; gap:1rem; padding:.75rem 1rem; border-bottom:1px solid var(--border); }
.cob-item:last-child { border-bottom:none; }
.cob-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:.68rem; font-weight:700; color:#fff; }
@media (max-width:768px) { .fin-resumo { grid-template-columns:repeat(2,1fr); } }
</style>

<a href="<?= module_url('financeiro') ?>" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;">← Voltar ao Financeiro</a>

<?php if ($filtroCase): ?>
<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius-md);padding:.6rem .85rem;margin-bottom:.75rem;display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;">
    <div style="font-size:.8rem;color:#1e40af;">
        🔗 <strong>Filtrado pelo processo:</strong> <?= e($filtroCase['title'] ?: 'Processo #' . $filtroCase['id']) ?>
        <?php if ($filtroCase['case_number']): ?><span style="color:#64748b;font-size:.72rem;">(<?= e($filtroCase['case_number']) ?>)</span><?php endif; ?>
    </div>
    <a href="<?= module_url('financeiro', 'cliente.php?id=' . $clientId) ?>" style="font-size:.72rem;background:#1e40af;color:#fff;padding:4px 10px;border-radius:4px;text-decoration:none;font-weight:600;">Ver todas as cobranças do cliente →</a>
</div>
<?php endif; ?>

<!-- Header -->
<div class="fin-header">
    <h2><?= e($client['name']) ?></h2>
    <div class="meta">
        CPF: <?= e($client['cpf'] ?: 'Não cadastrado') ?>
        · Asaas: <?= $asaasId ? '<span style="color:#059669;">✓ Vinculado (' . e($asaasId) . ')</span>' : '<span style="color:#f59e0b;">Não vinculado</span>' ?>
        <?php if ($vinculoErro): ?><span style="color:#dc2626;"> — <?= e($vinculoErro) ?></span><?php endif; ?>
    </div>
    <div style="margin-top:.5rem;display:flex;gap:.5rem;flex-wrap:wrap;">
        <?php if ($client['phone']): ?>
        <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $client['phone']) ?>',nome:<?= e(json_encode($client['name'])) ?>,clientId:<?= (int)$client['id'] ?>,canal:'24',mensagem:''})" class="btn btn-success btn-sm" style="font-size:.72rem;">💬 WhatsApp</button>
        <?php endif; ?>
        <a href="<?= module_url('clientes', 'ver.php?id=' . $clientId) ?>" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);font-size:.72rem;">👤 Ver cadastro</a>
        <?php if (($totalPendente + $totalVencido) > 0): ?>
        <a href="<?= module_url('financeiro', 'proposta.php?id=' . $clientId) ?>" class="btn btn-sm" style="background:#b45309;color:#fff;font-size:.72rem;font-weight:700;">📄 Gerar Proposta de Acordo</a>
        <?php endif; ?>
    </div>
</div>

<!-- Resumo -->
<div class="fin-resumo">
    <div class="fin-resumo-card"><div class="fin-resumo-val">R$ <?= number_format($totalContratado, 2, ',', '.') ?></div><div class="fin-resumo-label">Total Contratado</div></div>
    <div class="fin-resumo-card"><div class="fin-resumo-val" style="color:#059669;">R$ <?= number_format($totalPago, 2, ',', '.') ?></div><div class="fin-resumo-label">Total Pago</div></div>
    <div class="fin-resumo-card"><div class="fin-resumo-val" style="color:#f59e0b;">R$ <?= number_format($totalPendente, 2, ',', '.') ?></div><div class="fin-resumo-label">Pendente</div></div>
    <div class="fin-resumo-card"><div class="fin-resumo-val" style="color:#dc2626;">R$ <?= number_format($totalVencido, 2, ',', '.') ?></div><div class="fin-resumo-label">Vencido</div></div>
</div>

<!-- Cobranças -->
<div style="background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border);margin-bottom:1.25rem;">
    <div style="padding:1rem 1.15rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <h4 style="font-size:.88rem;font-weight:700;color:var(--petrol-900);">
            Cobranças (<?= count($cobrancas) ?>)
            <?php if ($filtroCase): ?><span style="font-size:.7rem;font-weight:500;color:#1e40af;">— só deste processo</span><?php endif; ?>
        </h4>
        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
            <?php if ($_podeEditarFin): ?>
                <?php if (!empty($client['asaas_customer_id'])): ?>
                <button onclick="sincronizarClienteAsaas(<?= (int)$clientId ?>, this)" class="btn btn-outline btn-sm" style="font-size:.72rem;" title="Busca TODAS as cobranças deste cliente no Asaas (sem limite de data) e atualiza no Hub. Útil quando faltam parcelas antigas.">🔄 Sincronizar com Asaas</button>
                <?php endif; ?>
                <button onclick="document.getElementById('modalNovaCob').style.display='flex'; if(window.atualizarCobUI2) setTimeout(atualizarCobUI2, 0);" class="btn btn-primary btn-sm" style="background:#B87333;font-size:.72rem;" title="Abre com dados do último lead pré-preenchidos — você só confere e ajusta">+ Nova Cobrança</button>
            <?php else: ?>
                <span style="font-size:.7rem;color:#94a3b8;font-style:italic;" title="Você está em modo só-leitura. Pra criar/alterar cobranças, fale com Amanda/Rodrigo/Luiz.">👁️ Só-leitura</span>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($cobrancas) && !empty($processosCliente) && $_podeEditarFin): ?>
    <!-- Vínculo em LOTE (bulk) — aparece só se tem cobranças + processos + pode editar -->
    <div style="padding:.55rem 1.15rem;background:#eff6ff;border-bottom:1px solid #bfdbfe;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;font-size:.75rem;">
        <strong style="color:#1e40af;">🔗 Vincular em lote:</strong>
        <select id="bulkVincCase" style="font-size:.75rem;padding:3px 6px;border:1px solid #93c5fd;border-radius:4px;background:#fff;min-width:200px;">
            <option value="">— Escolher processo —</option>
            <option value="0">Desvincular todas (sem processo)</option>
            <?php foreach ($processosCliente as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>"><?= e(mb_substr($pr['title'] ?: ('Processo #' . $pr['id']), 0, 60)) ?><?= $pr['case_number'] ? ' (' . e(substr($pr['case_number'], 0, 20)) . ')' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <select id="bulkVincEscopo" style="font-size:.75rem;padding:3px 6px;border:1px solid #93c5fd;border-radius:4px;background:#fff;">
            <option value="todas">Todas as cobranças</option>
            <option value="sem_vinculo">Só as sem vínculo</option>
            <option value="pendentes_vencidas">Só pendentes + vencidas</option>
        </select>
        <button type="button" onclick="vincularTodasAoProcesso(<?= (int)$clientId ?>)" class="btn btn-primary btn-sm" style="background:#1e40af;font-size:.72rem;">✓ Aplicar</button>
        <span style="font-size:.68rem;color:#64748b;margin-left:auto;">ℹ️ Ou use o dropdown individual em cada linha abaixo</span>
    </div>
    <?php endif; ?>

    <?php if (empty($cobrancas)): ?>
        <div style="text-align:center;padding:2rem;color:var(--text-muted);">Nenhuma cobrança registrada.</div>
    <?php else: ?>
        <?php foreach ($cobrancas as $cob):
            $cor = asaas_status_cor($cob['status']);
            $label = asaas_status_label($cob['status']);
        ?>
        <div class="cob-item" style="flex-wrap:wrap;">
            <div style="width:10px;height:10px;border-radius:50%;background:<?= $cor ?>;flex-shrink:0;"></div>
            <div style="flex:1;min-width:200px;">
                <div style="font-size:.85rem;font-weight:600;"><?= e($cob['descricao'] ?: 'Cobrança') ?></div>
                <div style="font-size:.7rem;color:var(--text-muted);">
                    <?= $cob['forma_pagamento'] ? strtoupper($cob['forma_pagamento']) . ' · ' : '' ?>
                    Vencimento: <?= date('d/m/Y', strtotime($cob['vencimento'])) ?>
                    <?php if ($cob['data_pagamento']): ?> · Pago em: <?= date('d/m/Y', strtotime($cob['data_pagamento'])) ?><?php endif; ?>
                </div>
                <?php if (!empty($processosCliente)): ?>
                <div style="margin-top:3px;display:flex;align-items:center;gap:4px;">
                    <span style="font-size:.62rem;color:var(--text-muted);">🔗 Processo:</span>
                    <?php if ($_podeEditarFin): ?>
                    <select onchange="vincularCobrancaProcesso(<?= (int)$cob['id'] ?>, this.value)" style="font-size:.68rem;padding:1px 5px;border:1px solid #e5e7eb;border-radius:4px;background:<?= $cob['case_id'] ? '#eff6ff' : '#f9fafb' ?>;color:<?= $cob['case_id'] ? '#1e40af' : '#6b7280' ?>;">
                        <option value="0" <?= empty($cob['case_id']) ? 'selected' : '' ?>>— Sem vínculo (histórico)</option>
                        <?php foreach ($processosCliente as $pr): ?>
                        <option value="<?= $pr['id'] ?>" <?= (int)$cob['case_id'] === (int)$pr['id'] ? 'selected' : '' ?>><?= e(mb_substr($pr['title'] ?? 'Processo #' . $pr['id'], 0, 40)) ?><?= $pr['case_number'] ? ' (' . e(substr($pr['case_number'], 0, 20)) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <?php
                    // Modo só-leitura: mostra texto, não dropdown
                    $_pNome = '— Sem vínculo';
                    foreach ($processosCliente as $pr) {
                        if ((int)$cob['case_id'] === (int)$pr['id']) { $_pNome = mb_substr($pr['title'] ?? 'Processo #' . $pr['id'], 0, 40); break; }
                    }
                    ?>
                    <span style="font-size:.68rem;color:<?= $cob['case_id'] ? '#1e40af' : '#94a3b8' ?>;font-weight:<?= $cob['case_id'] ? '600' : '400' ?>;"><?= e($_pNome) ?></span>
                    <?php endif; ?>
                </div>
                <?php
                // Combo: processos EXTRAS desta cobrança (1 contrato cobre 2+ processos)
                $_extras = $cobExtrasMap[(int)$cob['id']] ?? array();
                if (($_podeEditarFin && count($processosCliente) > 1) || $_extras):
                ?>
                <div style="margin-top:3px;display:flex;align-items:center;gap:4px;flex-wrap:wrap;">
                    <span style="font-size:.62rem;color:var(--text-muted);">➕ Combo:</span>
                    <?php if ($_extras): foreach ($_extras as $_ex): ?>
                        <span style="font-size:.6rem;background:#ede9fe;color:#6b21a8;padding:1px 6px;border-radius:8px;font-weight:600;"><?= e(mb_substr($procNome[$_ex] ?? ('#' . $_ex), 0, 22)) ?></span>
                    <?php endforeach; else: ?>
                        <span style="font-size:.6rem;color:#cbd5e1;">só o processo principal</span>
                    <?php endif; ?>
                    <?php if ($_podeEditarFin && count($processosCliente) > 1): ?>
                    <button type="button" onclick="abrirComboProcessos(<?= (int)$cob['id'] ?>)" style="font-size:.58rem;background:#f3e8ff;color:#6b21a8;border:1px solid #e9d5ff;border-radius:8px;padding:1px 7px;cursor:pointer;font-weight:700;">✏️ editar</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.95rem;font-weight:800;color:<?= $cor ?>;">R$ <?= number_format($cob['valor'], 2, ',', '.') ?></div>
                <span class="cob-badge" style="background:<?= $cor ?>;"><?= $label ?></span>
            </div>
            <div style="display:flex;gap:4px;flex-shrink:0;align-items:center;flex-wrap:wrap;">
                <?php if ($cob['invoice_url']): ?><a href="<?= e($cob['invoice_url']) ?>" target="_blank" style="font-size:.7rem;background:#052228;color:#fff;padding:3px 8px;border-radius:4px;text-decoration:none;">Fatura</a><?php endif; ?>
                <?php if ($cob['status'] === 'PENDING' || $cob['status'] === 'OVERDUE'): ?>
                    <?php if ($client['phone'] && $cob['invoice_url']):
                        $msgCob = "Olá " . $client['name'] . ", segue o link da sua cobrança:\n" . $cob['invoice_url'] . "\n\nValor: R$ " . number_format($cob['valor'], 2, ',', '.') . "\nVencimento: " . date('d/m/Y', strtotime($cob['vencimento'])) . "\n\n_Ferreira & Sá Advocacia_";
                    ?>
                    <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $client['phone']) ?>',nome:<?= e(json_encode($client['name'])) ?>,clientId:<?= (int)$client['id'] ?>,canal:'24',mensagem:<?= e(json_encode($msgCob)) ?>})" style="font-size:.7rem;background:#25D366;color:#fff;padding:3px 8px;border-radius:4px;border:none;cursor:pointer;">Enviar</button>
                    <?php endif; ?>
                    <?php if ($_podeEditarFin): ?>
                    <button type="button" title="Alterar data de vencimento"
                            onclick="cobAcaoSafe(<?= (int)$cob['id'] ?>, 'vencto', '<?= e($cob['vencimento']) ?>', <?= e(json_encode($client['name'])) ?>, <?= (float)$cob['valor'] ?>)"
                            style="background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:4px;padding:3px 8px;font-size:.66rem;font-weight:700;cursor:pointer;">📅</button>
                    <button type="button" title="Dar baixa manual (receber em dinheiro/transferência fora do Asaas)"
                            onclick="cobAcaoSafe(<?= (int)$cob['id'] ?>, 'baixa', '<?= e($cob['vencimento']) ?>', <?= e(json_encode($client['name'])) ?>, <?= (float)$cob['valor'] ?>)"
                            style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:4px;padding:3px 8px;font-size:.66rem;font-weight:700;cursor:pointer;">✓</button>
                    <button type="button" title="Cancelar cobrança no Asaas"
                            onclick="cobAcaoSafe(<?= (int)$cob['id'] ?>, 'cancelar', '<?= e($cob['vencimento']) ?>', <?= e(json_encode($client['name'])) ?>, <?= (float)$cob['valor'] ?>)"
                            style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:4px;padding:3px 8px;font-size:.66rem;font-weight:700;cursor:pointer;">✕</button>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Contratos -->
<?php if (!empty($contratos)): ?>
<div style="background:var(--bg-card);border-radius:var(--radius-lg);border:1px solid var(--border);padding:1.15rem;margin-bottom:1.25rem;">
    <h4 style="font-size:.88rem;font-weight:700;color:var(--petrol-900);margin-bottom:.75rem;">📝 Contratos (<?= count($contratos) ?>)</h4>
    <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
        <thead><tr style="border-bottom:1px solid var(--border);">
            <th style="text-align:left;padding:.4rem .5rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);">Tipo</th>
            <th style="text-align:left;padding:.4rem .5rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);">Valor Total</th>
            <th style="text-align:left;padding:.4rem .5rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);">Parcelas</th>
            <th style="text-align:left;padding:.4rem .5rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);">Caso</th>
            <th style="text-align:left;padding:.4rem .5rem;font-size:.68rem;text-transform:uppercase;color:var(--text-muted);">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($contratos as $ct): ?>
        <tr style="border-bottom:1px solid rgba(0,0,0,.04);">
            <td style="padding:.4rem .5rem;font-weight:600;"><?= e(ucfirst($ct['tipo_honorario'])) ?></td>
            <td style="padding:.4rem .5rem;">R$ <?= number_format($ct['valor_total'], 2, ',', '.') ?></td>
            <td style="padding:.4rem .5rem;"><?= $ct['num_parcelas'] ?>x R$ <?= number_format($ct['valor_parcela'] ?: 0, 2, ',', '.') ?></td>
            <td style="padding:.4rem .5rem;font-size:.75rem;"><?= e($ct['case_title'] ?: '—') ?></td>
            <td style="padding:.4rem .5rem;"><span class="cob-badge" style="background:<?= $ct['status'] === 'ativo' ? '#059669' : '#6b7280' ?>;"><?= e(ucfirst($ct['status'])) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Modal Nova Cobrança (pré-preenchido com este cliente) -->
<div id="modalNovaCob" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:450px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:1rem;margin-bottom:1rem;">💰 Nova Cobrança — <?= e($client['name']) ?></h3>
    <form method="POST" action="<?= module_url('financeiro', 'api.php') ?>" onsubmit="return travarSubmitCob2(this);">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar_cobranca">
        <input type="hidden" name="client_id" value="<?= $clientId ?>">

        <?php if ($preFill['valor']): ?>
        <div style="background:#ecfdf5;border:1px solid #bbf7d0;border-radius:8px;padding:.5rem .7rem;margin-bottom:.6rem;font-size:.78rem;color:#166534;">
            ✨ <b>Dados do lead preenchidos automaticamente</b> — confira e ajuste se precisar.
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Tipo</label>
                <select name="tipo" class="form-select" id="tipoCob2" onchange="atualizarCobUI2()">
                    <option value="unica" <?= $preFill['tipo']==='unica'?'selected':'' ?>>📄 Única</option>
                    <option value="parcelado" <?= $preFill['tipo']==='parcelado'?'selected':'' ?>>💳 Parcelada (N × — termina)</option>
                    <option value="recorrente" <?= $preFill['tipo']==='recorrente'?'selected':'' ?>>🔄 Recorrente (sem fim)</option>
                </select></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Pagamento</label>
                <select name="forma_pagamento" class="form-select">
                    <option value="PIX" <?= $preFill['forma']==='PIX'?'selected':'' ?>>PIX</option>
                    <option value="BOLETO" <?= $preFill['forma']==='BOLETO'?'selected':'' ?>>Boleto</option>
                    <option value="CREDIT_CARD" <?= $preFill['forma']==='CREDIT_CARD'?'selected':'' ?>>Cartão</option>
                    <option value="UNDEFINED">Todas</option>
                </select></div>
        </div>
        <div id="modoValorWrap2" style="display:none;margin-bottom:.5rem;padding:.4rem .6rem;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">
            <label style="font-size:.68rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.3px;">O valor que vou digitar é...</label>
            <label style="display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;margin-right:1rem;cursor:pointer;">
                <input type="radio" name="modo_valor" value="total" checked onchange="atualizarCobUI2()"> 📊 Total do contrato
            </label>
            <label style="display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;cursor:pointer;">
                <input type="radio" name="modo_valor" value="parcela" onchange="atualizarCobUI2()"> 🧮 Valor de cada parcela
            </label>
        </div>
        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label id="labelValorCob2" style="font-size:.75rem;font-weight:700;">Valor total (R$)</label><input type="text" name="valor" id="valorCob2" class="form-input input-reais" required placeholder="0,00" value="<?= e($preFill['valor']) ?>" oninput="atualizarCobUI2()"></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Vencimento</label><input type="date" name="vencimento" class="form-input" required value="<?= e($preFill['vencimento']) ?>"></div>
        </div>
        <div id="parcCob2" style="display:none;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Parcelas</label><input type="number" name="num_parcelas" id="parcelasCob2" class="form-input" min="2" max="60" value="<?= max(2, $preFill['parcelas']) ?>" oninput="atualizarCobUI2()"></div>
            <div style="flex:1;" id="diaVencWrap2"><label style="font-size:.75rem;font-weight:700;">Dia venc.</label><input type="number" name="dia_vencimento" class="form-input" min="1" max="31" value="10" title="Dia do mês da cobrança recorrente (1 a 31). Em meses sem esse dia, cai no último dia do mês."></div>
        </div>
        <div id="previewCob2" style="display:none;background:#f5ebe0;border-left:3px solid #B87333;padding:.5rem .7rem;margin-bottom:.6rem;border-radius:6px;font-size:.75rem;color:#3f2e1c;"></div>
        <script>
        function atualizarCobUI2(){
            var tipo = document.getElementById('tipoCob2').value;
            // Amanda 08/06/2026: parse SEMPRE como centavos (mesma logica do
            // formatarReais). Antes parseFloat falhava em valores intermediarios
            // tipo '47,900' (interpretava 47.9 em vez de 479). Ver index.php.
            var _vl2 = (document.getElementById('valorCob2').value || '');
            var _digits2 = _vl2.replace(/\D/g, '');
            var valor = _digits2 ? (parseInt(_digits2, 10) / 100) : 0;
            var parc = parseInt(document.getElementById('parcelasCob2').value, 10) || 1;
            var mostrar = (tipo === 'recorrente' || tipo === 'parcelado');
            document.getElementById('parcCob2').style.display = mostrar ? 'flex' : 'none';
            // "Dia venc." não se aplica à recorrente: o dia vem da data de Vencimento acima
            var _dvw2 = document.getElementById('diaVencWrap2');
            if (_dvw2) _dvw2.style.display = (tipo === 'recorrente') ? 'none' : '';

            var modoWrap = document.getElementById('modoValorWrap2');
            var modoTotal = (document.querySelector('#modalNovaCob input[name="modo_valor"][value="total"]') || {}).checked;
            if (tipo === 'parcelado') {
                modoWrap.style.display = 'block';
            } else {
                modoWrap.style.display = 'none';
                if (tipo === 'recorrente') {
                    var rp = document.querySelector('#modalNovaCob input[name="modo_valor"][value="parcela"]');
                    if (rp) rp.checked = true;
                    modoTotal = false;
                }
            }

            var lbl = document.getElementById('labelValorCob2');
            if (tipo === 'parcelado') {
                lbl.innerHTML = modoTotal
                    ? '📊 <u>Valor total do contrato</u> (R$)'
                    : '🧮 Valor de <u>cada parcela</u> (R$)';
            } else if (tipo === 'recorrente') {
                lbl.innerHTML = '💡 Valor de <u>cada mensalidade</u> (R$)';
            } else {
                lbl.innerHTML = 'Valor total (R$)';
            }

            var prev = document.getElementById('previewCob2');
            if (valor > 0 && parc > 1 && mostrar) {
                var total, parcela;
                if (tipo === 'parcelado' && modoTotal) {
                    total = valor; parcela = valor / parc;
                } else {
                    total = valor * parc; parcela = valor;
                }
                var pStr = parcela.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
                var tStr = total.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
                if (tipo === 'parcelado') {
                    prev.innerHTML = '📋 <b>' + parc + ' parcelas de R$ ' + pStr + '</b> = total <b>R$ ' + tStr + '</b>. Vence mensalmente a partir da data escolhida e <b>termina na última parcela</b>.';
                } else {
                    prev.innerHTML = '🔄 Cobrança <b>mensal de R$ ' + pStr + '</b>, sem fim definido. Max ' + parc + ' mensalidades.';
                }
                prev.style.display='block';
            } else { prev.style.display='none'; }
        }
        </script>
        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;">Processo vinculado <span style="color:#dc2626;">*</span></label>
            <?php if (empty($processosCliente)): ?>
                <div style="padding:.55rem .75rem;background:#fef3c7;color:#92400e;border-radius:8px;font-size:.78rem;font-weight:600;">⚠️ Este cliente ainda não tem processo cadastrado. Crie um processo antes de gerar cobrança.</div>
                <input type="hidden" name="case_id" value="">
            <?php else: ?>
                <select name="case_id" id="cobCasePrimary2" class="form-select" required onchange="syncComboExtras2()">
                    <option value="">— Selecione o processo —</option>
                    <?php foreach ($processosCliente as $pr): ?>
                        <option value="<?= (int)$pr['id'] ?>" <?= $preFill['case_id'] === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['title'] ?: 'Processo #' . $pr['id']) ?><?= $pr['case_number'] ? ' (' . e($pr['case_number']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <?php if (count($processosCliente) > 1): ?>
        <!-- Combo: 1 contrato/orçamento cobrindo 2+ processos (ex: alimentos + divórcio) -->
        <div style="margin-bottom:.6rem;">
            <label style="font-size:.72rem;font-weight:700;color:#6b7280;">🔗 Combo — esta cobrança também cobre outros processos? <span style="font-weight:400;color:#9ca3af;">(opcional)</span></label>
            <div id="comboExtras2" style="display:flex;flex-direction:column;gap:.2rem;margin-top:.3rem;max-height:120px;overflow:auto;border:1px solid #eef2f7;border-radius:8px;padding:.4rem .55rem;background:#fafbfc;">
                <?php foreach ($processosCliente as $pr): ?>
                <label class="combo-extra-lbl" data-caseid="<?= (int)$pr['id'] ?>" style="font-size:.75rem;display:flex;align-items:center;gap:.4rem;cursor:pointer;">
                    <input type="checkbox" name="case_ids_extra[]" value="<?= (int)$pr['id'] ?>">
                    <span><?= e(mb_substr($pr['title'] ?: ('Processo #' . $pr['id']), 0, 50)) ?><?= $pr['case_number'] ? ' (' . e(substr($pr['case_number'], 0, 20)) . ')' : '' ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="font-size:.65rem;color:#9ca3af;margin-top:.2rem;">O processo principal já entra automático — marque só os <b>demais</b> processos do mesmo contrato.</div>
        </div>
        <script>
        // Esconde do "combo" o processo que já é o principal (evita marcar ele mesmo)
        function syncComboExtras2(){
            var prim = (document.getElementById('cobCasePrimary2')||{}).value || '';
            document.querySelectorAll('#comboExtras2 .combo-extra-lbl').forEach(function(lbl){
                var isPrim = (lbl.dataset.caseid === prim);
                lbl.style.display = isPrim ? 'none' : 'flex';
                if (isPrim){ var cb = lbl.querySelector('input'); if (cb) cb.checked = false; }
            });
        }
        syncComboExtras2();
        </script>
        <?php endif; ?>
        <div style="margin-bottom:.6rem;"><label style="font-size:.75rem;font-weight:700;">Descrição</label><input type="text" name="descricao" class="form-input" value="<?= e($preFill['descricao']) ?>"></div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalNovaCob').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" id="btnCriarCobranca2" class="btn btn-primary btn-sm" style="background:#B87333;">Criar</button>
        </div>
    </form>
    <script>
    // Amanda 08/06/2026: trava contra double-submit (clicar 2x criava 2 cobrancas no Asaas)
    var _cobSubmitting2 = false;
    function travarSubmitCob2(form) {
        if (_cobSubmitting2) return false;
        _cobSubmitting2 = true;
        var btn = form.querySelector('button[type=submit]');
        if (btn) {
            btn.disabled = true;
            btn.dataset.origText = btn.textContent;
            btn.textContent = '⏳ Criando no Asaas...';
            btn.style.opacity = '.6';
            btn.style.cursor = 'wait';
        }
        setTimeout(function(){
            if (btn && btn.disabled) {
                btn.disabled = false;
                btn.textContent = btn.dataset.origText || 'Criar';
                btn.style.opacity = '';
                btn.style.cursor = '';
                _cobSubmitting2 = false;
            }
        }, 30000);
        return true;
    }
    </script>
</div></div>

<script>
// Vincula TODAS as cobranças do cliente ao processo escolhido (bulk)
// Sincroniza TODAS as cobrancas deste cliente do Asaas (sem limite de data,
// diferente do sync geral que so pega 30 dias). Resolve o caso de cliente
// com contrato antigo + parcelas espalhadas no futuro que nao apareciam
// no Hub (ex: Thais com 12 parcelas).
function sincronizarClienteAsaas(clientId, btn) {
    if (!confirm('Buscar TODAS as cobranças deste cliente no Asaas?\n\nIsso pode levar alguns segundos. Cobranças novas serão importadas e as existentes atualizadas com o status mais recente.')) return;
    var txtOrig = btn.textContent;
    btn.disabled = true;
    btn.textContent = '⏳ Sincronizando...';
    var csrf = window._FSA_CSRF || '<?= generate_csrf_token() ?>';
    var fd = new FormData();
    fd.append('action', 'sync_cliente');
    fd.append('client_id', clientId);
    fd.append('csrf_token', csrf);
    fetch('<?= module_url('financeiro', 'api.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){
        return r.text().then(function(t){ try { return { status:r.status, body:JSON.parse(t) }; } catch(e){ return { status:r.status, body:{ error:'Resposta nao-JSON (status '+r.status+')' } }; } });
    }).then(function(res){
        var d = res.body || {};
        btn.disabled = false;
        btn.textContent = txtOrig;
        if (d.error) { alert('❌ ' + d.error); return; }
        alert('✓ Sincronização concluída!\n\n' +
              'Total no Asaas: ' + (d.total || 0) + '\n' +
              'Novas importadas: ' + (d.novas || 0) + '\n' +
              'Atualizadas: ' + (d.atualizadas || 0));
        location.reload();
    }).catch(function(e){
        btn.disabled = false;
        btn.textContent = txtOrig;
        alert('❌ Erro: ' + e.message);
    });
}

function vincularTodasAoProcesso(clientId) {
    var selCase = document.getElementById('bulkVincCase');
    var selEscopo = document.getElementById('bulkVincEscopo');
    if (!selCase.value && selCase.value !== '0') { alert('Escolha um processo primeiro.'); return; }
    var caseId = selCase.value;
    var escopo = selEscopo.value;

    var desc = caseId === '0' ? 'DESVINCULAR' : 'vincular ao processo escolhido';
    var escLbl = escopo === 'todas' ? 'TODAS as cobranças' : (escopo === 'sem_vinculo' ? 'só as sem vínculo' : 'só as pendentes + vencidas');
    if (!confirm('Confirma ' + desc + ' (' + escLbl + ') deste cliente?')) return;

    var csrf = window._FSA_CSRF || '<?= generate_csrf_token() ?>';
    var fd = new FormData();
    fd.append('action', 'vincular_case_bulk');
    fd.append('client_id', clientId);
    fd.append('case_id', caseId);
    fd.append('apenas', escopo);
    fd.append('csrf_token', csrf);

    fetch('<?= module_url('financeiro', 'api.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){
        return r.text().then(function(t){ try { return { status: r.status, body: JSON.parse(t) }; } catch(e) { return { status: r.status, body: { error: 'Resposta inválida' } }; } });
    }).then(function(res){
        if (res.body.ok) {
            var toast = document.createElement('div');
            toast.textContent = '✓ ' + res.body.atualizadas + ' cobrança(s) atualizada(s).';
            toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#059669;color:#fff;padding:12px 18px;border-radius:8px;font-weight:700;z-index:100000;box-shadow:0 8px 24px rgba(0,0,0,.25);';
            document.body.appendChild(toast);
            setTimeout(function(){ toast.remove(); }, 2000);
            setTimeout(function(){ location.reload(); }, 700);
        } else {
            alert('Falha: ' + (res.body.error || ('HTTP ' + res.status)));
        }
    }).catch(function(e){ alert('Erro de rede: ' + e.message); });
}

// ── Combo: vincular UMA cobrança a MÚLTIPLOS processos ──
function abrirComboProcessos(cobId) {
    var csrf = window._FSA_CSRF || '<?= generate_csrf_token() ?>';
    var fd = new FormData();
    fd.append('action', 'listar_cobranca_processos');
    fd.append('cobranca_id', cobId);
    fd.append('csrf_token', csrf);
    fetch('<?= module_url('financeiro', 'api.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){ return r.json(); }).then(function(d){
        if (!d || d.error) { alert('⚠️ ' + ((d && d.error) || 'Erro')); return; }
        _renderComboModal(cobId, d);
    }).catch(function(e){ alert('Erro de rede: ' + e.message); });
}
function _renderComboModal(cobId, d) {
    var extras = d.extras || [];
    var prim = parseInt(d.primario || 0, 10);
    var linhas = (d.todos || []).map(function(c){
        var id = parseInt(c.id, 10);
        if (id === prim) return ''; // o principal não entra
        var num = c.case_number ? ' (' + c.case_number + ')' : '';
        var ck = extras.indexOf(id) !== -1 ? 'checked' : '';
        var titulo = (c.title || ('Processo #' + id)).replace(/</g,'&lt;');
        return '<label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;padding:.35rem .2rem;cursor:pointer;">'
             + '<input type="checkbox" class="combo-ck" value="' + id + '" ' + ck + '>'
             + '<span>' + titulo + num + '</span></label>';
    }).join('');
    if (!linhas.trim()) linhas = '<div style="font-size:.8rem;color:#6b7280;padding:.5rem 0;">Este cliente só tem 1 processo — nada pra combinar.</div>';
    var ov = document.createElement('div');
    ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:center;justify-content:center;';
    ov.innerHTML = '<div style="background:#fff;border-radius:12px;padding:1.3rem;max-width:420px;width:94%;box-shadow:0 20px 40px rgba(0,0,0,.25);">'
        + '<h3 style="font-size:1rem;margin:0 0 .3rem;">🔗 Combo — processos desta cobrança</h3>'
        + '<div style="font-size:.72rem;color:#6b7280;margin-bottom:.6rem;">Marque os <b>outros</b> processos que esta mesma cobrança/contrato cobre. O processo principal já entra automático.</div>'
        + '<div style="max-height:240px;overflow:auto;border:1px solid #eef2f7;border-radius:8px;padding:.3rem .55rem;background:#fafbfc;">' + linhas + '</div>'
        + '<div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">'
        + '<button type="button" class="btn btn-outline btn-sm" id="comboCancel">Cancelar</button>'
        + '<button type="button" class="btn btn-primary btn-sm" id="comboSave" style="background:#6b21a8;">Salvar</button>'
        + '</div></div>';
    document.body.appendChild(ov);
    var fechar = function(){ ov.remove(); };
    ov.addEventListener('click', function(e){ if (e.target === ov) fechar(); });
    ov.querySelector('#comboCancel').onclick = fechar;
    ov.querySelector('#comboSave').onclick = function(){
        var ids = Array.prototype.slice.call(ov.querySelectorAll('.combo-ck:checked')).map(function(x){ return x.value; });
        var btn = this; btn.disabled = true; btn.textContent = 'Salvando...';
        var csrf = window._FSA_CSRF || '<?= generate_csrf_token() ?>';
        var fd = new FormData();
        fd.append('action', 'vincular_cobranca_processos');
        fd.append('cobranca_id', cobId);
        if (ids.length === 0) fd.append('case_ids', ''); else ids.forEach(function(v){ fd.append('case_ids[]', v); });
        fd.append('csrf_token', csrf);
        fetch('<?= module_url('financeiro', 'api.php') ?>', {
            method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function(r){ return r.json(); }).then(function(res){
            if (res && res.ok) { fechar(); location.reload(); }
            else { alert('Falha: ' + ((res && res.error) || 'erro')); btn.disabled = false; btn.textContent = 'Salvar'; }
        }).catch(function(e){ alert('Erro de rede: ' + e.message); btn.disabled = false; btn.textContent = 'Salvar'; });
    };
}

function vincularCobrancaProcesso(cobId, caseId) {
    // Usa token fresco do heartbeat se disponível, senão o da renderização
    var csrf = window._FSA_CSRF || '<?= generate_csrf_token() ?>';
    var fd = new FormData();
    fd.append('action', 'vincular_case');
    fd.append('cobranca_id', cobId);
    fd.append('case_id', caseId);
    fd.append('csrf_token', csrf);
    fetch('<?= module_url('financeiro', 'api.php') ?>', {
        method: 'POST', body: fd, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function(r){
        return r.text().then(function(t){
            try { return { status: r.status, body: JSON.parse(t) }; }
            catch(e) { return { status: r.status, body: { error: 'Resposta não-JSON (status ' + r.status + ')' } }; }
        });
    }).then(function(res){
        var d = res.body || {};
        if (d.csrf_expired) {
            if (confirm('Token expirado. Recarregar a página pra pegar um novo?')) location.reload();
            return;
        }
        if (d.ok) {
            var toast = document.createElement('div');
            toast.textContent = caseId === '0' ? '✓ Desvinculado do processo' : '✓ Vinculado ao processo';
            toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#059669;color:#fff;padding:10px 16px;border-radius:8px;font-weight:600;z-index:100000;box-shadow:0 8px 24px rgba(0,0,0,.25);';
            document.body.appendChild(toast);
            setTimeout(function(){ toast.remove(); }, 2000);
        } else {
            alert('Falha: ' + (d.error || '?'));
        }
    }).catch(function(e){ alert('Erro de rede: ' + e.message); });
}
</script>
<script>
window._COB_CSRF = <?= json_encode(generate_csrf_token()) ?>;
window._COB_API_URL = <?= json_encode(module_url('financeiro', 'api.php')) ?>;
<?php if ($abrirNovaCobranca): ?>
// Vindo de "R$ Cobrar" do Kanban/Planilha — abre o modal automaticamente pra revisão
document.addEventListener('DOMContentLoaded', function(){
    var m = document.getElementById('modalNovaCob');
    if (m) { m.style.display = 'flex'; if (window.atualizarCobUI2) setTimeout(atualizarCobUI2, 50); }
});
<?php endif; ?>
</script>
<script>
<?php readfile(APP_ROOT . '/assets/js/cobranca_acoes.js'); ?>

window.cobAcaoSafe = function(id, tipo, venc, nome, valor) {
    if (typeof window.cobAcao !== 'function') {
        alert('⚠️ Erro: script de ações não carregou.\n\nPor favor:\n1. Feche o app\n2. Abra de novo\n3. Se ainda não funcionar, recarregue a página');
        return;
    }
    try { window.cobAcao(id, tipo, venc, nome, valor); }
    catch (e) { alert('Erro: ' + e.message); console.error(e); }
};
console.info('[cliente.php] JS pronto — cobAcao:', typeof window.cobAcao);
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
