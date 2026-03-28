<?php
/**
 * Ferreira & Sá Hub — Pipeline Comercial (Kanban)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Pipeline';
$pdo = db();

// Estágios do funil
$stages = [
    'novo'            => ['label' => 'Novo',            'color' => '#6366f1', 'icon' => '🆕'],
    'contato_inicial' => ['label' => 'Contato Inicial', 'color' => '#0ea5e9', 'icon' => '📞'],
    'agendado'        => ['label' => 'Agendado',        'color' => '#f59e0b', 'icon' => '📅'],
    'proposta'        => ['label' => 'Proposta',        'color' => '#d97706', 'icon' => '📄'],
    'contrato'        => ['label' => 'Contrato',        'color' => '#059669', 'icon' => '✅'],
    'perdido'         => ['label' => 'Perdido',         'color' => '#dc2626', 'icon' => '❌'],
];

// Buscar leads agrupados por estágio
$leads = $pdo->query(
    "SELECT pl.*, u.name as assigned_name,
     DATEDIFF(NOW(), pl.created_at) as days_in_pipeline
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     ORDER BY pl.updated_at DESC"
)->fetchAll();

$byStage = [];
foreach (array_keys($stages) as $s) { $byStage[$s] = []; }
foreach ($leads as $lead) {
    $byStage[$lead['stage']][] = $lead;
}

// KPIs
$totalLeads = count($leads);
$leadsAtivos = 0;
$valorTotal = 0;
foreach ($leads as $l) {
    if (!in_array($l['stage'], ['contrato', 'perdido'])) {
        $leadsAtivos++;
        $valorTotal += (int)($l['estimated_value_cents'] ?? 0);
    }
}
$convertidos = count($byStage['contrato']);
$taxaConversao = $totalLeads > 0 ? round(($convertidos / $totalLeads) * 100) : 0;

// Usuários para atribuir
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pipeline-stats { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
.pipeline-stats .stat-card { flex:1; min-width:160px; }

.kanban { display:flex; gap:1rem; overflow-x:auto; padding-bottom:1rem; min-height:500px; }
.kanban-col { min-width:260px; width:260px; flex-shrink:0; display:flex; flex-direction:column; }
.kanban-header { padding:.75rem 1rem; border-radius:var(--radius) var(--radius) 0 0; color:#fff; font-weight:700; font-size:.82rem; display:flex; align-items:center; justify-content:space-between; }
.kanban-header .count { background:rgba(255,255,255,.25); padding:.1rem .5rem; border-radius:100px; font-size:.72rem; }
.kanban-body { flex:1; background:var(--bg); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); padding:.5rem; display:flex; flex-direction:column; gap:.5rem; min-height:100px; }

.lead-card { background:var(--bg-card); border-radius:var(--radius); padding:.85rem; box-shadow:var(--shadow-sm); border:1px solid var(--border); cursor:pointer; transition:all var(--transition); }
.lead-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.lead-name { font-weight:700; font-size:.88rem; color:var(--petrol-900); margin-bottom:.25rem; }
.lead-meta { font-size:.72rem; color:var(--text-muted); display:flex; flex-direction:column; gap:.15rem; }
.lead-meta .phone { color:var(--success); }
.lead-days { font-size:.65rem; background:var(--bg); padding:.15rem .4rem; border-radius:6px; display:inline-block; margin-top:.35rem; }
.lead-assigned { font-size:.68rem; color:var(--rose-dark); font-weight:600; margin-top:.25rem; }
.lead-value { font-size:.75rem; font-weight:700; color:var(--petrol-500); }
.lead-actions { display:flex; gap:.25rem; margin-top:.5rem; flex-wrap:wrap; }
.lead-actions select { font-size:.7rem; padding:.2rem .35rem; border:1px solid var(--border); border-radius:6px; background:var(--bg-card); cursor:pointer; }
.lead-actions button { font-size:.65rem; padding:.2rem .4rem; background:var(--petrol-100); border:none; border-radius:6px; cursor:pointer; color:var(--petrol-500); font-weight:600; }
.lead-actions button:hover { background:var(--petrol-900); color:#fff; }

@media (max-width:768px) {
    .kanban { flex-direction:column; }
    .kanban-col { min-width:100%; width:100%; }
}
</style>

<!-- KPIs -->
<div class="pipeline-stats">
    <div class="stat-card">
        <div class="stat-icon info">📈</div>
        <div class="stat-info"><div class="stat-value"><?= $leadsAtivos ?></div><div class="stat-label">Leads ativos</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">✅</div>
        <div class="stat-info"><div class="stat-value"><?= $convertidos ?></div><div class="stat-label">Contratos</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon rose">📊</div>
        <div class="stat-info"><div class="stat-value"><?= $taxaConversao ?>%</div><div class="stat-label">Taxa conversão</div></div>
    </div>
    <?php if ($valorTotal > 0): ?>
    <div class="stat-card">
        <div class="stat-icon warning">💰</div>
        <div class="stat-info"><div class="stat-value"><?= brl($valorTotal) ?></div><div class="stat-label">Valor estimado</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Ações -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem;">
    <h3 style="font-size:1rem;font-weight:700;color:var(--petrol-900);">Funil de Vendas</h3>
    <a href="<?= module_url('pipeline', 'lead_form.php') ?>" class="btn btn-primary btn-sm">+ Novo Lead</a>
</div>

<!-- Kanban -->
<div class="kanban">
    <?php foreach ($stages as $stageKey => $stage): ?>
    <div class="kanban-col">
        <div class="kanban-header" style="background:<?= $stage['color'] ?>;">
            <span><?= $stage['icon'] ?> <?= $stage['label'] ?></span>
            <span class="count"><?= count($byStage[$stageKey]) ?></span>
        </div>
        <div class="kanban-body" data-stage="<?= $stageKey ?>">
            <?php if (empty($byStage[$stageKey])): ?>
                <div style="text-align:center;padding:1.5rem .5rem;color:var(--text-muted);font-size:.78rem;">Nenhum lead</div>
            <?php else: ?>
                <?php foreach ($byStage[$stageKey] as $lead): ?>
                <div class="lead-card" onclick="window.location='<?= module_url('pipeline', 'lead_ver.php?id=' . $lead['id']) ?>'">
                    <div class="lead-name"><?= e($lead['name']) ?></div>
                    <div class="lead-meta">
                        <?php if ($lead['phone']): ?>
                            <span class="phone">📱 <?= e($lead['phone']) ?></span>
                        <?php endif; ?>
                        <?php if ($lead['case_type']): ?>
                            <span>📁 <?= e($lead['case_type']) ?></span>
                        <?php endif; ?>
                        <?php if ($lead['estimated_value_cents']): ?>
                            <span class="lead-value">💰 <?= brl($lead['estimated_value_cents']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($lead['assigned_name']): ?>
                        <div class="lead-assigned">👤 <?= e($lead['assigned_name']) ?></div>
                    <?php endif; ?>
                    <div class="lead-days"><?= $lead['days_in_pipeline'] ?> dias no funil</div>

                    <div class="lead-actions" onclick="event.stopPropagation();">
                        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="display:flex;gap:.25rem;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <select name="to_stage" onchange="this.form.submit()">
                                <option value="">Mover →</option>
                                <?php foreach ($stages as $sk => $sv): ?>
                                    <?php if ($sk !== $stageKey): ?>
                                        <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
