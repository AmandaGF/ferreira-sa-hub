<?php
/**
 * Ferreira & Sá Hub — Dashboard (Geral / Comercial / Operacional)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Dashboard';
$user = current_user();
$role = current_user_role();
$pdo = db();
$firstName = explode(' ', $user['name'])[0];

$mesAtual = date('Y-m');
$mesAnterior = date('Y-m', strtotime('-1 month'));
$mesLabel = array('','Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez');

// ═══════════════════════════════════════════════════════════
// MÉTRICAS GERAIS
// ═══════════════════════════════════════════════════════════

$totalClientes = 0;
try { $totalClientes = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn(); } catch (Exception $e) {}

$pendencias = 0; $formsPendentes = 0; $ticketsAbertos = 0;
try { $formsPendentes = (int)$pdo->query("SELECT COUNT(*) FROM form_submissions WHERE status = 'novo'")->fetchColumn(); } catch (Exception $e) {}
try { $ticketsAbertos = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('aberto','em_andamento','aguardando')")->fetchColumn(); } catch (Exception $e) {}
$pendencias = $formsPendentes + $ticketsAbertos;

$docsFaltantes = 0;
try { $docsFaltantes = (int)$pdo->query("SELECT COUNT(*) FROM documentos_pendentes WHERE status = 'pendente'")->fetchColumn(); } catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// MÉTRICAS COMERCIAL
// ═══════════════════════════════════════════════════════════

// Leads ativos
$leadsAtivos = 0; $leadsHoje = 0; $leadsMes = 0;
try {
    $leadsAtivos = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage NOT IN ('finalizado','perdido','cancelado')")->fetchColumn();
    $leadsHoje = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $leadsMes = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE DATE_FORMAT(created_at, '%Y-%m') = '$mesAtual'")->fetchColumn();
} catch (Exception $e) {}

// Contratos fechados NO MÊS (pela data de conversão)
$contratosMes = 0; $contratosMesAnterior = 0; $contratosTotal = 0;
try {
    $contratosMes = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at, '%Y-%m') = '$mesAtual'")->fetchColumn();
    $contratosMesAnterior = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at, '%Y-%m') = '$mesAnterior'")->fetchColumn();
    $contratosTotal = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')")->fetchColumn();
} catch (Exception $e) {}

// Cancelamentos — com contexto do mês original
$canceladosMes = 0; $canceladosTotal = 0;
$canceladosDetalhe = array();
try {
    $canceladosTotal = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('cancelado','perdido')")->fetchColumn();
    $canceladosTotal += (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'cancelado'")->fetchColumn();
    $canceladosMes = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('cancelado','perdido') AND DATE_FORMAT(updated_at, '%Y-%m') = '$mesAtual'")->fetchColumn();
    $canceladosMes += (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'cancelado' AND DATE_FORMAT(updated_at, '%Y-%m') = '$mesAtual'")->fetchColumn();

    // Cancelados este mês — mostrar quando o contrato foi originalmente fechado
    $canceladosDetalhe = $pdo->query(
        "SELECT name, DATE_FORMAT(converted_at, '%m/%Y') as mes_contrato, DATE_FORMAT(updated_at, '%d/%m') as data_cancel
         FROM pipeline_leads
         WHERE stage IN ('cancelado','perdido')
         AND DATE_FORMAT(updated_at, '%Y-%m') = '$mesAtual'
         ORDER BY updated_at DESC LIMIT 10"
    )->fetchAll();
} catch (Exception $e) {}

// Taxa de conversão últimos 6 meses
$convLabels = array(); $convData = array(); $convLeads = array(); $convConvertidos = array();
for ($i = 5; $i >= 0; $i--) {
    $mes = date('Y-m', strtotime("-$i months"));
    $mesNum = (int)date('n', strtotime("-$i months"));
    $convLabels[] = $mesLabel[$mesNum];
    $total = 0; $convertidos = 0;
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE DATE_FORMAT(created_at, '%Y-%m') = '$mes'")->fetchColumn();
        $convertidos = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at, '%Y-%m') = '$mes'")->fetchColumn();
    } catch (Exception $e) {}
    $convLeads[] = $total;
    $convConvertidos[] = $convertidos;
    $convData[] = $total > 0 ? round(($convertidos / $total) * 100) : 0;
}

// Pipeline por estágio
$pipeStages = array(
    'cadastro_preenchido' => 0, 'elaboracao_docs' => 0, 'link_enviados' => 0,
    'contrato_assinado' => 0, 'agendado_docs' => 0, 'reuniao_cobranca' => 0,
    'pasta_apta' => 0, 'perdido' => 0
);
try {
    $rows = $pdo->query("SELECT stage, COUNT(*) as total FROM pipeline_leads GROUP BY stage")->fetchAll();
    foreach ($rows as $r) { if (isset($pipeStages[$r['stage']])) $pipeStages[$r['stage']] = (int)$r['total']; }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// MÉTRICAS OPERACIONAL
// ═══════════════════════════════════════════════════════════

$casosAtivos = 0; $casosDistribuidosMes = 0; $casosDistribuidosMesAnt = 0;
$casosEmAndamento = 0; $casosSuspensos = 0; $casosArquivadosMes = 0;
try {
    $casosAtivos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('concluido','arquivado','cancelado','renunciamos')")->fetchColumn();
    $casosEmAndamento = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'em_andamento'")->fetchColumn();
    $casosSuspensos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'suspenso'")->fetchColumn();
    $casosDistribuidosMes = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'distribuido' AND DATE_FORMAT(distribution_date, '%Y-%m') = '$mesAtual'")->fetchColumn();
    $casosDistribuidosMesAnt = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'distribuido' AND DATE_FORMAT(distribution_date, '%Y-%m') = '$mesAnterior'")->fetchColumn();
    $casosArquivadosMes = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status IN ('concluido','arquivado') AND DATE_FORMAT(updated_at, '%Y-%m') = '$mesAtual'")->fetchColumn();
} catch (Exception $e) {}

// Processos por status (para gráfico)
$casosPorStatus = array();
try {
    $casosPorStatus = $pdo->query("SELECT status, COUNT(*) as total FROM cases GROUP BY status ORDER BY total DESC")->fetchAll();
} catch (Exception $e) {}

// Produtividade por responsável
$prodLabels = array(); $prodCasos = array(); $prodContratos = array();
try {
    $prodRows = $pdo->query(
        "SELECT u.name,
         (SELECT COUNT(*) FROM cases c WHERE c.responsible_user_id = u.id AND c.status NOT IN ('concluido','arquivado','cancelado','renunciamos')) as casos,
         (SELECT COUNT(*) FROM pipeline_leads pl WHERE pl.assigned_to = u.id AND pl.converted_at IS NOT NULL AND DATE_FORMAT(pl.converted_at, '%Y-%m') = '$mesAtual') as contratos_mes
         FROM users u WHERE u.is_active = 1 ORDER BY u.name"
    )->fetchAll();
    foreach ($prodRows as $r) {
        $prodLabels[] = explode(' ', $r['name'])[0];
        $prodCasos[] = (int)$r['casos'];
        $prodContratos[] = (int)$r['contratos_mes'];
    }
} catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// METAS (configuráveis)
// ═══════════════════════════════════════════════════════════
$metas = array(
    'leads_mes' => 50,
    'contratos_mes' => 10,
    'casos_entregues_mes' => 5,
);

// ═══════════════════════════════════════════════════════════
// ATIVIDADES + ANIVERSÁRIOS
// ═══════════════════════════════════════════════════════════
$atividades = array();
try {
    $atividades = $pdo->query(
        "SELECT al.action, al.entity_type, al.details, al.created_at, u.name as user_name
         FROM audit_log al LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action NOT IN ('login','logout','login_failed')
         ORDER BY al.created_at DESC LIMIT 8"
    )->fetchAll();
} catch (Exception $e) {}

$aniversariantes = array();
try {
    $aniversariantes = $pdo->query(
        "SELECT name, phone, email, birth_date, TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) as idade
         FROM clients WHERE birth_date IS NOT NULL AND DATE_FORMAT(birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d') ORDER BY name"
    )->fetchAll();
} catch (Exception $e) {}

$proxAniversarios = array();
try {
    $proxAniversarios = $pdo->query(
        "SELECT name, birth_date, DATE_FORMAT(birth_date, '%d/%m') as data_fmt,
         DATEDIFF(DATE_ADD(birth_date, INTERVAL TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) + IF(DATE_FORMAT(birth_date, '%m%d') <= DATE_FORMAT(CURDATE(), '%m%d'), 1, 0) YEAR), CURDATE()) as dias_faltam
         FROM clients WHERE birth_date IS NOT NULL AND DATE_FORMAT(birth_date, '%m-%d') != DATE_FORMAT(CURDATE(), '%m-%d')
         HAVING dias_faltam BETWEEN 1 AND 7 ORDER BY dias_faltam ASC LIMIT 5"
    )->fetchAll();
} catch (Exception $e) {}

$tab = $_GET['tab'] ?? 'geral';

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.dash-welcome { background:linear-gradient(135deg,#052228 0%,#0d3640 50%,#173d46 100%); border-radius:var(--radius-lg); padding:1.5rem 2rem; color:#fff; margin-bottom:1.25rem; display:flex; justify-content:space-between; align-items:center; border:1px solid rgba(215,171,144,.15); }
.dash-welcome h2 { font-size:1.2rem; font-weight:800; margin-bottom:.25rem; }
.dash-welcome .role-badge { color:var(--rose); font-size:.82rem; }
.dash-welcome .date { color:rgba(255,255,255,.5); font-size:.78rem; }

.dash-tabs { display:flex; gap:0; margin-bottom:1.25rem; border-bottom:2px solid var(--border); }
.dash-tab { padding:.6rem 1.5rem; font-size:.85rem; font-weight:700; color:var(--text-muted); cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; text-decoration:none; transition:all .2s; }
.dash-tab:hover { color:var(--petrol-900); }
.dash-tab.active { color:var(--petrol-900); border-bottom-color:#B87333; }

.kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:.75rem; margin-bottom:1.25rem; }
.kpi-card { background:var(--bg-card); border-radius:var(--radius-lg); padding:1.1rem 1.25rem; border:1px solid var(--border); display:flex; align-items:center; gap:.85rem; transition:all var(--transition); }
.kpi-card:hover { box-shadow:var(--shadow-md); transform:translateY(-2px); }
.kpi-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
.kpi-icon.blue { background:rgba(99,102,241,.15); }
.kpi-icon.green { background:rgba(5,150,105,.15); }
.kpi-icon.orange { background:rgba(249,115,22,.15); }
.kpi-icon.red { background:rgba(239,68,68,.15); }
.kpi-icon.rose { background:rgba(215,171,144,.15); }
.kpi-icon.purple { background:rgba(139,92,246,.15); }
.kpi-value { font-size:1.6rem; font-weight:800; color:var(--petrol-900); line-height:1; }
.kpi-label { font-size:.72rem; color:var(--text-muted); margin-top:.15rem; text-transform:uppercase; letter-spacing:.5px; }
.kpi-sub { font-size:.68rem; color:var(--rose); font-weight:600; margin-top:.1rem; }

.meta-bar { height:6px; background:#e5e7eb; border-radius:3px; margin-top:.4rem; overflow:hidden; }
.meta-fill { height:100%; border-radius:3px; transition:width .5s; }
.meta-text { font-size:.62rem; color:var(--text-muted); margin-top:.15rem; }

.charts-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
.chart-card { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.25rem; }
.chart-card h4 { font-size:.88rem; font-weight:700; color:var(--petrol-900); margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }
.chart-card canvas { max-height:220px; }

.bottom-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-bottom:1.25rem; }
.activity-card,.birthday-card { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.25rem; }
.activity-card h4,.birthday-card h4 { font-size:.88rem; font-weight:700; color:var(--petrol-900); margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }

.activity-item { display:flex; gap:.75rem; padding:.6rem 0; border-bottom:1px solid var(--border); align-items:flex-start; }
.activity-item:last-child { border-bottom:none; }
.activity-dot { width:8px; height:8px; border-radius:50%; margin-top:.35rem; flex-shrink:0; }
.activity-dot.green { background:#059669; } .activity-dot.blue { background:#6366f1; } .activity-dot.orange { background:#f59e0b; } .activity-dot.red { background:#ef4444; }
.activity-text { font-size:.78rem; color:var(--text); line-height:1.4; }
.activity-text strong { color:var(--petrol-900); }
.activity-time { font-size:.65rem; color:var(--text-muted); margin-top:.15rem; }

.bday-item { display:flex; align-items:center; gap:.75rem; padding:.6rem 0; border-bottom:1px solid var(--border); }
.bday-item:last-child { border-bottom:none; }
.bday-avatar { width:36px; height:36px; border-radius:50%; background:linear-gradient(135deg,var(--rose),var(--rose-dark)); color:#fff; display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; flex-shrink:0; }
.bday-name { font-size:.82rem; font-weight:700; color:var(--petrol-900); }
.bday-detail { font-size:.7rem; color:var(--text-muted); }
.bday-tag { font-size:.65rem; font-weight:700; padding:.2rem .5rem; border-radius:6px; color:#fff; }
.bday-tag.today { background:#059669; } .bday-tag.soon { background:#6366f1; }

.funnel-card { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.25rem; margin-bottom:1.25rem; }
.funnel-card h4 { font-size:.88rem; font-weight:700; color:var(--petrol-900); margin-bottom:1rem; }
.funnel-bar { display:flex; gap:.35rem; height:32px; border-radius:var(--radius); overflow:hidden; margin-bottom:.5rem; }
.funnel-segment { display:flex; align-items:center; justify-content:center; font-size:.65rem; font-weight:700; color:#fff; min-width:24px; }
.funnel-legend { display:flex; flex-wrap:wrap; gap:.5rem .75rem; margin-top:.5rem; }
.funnel-legend-item { display:flex; align-items:center; gap:.3rem; font-size:.7rem; color:var(--text-muted); }
.funnel-legend-dot { width:10px; height:10px; border-radius:3px; }

.cancel-detail { font-size:.75rem; padding:.4rem .6rem; border-left:3px solid #dc2626; margin-bottom:.3rem; background:#fef2f2; border-radius:0 6px 6px 0; }
.cancel-detail .nome { font-weight:600; color:#dc2626; }
.cancel-detail .info { color:var(--text-muted); font-size:.68rem; }

.stat-compare { display:flex; align-items:center; gap:.3rem; font-size:.68rem; font-weight:600; margin-top:.1rem; }
.stat-up { color:#059669; } .stat-down { color:#dc2626; } .stat-equal { color:var(--text-muted); }

@media (max-width:1024px) { .kpi-grid { grid-template-columns:repeat(2,1fr); } .charts-row,.bottom-row { grid-template-columns:1fr; } }
@media (max-width:600px) { .kpi-grid { grid-template-columns:1fr; } .dash-welcome { flex-direction:column; text-align:center; } }
</style>

<!-- Bem-vindo -->
<div class="dash-welcome">
    <div>
        <h2>Bem-vindo(a), <?= e($firstName) ?>!</h2>
        <span class="role-badge"><?= e(role_label($role)) ?></span>
        <span class="date"> — <?= date('d/m/Y') ?></span>
    </div>
</div>

<!-- Abas -->
<div class="dash-tabs">
    <a href="?tab=geral" class="dash-tab <?= $tab === 'geral' ? 'active' : '' ?>">Geral</a>
    <?php if (has_role('admin','gestao','comercial','cx')): ?>
        <a href="?tab=comercial" class="dash-tab <?= $tab === 'comercial' ? 'active' : '' ?>">Comercial</a>
    <?php endif; ?>
    <?php if (has_role('admin','gestao','operacional')): ?>
        <a href="?tab=operacional" class="dash-tab <?= $tab === 'operacional' ? 'active' : '' ?>">Operacional</a>
    <?php endif; ?>
</div>

<?php if ($tab === 'geral'): ?>
<!-- ═══════════════ ABA GERAL ═══════════════ -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon green">✅</div>
        <div>
            <div class="kpi-value"><?= $contratosMes ?></div>
            <div class="kpi-label">Contratos no mês</div>
            <?php $diffC = $contratosMes - $contratosMesAnterior; ?>
            <div class="stat-compare <?= $diffC > 0 ? 'stat-up' : ($diffC < 0 ? 'stat-down' : 'stat-equal') ?>">
                <?= $diffC > 0 ? '↑' : ($diffC < 0 ? '↓' : '=') ?> <?= abs($diffC) ?> vs mês anterior
            </div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon blue">📈</div>
        <div>
            <div class="kpi-value"><?= $leadsAtivos ?></div>
            <div class="kpi-label">Leads Ativos</div>
            <?php if ($leadsHoje > 0): ?><div class="kpi-sub">+<?= $leadsHoje ?> hoje</div><?php endif; ?>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon orange">⚙️</div>
        <div>
            <div class="kpi-value"><?= $casosAtivos ?></div>
            <div class="kpi-label">Processos Ativos</div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon rose">⚠️</div>
        <div>
            <div class="kpi-value"><?= $pendencias ?></div>
            <div class="kpi-label">Pendências</div>
            <div class="kpi-sub"><?= $formsPendentes ?> forms · <?= $ticketsAbertos ?> tickets<?= $docsFaltantes > 0 ? " · $docsFaltantes docs" : '' ?></div>
        </div>
    </div>
</div>

<!-- Metas do mês -->
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <?php
    $metaItems = array(
        array('label' => 'Leads / Meta', 'atual' => $leadsMes, 'meta' => $metas['leads_mes'], 'cor' => '#6366f1'),
        array('label' => 'Contratos / Meta', 'atual' => $contratosMes, 'meta' => $metas['contratos_mes'], 'cor' => '#059669'),
        array('label' => 'Entregas / Meta', 'atual' => $casosArquivadosMes, 'meta' => $metas['casos_entregues_mes'], 'cor' => '#B87333'),
    );
    foreach ($metaItems as $mi):
        $pct = $mi['meta'] > 0 ? min(100, round(($mi['atual'] / $mi['meta']) * 100)) : 0;
        $corFill = $pct >= 100 ? '#059669' : ($pct >= 60 ? '#f59e0b' : '#dc2626');
    ?>
    <div class="kpi-card" style="flex-direction:column;align-items:stretch;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <div class="kpi-label" style="margin:0;"><?= $mi['label'] ?></div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--petrol-900);"><?= $mi['atual'] ?><span style="font-size:.75rem;color:var(--text-muted);font-weight:400;"> / <?= $mi['meta'] ?></span></div>
        </div>
        <div class="meta-bar"><div class="meta-fill" style="width:<?= $pct ?>%;background:<?= $corFill ?>;"></div></div>
        <div class="meta-text"><?= $pct ?>% da meta</div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Atividades + Aniversários -->
<div class="bottom-row">
    <div class="activity-card">
        <h4>🕐 Atividades Recentes</h4>
        <?php if (empty($atividades)): ?>
            <p style="color:var(--text-muted);font-size:.82rem;">Nenhuma atividade registrada.</p>
        <?php else: ?>
            <?php foreach ($atividades as $at):
                $dotClass = 'blue'; $actionLabel = $at['action'];
                $actionMap = array(
                    'user_approved'=>array('green','aprovou usuário'),'user_rejected'=>array('red','recusou usuário'),
                    'user_activated'=>array('green','ativou usuário'),'user_deactivated'=>array('orange','desativou usuário'),
                    'password_reset'=>array('orange','redefiniu senha'),'lead_created'=>array('blue','novo lead'),
                    'lead_moved'=>array('blue','moveu lead'),'lead_converted'=>array('green','converteu lead'),
                    'client_created'=>array('green','novo cliente'),'case_created'=>array('green','novo caso'),
                    'ticket_created'=>array('blue','novo chamado'),'ticket_closed'=>array('green','chamado encerrado'),
                );
                if (isset($actionMap[$at['action']])) { $dotClass = $actionMap[$at['action']][0]; $actionLabel = $actionMap[$at['action']][1]; }
                $diff = time() - strtotime($at['created_at']);
                if ($diff < 60) $timeAgo = 'agora'; elseif ($diff < 3600) $timeAgo = floor($diff/60).'min'; elseif ($diff < 86400) $timeAgo = floor($diff/3600).'h'; else $timeAgo = floor($diff/86400).'d';
            ?>
            <div class="activity-item">
                <div class="activity-dot <?= $dotClass ?>"></div>
                <div>
                    <div class="activity-text"><strong><?= e($at['user_name'] ? explode(' ',$at['user_name'])[0] : 'Sistema') ?></strong> <?= e($actionLabel) ?><?php if ($at['details']): ?> <span style="color:var(--text-muted);">— <?= e(mb_substr($at['details'],0,50)) ?></span><?php endif; ?></div>
                    <div class="activity-time"><?= $timeAgo ?> atrás</div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="birthday-card">
        <h4>🎂 Aniversariantes</h4>
        <?php if (!empty($aniversariantes)): ?>
            <div style="margin-bottom:.75rem;"><div style="font-size:.72rem;color:var(--rose);font-weight:700;text-transform:uppercase;margin-bottom:.5rem;">Hoje</div>
            <?php foreach ($aniversariantes as $bday): ?>
            <div class="bday-item"><div class="bday-avatar"><?= mb_substr($bday['name'],0,2,'UTF-8') ?></div><div style="flex:1;"><div class="bday-name"><?= e($bday['name']) ?></div><div class="bday-detail"><?= $bday['idade'] ? $bday['idade'].' anos' : '' ?></div></div><span class="bday-tag today">HOJE</span></div>
            <?php endforeach; ?></div>
        <?php endif; ?>
        <?php if (!empty($proxAniversarios)): ?>
            <div style="font-size:.72rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;margin-bottom:.5rem;">Próximos 7 dias</div>
            <?php foreach ($proxAniversarios as $prox): ?>
            <div class="bday-item"><div class="bday-avatar" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);"><?= mb_substr($prox['name'],0,2,'UTF-8') ?></div><div style="flex:1;"><div class="bday-name"><?= e($prox['name']) ?></div><div class="bday-detail"><?= e($prox['data_fmt']) ?></div></div><span class="bday-tag soon"><?= $prox['dias_faltam'] ?>d</span></div>
            <?php endforeach; ?>
        <?php endif; ?>
        <?php if (empty($aniversariantes) && empty($proxAniversarios)): ?>
            <p style="color:var(--text-muted);font-size:.82rem;">Nenhum aniversariante nos próximos 7 dias.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Acesso Rápido -->
<div class="chart-card">
    <h4>⚡ Acesso Rápido</h4>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.5rem;">
        <a href="<?= module_url('portal') ?>" class="btn btn-outline btn-sm" style="justify-content:flex-start;">🔗 Portal de Links</a>
        <a href="<?= module_url('helpdesk','novo.php') ?>" class="btn btn-outline btn-sm" style="justify-content:flex-start;">🎫 Novo Chamado</a>
        <?php if (can_view_pipeline()): ?><a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm" style="justify-content:flex-start;">📈 Pipeline</a><?php endif; ?>
        <?php if (can_view_operacional()): ?><a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm" style="justify-content:flex-start;">⚙️ Operacional</a><?php endif; ?>
        <a href="<?= module_url('agenda') ?>" class="btn btn-outline btn-sm" style="justify-content:flex-start;">📅 Agenda</a>
        <?php if (has_role('admin','gestao','operacional')): ?><a href="<?= module_url('documentos') ?>" class="btn btn-outline btn-sm" style="justify-content:flex-start;">📜 Documentos</a><?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'comercial'): ?>
<!-- ═══════════════ ABA COMERCIAL ═══════════════ -->

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon green">✅</div>
        <div>
            <div class="kpi-value"><?= $contratosMes ?></div>
            <div class="kpi-label">Contratos em <?= $mesLabel[(int)date('n')] ?></div>
            <div class="meta-bar" style="width:120px;"><div class="meta-fill" style="width:<?= $metas['contratos_mes'] > 0 ? min(100, round($contratosMes / $metas['contratos_mes'] * 100)) : 0 ?>%;background:<?= $contratosMes >= $metas['contratos_mes'] ? '#059669' : '#f59e0b' ?>;"></div></div>
            <div class="meta-text">Meta: <?= $metas['contratos_mes'] ?></div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon blue">📈</div>
        <div>
            <div class="kpi-value"><?= $leadsMes ?></div>
            <div class="kpi-label">Leads em <?= $mesLabel[(int)date('n')] ?></div>
            <div class="meta-bar" style="width:120px;"><div class="meta-fill" style="width:<?= $metas['leads_mes'] > 0 ? min(100, round($leadsMes / $metas['leads_mes'] * 100)) : 0 ?>%;background:<?= $leadsMes >= $metas['leads_mes'] ? '#059669' : '#6366f1' ?>;"></div></div>
            <div class="meta-text">Meta: <?= $metas['leads_mes'] ?></div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon purple">🎯</div>
        <div>
            <div class="kpi-value"><?= $leadsAtivos ?></div>
            <div class="kpi-label">Leads Ativos Total</div>
            <?php if ($leadsHoje > 0): ?><div class="kpi-sub">+<?= $leadsHoje ?> hoje</div><?php endif; ?>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon red">❌</div>
        <div>
            <div class="kpi-value"><?= $canceladosMes ?></div>
            <div class="kpi-label">Cancelados em <?= $mesLabel[(int)date('n')] ?></div>
            <div class="kpi-sub" style="color:var(--text-muted);">Total histórico: <?= $canceladosTotal ?></div>
        </div>
    </div>
</div>

<!-- Cancelados com contexto -->
<?php if (!empty($canceladosDetalhe)): ?>
<div class="chart-card" style="margin-bottom:1.25rem;">
    <h4>❌ Cancelamentos deste mês <span style="font-weight:400;color:var(--text-muted);font-size:.75rem;">(quando o contrato foi fechado)</span></h4>
    <?php foreach ($canceladosDetalhe as $cd): ?>
    <div class="cancel-detail">
        <span class="nome"><?= e($cd['name']) ?></span>
        <span class="info"> — cancelou em <?= $cd['data_cancel'] ?><?= $cd['mes_contrato'] ? ' (contrato: ' . $cd['mes_contrato'] . ')' : '' ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Funil -->
<?php
$totalPipe = array_sum($pipeStages);
$funnelColors = array('cadastro_preenchido'=>'#6366f1','elaboracao_docs'=>'#0ea5e9','link_enviados'=>'#f59e0b','contrato_assinado'=>'#059669','agendado_docs'=>'#0d9488','reuniao_cobranca'=>'#d97706','pasta_apta'=>'#15803d','perdido'=>'#dc2626');
$funnelLabels = array('cadastro_preenchido'=>'Cadastro','elaboracao_docs'=>'Elaboração','link_enviados'=>'Link Enviado','contrato_assinado'=>'Contrato','agendado_docs'=>'Agendado','reuniao_cobranca'=>'Cobrando Docs','pasta_apta'=>'Pasta Apta','perdido'=>'Cancelado');
?>
<div class="funnel-card">
    <h4>📊 Funil Comercial <span style="font-weight:400;color:var(--text-muted);font-size:.75rem;">(<?= $totalPipe ?> leads)</span></h4>
    <?php if ($totalPipe > 0): ?>
    <div class="funnel-bar">
        <?php foreach ($pipeStages as $stage => $count): if ($count > 0): ?>
        <div class="funnel-segment" style="flex:<?= $count ?>;background:<?= $funnelColors[$stage] ?? '#888' ?>;" title="<?= $funnelLabels[$stage] ?? $stage ?>: <?= $count ?>"><?= $count ?></div>
        <?php endif; endforeach; ?>
    </div>
    <div class="funnel-legend">
        <?php foreach ($pipeStages as $stage => $count): ?>
        <div class="funnel-legend-item"><div class="funnel-legend-dot" style="background:<?= $funnelColors[$stage] ?? '#888' ?>;"></div><?= $funnelLabels[$stage] ?? $stage ?> (<?= $count ?>)</div>
        <?php endforeach; ?>
    </div>
    <?php else: ?><p style="color:var(--text-muted);font-size:.82rem;">Nenhum lead no pipeline.</p><?php endif; ?>
</div>

<!-- Gráficos -->
<div class="charts-row">
    <div class="chart-card">
        <h4>📉 Taxa de Conversão (6 meses)</h4>
        <canvas id="chartConversao"></canvas>
    </div>
    <div class="chart-card">
        <h4>📊 Leads vs Contratos por mês</h4>
        <canvas id="chartLeadsContratos"></canvas>
    </div>
</div>

<?php elseif ($tab === 'operacional'): ?>
<!-- ═══════════════ ABA OPERACIONAL ═══════════════ -->

<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon green">🟢</div>
        <div>
            <div class="kpi-value"><?= $casosEmAndamento ?></div>
            <div class="kpi-label">Em Andamento</div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon orange">🟡</div>
        <div>
            <div class="kpi-value"><?= $casosSuspensos ?></div>
            <div class="kpi-label">Suspensos</div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon blue">🏛️</div>
        <div>
            <div class="kpi-value"><?= $casosDistribuidosMes ?></div>
            <div class="kpi-label">Distribuídos em <?= $mesLabel[(int)date('n')] ?></div>
            <?php $diffD = $casosDistribuidosMes - $casosDistribuidosMesAnt; ?>
            <div class="stat-compare <?= $diffD > 0 ? 'stat-up' : ($diffD < 0 ? 'stat-down' : 'stat-equal') ?>">
                <?= $diffD > 0 ? '↑' : ($diffD < 0 ? '↓' : '=') ?> <?= abs($diffD) ?> vs <?= $mesLabel[(int)date('n', strtotime('-1 month'))] ?>
            </div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon green">📦</div>
        <div>
            <div class="kpi-value"><?= $casosArquivadosMes ?></div>
            <div class="kpi-label">Entregues em <?= $mesLabel[(int)date('n')] ?></div>
            <div class="meta-bar" style="width:120px;"><div class="meta-fill" style="width:<?= $metas['casos_entregues_mes'] > 0 ? min(100, round($casosArquivadosMes / $metas['casos_entregues_mes'] * 100)) : 0 ?>%;background:#059669;"></div></div>
            <div class="meta-text">Meta: <?= $metas['casos_entregues_mes'] ?></div>
        </div>
    </div>
</div>

<!-- Pendências operacionais -->
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="kpi-card">
        <div class="kpi-icon red">📄</div>
        <div>
            <div class="kpi-value"><?= $docsFaltantes ?></div>
            <div class="kpi-label">Documentos Faltantes</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon rose">🎫</div>
        <div>
            <div class="kpi-value"><?= $ticketsAbertos ?></div>
            <div class="kpi-label">Chamados Abertos</div>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon blue">👥</div>
        <div>
            <div class="kpi-value"><?= $totalClientes ?></div>
            <div class="kpi-label">Total Clientes</div>
        </div>
    </div>
</div>

<!-- Processos por status -->
<?php if (!empty($casosPorStatus)): ?>
<div class="charts-row">
    <div class="chart-card">
        <h4>📊 Processos por Status</h4>
        <canvas id="chartStatus"></canvas>
    </div>
    <div class="chart-card">
        <h4>👷 Carga por Responsável</h4>
        <canvas id="chartProdutividade"></canvas>
    </div>
</div>
<?php endif; ?>

<?php endif; // fim das abas ?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function() {
    var fc = '#94a3b8', gc = 'rgba(148,163,184,.1)';

    <?php if ($tab === 'comercial'): ?>
    // Taxa de Conversão
    var c1 = document.getElementById('chartConversao');
    if (c1) new Chart(c1, { type:'line', data:{ labels:<?= json_encode($convLabels) ?>, datasets:[{ label:'Conversão %', data:<?= json_encode($convData) ?>, borderColor:'#d7ab90', backgroundColor:'rgba(215,171,144,.1)', borderWidth:2.5, tension:.4, fill:true, pointBackgroundColor:'#d7ab90', pointRadius:4 }] }, options:{ responsive:true, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,max:100,ticks:{color:fc,callback:function(v){return v+'%'}},grid:{color:gc}}, x:{ticks:{color:fc},grid:{display:false}} } } });

    // Leads vs Contratos
    var c2 = document.getElementById('chartLeadsContratos');
    if (c2) new Chart(c2, { type:'bar', data:{ labels:<?= json_encode($convLabels) ?>, datasets:[ {label:'Leads',data:<?= json_encode($convLeads) ?>,backgroundColor:'rgba(99,102,241,.6)',borderRadius:4}, {label:'Contratos',data:<?= json_encode($convConvertidos) ?>,backgroundColor:'rgba(5,150,105,.7)',borderRadius:4} ] }, options:{ responsive:true, plugins:{legend:{labels:{color:fc,font:{size:11}}}}, scales:{ y:{beginAtZero:true,ticks:{color:fc,stepSize:5},grid:{color:gc}}, x:{ticks:{color:fc},grid:{display:false}} } } });
    <?php endif; ?>

    <?php if ($tab === 'operacional'): ?>
    // Processos por status
    var c3 = document.getElementById('chartStatus');
    if (c3) {
        var statusCores = {'em_andamento':'#059669','suspenso':'#d97706','arquivado':'#dc2626','renunciamos':'#6b7280','aguardando_docs':'#f59e0b','em_elaboracao':'#0ea5e9','distribuido':'#15803d','doc_faltante':'#ef4444','aguardando_prazo':'#8b5cf6','concluido':'#059669','cancelado':'#dc2626'};
        var statusLabels = {'em_andamento':'Em Andamento','suspenso':'Suspenso','arquivado':'Arquivado','renunciamos':'Renunciamos','aguardando_docs':'Aguardando Docs','em_elaboracao':'Pasta Apta','distribuido':'Distribuído','doc_faltante':'Doc Faltante','aguardando_prazo':'Ag. Prazo','concluido':'Concluído','cancelado':'Cancelado'};
        var sData = <?= json_encode(array_values(array_map(function($r){ return (int)$r['total']; }, $casosPorStatus))) ?>;
        var sLabelsRaw = <?= json_encode(array_values(array_map(function($r){ return $r['status']; }, $casosPorStatus))) ?>;
        var sLabels = sLabelsRaw.map(function(s){ return statusLabels[s] || s; });
        var sCores = sLabelsRaw.map(function(s){ return statusCores[s] || '#888'; });
        new Chart(c3, { type:'doughnut', data:{ labels:sLabels, datasets:[{data:sData,backgroundColor:sCores}] }, options:{ responsive:true, plugins:{legend:{position:'right',labels:{color:fc,font:{size:11}}}} } });
    }

    // Carga por responsável
    var c4 = document.getElementById('chartProdutividade');
    if (c4) new Chart(c4, { type:'bar', data:{ labels:<?= json_encode($prodLabels) ?>, datasets:[ {label:'Casos ativos',data:<?= json_encode($prodCasos) ?>,backgroundColor:'rgba(99,102,241,.7)',borderRadius:4}, {label:'Contratos mês',data:<?= json_encode($prodContratos) ?>,backgroundColor:'rgba(5,150,105,.7)',borderRadius:4} ] }, options:{ responsive:true, indexAxis:'y', plugins:{legend:{labels:{color:fc,font:{size:11}}}}, scales:{ x:{beginAtZero:true,ticks:{color:fc,stepSize:1},grid:{color:gc}}, y:{ticks:{color:fc},grid:{display:false}} } } });
    <?php endif; ?>
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
