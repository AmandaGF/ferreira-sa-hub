<?php
/**
 * Ferreira & Sá Hub — Cobranças por Cliente (Asaas)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { redirect(url('modules/dashboard/')); }

require_once __DIR__ . '/../../core/asaas_helper.php';

$pdo = db();
$clientId = (int)($_GET['id'] ?? 0);
if (!$clientId) { flash_set('error', 'Cliente não informado.'); redirect(module_url('financeiro')); }

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
        $sqlCob = "SELECT * FROM asaas_cobrancas WHERE client_id = ? AND case_id = ? ORDER BY vencimento DESC";
        $stmtCob = $pdo->prepare($sqlCob);
        $stmtCob->execute(array($clientId, $fromCaseId));
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
        <button onclick="document.getElementById('modalNovaCob').style.display='flex';" class="btn btn-primary btn-sm" style="background:#B87333;font-size:.72rem;">+ Nova Cobrança</button>
    </div>

    <?php if (!empty($cobrancas) && !empty($processosCliente)): ?>
    <!-- Vínculo em LOTE (bulk) — aparece só se tem cobranças + processos -->
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
                    <select onchange="vincularCobrancaProcesso(<?= (int)$cob['id'] ?>, this.value)" style="font-size:.68rem;padding:1px 5px;border:1px solid #e5e7eb;border-radius:4px;background:<?= $cob['case_id'] ? '#eff6ff' : '#f9fafb' ?>;color:<?= $cob['case_id'] ? '#1e40af' : '#6b7280' ?>;">
                        <option value="0" <?= empty($cob['case_id']) ? 'selected' : '' ?>>— Sem vínculo (histórico)</option>
                        <?php foreach ($processosCliente as $pr): ?>
                        <option value="<?= $pr['id'] ?>" <?= (int)$cob['case_id'] === (int)$pr['id'] ? 'selected' : '' ?>><?= e(mb_substr($pr['title'] ?? 'Processo #' . $pr['id'], 0, 40)) ?><?= $pr['case_number'] ? ' (' . e(substr($pr['case_number'], 0, 20)) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
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
    <form method="POST" action="<?= module_url('financeiro', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar_cobranca">
        <input type="hidden" name="client_id" value="<?= $clientId ?>">

        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Tipo</label>
                <select name="tipo" class="form-select" id="tipoCob2" onchange="atualizarCobUI2()">
                    <option value="unica">📄 Única</option>
                    <option value="parcelado">💳 Parcelada (N × — termina)</option>
                    <option value="recorrente">🔄 Recorrente (sem fim)</option>
                </select></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Pagamento</label>
                <select name="forma_pagamento" class="form-select"><option value="PIX">PIX</option><option value="BOLETO">Boleto</option><option value="UNDEFINED">Todas</option></select></div>
        </div>
        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label id="labelValorCob2" style="font-size:.75rem;font-weight:700;">Valor total (R$)</label><input type="text" name="valor" id="valorCob2" class="form-input input-reais" required placeholder="0,00" oninput="atualizarCobUI2()"></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Vencimento</label><input type="date" name="vencimento" class="form-input" required value="<?= date('Y-m-d', strtotime('+3 days')) ?>"></div>
        </div>
        <div id="parcCob2" style="display:none;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Parcelas</label><input type="number" name="num_parcelas" id="parcelasCob2" class="form-input" min="2" max="60" value="12" oninput="atualizarCobUI2()"></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Dia venc.</label><input type="number" name="dia_vencimento" class="form-input" min="1" max="28" value="10"></div>
        </div>
        <div id="previewCob2" style="display:none;background:#f5ebe0;border-left:3px solid #B87333;padding:.5rem .7rem;margin-bottom:.6rem;border-radius:6px;font-size:.75rem;color:#3f2e1c;"></div>
        <script>
        function atualizarCobUI2(){
            var tipo = document.getElementById('tipoCob2').value;
            var valor = parseFloat((document.getElementById('valorCob2').value || '').replace(/[^\d,]/g,'').replace(',','.')) || 0;
            var parc = parseInt(document.getElementById('parcelasCob2').value, 10) || 1;
            var mostrar = (tipo === 'recorrente' || tipo === 'parcelado');
            document.getElementById('parcCob2').style.display = mostrar ? 'flex' : 'none';
            var lbl = document.getElementById('labelValorCob2');
            if (tipo === 'parcelado') lbl.innerHTML = '💡 Valor de <u>cada parcela</u> (R$)';
            else if (tipo === 'recorrente') lbl.innerHTML = '💡 Valor de <u>cada mensalidade</u> (R$)';
            else lbl.innerHTML = 'Valor total (R$)';

            var prev = document.getElementById('previewCob2');
            if (valor > 0 && parc > 1 && mostrar) {
                var total = valor * parc;
                var vStr = valor.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
                var tStr = total.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
                if (tipo === 'parcelado') {
                    prev.innerHTML = '📋 <b>' + parc + ' parcelas de R$ ' + vStr + '</b> — total <b>R$ ' + tStr + '</b>. Vence mensalmente a partir da data escolhida e <b>termina na última parcela</b>.';
                } else {
                    prev.innerHTML = '🔄 Cobrança <b>mensal de R$ ' + vStr + '</b>, sem fim definido. Max ' + parc + ' mensalidades.';
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
                <select name="case_id" class="form-select" required>
                    <option value="">— Selecione o processo —</option>
                    <?php foreach ($processosCliente as $pr): ?>
                        <option value="<?= (int)$pr['id'] ?>"><?= e($pr['title'] ?: 'Processo #' . $pr['id']) ?><?= $pr['case_number'] ? ' (' . e($pr['case_number']) . ')' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div style="margin-bottom:.6rem;"><label style="font-size:.75rem;font-weight:700;">Descrição</label><input type="text" name="descricao" class="form-input" value="Honorários Advocatícios"></div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalNovaCob').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Criar</button>
        </div>
    </form>
</div></div>

<script>
// Vincula TODAS as cobranças do cliente ao processo escolhido (bulk)
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
