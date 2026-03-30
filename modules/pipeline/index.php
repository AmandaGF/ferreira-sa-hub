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
    'novo'              => ['label' => 'Novo',               'color' => '#6366f1', 'icon' => '🆕'],
    'contato_inicial'   => ['label' => 'Contato Inicial',    'color' => '#0ea5e9', 'icon' => '📞'],
    'agendado'          => ['label' => 'Agendado',           'color' => '#f59e0b', 'icon' => '📅'],
    'proposta'          => ['label' => 'Proposta',           'color' => '#d97706', 'icon' => '📄'],
    'elaboracao'        => ['label' => 'Elaboração Contrato','color' => '#8b5cf6', 'icon' => '📝'],
    'contrato'          => ['label' => 'Contrato Assinado',  'color' => '#059669', 'icon' => '✅'],
    'preparacao_pasta'  => ['label' => 'Preparação da Pasta','color' => '#0d9488', 'icon' => '📂'],
    'pasta_apta'        => ['label' => 'Pasta Apta',         'color' => '#15803d', 'icon' => '✔️'],
    'perdido'           => ['label' => 'Perdido',            'color' => '#dc2626', 'icon' => '❌'],
];

// Buscar leads agrupados por estágio (exceto finalizados)
$leads = $pdo->query(
    "SELECT pl.*, u.name as assigned_name,
     DATEDIFF(NOW(), pl.created_at) as days_in_pipeline
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     WHERE pl.stage != 'finalizado'
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

.page-content { max-width:none !important; padding:.75rem !important; overflow-x:auto; }
.kanban-header { padding:.75rem 1rem; border-radius:var(--radius) var(--radius) 0 0; color:#fff; font-weight:700; font-size:.82rem; display:flex; align-items:center; justify-content:space-between; }
.kanban-header .count { background:rgba(255,255,255,.25); padding:.1rem .5rem; border-radius:100px; font-size:.72rem; }
.kanban-body { flex:1; background:var(--bg); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); padding:.5rem; display:flex; flex-direction:column; gap:.5rem; min-height:80px; }

.lead-card { background:var(--bg-card); border-radius:var(--radius); padding:.7rem; box-shadow:var(--shadow-sm); border-left:4px solid #ccc; cursor:pointer; transition:all var(--transition); overflow:hidden; }
.lead-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.lead-card[data-stage="novo"] { border-left-color:#6366f1; background:#f5f3ff; }
.lead-card[data-stage="contato_inicial"] { border-left-color:#0ea5e9; background:#f0f9ff; }
.lead-card[data-stage="agendado"] { border-left-color:#f59e0b; background:#fffbeb; }
.lead-card[data-stage="proposta"] { border-left-color:#d97706; background:#fff7ed; }
.lead-card[data-stage="elaboracao"] { border-left-color:#8b5cf6; background:#faf5ff; }
.lead-card[data-stage="contrato"] { border-left-color:#059669; background:#ecfdf5; }
.lead-card[data-stage="preparacao_pasta"] { border-left-color:#0d9488; background:#f0fdfa; }
.lead-card[data-stage="pasta_apta"] { border-left-color:#15803d; background:#f0fdf4; }
.lead-card[data-stage="perdido"] { border-left-color:#dc2626; background:#fef2f2; }
.lead-name { font-weight:700; font-size:.88rem; color:var(--petrol-900); margin-bottom:.25rem; }
.lead-meta { font-size:.72rem; color:var(--text-muted); display:flex; flex-direction:column; gap:.15rem; }
.lead-meta .phone { color:var(--success); }
.lead-days { font-size:.65rem; background:rgba(0,0,0,.05); padding:.15rem .4rem; border-radius:6px; display:inline-block; margin-top:.35rem; }
.lead-assigned { font-size:.68rem; color:var(--rose-dark); font-weight:600; margin-top:.25rem; }
.lead-value { font-size:.75rem; font-weight:700; color:var(--petrol-500); }
.lead-actions { display:flex; gap:.25rem; margin-top:.5rem; flex-wrap:wrap; }
.lead-actions select { font-size:.68rem; padding:.2rem .25rem; border:1px solid var(--border); border-radius:6px; background:var(--bg-card); cursor:pointer; max-width:100%; width:100%; }
.lead-actions button { font-size:.65rem; padding:.2rem .4rem; background:var(--petrol-100); border:none; border-radius:6px; cursor:pointer; color:var(--petrol-500); font-weight:600; }
.lead-actions button:hover { background:var(--petrol-900); color:#fff; }

.kanban-header { font-size:.72rem; }
.lead-name { font-size:.82rem; }
.lead-meta { font-size:.68rem; }
.lead-days { font-size:.6rem; }
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
<div style="display:grid;grid-template-columns:repeat(<?= count($stages) ?>,minmax(140px,1fr));gap:.5rem;min-height:400px;overflow-x:auto;">
    <?php foreach ($stages as $stageKey => $stage): ?>
    <div style="display:flex;flex-direction:column;min-width:0;">
        <div class="kanban-header" style="background:<?= $stage['color'] ?>;">
            <span><?= $stage['icon'] ?> <?= $stage['label'] ?></span>
            <span class="count"><?= count($byStage[$stageKey]) ?></span>
        </div>
        <div class="kanban-body" data-stage="<?= $stageKey ?>">
            <?php if (empty($byStage[$stageKey])): ?>
                <div style="text-align:center;padding:1.5rem .5rem;color:var(--text-muted);font-size:.78rem;">Nenhum lead</div>
            <?php else: ?>
                <?php foreach ($byStage[$stageKey] as $lead): ?>
                <div class="lead-card" draggable="true" data-lead-id="<?= $lead['id'] ?>" data-stage="<?= $stageKey ?>" onclick="if(!window._dragging)window.location='<?= module_url('pipeline', 'lead_ver.php?id=' . $lead['id']) ?>'">
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

                    <div class="lead-actions" onclick="event.stopPropagation();" style="display:flex;gap:.25rem;align-items:center;">
                        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="flex:1;" data-lead-name="<?= e($lead['name']) ?>" data-case-type="<?= e($lead['case_type'] ?: '') ?>">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <input type="hidden" name="folder_name" value="">
                            <select name="to_stage" onchange="handleStageMove(this)">
                                <option value="">Mover →</option>
                                <?php foreach ($stages as $sk => $sv): ?>
                                    <?php if ($sk !== $stageKey): ?>
                                        <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" style="flex-shrink:0;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.8rem;padding:.2rem;opacity:.5;" title="Excluir lead" data-confirm="Excluir <?= e($lead['name']) ?> do Pipeline?">🗑️</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<style>
.lead-card[draggable="true"] { cursor:grab; }
.lead-card[draggable="true"]:active { cursor:grabbing; }
.lead-card.dragging { opacity:.4; transform:rotate(2deg); }
.kanban-body.drag-over { background:rgba(215,171,144,.15); border:2px dashed var(--rose); border-radius:var(--radius); }
</style>

<!-- Modal: Nome da Pasta -->
<div id="folderModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.25rem;">📂 Nome da Pasta no Drive</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Digite como quer que a pasta seja criada (ex: Ana Maria Braga x Pensão)</p>
        <input type="text" id="folderNameInput" style="width:100%;padding:.65rem .85rem;font-size:.95rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;" placeholder="Nome da pasta...">
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeFolderModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600;color:#6b7280;">Cancelar</button>
            <button onclick="confirmFolder()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Criar Pasta →</button>
        </div>
    </div>
</div>

<script>
var _pendingForm = null;
var _pendingDragData = null;

// Quando seleciona no dropdown "Mover →"
function handleStageMove(select) {
    var stage = select.value;
    if (!stage) return;
    var form = select.closest('form');

    if (stage === 'preparacao_pasta') {
        // Abrir modal para digitar nome da pasta
        var leadName = form.dataset.leadName || '';
        var caseType = form.dataset.caseType || '';
        var sugestao = leadName + (caseType ? ' x ' + caseType : '');
        document.getElementById('folderNameInput').value = sugestao;
        document.getElementById('folderModal').style.display = 'flex';
        document.getElementById('folderNameInput').focus();
        document.getElementById('folderNameInput').select();
        _pendingForm = form;
        _pendingDragData = null;
    } else {
        form.submit();
    }
}

function closeFolderModal() {
    document.getElementById('folderModal').style.display = 'none';
    // Resetar select se veio do dropdown
    if (_pendingForm) {
        var sel = _pendingForm.querySelector('select[name="to_stage"]');
        if (sel) sel.value = '';
    }
    _pendingForm = null;
    _pendingDragData = null;
}

function confirmFolder() {
    var folderName = document.getElementById('folderNameInput').value.trim();
    if (!folderName) {
        document.getElementById('folderNameInput').style.borderColor = '#ef4444';
        return;
    }

    document.getElementById('folderModal').style.display = 'none';

    if (_pendingForm) {
        // Veio do dropdown
        _pendingForm.querySelector('input[name="folder_name"]').value = folderName;
        _pendingForm.submit();
    } else if (_pendingDragData) {
        // Veio do drag-and-drop
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = _pendingDragData.apiUrl;
        form.innerHTML = '<input type="hidden" name="csrf_token" value="' + _pendingDragData.csrfToken + '">' +
            '<input type="hidden" name="action" value="move">' +
            '<input type="hidden" name="lead_id" value="' + _pendingDragData.leadId + '">' +
            '<input type="hidden" name="to_stage" value="preparacao_pasta">' +
            '<input type="hidden" name="folder_name" value="' + folderName.replace(/"/g, '&quot;') + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

// Enter para confirmar
document.getElementById('folderNameInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') confirmFolder();
    if (e.key === 'Escape') closeFolderModal();
});

(function() {
    var draggedId = null;
    var draggedName = '';
    var draggedType = '';
    window._dragging = false;
    var csrfToken = '<?= generate_csrf_token() ?>';
    var apiUrl = '<?= module_url("pipeline", "api.php") ?>';

    // Drag start
    document.querySelectorAll('.lead-card[draggable]').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            draggedId = this.dataset.leadId;
            // Pegar nome e tipo do card
            var nameEl = this.querySelector('.lead-name');
            draggedName = nameEl ? nameEl.textContent.trim() : '';
            var form = this.querySelector('form');
            draggedType = form ? (form.dataset.caseType || '') : '';
            window._dragging = true;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', draggedId);
        });

        card.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            setTimeout(function() { window._dragging = false; }, 100);
            document.querySelectorAll('.kanban-body').forEach(function(b) { b.classList.remove('drag-over'); });
        });
    });

    // Drop zones
    document.querySelectorAll('.kanban-body').forEach(function(body) {
        body.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        });

        body.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });

        body.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            var toStage = this.dataset.stage;
            if (!draggedId || !toStage) return;

            if (toStage === 'preparacao_pasta') {
                // Abrir modal
                var sugestao = draggedName + (draggedType ? ' x ' + draggedType : '');
                document.getElementById('folderNameInput').value = sugestao;
                document.getElementById('folderModal').style.display = 'flex';
                document.getElementById('folderNameInput').focus();
                document.getElementById('folderNameInput').select();
                _pendingForm = null;
                _pendingDragData = { leadId: draggedId, csrfToken: csrfToken, apiUrl: apiUrl };
            } else {
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = apiUrl;
                form.innerHTML = '<input type="hidden" name="csrf_token" value="' + csrfToken + '">' +
                    '<input type="hidden" name="action" value="move">' +
                    '<input type="hidden" name="lead_id" value="' + draggedId + '">' +
                    '<input type="hidden" name="to_stage" value="' + toStage + '">';
                document.body.appendChild(form);
                form.submit();
            }
        });
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
