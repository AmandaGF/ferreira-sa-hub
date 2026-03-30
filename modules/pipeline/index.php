<?php
/**
 * Ferreira & Sá Hub — Pipeline Comercial/CX (Kanban)
 * Fluxo: Cadastro → Elaboração → Link Enviados → Contrato Assinado →
 *        Agendado/Docs → Reunião/Cobrança → Doc Faltante → Pasta Apta
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Pipeline Comercial/CX';
$pdo = db();

// Estágios do funil (conforme doc técnico)
$stages = array(
    'cadastro_preenchido' => array('label' => 'Cadastro Preenchido',        'color' => '#6366f1', 'icon' => '📋', 'resp' => 'Auto'),
    'elaboracao_docs'     => array('label' => 'Elaboração Procuração/Contrato', 'color' => '#0ea5e9', 'icon' => '📝', 'resp' => 'Comercial'),
    'link_enviados'       => array('label' => 'Link Enviados',              'color' => '#f59e0b', 'icon' => '📨', 'resp' => 'Comercial'),
    'contrato_assinado'   => array('label' => 'Contrato Assinado',          'color' => '#059669', 'icon' => '✅', 'resp' => 'Comercial'),
    'agendado_docs'       => array('label' => 'Agendado + Docs Solicitados','color' => '#0d9488', 'icon' => '📅', 'resp' => 'CX'),
    'reuniao_cobranca'    => array('label' => 'Reunião / Cobrando Docs',    'color' => '#d97706', 'icon' => '🤝', 'resp' => 'CX'),
    'doc_faltante'        => array('label' => 'Documento Faltante',         'color' => '#dc2626', 'icon' => '⚠️', 'resp' => 'Auto'),
    'pasta_apta'          => array('label' => 'Pasta Apta',                 'color' => '#15803d', 'icon' => '✔️', 'resp' => 'CX'),
);

// Buscar leads (exceto finalizados)
$leads = $pdo->query(
    "SELECT pl.*, u.name as assigned_name, c.name as client_name,
     DATEDIFF(NOW(), pl.created_at) as days_in_pipeline
     FROM pipeline_leads pl
     LEFT JOIN users u ON u.id = pl.assigned_to
     LEFT JOIN clients c ON c.id = pl.client_id
     WHERE pl.stage NOT IN ('finalizado','perdido')
     ORDER BY pl.updated_at DESC"
)->fetchAll();

$byStage = array();
foreach (array_keys($stages) as $s) { $byStage[$s] = array(); }
foreach ($leads as $lead) {
    $st = $lead['stage'];
    if (isset($byStage[$st])) {
        $byStage[$st][] = $lead;
    }
}

// KPIs
$totalAtivos = count($leads);
$contratosAssinados = count($byStage['contrato_assinado']) + count($byStage['agendado_docs']) + count($byStage['reuniao_cobranca']) + count($byStage['pasta_apta']);
$pastasAptas = count($byStage['pasta_apta']);
$docsFaltantes = count($byStage['doc_faltante']);

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pipeline-stats { display:flex; gap:1rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.pipeline-stats .stat-card { flex:1; min-width:140px; }
.page-content { max-width:none !important; padding:.75rem !important; overflow-x:auto; }

.kanban-header { padding:.6rem .85rem; border-radius:var(--radius) var(--radius) 0 0; color:#fff; font-weight:700; font-size:.72rem; display:flex; align-items:center; justify-content:space-between; }
.kanban-header .count { background:rgba(255,255,255,.25); padding:.1rem .5rem; border-radius:100px; font-size:.65rem; }
.kanban-header .resp { font-size:.55rem; opacity:.7; font-weight:400; }
.kanban-body { flex:1; background:var(--bg); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); padding:.4rem; display:flex; flex-direction:column; gap:.4rem; min-height:80px; }
.kanban-body.drag-over { background:rgba(215,171,144,.15); border:2px dashed var(--rose); }

.lead-card { background:var(--bg-card); border-radius:var(--radius); padding:.6rem .7rem; box-shadow:var(--shadow-sm); border-left:4px solid #ccc; cursor:grab; transition:all var(--transition); overflow:hidden; }
.lead-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.lead-card.dragging { opacity:.4; cursor:grabbing; }
.lead-name { font-weight:700; font-size:.8rem; color:var(--petrol-900); margin-bottom:.2rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lead-meta { font-size:.65rem; color:var(--text-muted); display:flex; flex-direction:column; gap:.1rem; }
.lead-meta .phone { color:var(--success); }
.lead-days { font-size:.58rem; background:rgba(0,0,0,.05); padding:.1rem .35rem; border-radius:6px; display:inline-block; margin-top:.25rem; }
.lead-doc-alert { background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:.3rem .5rem; font-size:.6rem; color:#dc2626; font-weight:600; margin-top:.3rem; }
.lead-actions { display:flex; gap:.25rem; margin-top:.4rem; align-items:center; }
.lead-actions select { font-size:.62rem; padding:.2rem .2rem; border:1px solid var(--border); border-radius:6px; background:var(--bg-card); cursor:pointer; max-width:100%; flex:1; }
.lead-del { background:none; border:none; cursor:pointer; font-size:.75rem; padding:.15rem; opacity:.4; }
.lead-del:hover { opacity:1; }
</style>

<!-- KPIs -->
<div class="pipeline-stats">
    <div class="stat-card"><div class="stat-icon info">📋</div><div class="stat-info"><div class="stat-value"><?= $totalAtivos ?></div><div class="stat-label">No funil</div></div></div>
    <div class="stat-card"><div class="stat-icon success">✅</div><div class="stat-info"><div class="stat-value"><?= $contratosAssinados ?></div><div class="stat-label">Pós-contrato</div></div></div>
    <div class="stat-card"><div class="stat-icon rose">✔️</div><div class="stat-info"><div class="stat-value"><?= $pastasAptas ?></div><div class="stat-label">Pastas aptas</div></div></div>
    <?php if ($docsFaltantes > 0): ?>
    <div class="stat-card"><div class="stat-icon danger">⚠️</div><div class="stat-info"><div class="stat-value"><?= $docsFaltantes ?></div><div class="stat-label">Doc faltante</div></div></div>
    <?php endif; ?>
</div>

<!-- Ações -->
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem;">
    <h3 style="font-size:.95rem;font-weight:700;color:var(--petrol-900);">Pipeline Comercial/CX</h3>
    <div style="display:flex;gap:.5rem;">
        <a href="<?= module_url('pipeline', 'lead_form.php') ?>" class="btn btn-primary btn-sm">+ Novo Lead</a>
        <a href="<?= module_url('pipeline', 'perdidos.php') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Perdidos</a>
    </div>
</div>

<!-- Kanban -->
<div style="display:grid;grid-template-columns:repeat(<?= count($stages) ?>,minmax(155px,1fr));gap:.4rem;min-height:400px;overflow-x:auto;">
    <?php foreach ($stages as $stageKey => $stage): ?>
    <div style="display:flex;flex-direction:column;min-width:0;">
        <div class="kanban-header" style="background:<?= $stage['color'] ?>;">
            <span><?= $stage['icon'] ?> <?= $stage['label'] ?></span>
            <span class="count"><?= count($byStage[$stageKey]) ?></span>
        </div>
        <div class="kanban-body" data-stage="<?= $stageKey ?>">
            <?php if (empty($byStage[$stageKey])): ?>
                <div style="text-align:center;padding:1rem .5rem;color:var(--text-muted);font-size:.72rem;">Nenhum</div>
            <?php else: ?>
                <?php foreach ($byStage[$stageKey] as $lead): ?>
                <div class="lead-card" draggable="true" data-lead-id="<?= $lead['id'] ?>" style="border-left-color:<?= $stage['color'] ?>;"
                     onclick="if(!window._dragging&&!event.target.closest('.lead-actions'))window.location='<?= module_url('pipeline', 'lead_ver.php?id=' . $lead['id']) ?>'">
                    <div class="lead-name"><?= e($lead['name']) ?></div>
                    <div class="lead-meta">
                        <?php if ($lead['phone']): ?><span class="phone">📱 <?= e($lead['phone']) ?></span><?php endif; ?>
                        <?php if ($lead['case_type']): ?><span>📁 <?= e($lead['case_type']) ?></span><?php endif; ?>
                    </div>
                    <?php if ($lead['assigned_name']): ?>
                        <div style="font-size:.6rem;color:var(--rose-dark);font-weight:600;margin-top:.15rem;">👤 <?= e(explode(' ', $lead['assigned_name'])[0]) ?></div>
                    <?php endif; ?>
                    <?php if ($stageKey === 'doc_faltante' && $lead['doc_faltante_motivo']): ?>
                        <div class="lead-doc-alert">⚠️ <?= e($lead['doc_faltante_motivo']) ?></div>
                    <?php endif; ?>
                    <div class="lead-days"><?= $lead['days_in_pipeline'] ?>d no funil</div>

                    <div class="lead-actions" onclick="event.stopPropagation();">
                        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>" data-lead-name="<?= e($lead['name']) ?>" data-case-type="<?= e($lead['case_type'] ?: '') ?>">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="move">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <input type="hidden" name="folder_name" value="">
                            <select name="to_stage" onchange="handleStageMove(this)" style="flex:1;">
                                <option value="">Mover →</option>
                                <?php foreach ($stages as $sk => $sv): ?>
                                    <?php if ($sk !== $stageKey && $sk !== 'doc_faltante'): ?>
                                        <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <option value="perdido">❌ Perdido</option>
                            </select>
                        </form>
                        <form method="POST" action="<?= module_url('pipeline', 'api.php') ?>">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                            <button type="submit" class="lead-del" title="Excluir" data-confirm="Excluir <?= e($lead['name']) ?>?">🗑️</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal: Nome da Pasta (ao mover para contrato_assinado) -->
<div id="folderModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.25rem;">📂 Nome da Pasta no Drive</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Ao assinar contrato, uma pasta será criada no Drive e o Operacional será notificado.</p>
        <input type="text" id="folderNameInput" style="width:100%;padding:.65rem .85rem;font-size:.95rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;" placeholder="Ex: Ana Maria Braga x Pensão">
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeFolderModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:600;color:#6b7280;">Cancelar</button>
            <button onclick="confirmFolder()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Criar Pasta →</button>
        </div>
    </div>
</div>

<script>
var _pendingForm = null;
var _pendingDragData = null;

function handleStageMove(select) {
    var stage = select.value;
    if (!stage) return;
    var form = select.closest('form');

    if (stage === 'contrato_assinado') {
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
    if (_pendingForm) {
        var sel = _pendingForm.querySelector('select[name="to_stage"]');
        if (sel) sel.value = '';
    }
    _pendingForm = null;
    _pendingDragData = null;
}

function confirmFolder() {
    var folderName = document.getElementById('folderNameInput').value.trim();
    if (!folderName) { document.getElementById('folderNameInput').style.borderColor = '#ef4444'; return; }
    document.getElementById('folderModal').style.display = 'none';

    if (_pendingForm) {
        _pendingForm.querySelector('input[name="folder_name"]').value = folderName;
        _pendingForm.submit();
    } else if (_pendingDragData) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = _pendingDragData.apiUrl;
        form.innerHTML = '<input type="hidden" name="csrf_token" value="' + _pendingDragData.csrfToken + '">' +
            '<input type="hidden" name="action" value="move">' +
            '<input type="hidden" name="lead_id" value="' + _pendingDragData.leadId + '">' +
            '<input type="hidden" name="to_stage" value="contrato_assinado">' +
            '<input type="hidden" name="folder_name" value="' + folderName.replace(/"/g, '&quot;') + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

document.getElementById('folderNameInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') confirmFolder();
    if (e.key === 'Escape') closeFolderModal();
});

// Drag & Drop
(function() {
    var draggedId = null, draggedName = '', draggedType = '';
    window._dragging = false;
    var csrfToken = '<?= generate_csrf_token() ?>';
    var apiUrl = '<?= module_url("pipeline", "api.php") ?>';

    document.querySelectorAll('.lead-card[draggable]').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            draggedId = this.dataset.leadId;
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

    document.querySelectorAll('.kanban-body').forEach(function(body) {
        body.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        body.addEventListener('dragleave', function() { this.classList.remove('drag-over'); });
        body.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            var toStage = this.dataset.stage;
            if (!draggedId || !toStage) return;

            if (toStage === 'contrato_assinado') {
                var sugestao = draggedName + (draggedType ? ' x ' + draggedType : '');
                document.getElementById('folderNameInput').value = sugestao;
                document.getElementById('folderModal').style.display = 'flex';
                document.getElementById('folderNameInput').focus();
                _pendingForm = null;
                _pendingDragData = { leadId: draggedId, csrfToken: csrfToken, apiUrl: apiUrl };
            } else {
                var form = document.createElement('form');
                form.method = 'POST'; form.action = apiUrl;
                form.innerHTML = '<input type="hidden" name="csrf_token" value="' + csrfToken + '"><input type="hidden" name="action" value="move"><input type="hidden" name="lead_id" value="' + draggedId + '"><input type="hidden" name="to_stage" value="' + toStage + '">';
                document.body.appendChild(form); form.submit();
            }
        });
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
