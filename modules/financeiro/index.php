<?php
/**
 * Ferreira & Sá Hub — Módulo Financeiro (Visão Geral)
 * Integração Asaas
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { redirect(url('modules/dashboard/')); }

require_once __DIR__ . '/../../core/asaas_helper.php';

$pageTitle = 'Financeiro';
$pdo = db();

// Seletor de mês — permite navegar no histórico (default: mês atual)
$mesSel = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mesSel)) $mesSel = date('Y-m');
$mesAtual = $mesSel;
$ML = array('','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez');
$mesNum = (int)substr($mesSel, 5, 2);
$anoSel = substr($mesSel, 0, 4);
$mesNome = $ML[$mesNum];

// Lista de meses disponíveis no banco (pra popular dropdown)
$mesesDisponiveis = $pdo->query(
    "SELECT DISTINCT DATE_FORMAT(vencimento,'%Y-%m') as m FROM asaas_cobrancas WHERE vencimento IS NOT NULL
     UNION SELECT DISTINCT DATE_FORMAT(data_pagamento,'%Y-%m') as m FROM asaas_cobrancas WHERE data_pagamento IS NOT NULL
     ORDER BY m DESC"
)->fetchAll(PDO::FETCH_COLUMN);

// ─── KPIs ───
$receitaPrevista = 0; $receitaRecebida = 0; $inadimplentes = 0; $totalVencido = 0;
$contratosAtivos = 0; $vencendoHoje = 0; $vencendo7d = 0;
try {
    // Do cache local
    $receitaPrevista = (float)$pdo->query("SELECT IFNULL(SUM(valor),0) FROM asaas_cobrancas WHERE status = 'PENDING' AND DATE_FORMAT(vencimento,'%Y-%m') = '$mesAtual'")->fetchColumn();
    $receitaRecebida = (float)$pdo->query("SELECT IFNULL(SUM(valor_pago),0) FROM asaas_cobrancas WHERE status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') AND DATE_FORMAT(data_pagamento,'%Y-%m') = '$mesAtual'")->fetchColumn();
    $inadimplentes = (int)$pdo->query("SELECT COUNT(DISTINCT client_id) FROM asaas_cobrancas WHERE status = 'OVERDUE'")->fetchColumn();
    $totalVencido = (float)$pdo->query("SELECT IFNULL(SUM(valor),0) FROM asaas_cobrancas WHERE status = 'OVERDUE'")->fetchColumn();
    $contratosAtivos = (int)$pdo->query("SELECT COUNT(*) FROM contratos_financeiros WHERE status = 'ativo'")->fetchColumn();
    $vencendoHoje = (int)$pdo->query("SELECT COUNT(*) FROM asaas_cobrancas WHERE status = 'PENDING' AND vencimento = CURDATE()")->fetchColumn();
    $vencendo7d = (int)$pdo->query("SELECT COUNT(*) FROM asaas_cobrancas WHERE status = 'PENDING' AND vencimento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
} catch (Exception $e) {}

// ─── Gráfico: Previsto x Recebido (6 meses) ───
$grafLabels = array(); $grafPrevisto = array(); $grafRecebido = array();
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $grafLabels[] = $ML[(int)date('n', strtotime("-$i months"))];
    try {
        $grafPrevisto[] = (float)$pdo->query("SELECT IFNULL(SUM(valor),0) FROM asaas_cobrancas WHERE DATE_FORMAT(vencimento,'%Y-%m') = '$m'")->fetchColumn();
        $grafRecebido[] = (float)$pdo->query("SELECT IFNULL(SUM(valor_pago),0) FROM asaas_cobrancas WHERE status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') AND DATE_FORMAT(data_pagamento,'%Y-%m') = '$m'")->fetchColumn();
    } catch (Exception $e) { $grafPrevisto[] = 0; $grafRecebido[] = 0; }
}

// ─── Inadimplentes ───
$listaInadimplentes = array();
try {
    $listaInadimplentes = $pdo->query(
        "SELECT ac.client_id, cl.name, cl.phone, SUM(ac.valor) as valor_aberto,
         MIN(ac.vencimento) as primeiro_vencimento, DATEDIFF(CURDATE(), MIN(ac.vencimento)) as dias_atraso,
         COUNT(*) as qtd_parcelas
         FROM asaas_cobrancas ac
         LEFT JOIN clients cl ON cl.id = ac.client_id
         WHERE ac.status = 'OVERDUE'
         GROUP BY ac.client_id ORDER BY dias_atraso DESC LIMIT 20"
    )->fetchAll();
} catch (Exception $e) {}

// ─── Últimas cobranças ───
$ultimasCobrancas = array();
try {
    $ultimasCobrancas = $pdo->query(
        "SELECT ac.*, cl.name as client_name FROM asaas_cobrancas ac
         LEFT JOIN clients cl ON cl.id = ac.client_id
         ORDER BY ac.created_at DESC LIMIT 15"
    )->fetchAll();
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
echo voltar_ao_processo_html();
?>

<style>
.fin-kpi { display:grid; grid-template-columns:repeat(3,1fr); gap:.75rem; margin-bottom:1.25rem; }
.fin-card { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1rem 1.15rem; display:flex; align-items:center; gap:.75rem; }
.fin-card .icon { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.2rem; flex-shrink:0; }
.fin-val { font-size:1.4rem; font-weight:800; color:var(--petrol-900); line-height:1; }
.fin-label { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; margin-top:.1rem; }
.fin-sub { font-size:.63rem; font-weight:600; margin-top:.1rem; }
.fin-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
.fin-section { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.15rem; }
.fin-section h4 { font-size:.85rem; font-weight:700; color:var(--petrol-900); margin-bottom:.75rem; }
.fin-section canvas { max-height:220px; }
.fin-table { width:100%; border-collapse:collapse; font-size:.8rem; }
.fin-table th { text-align:left; font-size:.68rem; text-transform:uppercase; color:var(--text-muted); padding:.4rem .5rem; border-bottom:1px solid var(--border); }
.fin-table td { padding:.45rem .5rem; border-bottom:1px solid rgba(0,0,0,.04); }
.fin-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:.68rem; font-weight:700; color:#fff; }
@media (max-width:1024px) { .fin-kpi { grid-template-columns:repeat(2,1fr); } .fin-grid2 { grid-template-columns:1fr; } }
@media (max-width:600px) { .fin-kpi { grid-template-columns:1fr; } }
</style>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
    <div style="display:flex;align-items:center;gap:.7rem;">
        <h2 style="font-size:1.1rem;font-weight:800;color:var(--petrol-900);margin:0;">💰 Financeiro —</h2>
        <form method="GET" style="margin:0;">
            <select name="mes" onchange="this.form.submit()" style="padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:.85rem;font-weight:600;">
                <?php foreach ($mesesDisponiveis as $m):
                    $mn = (int)substr($m,5,2); $ma = substr($m,0,4);
                    $label = ($ML[$mn] ?? '') . '/' . $ma;
                ?>
                <option value="<?= e($m) ?>" <?= $m === $mesSel ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <div style="display:flex;gap:.5rem;">
        <a href="<?= module_url('financeiro', 'sync.php') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;" title="Busca cobranças dos últimos 30 dias no Asaas">🔄 Sincronizar Asaas</a>
        <button onclick="document.getElementById('modalCobranca').style.display='flex';" class="btn btn-primary btn-sm" style="background:#B87333;">+ Nova Cobrança</button>
    </div>
</div>

<!-- KPIs -->
<div class="fin-kpi">
    <div class="fin-card">
        <div class="icon" style="background:rgba(5,150,105,.12);">💵</div>
        <div><div class="fin-val" style="color:#059669;">R$ <?= number_format($receitaRecebida, 2, ',', '.') ?></div><div class="fin-label">Recebido em <?= $mesNome ?></div></div>
    </div>
    <div class="fin-card">
        <div class="icon" style="background:rgba(249,115,22,.12);">📋</div>
        <div><div class="fin-val">R$ <?= number_format($receitaPrevista, 2, ',', '.') ?></div><div class="fin-label">Pendente em <?= $mesNome ?></div><div class="fin-sub" style="color:var(--text-muted);"><?= $vencendo7d ?> vencendo em 7 dias</div></div>
    </div>
    <div class="fin-card">
        <div class="icon" style="background:rgba(220,38,38,.12);">⚠️</div>
        <div><div class="fin-val" style="color:#dc2626;">R$ <?= number_format($totalVencido, 2, ',', '.') ?></div><div class="fin-label">Vencido (<?= $inadimplentes ?> cliente<?= $inadimplentes !== 1 ? 's' : '' ?>)</div></div>
    </div>
</div>

<div class="fin-kpi" style="grid-template-columns:repeat(3,1fr);">
    <div class="fin-card">
        <div class="icon" style="background:rgba(99,102,241,.12);">📝</div>
        <div><div class="fin-val"><?= $contratosAtivos ?></div><div class="fin-label">Contratos Ativos</div></div>
    </div>
    <div class="fin-card">
        <div class="icon" style="background:<?= $vencendoHoje > 0 ? 'rgba(220,38,38,.12)' : 'rgba(5,150,105,.12)' ?>;">📅</div>
        <div><div class="fin-val"><?= $vencendoHoje ?></div><div class="fin-label">Vencendo Hoje</div></div>
    </div>
    <div class="fin-card">
        <div class="icon" style="background:rgba(139,92,246,.12);">📊</div>
        <div>
            <?php $taxa = ($receitaPrevista + $receitaRecebida + $totalVencido) > 0 ? round($totalVencido / ($receitaPrevista + $receitaRecebida + $totalVencido) * 100) : 0; ?>
            <div class="fin-val"><?= $taxa ?>%</div><div class="fin-label">Taxa de Inadimplência</div>
        </div>
    </div>
</div>

<!-- Gráfico + Inadimplentes -->
<div class="fin-grid2">
    <div class="fin-section">
        <h4>📊 Previsto × Recebido (6 meses)</h4>
        <canvas id="chartFinanceiro"></canvas>
    </div>
    <div class="fin-section">
        <h4>⚠️ Inadimplentes (<?= count($listaInadimplentes) ?>)</h4>
        <?php if (empty($listaInadimplentes)): ?>
            <p style="text-align:center;color:var(--text-muted);padding:2rem;font-size:.85rem;">Nenhum inadimplente 🎉</p>
        <?php else: ?>
        <table class="fin-table">
            <thead><tr><th>Cliente</th><th>Valor</th><th>Atraso</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($listaInadimplentes as $inad):
                $dias = (int)$inad['dias_atraso'];
                $cor = $dias > 30 ? '#dc2626' : ($dias > 7 ? '#d97706' : '#f59e0b');
            ?>
            <tr>
                <td style="font-weight:600;"><?= e($inad['name'] ?: 'Sem nome') ?></td>
                <td style="font-weight:700;color:#dc2626;">R$ <?= number_format($inad['valor_aberto'], 2, ',', '.') ?></td>
                <td><span style="color:<?= $cor ?>;font-weight:700;"><?= $dias ?>d</span> <span style="font-size:.65rem;color:var(--text-muted);">(<?= $inad['qtd_parcelas'] ?> parc.)</span></td>
                <td><a href="<?= module_url('financeiro', 'cliente.php?id=' . $inad['client_id']) ?>" style="font-size:.72rem;">Ver →</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Últimas cobranças -->
<div class="fin-section" style="margin-bottom:1.25rem;">
    <h4>🕐 Últimas Cobranças</h4>
    <?php if (empty($ultimasCobrancas)): ?>
        <p style="text-align:center;color:var(--text-muted);padding:1rem;">Nenhuma cobrança registrada. Clique em "Sincronizar Asaas" ou crie uma nova.</p>
    <?php else: ?>
    <table class="fin-table">
        <thead><tr><th>Cliente</th><th>Descrição</th><th>Valor</th><th>Vencimento</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($ultimasCobrancas as $cob): ?>
        <tr>
            <td style="font-weight:600;"><?= e($cob['client_name'] ?: '—') ?></td>
            <td style="font-size:.75rem;"><?= e(mb_substr($cob['descricao'] ?: '—', 0, 40)) ?></td>
            <td style="font-weight:700;">R$ <?= number_format($cob['valor'], 2, ',', '.') ?></td>
            <td style="font-family:monospace;font-size:.75rem;"><?= date('d/m/Y', strtotime($cob['vencimento'])) ?></td>
            <td><span class="fin-badge" style="background:<?= asaas_status_cor($cob['status']) ?>;"><?= asaas_status_label($cob['status']) ?></span></td>
            <td>
                <?php if ($cob['invoice_url']): ?><a href="<?= e($cob['invoice_url']) ?>" target="_blank" style="font-size:.7rem;">Fatura</a><?php endif; ?>
                <?php if ($cob['link_boleto']): ?><a href="<?= e($cob['link_boleto']) ?>" target="_blank" style="font-size:.7rem;margin-left:4px;">Boleto</a><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Modal Nova Cobrança -->
<div id="modalCobranca" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:500px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);max-height:90vh;overflow-y:auto;">
    <h3 style="font-size:1rem;margin-bottom:1rem;color:var(--petrol-900);">💰 Nova Cobrança</h3>
    <form method="POST" action="<?= module_url('financeiro', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar_cobranca">

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Cliente *</label>
            <select name="client_id" class="form-select" required>
                <option value="">— Selecionar —</option>
                <?php
                $clientes = $pdo->query("SELECT id, name, cpf, asaas_customer_id FROM clients WHERE cpf IS NOT NULL AND cpf != '' ORDER BY name")->fetchAll();
                foreach ($clientes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['asaas_customer_id'] ? '' : 'style="color:var(--text-muted);"' ?>><?= e($c['name']) ?> <?= $c['cpf'] ? '— ' . e($c['cpf']) : '' ?> <?= $c['asaas_customer_id'] ? '✓' : '(novo)' ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Tipo</label>
                <select name="tipo" class="form-select" id="tipoCobranca" onchange="toggleParcelas()">
                    <option value="unica">Cobrança única</option>
                    <option value="recorrente">Assinatura recorrente</option>
                </select>
            </div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Forma pagamento</label>
                <select name="forma_pagamento" class="form-select">
                    <option value="PIX">PIX</option>
                    <option value="BOLETO">Boleto</option>
                    <option value="UNDEFINED">Todas</option>
                </select>
            </div>
        </div>

        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Valor (R$) *</label>
                <input type="text" name="valor" class="form-input input-reais" required placeholder="0,00">
            </div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Vencimento *</label>
                <input type="date" name="vencimento" class="form-input" required value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
            </div>
        </div>

        <div id="camposParcelas" style="display:none;margin-bottom:.6rem;">
            <div style="display:flex;gap:.5rem;">
                <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Nº Parcelas</label>
                    <input type="number" name="num_parcelas" class="form-input" min="2" max="60" value="12">
                </div>
                <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Dia vencimento mensal</label>
                    <input type="number" name="dia_vencimento" class="form-input" min="1" max="28" value="10">
                </div>
            </div>
        </div>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Descrição</label>
            <input type="text" name="descricao" class="form-input" placeholder="Ex: Honorários - Alimentos" value="Honorários Advocatícios">
        </div>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Caso vinculado (opcional)</label>
            <select name="case_id" class="form-select">
                <option value="">— Nenhum —</option>
                <?php
                $casos = $pdo->query("SELECT cs.id, cs.title, cl.name FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id ORDER BY cs.created_at DESC LIMIT 50")->fetchAll();
                foreach ($casos as $cs): ?>
                <option value="<?= $cs['id'] ?>"><?= e($cs['title']) ?> — <?= e($cs['name'] ?: '') ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalCobranca').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Criar Cobrança</button>
        </div>
    </form>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function toggleParcelas() {
    document.getElementById('camposParcelas').style.display = document.getElementById('tipoCobranca').value === 'recorrente' ? 'block' : 'none';
}

(function(){
    var c = document.getElementById('chartFinanceiro');
    if (c) new Chart(c, {
        type: 'bar',
        data: {
            labels: <?= json_encode($grafLabels) ?>,
            datasets: [
                { label: 'Previsto', data: <?= json_encode($grafPrevisto) ?>, backgroundColor: 'rgba(99,102,241,.5)', borderRadius: 4 },
                { label: 'Recebido', data: <?= json_encode($grafRecebido) ?>, backgroundColor: 'rgba(5,150,105,.6)', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { labels: { color: '#94a3b8', font: { size: 11 } } } },
            scales: {
                y: { beginAtZero: true, ticks: { color: '#94a3b8', callback: function(v){ return 'R$'+v.toLocaleString('pt-BR'); } }, grid: { color: 'rgba(148,163,184,.08)' } },
                x: { ticks: { color: '#94a3b8' }, grid: { display: false } }
            }
        }
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
