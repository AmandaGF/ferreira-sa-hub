<?php
/**
 * Ferreira & Sá Hub — Dashboard Principal (Dark Premium)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Dashboard';
$user = current_user();
$role = current_user_role();
$pdo = db();
$firstName = explode(' ', $user['name'])[0];

// ─── KPIs ───────────────────────────────────────────────

// Leads hoje
$leadsHoje = 0;
try { $leadsHoje = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE DATE(created_at) = CURDATE()")->fetchColumn(); } catch (Exception $e) {}

// Leads ativos no pipeline (exceto finalizados e perdidos)
$leadsAtivos = 0;
try { $leadsAtivos = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage NOT IN ('finalizado','perdido')")->fetchColumn(); } catch (Exception $e) {}

// Contratos assinados (total — inclui todos que passaram por contrato_assinado)
$contratos = 0;
try { $contratos = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado')")->fetchColumn(); } catch (Exception $e) {}

// Contratos este mês
$contratosMes = 0;
try { $contratosMes = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND MONTH(converted_at) = MONTH(CURDATE()) AND YEAR(converted_at) = YEAR(CURDATE())")->fetchColumn(); } catch (Exception $e) {}

// Cancelados este mês (pipeline cancelado + operacional cancelado)
$canceladosMes = 0;
try {
    $canceladosMes += (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('cancelado','perdido') AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())")->fetchColumn();
    $canceladosMes += (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'cancelado' AND MONTH(updated_at) = MONTH(CURDATE()) AND YEAR(updated_at) = YEAR(CURDATE())")->fetchColumn();
} catch (Exception $e) {}

// Casos em execução (operacional)
$casosExecucao = 0;
try { $casosExecucao = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('concluido','arquivado')")->fetchColumn(); } catch (Exception $e) {}

// Docs faltantes
$docsFaltantes = 0;
try { $docsFaltantes = (int)$pdo->query("SELECT COUNT(*) FROM documentos_pendentes WHERE status = 'pendente'")->fetchColumn(); } catch (Exception $e) {}

// Pendências (formulários novos + tickets abertos)
$formsPendentes = 0;
try { $formsPendentes = (int)$pdo->query("SELECT COUNT(*) FROM form_submissions WHERE status = 'novo'")->fetchColumn(); } catch (Exception $e) {}
$ticketsAbertos = 0;
try { $ticketsAbertos = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('aberto','em_andamento','aguardando')")->fetchColumn(); } catch (Exception $e) {}
$pendencias = $formsPendentes + $ticketsAbertos;

// Total clientes
$totalClientes = 0;
try { $totalClientes = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn(); } catch (Exception $e) {}

// ─── Taxa de conversão (últimos 6 meses) ────────────────
$convLabels = array();
$convData = array();
for ($i = 5; $i >= 0; $i--) {
    $mes = date('Y-m', strtotime("-$i months"));
    $mesLabel = strftime('%b', strtotime("-$i months"));
    // fallback para PHP sem strftime
    $meses = array('Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez');
    $mesNum = (int)date('n', strtotime("-$i months"));
    $convLabels[] = $meses[$mesNum - 1];

    $total = 0;
    $convertidos = 0;
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE DATE_FORMAT(created_at, '%Y-%m') = '$mes'")->fetchColumn();
        $convertidos = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE converted_at IS NOT NULL AND DATE_FORMAT(converted_at, '%Y-%m') = '$mes'")->fetchColumn();
    } catch (Exception $e) {}
    $convData[] = $total > 0 ? round(($convertidos / $total) * 100) : 0;
}

// ─── Produtividade por responsável ──────────────────────
$prodLabels = array();
$prodCasos = array();
$prodContratos = array();
try {
    $prodRows = $pdo->query(
        "SELECT u.name,
         (SELECT COUNT(*) FROM cases c WHERE c.responsible_user_id = u.id AND c.status NOT IN ('concluido','arquivado')) as casos,
         (SELECT COUNT(*) FROM pipeline_leads pl WHERE pl.assigned_to = u.id AND pl.converted_at IS NOT NULL) as contratos
         FROM users u WHERE u.is_active = 1 ORDER BY u.name"
    )->fetchAll();
    foreach ($prodRows as $r) {
        $nome = explode(' ', $r['name']);
        $prodLabels[] = $nome[0];
        $prodCasos[] = (int)$r['casos'];
        $prodContratos[] = (int)$r['contratos'];
    }
} catch (Exception $e) {}

// ─── Atividades recentes ────────────────────────────────
$atividades = array();
try {
    $atividades = $pdo->query(
        "SELECT al.action, al.entity_type, al.details, al.created_at, u.name as user_name
         FROM audit_log al
         LEFT JOIN users u ON u.id = al.user_id
         WHERE al.action NOT IN ('login','logout','login_failed')
         ORDER BY al.created_at DESC LIMIT 8"
    )->fetchAll();
} catch (Exception $e) {}

// ─── Aniversariantes ────────────────────────────────────
$aniversariantes = array();
try {
    $aniversariantes = $pdo->query(
        "SELECT name, phone, email, birth_date,
         TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) as idade
         FROM clients
         WHERE birth_date IS NOT NULL
         AND DATE_FORMAT(birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
         ORDER BY name"
    )->fetchAll();
} catch (Exception $e) {}

// Próximos 7 dias
$proxAniversarios = array();
try {
    $proxAniversarios = $pdo->query(
        "SELECT name, birth_date,
         DATE_FORMAT(birth_date, '%d/%m') as data_fmt,
         DATEDIFF(
            DATE_ADD(birth_date, INTERVAL TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) +
            IF(DATE_FORMAT(birth_date, '%m%d') <= DATE_FORMAT(CURDATE(), '%m%d'), 1, 0) YEAR),
            CURDATE()
         ) as dias_faltam
         FROM clients
         WHERE birth_date IS NOT NULL
         AND DATE_FORMAT(birth_date, '%m-%d') != DATE_FORMAT(CURDATE(), '%m-%d')
         HAVING dias_faltam BETWEEN 1 AND 7
         ORDER BY dias_faltam ASC
         LIMIT 5"
    )->fetchAll();
} catch (Exception $e) {}

// ─── Pipeline por estágio (para gráfico de funil) ──────
$pipeStages = array(
    'cadastro_preenchido' => 0, 'elaboracao_docs' => 0, 'link_enviados' => 0,
    'contrato_assinado' => 0, 'agendado_docs' => 0, 'reuniao_cobranca' => 0,
    'pasta_apta' => 0, 'perdido' => 0
);
try {
    $rows = $pdo->query("SELECT stage, COUNT(*) as total FROM pipeline_leads GROUP BY stage")->fetchAll();
    foreach ($rows as $r) {
        if (isset($pipeStages[$r['stage']])) {
            $pipeStages[$r['stage']] = (int)$r['total'];
        }
    }
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
/* Dashboard Dark Premium */
.dash-welcome {
    background: linear-gradient(135deg, #052228 0%, #0d3640 50%, #173d46 100%);
    border-radius: var(--radius-lg);
    padding: 1.5rem 2rem;
    color: #fff;
    margin-bottom: 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border: 1px solid rgba(215,171,144,.15);
}
.dash-welcome h2 { font-size: 1.2rem; font-weight: 800; margin-bottom: .25rem; }
.dash-welcome .role-badge { color: var(--rose); font-size: .82rem; }
.dash-welcome .date { color: rgba(255,255,255,.5); font-size: .78rem; }

/* KPI Cards */
.kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: .75rem; margin-bottom: 1.25rem; }
.kpi-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    padding: 1.1rem 1.25rem;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: .85rem;
    transition: all var(--transition);
}
.kpi-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
.kpi-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}
.kpi-icon.blue { background: rgba(99,102,241,.15); }
.kpi-icon.green { background: rgba(5,150,105,.15); }
.kpi-icon.orange { background: rgba(249,115,22,.15); }
.kpi-icon.red { background: rgba(239,68,68,.15); }
.kpi-icon.rose { background: rgba(215,171,144,.15); }
.kpi-value { font-size: 1.6rem; font-weight: 800; color: var(--petrol-900); line-height: 1; }
.kpi-label { font-size: .72rem; color: var(--text-muted); margin-top: .15rem; text-transform: uppercase; letter-spacing: .5px; }
.kpi-sub { font-size: .68rem; color: var(--rose); font-weight: 600; margin-top: .1rem; }

/* Charts row */
.charts-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem; }
.chart-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 1.25rem;
}
.chart-card h4 { font-size: .88rem; font-weight: 700; color: var(--petrol-900); margin-bottom: 1rem; display: flex; align-items: center; gap: .5rem; }
.chart-card canvas { max-height: 220px; }

/* Bottom row */
.bottom-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.25rem; }
.activity-card, .birthday-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 1.25rem;
}
.activity-card h4, .birthday-card h4 {
    font-size: .88rem; font-weight: 700; color: var(--petrol-900);
    margin-bottom: 1rem; display: flex; align-items: center; gap: .5rem;
}

/* Activity items */
.activity-item {
    display: flex; gap: .75rem; padding: .6rem 0;
    border-bottom: 1px solid var(--border);
    align-items: flex-start;
}
.activity-item:last-child { border-bottom: none; }
.activity-dot {
    width: 8px; height: 8px; border-radius: 50%;
    margin-top: .35rem; flex-shrink: 0;
}
.activity-dot.green { background: #059669; }
.activity-dot.blue { background: #6366f1; }
.activity-dot.orange { background: #f59e0b; }
.activity-dot.red { background: #ef4444; }
.activity-text { font-size: .78rem; color: var(--text); line-height: 1.4; }
.activity-text strong { color: var(--petrol-900); }
.activity-time { font-size: .65rem; color: var(--text-muted); margin-top: .15rem; }

/* Birthday items */
.bday-item {
    display: flex; align-items: center; gap: .75rem;
    padding: .6rem 0;
    border-bottom: 1px solid var(--border);
}
.bday-item:last-child { border-bottom: none; }
.bday-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, var(--rose), var(--rose-dark));
    color: #fff; display: flex; align-items: center; justify-content: center;
    font-size: .75rem; font-weight: 700; flex-shrink: 0;
}
.bday-info { flex: 1; }
.bday-name { font-size: .82rem; font-weight: 700; color: var(--petrol-900); }
.bday-detail { font-size: .7rem; color: var(--text-muted); }
.bday-actions { display: flex; gap: .25rem; }
.bday-tag {
    font-size: .65rem; font-weight: 700; padding: .2rem .5rem;
    border-radius: 6px; color: #fff;
}
.bday-tag.today { background: #059669; }
.bday-tag.soon { background: #6366f1; }

/* Funil */
.funnel-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 1.25rem;
    margin-bottom: 1.25rem;
}
.funnel-card h4 { font-size: .88rem; font-weight: 700; color: var(--petrol-900); margin-bottom: 1rem; display: flex; align-items: center; gap: .5rem; }
.funnel-bar { display: flex; gap: .35rem; height: 32px; border-radius: var(--radius); overflow: hidden; margin-bottom: .5rem; }
.funnel-segment {
    display: flex; align-items: center; justify-content: center;
    font-size: .65rem; font-weight: 700; color: #fff;
    min-width: 24px; transition: all var(--transition);
}
.funnel-legend { display: flex; flex-wrap: wrap; gap: .5rem .75rem; margin-top: .5rem; }
.funnel-legend-item { display: flex; align-items: center; gap: .3rem; font-size: .7rem; color: var(--text-muted); }
.funnel-legend-dot { width: 10px; height: 10px; border-radius: 3px; }

/* Responsive */
@media (max-width: 1024px) {
    .kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .charts-row, .bottom-row { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .kpi-grid { grid-template-columns: 1fr; }
    .dash-welcome { flex-direction: column; text-align: center; }
}
</style>

<!-- Bem-vindo -->
<div class="dash-welcome">
    <div>
        <h2>Bem-vindo(a), <?= e($firstName) ?>!</h2>
        <span class="role-badge"><?= e(role_label($role)) ?></span>
        <span class="date"> &mdash; <?= date('d/m/Y') ?></span>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon blue">📈</div>
        <div>
            <div class="kpi-value"><?= $leadsAtivos ?></div>
            <div class="kpi-label">Leads Ativos</div>
            <?php if ($leadsHoje > 0): ?>
                <div class="kpi-sub">+<?= $leadsHoje ?> hoje</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon green">✅</div>
        <div>
            <div class="kpi-value"><?= $contratos ?></div>
            <div class="kpi-label">Contratos</div>
            <?php if ($contratosMes > 0): ?>
                <div class="kpi-sub"><?= $contratosMes ?> este mês</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon orange">⚙️</div>
        <div>
            <div class="kpi-value"><?= $casosExecucao ?></div>
            <div class="kpi-label">Em Execução</div>
        </div>
    </div>

    <div class="kpi-card">
        <div class="kpi-icon red">❌</div>
        <div>
            <div class="kpi-value"><?= $canceladosMes ?></div>
            <div class="kpi-label">CANCELADOS NO MÊS</div>
        </div>
    </div>
</div>

<!-- KPIs secundários -->
<div class="kpi-grid" style="margin-bottom:1.25rem;">
    <div class="kpi-card">
        <div class="kpi-icon rose">⚠️</div>
        <div>
            <div class="kpi-value"><?= $pendencias ?></div>
            <div class="kpi-label">Pendências</div>
            <div class="kpi-sub"><?= $formsPendentes ?> forms · <?= $ticketsAbertos ?> tickets</div>
        </div>
    </div>
    <?php if ($docsFaltantes > 0): ?>
    <div class="kpi-card">
        <div class="kpi-icon red">📄</div>
        <div>
            <div class="kpi-value"><?= $docsFaltantes ?></div>
            <div class="kpi-label">Docs faltantes</div>
        </div>
    </div>
    <?php endif; ?>
    <div class="kpi-card">
        <div class="kpi-icon blue">👥</div>
        <div>
            <div class="kpi-value"><?= $totalClientes ?></div>
            <div class="kpi-label">Clientes</div>
        </div>
    </div>
</div>

<!-- Funil do Pipeline -->
<?php if (has_role('admin','gestao','comercial','cx')): ?>
<?php
    $totalPipe = array_sum($pipeStages);
    $funnelColors = array(
        'cadastro_preenchido' => '#6366f1', 'elaboracao_docs' => '#0ea5e9', 'link_enviados' => '#f59e0b',
        'contrato_assinado' => '#059669', 'agendado_docs' => '#0d9488', 'reuniao_cobranca' => '#d97706',
        'pasta_apta' => '#15803d', 'perdido' => '#dc2626', 'cancelado' => '#dc2626'
    );
    $funnelLabels = array(
        'cadastro_preenchido' => 'Cadastro', 'elaboracao_docs' => 'Elaboração', 'link_enviados' => 'Link Enviado',
        'contrato_assinado' => 'Contrato', 'agendado_docs' => 'Agendado', 'reuniao_cobranca' => 'Cobrando Docs',
        'pasta_apta' => 'Pasta Apta', 'perdido' => 'Cancelado', 'cancelado' => 'Cancelado'
    );
?>
<div class="funnel-card">
    <h4>📊 Funil Comercial <span style="font-weight:400;color:var(--text-muted);font-size:.75rem;">(<?= $totalPipe ?> leads)</span></h4>
    <?php if ($totalPipe > 0): ?>
    <div class="funnel-bar">
        <?php foreach ($pipeStages as $stage => $count): ?>
            <?php if ($count > 0): ?>
            <div class="funnel-segment" style="flex:<?= $count ?>;background:<?= $funnelColors[$stage] ?>;" title="<?= $funnelLabels[$stage] ?>: <?= $count ?>">
                <?= $count ?>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="funnel-legend">
        <?php foreach ($pipeStages as $stage => $count): ?>
        <div class="funnel-legend-item">
            <div class="funnel-legend-dot" style="background:<?= $funnelColors[$stage] ?>;"></div>
            <?= $funnelLabels[$stage] ?> (<?= $count ?>)
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p style="color:var(--text-muted);font-size:.82rem;">Nenhum lead no pipeline.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Gráficos -->
<?php if (has_role('admin','gestao','comercial','cx')): ?>
<div class="charts-row">
    <div class="chart-card">
        <h4>📉 Taxa de Conversão</h4>
        <canvas id="chartConversao"></canvas>
    </div>
    <div class="chart-card">
        <h4>📊 Produtividade por Responsável</h4>
        <canvas id="chartProdutividade"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- Atividades + Aniversários -->
<div class="bottom-row">
    <div class="activity-card">
        <h4>🕐 Atividades Recentes</h4>
        <?php if (empty($atividades)): ?>
            <p style="color:var(--text-muted);font-size:.82rem;">Nenhuma atividade registrada.</p>
        <?php else: ?>
            <?php foreach ($atividades as $at): ?>
            <?php
                $dotClass = 'blue';
                $actionLabel = $at['action'];
                $actionMap = array(
                    'user_approved' => array('green', 'aprovou usuário'),
                    'user_rejected' => array('red', 'recusou usuário'),
                    'user_activated' => array('green', 'ativou usuário'),
                    'user_deactivated' => array('orange', 'desativou usuário'),
                    'password_reset' => array('orange', 'redefiniu senha'),
                    'lead_created' => array('blue', 'novo lead criado'),
                    'lead_moved' => array('blue', 'moveu lead no pipeline'),
                    'lead_converted' => array('green', 'converteu lead em cliente'),
                    'client_created' => array('green', 'novo cliente cadastrado'),
                    'case_created' => array('green', 'novo caso criado'),
                    'ticket_created' => array('blue', 'novo chamado aberto'),
                    'ticket_closed' => array('green', 'chamado encerrado'),
                );
                if (isset($actionMap[$at['action']])) {
                    $dotClass = $actionMap[$at['action']][0];
                    $actionLabel = $actionMap[$at['action']][1];
                }
                $timeAgo = '';
                $diff = time() - strtotime($at['created_at']);
                if ($diff < 60) $timeAgo = 'agora';
                elseif ($diff < 3600) $timeAgo = floor($diff/60) . ' min';
                elseif ($diff < 86400) $timeAgo = floor($diff/3600) . 'h';
                else $timeAgo = floor($diff/86400) . 'd';
            ?>
            <div class="activity-item">
                <div class="activity-dot <?= $dotClass ?>"></div>
                <div>
                    <div class="activity-text">
                        <strong><?= e($at['user_name'] ? explode(' ', $at['user_name'])[0] : 'Sistema') ?></strong>
                        <?= e($actionLabel) ?>
                        <?php if ($at['details']): ?>
                            <span style="color:var(--text-muted);">— <?= e(mb_substr($at['details'], 0, 50)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="activity-time"><?= $timeAgo ?> atrás</div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="birthday-card">
        <h4>🎂 Aniversariantes</h4>
        <?php if (!empty($aniversariantes)): ?>
            <div style="margin-bottom:.75rem;">
                <div style="font-size:.72rem;color:var(--rose);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.5rem;">Hoje</div>
                <?php foreach ($aniversariantes as $bday): ?>
                <div class="bday-item">
                    <div class="bday-avatar"><?= mb_substr($bday['name'], 0, 2, 'UTF-8') ?></div>
                    <div class="bday-info">
                        <div class="bday-name"><?= e($bday['name']) ?></div>
                        <div class="bday-detail"><?= $bday['idade'] ? $bday['idade'] . ' anos' : '' ?></div>
                    </div>
                    <span class="bday-tag today">HOJE</span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($proxAniversarios)): ?>
            <div style="font-size:.72rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:.5rem;">Próximos 7 dias</div>
            <?php foreach ($proxAniversarios as $prox): ?>
            <div class="bday-item">
                <div class="bday-avatar" style="background:linear-gradient(135deg,#6366f1,#8b5cf6);"><?= mb_substr($prox['name'], 0, 2, 'UTF-8') ?></div>
                <div class="bday-info">
                    <div class="bday-name"><?= e($prox['name']) ?></div>
                    <div class="bday-detail"><?= e($prox['data_fmt']) ?></div>
                </div>
                <span class="bday-tag soon"><?= $prox['dias_faltam'] ?>d</span>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (empty($aniversariantes) && empty($proxAniversarios)): ?>
            <p style="color:var(--text-muted);font-size:.82rem;">Nenhum aniversariante nos próximos 7 dias.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Acesso Rápido -->
<div class="card">
    <div class="card-header"><h3>Acesso Rápido</h3></div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;">
            <a href="<?= module_url('portal') ?>" class="btn btn-outline" style="justify-content:flex-start;">🔗 Portal de Links</a>
            <a href="<?= module_url('helpdesk', 'novo.php') ?>" class="btn btn-outline" style="justify-content:flex-start;">🎫 Novo Chamado</a>
            <?php if (can_view_pipeline()): ?>
            <a href="<?= module_url('pipeline') ?>" class="btn btn-outline" style="justify-content:flex-start;">📈 Pipeline</a>
            <a href="<?= module_url('crm') ?>" class="btn btn-outline" style="justify-content:flex-start;">🎯 CRM</a>
            <?php endif; ?>
            <?php if (can_view_operacional()): ?>
            <a href="<?= module_url('operacional') ?>" class="btn btn-outline" style="justify-content:flex-start;">⚙️ Operacional</a>
            <?php endif; ?>
            <?php if (has_role('admin','gestao','operacional')): ?>
            <a href="<?= module_url('documentos') ?>" class="btn btn-outline" style="justify-content:flex-start;">📜 Documentos</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<?php if (has_min_role('gestao')): ?>
<script>
(function() {
    var fontColor = '#94a3b8';
    var gridColor = 'rgba(148,163,184,.1)';

    // Taxa de Conversão (Line)
    var ctxConv = document.getElementById('chartConversao');
    if (ctxConv) {
        new Chart(ctxConv, {
            type: 'line',
            data: {
                labels: <?= json_encode($convLabels) ?>,
                datasets: [{
                    label: 'Conversão %',
                    data: <?= json_encode($convData) ?>,
                    borderColor: '#d7ab90',
                    backgroundColor: 'rgba(215,171,144,.1)',
                    borderWidth: 2.5,
                    tension: .4,
                    fill: true,
                    pointBackgroundColor: '#d7ab90',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, max: 100, ticks: { color: fontColor, callback: function(v){ return v+'%'; } }, grid: { color: gridColor } },
                    x: { ticks: { color: fontColor }, grid: { display: false } }
                }
            }
        });
    }

    // Produtividade (Bar)
    var ctxProd = document.getElementById('chartProdutividade');
    if (ctxProd) {
        new Chart(ctxProd, {
            type: 'bar',
            data: {
                labels: <?= json_encode($prodLabels) ?>,
                datasets: [
                    {
                        label: 'Casos ativos',
                        data: <?= json_encode($prodCasos) ?>,
                        backgroundColor: 'rgba(99,102,241,.7)',
                        borderRadius: 4
                    },
                    {
                        label: 'Contratos',
                        data: <?= json_encode($prodContratos) ?>,
                        backgroundColor: 'rgba(5,150,105,.7)',
                        borderRadius: 4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { labels: { color: fontColor, font: { size: 11 } } } },
                scales: {
                    y: { beginAtZero: true, ticks: { color: fontColor, stepSize: 1 }, grid: { color: gridColor } },
                    x: { ticks: { color: fontColor }, grid: { display: false } }
                }
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
