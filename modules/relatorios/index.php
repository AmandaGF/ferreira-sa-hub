<?php
/**
 * Ferreira & Sá Hub — Relatórios
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Relatórios';
$pdo = db();

// ─── Métricas gerais ────────────────────────────────────
$totalClientes = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$clientesMes = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

$totalLeads = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads")->fetchColumn();
$leadsMes = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
$conversoes = (int)$pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE stage='contrato'")->fetchColumn();
$taxaConversao = $totalLeads > 0 ? round(($conversoes / $totalLeads) * 100, 1) : 0;

$casosAtivos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('concluido','arquivado')")->fetchColumn();
$casosUrgentes = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE priority='urgente' AND status NOT IN ('concluido','arquivado')")->fetchColumn();
$casosConcluidos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='concluido' AND MONTH(closed_at)=MONTH(NOW()) AND YEAR(closed_at)=YEAR(NOW())")->fetchColumn();

$ticketsAbertos = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('aberto','em_andamento','aguardando')")->fetchColumn();
$ticketsResolvidosMes = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status='resolvido' AND MONTH(resolved_at)=MONTH(NOW())")->fetchColumn();

$formsPendentes = (int)$pdo->query("SELECT COUNT(*) FROM form_submissions WHERE status='novo'")->fetchColumn();

// Leads por origem
$leadsBySource = $pdo->query("SELECT source, COUNT(*) as total FROM pipeline_leads GROUP BY source ORDER BY total DESC")->fetchAll();

// Leads por estágio
$leadsByStage = $pdo->query("SELECT stage, COUNT(*) as total FROM pipeline_leads WHERE stage NOT IN ('contrato','perdido') GROUP BY stage ORDER BY FIELD(stage,'novo','contato_inicial','agendado','proposta')")->fetchAll();

// Casos por tipo
$casesByType = $pdo->query("SELECT case_type, COUNT(*) as total FROM cases WHERE status NOT IN ('concluido','arquivado') GROUP BY case_type ORDER BY total DESC")->fetchAll();

// Casos por responsável
$casesByUser = $pdo->query("SELECT u.name, COUNT(*) as total FROM cases cs JOIN users u ON u.id = cs.responsible_user_id WHERE cs.status NOT IN ('concluido','arquivado') GROUP BY cs.responsible_user_id ORDER BY total DESC")->fetchAll();

$sourceLabels = ['calculadora'=>'Calculadora','landing'=>'Site','indicacao'=>'Indicação','instagram'=>'Instagram','google'=>'Google','whatsapp'=>'WhatsApp','outro'=>'Outro'];
$stageLabels = ['novo'=>'Novo','contato_inicial'=>'Contato','agendado'=>'Agendado','proposta'=>'Proposta'];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.report-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:1rem; margin-bottom:1.5rem; }
.bar-chart { display:flex; flex-direction:column; gap:.5rem; }
.bar-row { display:flex; align-items:center; gap:.75rem; }
.bar-label { width:100px; font-size:.78rem; font-weight:600; color:var(--text-muted); text-align:right; flex-shrink:0; }
.bar-track { flex:1; height:24px; background:var(--bg); border-radius:6px; overflow:hidden; }
.bar-fill { height:100%; border-radius:6px; display:flex; align-items:center; padding:0 .5rem; font-size:.7rem; font-weight:700; color:#fff; min-width:fit-content; }
.bar-value { font-size:.78rem; font-weight:700; color:var(--petrol-900); flex-shrink:0; width:30px; }
</style>

<!-- KPIs Comercial -->
<h3 style="font-size:.88rem;font-weight:700;color:var(--petrol-900);margin-bottom:1rem;">📈 Comercial</h3>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon info">📈</div><div class="stat-info"><div class="stat-value"><?= $totalLeads ?></div><div class="stat-label">Leads total</div></div></div>
    <div class="stat-card"><div class="stat-icon rose">🆕</div><div class="stat-info"><div class="stat-value"><?= $leadsMes ?></div><div class="stat-label">Leads este mês</div></div></div>
    <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $conversoes ?></div><div class="stat-label">Contratos fechados</div></div></div>
    <div class="stat-card"><div class="stat-icon warning">📊</div><div class="stat-info"><div class="stat-value"><?= $taxaConversao ?>%</div><div class="stat-label">Taxa de conversão</div></div></div>
</div>

<!-- Gráficos Comercial -->
<div class="report-grid">
    <div class="card">
        <div class="card-header"><h3>Leads por Origem</h3></div>
        <div class="card-body">
            <div class="bar-chart">
                <?php $maxLeads = max(array_column($leadsBySource ?: [['total'=>1]], 'total')); ?>
                <?php foreach ($leadsBySource as $ls): ?>
                <div class="bar-row">
                    <span class="bar-label"><?= $sourceLabels[$ls['source']] ?? $ls['source'] ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round(($ls['total']/$maxLeads)*100) ?>%;background:var(--petrol-500);"><?= $ls['total'] ?></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($leadsBySource)): ?><p class="text-muted text-sm">Sem dados</p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Leads por Estágio</h3></div>
        <div class="card-body">
            <div class="bar-chart">
                <?php $maxStage = max(array_column($leadsByStage ?: [['total'=>1]], 'total')); ?>
                <?php foreach ($leadsByStage as $ls): ?>
                <div class="bar-row">
                    <span class="bar-label"><?= $stageLabels[$ls['stage']] ?? $ls['stage'] ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round(($ls['total']/$maxStage)*100) ?>%;background:var(--rose);"><?= $ls['total'] ?></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($leadsByStage)): ?><p class="text-muted text-sm">Sem dados</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- KPIs Operacional -->
<h3 style="font-size:.88rem;font-weight:700;color:var(--petrol-900);margin:1.5rem 0 1rem;">⚙️ Operacional</h3>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon petrol">📂</div><div class="stat-info"><div class="stat-value"><?= $casosAtivos ?></div><div class="stat-label">Casos ativos</div></div></div>
    <div class="stat-card"><div class="stat-icon danger">🔴</div><div class="stat-info"><div class="stat-value"><?= $casosUrgentes ?></div><div class="stat-label">Urgentes</div></div></div>
    <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $casosConcluidos ?></div><div class="stat-label">Concluídos este mês</div></div></div>
    <div class="stat-card"><div class="stat-icon info">👥</div><div class="stat-info"><div class="stat-value"><?= $totalClientes ?></div><div class="stat-label">Clientes total (<?= $clientesMes ?> novos)</div></div></div>
</div>

<!-- Gráficos Operacional -->
<div class="report-grid">
    <div class="card">
        <div class="card-header"><h3>Casos por Tipo</h3></div>
        <div class="card-body">
            <div class="bar-chart">
                <?php $maxType = max(array_column($casesByType ?: [['total'=>1]], 'total')); ?>
                <?php foreach ($casesByType as $ct): ?>
                <div class="bar-row">
                    <span class="bar-label"><?= e($ct['case_type']) ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round(($ct['total']/$maxType)*100) ?>%;background:var(--success);"><?= $ct['total'] ?></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($casesByType)): ?><p class="text-muted text-sm">Sem dados</p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>Casos por Responsável</h3></div>
        <div class="card-body">
            <div class="bar-chart">
                <?php $maxUser = max(array_column($casesByUser ?: [['total'=>1]], 'total')); ?>
                <?php foreach ($casesByUser as $cu): ?>
                <div class="bar-row">
                    <span class="bar-label"><?= e($cu['name']) ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round(($cu['total']/$maxUser)*100) ?>%;background:var(--info);"><?= $cu['total'] ?></div></div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($casesByUser)): ?><p class="text-muted text-sm">Sem dados</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Atendimento -->
<h3 style="font-size:.88rem;font-weight:700;color:var(--petrol-900);margin:1.5rem 0 1rem;">🎫 Atendimento</h3>
<div class="stats-grid">
    <div class="stat-card"><div class="stat-icon warning">🎫</div><div class="stat-info"><div class="stat-value"><?= $ticketsAbertos ?></div><div class="stat-label">Tickets abertos</div></div></div>
    <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $ticketsResolvidosMes ?></div><div class="stat-label">Resolvidos este mês</div></div></div>
    <div class="stat-card"><div class="stat-icon info">📋</div><div class="stat-info"><div class="stat-value"><?= $formsPendentes ?></div><div class="stat-label">Formulários pendentes</div></div></div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
