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

// Cobranças do cliente
$cobrancas = array();
try {
    $cobrancas = $pdo->prepare(
        "SELECT * FROM asaas_cobrancas WHERE client_id = ? ORDER BY vencimento DESC"
    );
    $cobrancas->execute(array($clientId));
    $cobrancas = $cobrancas->fetchAll();
} catch (Exception $e) {}

// Contratos
$contratos = array();
try {
    $contratos = $pdo->prepare(
        "SELECT cf.*, cs.title as case_title FROM contratos_financeiros cf LEFT JOIN cases cs ON cs.id = cf.case_id WHERE cf.client_id = ? ORDER BY cf.created_at DESC"
    );
    $contratos->execute(array($clientId));
    $contratos = $contratos->fetchAll();
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

<!-- Header -->
<div class="fin-header">
    <h2><?= e($client['name']) ?></h2>
    <div class="meta">
        CPF: <?= e($client['cpf'] ?: 'Não cadastrado') ?>
        · Asaas: <?= $asaasId ? '<span style="color:#059669;">✓ Vinculado (' . e($asaasId) . ')</span>' : '<span style="color:#f59e0b;">Não vinculado</span>' ?>
        <?php if ($vinculoErro): ?><span style="color:#dc2626;"> — <?= e($vinculoErro) ?></span><?php endif; ?>
    </div>
    <div style="margin-top:.5rem;display:flex;gap:.5rem;">
        <?php if ($client['phone']): ?>
        <a href="https://wa.me/55<?= preg_replace('/\D/', '', $client['phone']) ?>" target="_blank" class="btn btn-success btn-sm" style="font-size:.72rem;">💬 WhatsApp</a>
        <?php endif; ?>
        <a href="<?= module_url('clientes', 'ver.php?id=' . $clientId) ?>" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);font-size:.72rem;">👤 Ver cadastro</a>
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
    <div style="padding:1rem 1.15rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <h4 style="font-size:.88rem;font-weight:700;color:var(--petrol-900);">Cobranças (<?= count($cobrancas) ?>)</h4>
        <button onclick="document.getElementById('modalNovaCob').style.display='flex';" class="btn btn-primary btn-sm" style="background:#B87333;font-size:.72rem;">+ Nova Cobrança</button>
    </div>

    <?php if (empty($cobrancas)): ?>
        <div style="text-align:center;padding:2rem;color:var(--text-muted);">Nenhuma cobrança registrada.</div>
    <?php else: ?>
        <?php foreach ($cobrancas as $cob):
            $cor = asaas_status_cor($cob['status']);
            $label = asaas_status_label($cob['status']);
        ?>
        <div class="cob-item">
            <div style="width:10px;height:10px;border-radius:50%;background:<?= $cor ?>;flex-shrink:0;"></div>
            <div style="flex:1;">
                <div style="font-size:.85rem;font-weight:600;"><?= e($cob['descricao'] ?: 'Cobrança') ?></div>
                <div style="font-size:.7rem;color:var(--text-muted);">
                    <?= $cob['forma_pagamento'] ? strtoupper($cob['forma_pagamento']) . ' · ' : '' ?>
                    Vencimento: <?= date('d/m/Y', strtotime($cob['vencimento'])) ?>
                    <?php if ($cob['data_pagamento']): ?> · Pago em: <?= date('d/m/Y', strtotime($cob['data_pagamento'])) ?><?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:.95rem;font-weight:800;color:<?= $cor ?>;">R$ <?= number_format($cob['valor'], 2, ',', '.') ?></div>
                <span class="cob-badge" style="background:<?= $cor ?>;"><?= $label ?></span>
            </div>
            <div style="display:flex;gap:4px;flex-shrink:0;">
                <?php if ($cob['invoice_url']): ?><a href="<?= e($cob['invoice_url']) ?>" target="_blank" style="font-size:.7rem;background:#052228;color:#fff;padding:3px 8px;border-radius:4px;text-decoration:none;">Fatura</a><?php endif; ?>
                <?php if ($cob['status'] === 'PENDING' || $cob['status'] === 'OVERDUE'): ?>
                    <?php if ($client['phone'] && $cob['invoice_url']): ?>
                    <a href="https://wa.me/55<?= preg_replace('/\D/', '', $client['phone']) ?>?text=<?= urlencode("Olá " . $client['name'] . ", segue o link da sua cobrança:\n" . $cob['invoice_url'] . "\n\nValor: R$ " . number_format($cob['valor'], 2, ',', '.') . "\nVencimento: " . date('d/m/Y', strtotime($cob['vencimento'])) . "\n\n_Ferreira & Sá Advocacia_") ?>" target="_blank" style="font-size:.7rem;background:#25D366;color:#fff;padding:3px 8px;border-radius:4px;text-decoration:none;">Enviar</a>
                    <?php endif; ?>
                    <form method="POST" action="<?= module_url('financeiro', 'api.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="cancelar_cobranca">
                        <input type="hidden" name="cobranca_id" value="<?= $cob['id'] ?>">
                        <button type="submit" onclick="return confirm('Cancelar esta cobrança?');" style="font-size:.65rem;background:#dc2626;color:#fff;border:none;padding:3px 8px;border-radius:4px;cursor:pointer;">✕</button>
                    </form>
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
                <select name="tipo" class="form-select" id="tipoCob2" onchange="document.getElementById('parcCob2').style.display=this.value==='recorrente'?'flex':'none';">
                    <option value="unica">Única</option><option value="recorrente">Recorrente</option>
                </select></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Pagamento</label>
                <select name="forma_pagamento" class="form-select"><option value="PIX">PIX</option><option value="BOLETO">Boleto</option><option value="UNDEFINED">Todas</option></select></div>
        </div>
        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Valor (R$)</label><input type="text" name="valor" class="form-input input-reais" required placeholder="0,00"></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Vencimento</label><input type="date" name="vencimento" class="form-input" required value="<?= date('Y-m-d', strtotime('+3 days')) ?>"></div>
        </div>
        <div id="parcCob2" style="display:none;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Parcelas</label><input type="number" name="num_parcelas" class="form-input" min="2" max="60" value="12"></div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;">Dia venc.</label><input type="number" name="dia_vencimento" class="form-input" min="1" max="28" value="10"></div>
        </div>
        <div style="margin-bottom:.6rem;"><label style="font-size:.75rem;font-weight:700;">Descrição</label><input type="text" name="descricao" class="form-input" value="Honorários Advocatícios"></div>
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalNovaCob').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Criar</button>
        </div>
    </form>
</div></div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
