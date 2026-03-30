<?php
/**
 * Ferreira & Sá Hub — Relatórios Completos
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Relatórios';
$pdo = db();

// ─── Filtro de período ─────────────────────────────────
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : 'mes';
$aba = isset($_GET['aba']) ? $_GET['aba'] : 'comercial';

$now = date('Y-m-d');
$inicioMes = date('Y-m-01');
$fimMes = date('Y-m-t');

switch ($periodo) {
    case 'semana':
        $dataInicio = date('Y-m-d', strtotime('monday this week'));
        $dataFim = $now;
        $periodoLabel = 'Esta semana';
        break;
    case 'mes':
        $dataInicio = $inicioMes;
        $dataFim = $fimMes;
        $periodoLabel = date('F/Y');
        break;
    case 'trimestre':
        $mesAtual = (int)date('n');
        $triInicio = (int)(floor(($mesAtual - 1) / 3) * 3 + 1);
        $dataInicio = date('Y') . '-' . str_pad($triInicio, 2, '0', STR_PAD_LEFT) . '-01';
        $dataFim = date('Y-m-t', strtotime($dataInicio . ' +2 months'));
        $periodoLabel = $triInicio . 'º trimestre/' . date('Y');
        break;
    case 'ano':
        $dataInicio = date('Y-01-01');
        $dataFim = date('Y-12-31');
        $periodoLabel = date('Y');
        break;
    case 'custom':
        $dataInicio = isset($_GET['de']) ? $_GET['de'] : $inicioMes;
        $dataFim = isset($_GET['ate']) ? $_GET['ate'] : $now;
        $periodoLabel = date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim));
        break;
    default:
        $dataInicio = $inicioMes;
        $dataFim = $fimMes;
        $periodoLabel = date('F/Y');
}

$meses = array('','Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');
$periodoLabel = str_replace(
    array('January','February','March','April','May','June','July','August','September','October','November','December'),
    array('Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'),
    $periodoLabel
);

// ═══════════════════════════════════════════════════════
// DADOS COMERCIAIS
// ═══════════════════════════════════════════════════════

// Leads no período
$leadsPerido = (int)$pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE DATE(created_at) BETWEEN ? AND ?")->execute(array($dataInicio, $dataFim)) ? (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
$stmtLP = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE DATE(created_at) BETWEEN ? AND ?");
$stmtLP->execute(array($dataInicio, $dataFim));
$leadsPeriodo = (int)$stmtLP->fetchColumn();

// Conversões no período
$stmtConv = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('contrato','preparacao_pasta','pasta_apta','finalizado') AND DATE(converted_at) BETWEEN ? AND ?");
$stmtConv->execute(array($dataInicio, $dataFim));
$conversoesPeriodo = (int)$stmtConv->fetchColumn();

// Perdidos no período
$stmtPerd = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE stage = 'perdido' AND DATE(updated_at) BETWEEN ? AND ?");
$stmtPerd->execute(array($dataInicio, $dataFim));
$perdidosPeriodo = (int)$stmtPerd->fetchColumn();

// Taxa conversão
$taxaConv = $leadsPeriodo > 0 ? round(($conversoesPeriodo / $leadsPeriodo) * 100, 1) : 0;

// Leads por origem no período
$stmtOrig = $pdo->prepare("SELECT source, COUNT(*) as total FROM pipeline_leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY source ORDER BY total DESC");
$stmtOrig->execute(array($dataInicio, $dataFim));
$leadsBySource = $stmtOrig->fetchAll();

// Leads por estágio (todos ativos)
$leadsByStage = $pdo->query("SELECT stage, COUNT(*) as total FROM pipeline_leads WHERE stage NOT IN ('contrato','finalizado','perdido') GROUP BY stage ORDER BY FIELD(stage,'novo','contato_inicial','agendado','proposta','elaboracao','preparacao_pasta','pasta_apta')")->fetchAll();

// Tempo médio no funil (dias) — leads convertidos
$tempoMedio = $pdo->query("SELECT ROUND(AVG(DATEDIFF(COALESCE(converted_at, NOW()), created_at))) as media FROM pipeline_leads WHERE stage IN ('contrato','preparacao_pasta','pasta_apta','finalizado') AND converted_at IS NOT NULL")->fetchColumn();
$tempoMedio = $tempoMedio ?: 0;

// Tendência mensal (últimos 6 meses)
$tendencia = array();
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $stmtT = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
    $stmtT->execute(array($m));
    $novos = (int)$stmtT->fetchColumn();

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('contrato','preparacao_pasta','pasta_apta','finalizado') AND DATE_FORMAT(converted_at, '%Y-%m') = ?");
    $stmtC->execute(array($m));
    $conv = (int)$stmtC->fetchColumn();

    $mesNum = (int)date('n', strtotime($m . '-01'));
    $tendencia[] = array('mes' => substr($meses[$mesNum], 0, 3) . '/' . date('y', strtotime($m . '-01')), 'novos' => $novos, 'convertidos' => $conv);
}

// Funil completo (para gráfico)
$funilStages = array('novo','contato_inicial','agendado','proposta','elaboracao','contrato','preparacao_pasta','pasta_apta');
$funilLabels = array('Novo','Contato','Agendado','Proposta','Elaboração','Contrato','Prep. Pasta','Pasta Apta');
$funilData = array();
foreach ($funilStages as $fs) {
    $stmtF = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE stage = ?");
    $stmtF->execute(array($fs));
    $funilData[] = (int)$stmtF->fetchColumn();
}

// ═══════════════════════════════════════════════════════
// DADOS OPERACIONAIS
// ═══════════════════════════════════════════════════════

// Casos no período
$stmtCA = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE DATE(created_at) BETWEEN ? AND ?");
$stmtCA->execute(array($dataInicio, $dataFim));
$casosNovos = (int)$stmtCA->fetchColumn();

$casosAtivos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('concluido','arquivado')")->fetchColumn();

$stmtCC = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE status = 'concluido' AND DATE(closed_at) BETWEEN ? AND ?");
$stmtCC->execute(array($dataInicio, $dataFim));
$casosConcluidos = (int)$stmtCC->fetchColumn();

$casosUrgentes = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE priority='urgente' AND status NOT IN ('concluido','arquivado')")->fetchColumn();

// Prazos vencidos
$prazosVencidos = $pdo->query(
    "SELECT cs.id, cs.title, cs.deadline, cs.priority, c.name as client_name, u.name as responsible_name
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.deadline < CURDATE() AND cs.status NOT IN ('concluido','arquivado')
     ORDER BY cs.deadline ASC LIMIT 20"
)->fetchAll();

// Casos por status
$casesByStatus = $pdo->query(
    "SELECT status, COUNT(*) as total FROM cases WHERE status NOT IN ('arquivado') GROUP BY status ORDER BY FIELD(status,'aguardando_docs','em_elaboracao','em_andamento','aguardando_prazo','distribuido','concluido')"
)->fetchAll();
$statusLabels = array('aguardando_docs'=>'Aguardando Docs','em_elaboracao'=>'Em Elaboração','em_andamento'=>'Em Execução','aguardando_prazo'=>'Aguardando Prazo','distribuido'=>'Revisão','concluido'=>'Concluído');
$statusColors = array('aguardando_docs'=>'#f59e0b','em_elaboracao'=>'#6366f1','em_andamento'=>'#0ea5e9','aguardando_prazo'=>'#d97706','distribuido'=>'#8b5cf6','concluido'=>'#059669');

// Casos por responsável
$casesByUser = $pdo->query("SELECT u.name, COUNT(*) as total, SUM(CASE WHEN cs.status='concluido' THEN 1 ELSE 0 END) as concluidos FROM cases cs JOIN users u ON u.id = cs.responsible_user_id GROUP BY cs.responsible_user_id ORDER BY total DESC")->fetchAll();

// Casos por tipo
$casesByType = $pdo->query("SELECT case_type, COUNT(*) as total FROM cases WHERE status NOT IN ('arquivado') GROUP BY case_type ORDER BY total DESC")->fetchAll();

// Tempo médio resolução (dias)
$tempoResolucao = $pdo->query("SELECT ROUND(AVG(DATEDIFF(closed_at, opened_at))) FROM cases WHERE status = 'concluido' AND closed_at IS NOT NULL AND opened_at IS NOT NULL")->fetchColumn();
$tempoResolucao = $tempoResolucao ?: 0;

// ═══════════════════════════════════════════════════════
// DADOS ANIVERSARIANTES
// ═══════════════════════════════════════════════════════
$mesAniv = isset($_GET['mes_aniv']) ? (int)$_GET['mes_aniv'] : (int)date('n');

$stmtAniv = $pdo->prepare(
    "SELECT c.id, c.name, c.phone, c.email, c.birth_date, DAY(c.birth_date) as dia,
     TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) as idade
     FROM clients c WHERE c.birth_date IS NOT NULL AND MONTH(c.birth_date) = ?
     ORDER BY DAY(c.birth_date) ASC"
);
$stmtAniv->execute(array($mesAniv));
$aniversariantes = $stmtAniv->fetchAll();

// Contagem por mês (para gráfico)
$anivPorMes = array_fill(1, 12, 0);
$rows = $pdo->query("SELECT MONTH(birth_date) as m, COUNT(*) as t FROM clients WHERE birth_date IS NOT NULL GROUP BY MONTH(birth_date)")->fetchAll();
foreach ($rows as $r) { $anivPorMes[(int)$r['m']] = (int)$r['t']; }

// Atendimento
$ticketsAbertos = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('aberto','em_andamento','aguardando')")->fetchColumn();
$stmtTR = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE status='resolvido' AND DATE(resolved_at) BETWEEN ? AND ?");
$stmtTR->execute(array($dataInicio, $dataFim));
$ticketsResolvidosPeriodo = (int)$stmtTR->fetchColumn();
$formsPendentes = (int)$pdo->query("SELECT COUNT(*) FROM form_submissions WHERE status='novo'")->fetchColumn();

$sourceLabels = array('calculadora'=>'Calculadora','landing'=>'Site','indicacao'=>'Indicação','instagram'=>'Instagram','google'=>'Google','whatsapp'=>'WhatsApp','outro'=>'Outro');

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.rel-filtros { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; margin-bottom:1.25rem; }
.rel-btn { padding:.4rem .75rem; font-size:.75rem; font-weight:600; border:1.5px solid var(--border); border-radius:100px; background:var(--bg-card); color:var(--text-muted); cursor:pointer; text-decoration:none; transition:all var(--transition); }
.rel-btn:hover { border-color:var(--petrol-300); }
.rel-btn.active { background:var(--petrol-900); color:#fff; border-color:var(--petrol-900); }
.rel-tabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:1.5rem; }
.rel-tab { padding:.6rem 1.25rem; font-size:.85rem; font-weight:600; color:var(--text-muted); cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; text-decoration:none; transition:all var(--transition); }
.rel-tab:hover { color:var(--petrol-500); }
.rel-tab.active { color:var(--petrol-900); border-bottom-color:var(--rose); }
.rel-section { display:none; }
.rel-section.active { display:block; }
.report-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
.chart-box { position:relative; height:250px; }
.bar-chart { display:flex; flex-direction:column; gap:.4rem; }
.bar-row { display:flex; align-items:center; gap:.5rem; }
.bar-label { width:90px; font-size:.72rem; font-weight:600; color:var(--text-muted); text-align:right; flex-shrink:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.bar-track { flex:1; height:22px; background:var(--bg); border-radius:6px; overflow:hidden; }
.bar-fill { height:100%; border-radius:6px; display:flex; align-items:center; padding:0 .5rem; font-size:.65rem; font-weight:700; color:#fff; min-width:fit-content; }
.prazo-vencido { background:var(--bg-card); border-radius:var(--radius); padding:.6rem .85rem; border-left:4px solid #ef4444; margin-bottom:.4rem; display:flex; align-items:center; justify-content:space-between; gap:.5rem; }
.prazo-vencido .info { flex:1; }
.prazo-vencido .titulo { font-weight:700; font-size:.8rem; color:var(--petrol-900); }
.prazo-vencido .meta { font-size:.68rem; color:var(--text-muted); }
.prazo-vencido .dias { font-size:.72rem; font-weight:700; color:#ef4444; flex-shrink:0; }
.export-btn { display:inline-flex; align-items:center; gap:.3rem; padding:.4rem .75rem; font-size:.72rem; font-weight:600; background:var(--petrol-100); color:var(--petrol-900); border:1px solid var(--border); border-radius:8px; cursor:pointer; text-decoration:none; }
.export-btn:hover { background:var(--petrol-900); color:#fff; }
</style>

<!-- Filtro de período -->
<div class="rel-filtros">
    <span style="font-size:.75rem;color:var(--text-muted);font-weight:600;">Período:</span>
    <?php
    $periodos = array('semana'=>'Semana','mes'=>'Mês','trimestre'=>'Trimestre','ano'=>'Ano','custom'=>'Personalizado');
    foreach ($periodos as $pk => $pl): ?>
        <a href="?aba=<?= $aba ?>&periodo=<?= $pk ?><?= $pk === 'custom' ? '&de=' . $dataInicio . '&ate=' . $dataFim : '' ?>&mes_aniv=<?= $mesAniv ?>" class="rel-btn <?= $periodo === $pk ? 'active' : '' ?>"><?= $pl ?></a>
    <?php endforeach; ?>
    <?php if ($periodo === 'custom'): ?>
        <form method="GET" style="display:flex;gap:.35rem;align-items:center;">
            <input type="hidden" name="aba" value="<?= $aba ?>">
            <input type="hidden" name="periodo" value="custom">
            <input type="date" name="de" value="<?= $dataInicio ?>" class="form-input" style="font-size:.72rem;padding:.3rem .5rem;">
            <span style="font-size:.72rem;">a</span>
            <input type="date" name="ate" value="<?= $dataFim ?>" class="form-input" style="font-size:.72rem;padding:.3rem .5rem;">
            <button type="submit" class="rel-btn active">Aplicar</button>
        </form>
    <?php endif; ?>
    <span style="font-size:.72rem;color:var(--rose-dark);font-weight:600;margin-left:auto;"><?= $periodoLabel ?></span>
</div>

<!-- Abas -->
<div class="rel-tabs">
    <a href="?aba=comercial&periodo=<?= $periodo ?>&de=<?= $dataInicio ?>&ate=<?= $dataFim ?>&mes_aniv=<?= $mesAniv ?>" class="rel-tab <?= $aba === 'comercial' ? 'active' : '' ?>">📈 Comercial</a>
    <a href="?aba=operacional&periodo=<?= $periodo ?>&de=<?= $dataInicio ?>&ate=<?= $dataFim ?>&mes_aniv=<?= $mesAniv ?>" class="rel-tab <?= $aba === 'operacional' ? 'active' : '' ?>">⚙️ Operacional</a>
    <a href="?aba=aniversariantes&periodo=<?= $periodo ?>&de=<?= $dataInicio ?>&ate=<?= $dataFim ?>&mes_aniv=<?= $mesAniv ?>" class="rel-tab <?= $aba === 'aniversariantes' ? 'active' : '' ?>">🎂 Aniversariantes</a>
    <a href="?aba=atendimento&periodo=<?= $periodo ?>&de=<?= $dataInicio ?>&ate=<?= $dataFim ?>&mes_aniv=<?= $mesAniv ?>" class="rel-tab <?= $aba === 'atendimento' ? 'active' : '' ?>">🎫 Atendimento</a>
</div>

<!-- ═══════════════════ COMERCIAL ═══════════════════ -->
<div class="rel-section <?= $aba === 'comercial' ? 'active' : '' ?>">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon info">📈</div><div class="stat-info"><div class="stat-value"><?= $leadsPeriodo ?></div><div class="stat-label">Leads no período</div></div></div>
        <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $conversoesPeriodo ?></div><div class="stat-label">Conversões</div></div></div>
        <div class="stat-card"><div class="stat-icon danger">❌</div><div class="stat-info"><div class="stat-value"><?= $perdidosPeriodo ?></div><div class="stat-label">Perdidos</div></div></div>
        <div class="stat-card"><div class="stat-icon warning">📊</div><div class="stat-info"><div class="stat-value"><?= $taxaConv ?>%</div><div class="stat-label">Taxa conversão</div></div></div>
        <div class="stat-card"><div class="stat-icon rose">⏱️</div><div class="stat-info"><div class="stat-value"><?= $tempoMedio ?>d</div><div class="stat-label">Tempo médio funil</div></div></div>
    </div>

    <div class="report-grid">
        <!-- Tendência 6 meses -->
        <div class="card">
            <div class="card-header"><h3>Tendência (6 meses)</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="chartTendencia"></canvas></div></div>
        </div>
        <!-- Funil -->
        <div class="card">
            <div class="card-header"><h3>Funil Comercial</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="chartFunil"></canvas></div></div>
        </div>
    </div>

    <div class="report-grid">
        <div class="card">
            <div class="card-header"><h3>Leads por Origem</h3><a href="<?= module_url('relatorios', 'exportar.php?tipo=leads_origem&de=' . $dataInicio . '&ate=' . $dataFim) ?>" class="export-btn">📥 CSV</a></div>
            <div class="card-body">
                <div class="bar-chart">
                    <?php $maxL = 1; foreach ($leadsBySource as $ls) { if ($ls['total'] > $maxL) $maxL = $ls['total']; } ?>
                    <?php foreach ($leadsBySource as $ls): ?>
                    <div class="bar-row">
                        <span class="bar-label"><?= isset($sourceLabels[$ls['source']]) ? $sourceLabels[$ls['source']] : $ls['source'] ?></span>
                        <div class="bar-track"><div class="bar-fill" style="width:<?= round(($ls['total']/$maxL)*100) ?>%;background:var(--petrol-500);"><?= $ls['total'] ?></div></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($leadsBySource)): ?><p class="text-muted text-sm">Sem dados no período</p><?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3>Leads por Estágio (ativos)</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="chartEstagios"></canvas></div></div>
        </div>
    </div>
</div>

<!-- ═══════════════════ OPERACIONAL ═══════════════════ -->
<div class="rel-section <?= $aba === 'operacional' ? 'active' : '' ?>">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon petrol">📂</div><div class="stat-info"><div class="stat-value"><?= $casosAtivos ?></div><div class="stat-label">Casos ativos</div></div></div>
        <div class="stat-card"><div class="stat-icon info">🆕</div><div class="stat-info"><div class="stat-value"><?= $casosNovos ?></div><div class="stat-label">Novos no período</div></div></div>
        <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $casosConcluidos ?></div><div class="stat-label">Concluídos no período</div></div></div>
        <div class="stat-card"><div class="stat-icon danger">🔴</div><div class="stat-info"><div class="stat-value"><?= $casosUrgentes ?></div><div class="stat-label">Urgentes</div></div></div>
        <div class="stat-card"><div class="stat-icon rose">⏱️</div><div class="stat-info"><div class="stat-value"><?= $tempoResolucao ?>d</div><div class="stat-label">Tempo médio resolução</div></div></div>
    </div>

    <div class="report-grid">
        <div class="card">
            <div class="card-header"><h3>Casos por Status</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="chartStatus"></canvas></div></div>
        </div>
        <div class="card">
            <div class="card-header"><h3>Produtividade por Responsável</h3><a href="<?= module_url('relatorios', 'exportar.php?tipo=produtividade&de=' . $dataInicio . '&ate=' . $dataFim) ?>" class="export-btn">📥 CSV</a></div>
            <div class="card-body">
                <div class="bar-chart">
                    <?php $maxU = 1; foreach ($casesByUser as $cu) { if ($cu['total'] > $maxU) $maxU = $cu['total']; } ?>
                    <?php foreach ($casesByUser as $cu): ?>
                    <div class="bar-row">
                        <span class="bar-label"><?= e(explode(' ', $cu['name'])[0]) ?></span>
                        <div class="bar-track">
                            <div class="bar-fill" style="width:<?= round(($cu['total']/$maxU)*100) ?>%;background:var(--info);">
                                <?= $cu['total'] ?> (<?= $cu['concluidos'] ?> ok)
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($casesByUser)): ?><p class="text-muted text-sm">Sem dados</p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="report-grid">
        <div class="card">
            <div class="card-header"><h3>Casos por Tipo</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="chartTipos"></canvas></div></div>
        </div>
        <!-- Prazos vencidos -->
        <div class="card">
            <div class="card-header"><h3>Prazos Vencidos (<?= count($prazosVencidos) ?>)</h3></div>
            <div class="card-body" style="max-height:300px;overflow-y:auto;">
                <?php if (empty($prazosVencidos)): ?>
                    <p class="text-muted text-sm" style="text-align:center;padding:1rem;">Nenhum prazo vencido!</p>
                <?php else: ?>
                    <?php foreach ($prazosVencidos as $pv):
                        $diasVencido = (int)((strtotime('today') - strtotime($pv['deadline'])) / 86400);
                    ?>
                    <div class="prazo-vencido">
                        <div class="info">
                            <div class="titulo"><?= e($pv['title'] ?: 'Caso #' . $pv['id']) ?></div>
                            <div class="meta">👤 <?= e($pv['client_name'] ?: '—') ?> · <?= e($pv['responsible_name'] ? explode(' ', $pv['responsible_name'])[0] : '—') ?></div>
                        </div>
                        <div class="dias">-<?= $diasVencido ?>d (<?= date('d/m', strtotime($pv['deadline'])) ?>)</div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ ANIVERSARIANTES ═══════════════════ -->
<div class="rel-section <?= $aba === 'aniversariantes' ? 'active' : '' ?>">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
        <div style="display:flex;gap:.25rem;flex-wrap:wrap;">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <a href="?aba=aniversariantes&periodo=<?= $periodo ?>&de=<?= $dataInicio ?>&ate=<?= $dataFim ?>&mes_aniv=<?= $m ?>" class="rel-btn <?= $mesAniv === $m ? 'active' : '' ?>"><?= substr($meses[$m], 0, 3) ?> (<?= $anivPorMes[$m] ?>)</a>
            <?php endfor; ?>
        </div>
        <a href="<?= module_url('relatorios', 'exportar.php?tipo=aniversariantes&mes=' . $mesAniv) ?>" class="export-btn">📥 Exportar <?= $meses[$mesAniv] ?></a>
    </div>

    <div class="report-grid">
        <div class="card">
            <div class="card-header"><h3>Distribuição Anual</h3></div>
            <div class="card-body"><div class="chart-box"><canvas id="chartAniv"></canvas></div></div>
        </div>
        <div class="card">
            <div class="card-header"><h3>Aniversariantes de <?= $meses[$mesAniv] ?> (<?= count($aniversariantes) ?>)</h3></div>
            <div class="card-body" style="max-height:350px;overflow-y:auto;">
                <?php if (empty($aniversariantes)): ?>
                    <p class="text-muted text-sm" style="text-align:center;padding:1rem;">Nenhum aniversariante em <?= $meses[$mesAniv] ?></p>
                <?php else: ?>
                    <table style="width:100%;font-size:.78rem;">
                        <thead><tr><th style="text-align:left;">Dia</th><th style="text-align:left;">Nome</th><th>Idade</th><th>Telefone</th><th>E-mail</th></tr></thead>
                        <tbody>
                        <?php foreach ($aniversariantes as $a): ?>
                            <tr>
                                <td style="font-weight:700;"><?= str_pad($a['dia'], 2, '0', STR_PAD_LEFT) ?></td>
                                <td><?= e($a['name']) ?></td>
                                <td style="text-align:center;"><?= $a['idade'] ?></td>
                                <td><?= $a['phone'] ? '<a href="https://wa.me/55' . preg_replace('/\D/', '', $a['phone']) . '" target="_blank" style="color:var(--success);">' . e($a['phone']) . '</a>' : '—' ?></td>
                                <td class="text-muted"><?= e($a['email'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════ ATENDIMENTO ═══════════════════ -->
<div class="rel-section <?= $aba === 'atendimento' ? 'active' : '' ?>">
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon warning">🎫</div><div class="stat-info"><div class="stat-value"><?= $ticketsAbertos ?></div><div class="stat-label">Tickets abertos</div></div></div>
        <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $ticketsResolvidosPeriodo ?></div><div class="stat-label">Resolvidos no período</div></div></div>
        <div class="stat-card"><div class="stat-icon info">📋</div><div class="stat-info"><div class="stat-value"><?= $formsPendentes ?></div><div class="stat-label">Formulários pendentes</div></div></div>
    </div>
</div>

<!-- ═══════════════════ CENTRAL DE EXPORTAÇÕES ═══════════════════ -->
<div class="card" style="margin-top:1.5rem;">
    <div class="card-header"><h3>📥 Central de Exportações (CSV)</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;">
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=clientes') ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                👥 Clientes Completo
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=pipeline') ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                📈 Pipeline Completo
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=casos') ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                ⚙️ Casos Operacionais
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=contratos&de=' . $dataInicio . '&ate=' . $dataFim) ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                ✅ Contratos (<?= $periodoLabel ?>)
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=leads_origem&de=' . $dataInicio . '&ate=' . $dataFim) ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                📊 Leads por Origem (<?= $periodoLabel ?>)
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=docs_faltantes') ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                ⚠️ Documentos Faltantes
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=formularios&de=' . $dataInicio . '&ate=' . $dataFim) ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                📋 Formulários (<?= $periodoLabel ?>)
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=produtividade') ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                📉 Produtividade
            </a>
            <a href="<?= module_url('relatorios', 'exportar.php?tipo=aniversariantes&mes=' . $mesAniv) ?>" class="export-btn" style="padding:.75rem 1rem;font-size:.8rem;">
                🎂 Aniversariantes (<?= $meses[$mesAniv] ?>)
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
var fontFamily = "'Open Sans', sans-serif";
var defaultOpts = { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ labels:{ font:{ family:fontFamily, size:11 } } } }, scales:{ x:{ ticks:{ font:{ family:fontFamily, size:10 } } }, y:{ ticks:{ font:{ family:fontFamily, size:10 } } } } };

<?php if ($aba === 'comercial'): ?>
// Tendência
new Chart(document.getElementById('chartTendencia'), {
    type:'line',
    data:{
        labels:<?= json_encode(array_column($tendencia, 'mes')) ?>,
        datasets:[
            { label:'Novos leads', data:<?= json_encode(array_column($tendencia, 'novos')) ?>, borderColor:'#6366f1', backgroundColor:'rgba(99,102,241,.1)', fill:true, tension:.3 },
            { label:'Convertidos', data:<?= json_encode(array_column($tendencia, 'convertidos')) ?>, borderColor:'#059669', backgroundColor:'rgba(5,150,105,.1)', fill:true, tension:.3 }
        ]
    },
    options:defaultOpts
});

// Funil
new Chart(document.getElementById('chartFunil'), {
    type:'bar',
    data:{
        labels:<?= json_encode($funilLabels) ?>,
        datasets:[{ label:'Leads', data:<?= json_encode($funilData) ?>, backgroundColor:['#6366f1','#0ea5e9','#f59e0b','#d97706','#8b5cf6','#059669','#0d9488','#15803d'] }]
    },
    options:Object.assign({}, defaultOpts, { plugins:{ legend:{ display:false } }, indexAxis:'y' })
});

// Estágios (pizza)
new Chart(document.getElementById('chartEstagios'), {
    type:'doughnut',
    data:{
        labels:<?= json_encode(array_map(function($s) use ($sourceLabels) { $map = array('novo'=>'Novo','contato_inicial'=>'Contato','agendado'=>'Agendado','proposta'=>'Proposta','elaboracao'=>'Elaboração','preparacao_pasta'=>'Prep. Pasta','pasta_apta'=>'Pasta Apta'); return isset($map[$s['stage']]) ? $map[$s['stage']] : $s['stage']; }, $leadsByStage)) ?>,
        datasets:[{ data:<?= json_encode(array_column($leadsByStage, 'total')) ?>, backgroundColor:['#6366f1','#0ea5e9','#f59e0b','#d97706','#8b5cf6','#0d9488','#15803d'] }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'right', labels:{ font:{ family:fontFamily, size:10 } } } } }
});
<?php endif; ?>

<?php if ($aba === 'operacional'): ?>
// Status (pizza)
new Chart(document.getElementById('chartStatus'), {
    type:'doughnut',
    data:{
        labels:<?= json_encode(array_map(function($s) use ($statusLabels) { return isset($statusLabels[$s['status']]) ? $statusLabels[$s['status']] : $s['status']; }, $casesByStatus)) ?>,
        datasets:[{ data:<?= json_encode(array_column($casesByStatus, 'total')) ?>, backgroundColor:<?= json_encode(array_values(array_map(function($s) use ($statusColors) { return isset($statusColors[$s['status']]) ? $statusColors[$s['status']] : '#9ca3af'; }, $casesByStatus))) ?> }]
    },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'right', labels:{ font:{ family:fontFamily, size:10 } } } } }
});

// Tipos (bar)
new Chart(document.getElementById('chartTipos'), {
    type:'bar',
    data:{
        labels:<?= json_encode(array_column($casesByType, 'case_type')) ?>,
        datasets:[{ label:'Casos', data:<?= json_encode(array_column($casesByType, 'total')) ?>, backgroundColor:'#173d46' }]
    },
    options:Object.assign({}, defaultOpts, { plugins:{ legend:{ display:false } } })
});
<?php endif; ?>

<?php if ($aba === 'aniversariantes'): ?>
new Chart(document.getElementById('chartAniv'), {
    type:'bar',
    data:{
        labels:<?= json_encode(array_map(function($i) use ($meses) { return substr($meses[$i], 0, 3); }, range(1, 12))) ?>,
        datasets:[{ label:'Aniversariantes', data:<?= json_encode(array_values($anivPorMes)) ?>, backgroundColor:function(ctx) { return ctx.dataIndex + 1 === <?= $mesAniv ?> ? '#d7ab90' : '#173d46'; } }]
    },
    options:Object.assign({}, defaultOpts, { plugins:{ legend:{ display:false } } })
});
<?php endif; ?>
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
