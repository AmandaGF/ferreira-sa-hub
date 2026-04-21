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
$ordenacoes = array(
    'atraso_desc'   => array('label' => 'Atraso ↓ (mais antigos primeiro)', 'sql' => 'dias_atraso DESC'),
    'atraso_asc'    => array('label' => 'Atraso ↑ (mais recentes primeiro)','sql' => 'dias_atraso ASC'),
    'valor_desc'    => array('label' => 'Maior valor devido',               'sql' => 'valor_aberto DESC'),
    'valor_asc'     => array('label' => 'Menor valor devido',               'sql' => 'valor_aberto ASC'),
    'parcelas_desc' => array('label' => 'Mais parcelas vencidas',           'sql' => 'qtd_parcelas DESC, dias_atraso DESC'),
    'nome_asc'      => array('label' => 'Nome (A-Z)',                       'sql' => 'cl.name ASC'),
);
$ordemSel = $_GET['ordem'] ?? 'atraso_desc';
if (!isset($ordenacoes[$ordemSel])) $ordemSel = 'atraso_desc';
$orderBy = $ordenacoes[$ordemSel]['sql'];

// Filtro de mês da inadimplência (independente do select principal). Default vazio = todos.
$mesInadSel = $_GET['mes_inad'] ?? '';
$filtroMesInadSql = '';
$paramsInad = array();
if ($mesInadSel && preg_match('/^\d{4}-\d{2}$/', $mesInadSel)) {
    $filtroMesInadSql = " AND DATE_FORMAT(ac.vencimento, '%Y-%m') = ?";
    $paramsInad[] = $mesInadSel;
}

$listaInadimplentes = array();
$totalInadMes = 0; $valorInadMes = 0;
try {
    $stmtInad = $pdo->prepare(
        "SELECT ac.client_id, cl.name, cl.phone, SUM(ac.valor) as valor_aberto,
         MIN(ac.vencimento) as primeiro_vencimento, DATEDIFF(CURDATE(), MIN(ac.vencimento)) as dias_atraso,
         COUNT(*) as qtd_parcelas
         FROM asaas_cobrancas ac
         LEFT JOIN clients cl ON cl.id = ac.client_id
         WHERE ac.status = 'OVERDUE'{$filtroMesInadSql}
         GROUP BY ac.client_id ORDER BY {$orderBy} LIMIT 50"
    );
    $stmtInad->execute($paramsInad);
    $listaInadimplentes = $stmtInad->fetchAll();

    if ($mesInadSel) {
        $stmtTot = $pdo->prepare(
            "SELECT COUNT(DISTINCT ac.client_id) AS qtd, IFNULL(SUM(ac.valor),0) AS total
             FROM asaas_cobrancas ac
             WHERE ac.status = 'OVERDUE'{$filtroMesInadSql}"
        );
        $stmtTot->execute($paramsInad);
        $rowTot = $stmtTot->fetch();
        $totalInadMes = (int)$rowTot['qtd'];
        $valorInadMes = (float)$rowTot['total'];
    }
} catch (Exception $e) {}

// ─── Cobranças do mês selecionado ───
$ultimasCobrancas = array();
$totalCobrancasMes = 0;
try {
    $stmt = $pdo->prepare(
        "SELECT ac.*, cl.name as client_name FROM asaas_cobrancas ac
         LEFT JOIN clients cl ON cl.id = ac.client_id
         WHERE DATE_FORMAT(ac.vencimento,'%Y-%m') = ?
         ORDER BY ac.vencimento DESC, ac.id DESC LIMIT 50"
    );
    $stmt->execute(array($mesSel));
    $ultimasCobrancas = $stmt->fetchAll();

    $c = $pdo->prepare("SELECT COUNT(*) FROM asaas_cobrancas WHERE DATE_FORMAT(vencimento,'%Y-%m') = ?");
    $c->execute(array($mesSel));
    $totalCobrancasMes = (int)$c->fetchColumn();
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
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <a href="<?= module_url('financeiro', 'cobrancas.php') ?>" class="btn btn-primary btn-sm" style="font-size:.72rem;background:#7c3aed;" title="Ver todas as cobranças com filtros por período, status, busca etc">📋 Todas as cobranças</a>
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
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem;">
            <h4 style="margin:0;">⚠️ Inadimplentes (<?= count($listaInadimplentes) ?>)<?php if ($mesInadSel): $mn = (int)substr($mesInadSel,5,2); $ma = substr($mesInadSel,0,4); ?><span style="font-weight:400;color:var(--text-muted);font-size:.82rem;"> em <?= ($ML[$mn] ?? '') . '/' . $ma ?></span><?php endif; ?></h4>
            <form method="GET" style="margin:0;display:flex;gap:.35rem;flex-wrap:wrap;">
                <?php foreach ($_GET as $k => $v): ?>
                    <?php if ($k !== 'ordem' && $k !== 'mes_inad' && is_scalar($v)): ?>
                        <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                <select name="mes_inad" onchange="this.form.submit()" style="padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.72rem;" title="Filtrar inadimplentes por mês de vencimento">
                    <option value="">📅 Todos os meses</option>
                    <?php foreach ($mesesDisponiveis as $m):
                        $mn = (int)substr($m,5,2); $ma = substr($m,0,4);
                        $label = ($ML[$mn] ?? '') . '/' . $ma;
                    ?>
                    <option value="<?= e($m) ?>" <?= $m === $mesInadSel ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="ordem" onchange="this.form.submit()" style="padding:4px 8px;border:1px solid var(--border);border-radius:6px;font-size:.72rem;">
                    <?php foreach ($ordenacoes as $k => $o): ?>
                    <option value="<?= $k ?>" <?= $ordemSel === $k ? 'selected' : '' ?>><?= e($o['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php if ($mesInadSel): ?>
            <div style="background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.18);border-radius:8px;padding:.5rem .75rem;margin-bottom:.6rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap;">
                <span style="font-size:.78rem;color:#991b1b;font-weight:600;"><?= $totalInadMes ?> cliente<?= $totalInadMes !== 1 ? 's' : '' ?> com parcela vencida em <?= ($ML[(int)substr($mesInadSel,5,2)] ?? '') . '/' . substr($mesInadSel,0,4) ?></span>
                <span style="font-size:.85rem;color:#dc2626;font-weight:800;">R$ <?= number_format($valorInadMes, 2, ',', '.') ?></span>
            </div>
        <?php endif; ?>
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

<!-- Cobranças do mês selecionado -->
<div class="fin-section" style="margin-bottom:1.25rem;">
    <h4>🕐 Cobranças de <?= $mesNome ?>/<?= $anoSel ?>
        <span style="font-weight:400;color:var(--text-muted);font-size:.8rem;">
            (<?= count($ultimasCobrancas) ?> de <?= $totalCobrancasMes ?>)
        </span>
    </h4>
    <?php if (empty($ultimasCobrancas)): ?>
        <p style="text-align:center;color:var(--text-muted);padding:1rem;">Nenhuma cobrança com vencimento em <?= $mesNome ?>/<?= $anoSel ?>.</p>
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

        <?php
            $clientes = $pdo->query("SELECT id, name, cpf, asaas_customer_id FROM clients WHERE cpf IS NOT NULL AND cpf != '' ORDER BY name")->fetchAll();
            $clientesJS = array_map(function($c){
                return array('id'=>(int)$c['id'], 'name'=>$c['name'], 'asaas'=>!empty($c['asaas_customer_id']));
            }, $clientes);
            // Casos ativos agrupados por cliente (pro select dinâmico abaixo)
            $casosByClient = array();
            foreach ($pdo->query("SELECT cs.id, cs.title, cs.case_number, cs.client_id FROM cases cs WHERE cs.status NOT IN ('arquivado','cancelado') AND cs.client_id IS NOT NULL ORDER BY cs.created_at DESC") as $cs) {
                $cid = (int)$cs['client_id'];
                if (!isset($casosByClient[$cid])) $casosByClient[$cid] = array();
                $casosByClient[$cid][] = array(
                    'id' => (int)$cs['id'],
                    'title' => $cs['title'] ?: ('Processo #' . $cs['id']),
                    'number' => $cs['case_number'] ?: '',
                );
            }
        ?>
        <div style="margin-bottom:.6rem;position:relative;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Cliente *</label>
            <input type="text" id="cobBuscaNome" class="form-input" placeholder="Digite o nome do cliente..." autocomplete="off" oninput="cobFiltrar(this.value)" onfocus="cobFiltrar(this.value)" required>
            <input type="hidden" name="client_id" id="cobClienteId" required>
            <div id="cobDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:240px;overflow-y:auto;background:#fff;border:1px solid var(--border);border-top:none;border-radius:0 0 6px 6px;z-index:10;box-shadow:0 8px 20px rgba(0,0,0,.12);"></div>
        </div>
        <script>
        var _cobClientes = <?= json_encode($clientesJS, JSON_UNESCAPED_UNICODE) ?>;
        var _cobCasosByClient = <?= json_encode($casosByClient, JSON_UNESCAPED_UNICODE) ?>;
        function cobFiltrar(q) {
            var drop = document.getElementById('cobDropdown');
            q = (q || '').trim().toLowerCase();
            if (q.length < 1) { drop.style.display = 'none'; return; }
            var matches = _cobClientes.filter(function(c){ return c.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 30);
            if (!matches.length) {
                drop.innerHTML = '<div style="padding:.55rem .75rem;color:#999;font-size:.8rem;">Nenhum cliente encontrado</div>';
            } else {
                drop.innerHTML = matches.map(function(c){
                    var nomeSafe = c.name.replace(/</g,'&lt;');
                    var tag = c.asaas ? '<span style="color:#059669;font-weight:700;margin-left:6px;">✓ Asaas</span>' : '<span style="color:#B87333;font-weight:600;margin-left:6px;">(novo)</span>';
                    // Usa data-* em vez de onclick inline — mais robusto, evita problemas com aspas/escape
                    return '<div class="cob-item" data-cliid="' + c.id + '" data-cliname="' + nomeSafe.replace(/"/g,'&quot;') + '" style="padding:.5rem .75rem;cursor:pointer;font-size:.85rem;border-bottom:1px solid #f0f0f0;">' + nomeSafe + tag + '</div>';
                }).join('');
            }
            drop.style.display = 'block';
        }
        function cobSelect(id, nome) {
            document.getElementById('cobClienteId').value = id;
            document.getElementById('cobBuscaNome').value = nome;
            document.getElementById('cobDropdown').style.display = 'none';
            // Popular select de casos com os processos deste cliente
            cobPopularCasos(id);
        }
        // Event delegation — mousedown dispara antes do blur e antes do outer click listener
        (function(){
            var drop = document.getElementById('cobDropdown');
            if (!drop) return;
            drop.addEventListener('mousedown', function(e){
                var item = e.target.closest('.cob-item');
                if (!item) return;
                e.preventDefault(); // impede blur do input
                var id = parseInt(item.getAttribute('data-cliid'), 10);
                var nome = item.getAttribute('data-cliname') || '';
                // Decodifica &quot; e &lt;
                var tmp = document.createElement('textarea'); tmp.innerHTML = nome; nome = tmp.value;
                cobSelect(id, nome);
            });
            // Hover visual via delegation
            drop.addEventListener('mouseover', function(e){
                var it = e.target.closest('.cob-item'); if (it) it.style.background = '#f5ebe0';
            });
            drop.addEventListener('mouseout', function(e){
                var it = e.target.closest('.cob-item'); if (it) it.style.background = '';
            });
        })();
        function cobPopularCasos(clientId) {
            var sel = document.getElementById('cobCaseSelect');
            if (!sel) return;
            var casos = _cobCasosByClient[clientId] || [];
            if (!casos.length) {
                sel.innerHTML = '<option value="">⚠️ Este cliente não tem processos cadastrados — crie um antes</option>';
                sel.disabled = true;
            } else {
                sel.disabled = false;
                var html = '<option value="">— Selecione o processo —</option>';
                casos.forEach(function(c){
                    var num = c.number ? ' (' + c.number + ')' : '';
                    html += '<option value="' + c.id + '">' + c.title + num + '</option>';
                });
                sel.innerHTML = html;
            }
        }
        // Fecha dropdown ao clicar fora
        document.addEventListener('click', function(e){
            if (!e.target.closest('#cobDropdown') && e.target.id !== 'cobBuscaNome') {
                var d = document.getElementById('cobDropdown');
                if (d) d.style.display = 'none';
            }
        });
        </script>

        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Tipo</label>
                <select name="tipo" class="form-select" id="tipoCobranca" onchange="atualizarCobUI1()">
                    <option value="unica">📄 Única (1 pagamento)</option>
                    <option value="parcelado">💳 Parcelada (N boletos, termina)</option>
                    <option value="recorrente">🔄 Assinatura recorrente (sem fim)</option>
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

        <div id="modoValorWrap1" style="display:none;margin-bottom:.5rem;padding:.4rem .6rem;background:#f9fafb;border-radius:6px;border:1px solid #e5e7eb;">
            <label style="font-size:.68rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.3px;">O valor que vou digitar é...</label>
            <label style="display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;margin-right:1rem;cursor:pointer;">
                <input type="radio" name="modo_valor" value="total" checked onchange="atualizarCobUI1()"> 📊 Total do contrato
            </label>
            <label style="display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;cursor:pointer;">
                <input type="radio" name="modo_valor" value="parcela" onchange="atualizarCobUI1()"> 🧮 Valor de cada parcela
            </label>
        </div>
        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;"><label id="labelValorCob1" style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Valor total (R$) *</label>
                <input type="text" name="valor" id="valorCob1" class="form-input input-reais" required placeholder="0,00" oninput="atualizarCobUI1()">
            </div>
            <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Vencimento *</label>
                <input type="date" name="vencimento" class="form-input" required value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
            </div>
        </div>

        <div id="camposParcelas" style="display:none;margin-bottom:.6rem;">
            <div style="display:flex;gap:.5rem;">
                <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Nº Parcelas</label>
                    <input type="number" name="num_parcelas" id="parcelasCob1" class="form-input" min="2" max="60" value="12" oninput="atualizarCobUI1()">
                </div>
                <div style="flex:1;"><label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Dia vencimento mensal</label>
                    <input type="number" name="dia_vencimento" class="form-input" min="1" max="28" value="10">
                </div>
            </div>
        </div>
        <div id="previewCob1" style="display:none;background:#f5ebe0;border-left:3px solid #B87333;padding:.5rem .7rem;margin-bottom:.6rem;border-radius:6px;font-size:.75rem;color:#3f2e1c;"></div>
        <script>
        function atualizarCobUI1(){
            var tipo = document.getElementById('tipoCobranca').value;
            var vl = document.getElementById('valorCob1').value || '';
            var valor = parseFloat(vl.replace(/[^\d,]/g,'').replace(',','.')) || 0;
            var parc = parseInt((document.getElementById('parcelasCob1') || {}).value, 10) || 1;
            var mostrarParcelas = (tipo === 'recorrente' || tipo === 'parcelado');
            document.getElementById('camposParcelas').style.display = mostrarParcelas ? 'block' : 'none';

            // Toggle total/parcela só faz sentido em 'parcelado'
            // Recorrente: só por mensalidade (Asaas não aceita total). Única: só total.
            var modoWrap = document.getElementById('modoValorWrap1');
            var modoTotal = (document.querySelector('input[name="modo_valor"][value="total"]') || {}).checked;
            if (tipo === 'parcelado') {
                modoWrap.style.display = 'block';
            } else {
                modoWrap.style.display = 'none';
                // força modo_valor=parcela pra recorrente (Asaas só aceita value por mensalidade)
                if (tipo === 'recorrente') {
                    var rp = document.querySelector('input[name="modo_valor"][value="parcela"]');
                    if (rp) rp.checked = true;
                    modoTotal = false;
                }
            }

            var lbl = document.getElementById('labelValorCob1');
            if (tipo === 'parcelado') {
                lbl.innerHTML = modoTotal
                    ? '📊 <u>Valor total do contrato</u> (R$) — o Asaas divide pelo nº de parcelas *'
                    : '🧮 Valor de <u>cada parcela</u> (R$) — o Asaas multiplica pelo nº de parcelas *';
            } else if (tipo === 'recorrente') {
                lbl.innerHTML = '💡 Valor de <u>cada mensalidade</u> (R$) *';
            } else {
                lbl.innerHTML = 'Valor total (R$) *';
            }

            var prev = document.getElementById('previewCob1');
            if (valor > 0 && parc > 1 && mostrarParcelas) {
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
                    prev.innerHTML = '🔄 Cobrança <b>mensal de R$ ' + pStr + '</b>, sem data de fim. Max ' + parc + ' mensalidades (ou cancele antes).';
                }
                prev.style.display='block';
            } else { prev.style.display='none'; }
        }
        // Compatibilidade: toggleParcelas ainda é chamado em outros lugares do arquivo
        function toggleParcelas(){ atualizarCobUI1(); }
        </script>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Descrição</label>
            <input type="text" name="descricao" class="form-input" placeholder="Ex: Honorários - Alimentos" value="Honorários Advocatícios">
        </div>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Processo vinculado <span style="color:#dc2626;">*</span></label>
            <select name="case_id" id="cobCaseSelect" class="form-select" required>
                <option value="">— Selecione primeiro o cliente acima —</option>
            </select>
            <div style="font-size:.64rem;color:var(--text-muted);margin-top:.2rem;">Selecione o cliente acima — os processos dele vão aparecer aqui automaticamente.</div>
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
