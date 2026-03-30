<?php
/**
 * Ferreira & Sá Hub — Operacional (Kanban)
 * Fluxo: Contrato Assinado → Pasta Apta → Em Execução →
 *        Doc Faltante → Aguardando Distribuição → Processo Distribuído
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Operacional';
$pdo = db();
$userId = current_user_id();
$isColaborador = has_role('colaborador');

// Filtros
$filterPriority = isset($_GET['priority']) ? $_GET['priority'] : '';
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';

// Colunas do board (conforme doc técnico)
$columns = array(
    'aguardando_docs'        => array('label' => 'Contrato Assinado — Aguardando Docs', 'color' => '#f59e0b', 'icon' => '📄'),
    'em_elaboracao'          => array('label' => 'Pasta Apta',                           'color' => '#059669', 'icon' => '✔️'),
    'em_andamento'           => array('label' => 'Em Execução',                          'color' => '#0ea5e9', 'icon' => '⚙️'),
    'doc_faltante'           => array('label' => 'Documento Faltante',                   'color' => '#dc2626', 'icon' => '⚠️'),
    'aguardando_prazo'       => array('label' => 'Aguardando Distribuição / Extrajudicial', 'color' => '#8b5cf6', 'icon' => '⏳'),
    'distribuido'            => array('label' => 'Processo Distribuído',                  'color' => '#15803d', 'icon' => '🏛️'),
);

// Construir query
$where = array('1=1');
$params = array();

if ($isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = $userId;
}
if ($filterPriority) {
    $where[] = "cs.priority = ?";
    $params[] = $filterPriority;
}
if ($filterUser && !$isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = (int)$filterUser;
}

$whereStr = implode(' AND ', $where);

$sql = "SELECT cs.*, c.name as client_name, c.phone as client_phone, u.name as responsible_name,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'pendente') as pending_tasks,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'feito') as done_tasks,
        (SELECT descricao FROM documentos_pendentes WHERE case_id = cs.id AND status = 'pendente' ORDER BY solicitado_em DESC LIMIT 1) as doc_faltante_desc
        FROM cases cs
        LEFT JOIN clients c ON c.id = cs.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE $whereStr AND cs.status NOT IN ('concluido','arquivado')
        ORDER BY FIELD(cs.priority, 'urgente','alta','normal','baixa'), cs.deadline ASC, cs.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allCases = $stmt->fetchAll();

// Agrupar por status
$byStatus = array();
foreach (array_keys($columns) as $s) { $byStatus[$s] = array(); }
foreach ($allCases as $cs) {
    $status = $cs['status'];
    if (!isset($byStatus[$status])) { $status = 'em_andamento'; }
    $byStatus[$status][] = $cs;
}

// KPIs
$totalAtivos = count($allCases);
$urgentes = 0; $docsFaltantes = count($byStatus['doc_faltante']);
foreach ($allCases as $cs) {
    if ($cs['priority'] === 'urgente') $urgentes++;
}

// Documentos pendentes (para banner de alerta)
$docsPendentes = array();
try {
    $docsPendentes = $pdo->query(
        "SELECT dp.*, c.name as client_name, cs.title as case_title
         FROM documentos_pendentes dp
         LEFT JOIN clients c ON c.id = dp.client_id
         LEFT JOIN cases cs ON cs.id = dp.case_id
         WHERE dp.status = 'pendente'
         ORDER BY dp.solicitado_em DESC"
    )->fetchAll();
} catch (Exception $e) {}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$priorityColors = array('urgente' => '#ef4444', 'alta' => '#f59e0b', 'normal' => '#6366f1', 'baixa' => '#9ca3af');
$priorityLabels = array('urgente' => 'URGENTE', 'alta' => 'Alta', 'normal' => 'Normal', 'baixa' => 'Baixa');

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.op-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:.75rem; flex-wrap:wrap; gap:.5rem; }
.op-topbar h3 { font-size:.95rem; font-weight:700; color:var(--petrol-900); }
.op-filters { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
.op-filter-select { font-size:.72rem; padding:.3rem .45rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); color:var(--text); cursor:pointer; }

.op-kpis { display:flex; gap:.75rem; margin-bottom:.75rem; flex-wrap:wrap; }
.op-kpi { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.6rem .85rem; display:flex; align-items:center; gap:.5rem; min-width:120px; }
.op-kpi-value { font-size:1.2rem; font-weight:800; color:var(--petrol-900); }
.op-kpi-label { font-size:.62rem; color:var(--text-muted); text-transform:uppercase; }

.op-board { display:grid; grid-template-columns:repeat(<?= count($columns) ?>, 1fr); gap:.5rem; min-height:450px; overflow-x:auto; }
.op-column { display:flex; flex-direction:column; min-width:0; }
.op-col-header { padding:.55rem .7rem; border-radius:var(--radius) var(--radius) 0 0; color:#fff; font-weight:700; font-size:.68rem; display:flex; justify-content:space-between; align-items:center; }
.op-col-header .count { background:rgba(255,255,255,.25); padding:.1rem .4rem; border-radius:100px; font-size:.6rem; }
.op-col-body { flex:1; background:var(--bg); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); padding:.35rem; display:flex; flex-direction:column; gap:.35rem; min-height:80px; overflow-y:auto; max-height:70vh; }
.op-col-body.drag-over { background:rgba(99,102,241,.08); border-color:rgba(99,102,241,.4); }

.op-card { background:var(--bg-card); border-radius:var(--radius); padding:.6rem .7rem; box-shadow:var(--shadow-sm); border-left:4px solid #ccc; cursor:grab; transition:all var(--transition); }
.op-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.op-card.dragging { opacity:.4; cursor:grabbing; }
.op-card-name { font-weight:700; font-size:.78rem; color:var(--petrol-900); margin-bottom:.15rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.op-card-client { font-size:.65rem; color:var(--text-muted); margin-bottom:.2rem; }
.op-card-badges { display:flex; gap:.15rem; flex-wrap:wrap; margin-bottom:.25rem; }
.op-card-badge { font-size:.55rem; font-weight:700; padding:.1rem .3rem; border-radius:4px; color:#fff; text-transform:uppercase; }
.op-card-footer { display:flex; justify-content:space-between; align-items:center; }
.op-card-resp { font-size:.6rem; color:var(--rose-dark); font-weight:600; }
.op-card-tasks { font-size:.6rem; color:var(--text-muted); display:flex; align-items:center; gap:.2rem; }
.op-card-tasks .mini-bar { width:35px; height:3px; background:var(--border); border-radius:3px; overflow:hidden; display:inline-block; }
.op-card-tasks .mini-fill { height:100%; background:var(--success); border-radius:3px; display:block; }
.op-card-deadline { font-size:.55rem; margin-top:.2rem; }
.op-card-deadline.overdue { color:#ef4444; font-weight:700; }
.op-card-process { font-size:.58rem; color:var(--petrol-500); font-weight:600; margin-top:.2rem; }
.op-card-doc-alert { background:#fef2f2; border:1px solid #fecaca; border-radius:4px; padding:.2rem .4rem; font-size:.55rem; color:#dc2626; font-weight:600; margin-top:.2rem; }

.op-card-move { margin-top:.3rem; width:100%; font-size:.6rem; padding:.2rem .25rem; border:1px solid var(--border); border-radius:4px; background:var(--bg-card); cursor:pointer; }
.op-empty { text-align:center; padding:1rem .5rem; color:var(--text-muted); font-size:.7rem; }

.page-content { max-width:none !important; padding:.75rem !important; }
@media (max-width: 1024px) { .op-board { grid-template-columns:repeat(3, 1fr); } }
@media (max-width: 768px) { .op-board { grid-template-columns:repeat(2, 1fr); } }
</style>

<!-- Banner: Documentos Pendentes -->
<?php if (!empty($docsPendentes)): ?>
<div style="background:#fef2f2;border:2px solid #fecaca;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.5rem;">
        <span style="font-size:1.2rem;">⚠️</span>
        <strong style="font-size:.88rem;color:#dc2626;"><?= count($docsPendentes) ?> documento(s) faltante(s) — aguardando CX</strong>
    </div>
    <?php foreach ($docsPendentes as $dp): ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.4rem 0;border-top:1px solid #fecaca;">
        <span style="font-size:.78rem;font-weight:700;color:#052228;"><?= e($dp['client_name'] ?: $dp['case_title'] ?: 'Caso #' . $dp['case_id']) ?></span>
        <span style="font-size:.75rem;color:#dc2626;font-weight:600;">→ <?= e($dp['descricao']) ?></span>
        <span style="font-size:.65rem;color:#6b7280;margin-left:auto;"><?= date('d/m H:i', strtotime($dp['solicitado_em'])) ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="op-kpis">
    <div class="op-kpi"><span style="font-size:1rem;">📂</span><div><div class="op-kpi-value"><?= $totalAtivos ?></div><div class="op-kpi-label"><?= $isColaborador ? 'Seus casos' : 'Casos ativos' ?></div></div></div>
    <?php if ($urgentes > 0): ?><div class="op-kpi"><span style="font-size:1rem;">🔴</span><div><div class="op-kpi-value"><?= $urgentes ?></div><div class="op-kpi-label">Urgentes</div></div></div><?php endif; ?>
    <?php if ($docsFaltantes > 0): ?><div class="op-kpi"><span style="font-size:1rem;">⚠️</span><div><div class="op-kpi-value"><?= $docsFaltantes ?></div><div class="op-kpi-label">Doc faltante</div></div></div><?php endif; ?>
</div>

<!-- Filtros -->
<div class="op-topbar">
    <h3>Kanban Operacional</h3>
    <form method="GET" class="op-filters">
        <select name="priority" class="op-filter-select" onchange="this.form.submit()">
            <option value="">Prioridade</option>
            <?php foreach ($priorityLabels as $pk => $pl): ?>
                <option value="<?= $pk ?>" <?= $filterPriority === $pk ? 'selected' : '' ?>><?= $pl ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!$isColaborador): ?>
        <select name="user" class="op-filter-select" onchange="this.form.submit()">
            <option value="">Responsável</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($filterPriority || $filterUser): ?>
            <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm" style="font-size:.65rem;">Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Board Kanban -->
<div class="op-board">
    <?php foreach ($columns as $colKey => $col): ?>
    <div class="op-column">
        <div class="op-col-header" style="background:<?= $col['color'] ?>;">
            <span><?= $col['icon'] ?> <?= $col['label'] ?></span>
            <span class="count"><?= count($byStatus[$colKey]) ?></span>
        </div>
        <div class="op-col-body" data-status="<?= $colKey ?>">
            <?php if (empty($byStatus[$colKey])): ?>
                <div class="op-empty">Nenhum caso</div>
            <?php else: ?>
                <?php foreach ($byStatus[$colKey] as $cs):
                    $totalTasks = $cs['pending_tasks'] + $cs['done_tasks'];
                    $taskPct = $totalTasks > 0 ? round(($cs['done_tasks'] / $totalTasks) * 100) : 0;
                    $isOverdue = $cs['deadline'] && $cs['deadline'] < date('Y-m-d');
                    $pColor = isset($priorityColors[$cs['priority']]) ? $priorityColors[$cs['priority']] : '#9ca3af';
                ?>
                <div class="op-card" draggable="true" data-case-id="<?= $cs['id'] ?>" data-case-type="<?= e($cs['case_type'] ?: '') ?>" style="border-left-color:<?= $pColor ?>;"
                     onclick="if(!event.target.closest('select,form,.op-card-move'))window.location='<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>'">
                    <div class="op-card-name"><?= e($cs['title'] ?: 'Caso #' . $cs['id']) ?></div>
                    <div class="op-card-client">👤 <?= e($cs['client_name'] ?: 'Sem cliente') ?></div>
                    <div class="op-card-badges">
                        <span class="op-card-badge" style="background:<?= $pColor ?>;"><?= isset($priorityLabels[$cs['priority']]) ? $priorityLabels[$cs['priority']] : $cs['priority'] ?></span>
                        <?php if ($cs['case_type'] && $cs['case_type'] !== 'outro'): ?>
                            <span class="op-card-badge" style="background:#173d46;"><?= e($cs['case_type']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="op-card-footer">
                        <span class="op-card-resp"><?= e($cs['responsible_name'] ? explode(' ', $cs['responsible_name'])[0] : '—') ?></span>
                        <?php if ($totalTasks > 0): ?>
                        <span class="op-card-tasks">
                            <span class="mini-bar"><span class="mini-fill" style="width:<?= $taskPct ?>%;"></span></span>
                            <?= $cs['done_tasks'] ?>/<?= $totalTasks ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($cs['case_number']): ?>
                        <div class="op-card-process">🏛️ <?= e($cs['case_number']) ?></div>
                    <?php endif; ?>
                    <?php if ($cs['deadline']): ?>
                        <div class="op-card-deadline <?= $isOverdue ? 'overdue' : '' ?>"><?= $isOverdue ? '⚠️ Vencido ' : '📅 ' ?><?= date('d/m', strtotime($cs['deadline'])) ?></div>
                    <?php endif; ?>
                    <?php if ($cs['doc_faltante_desc']): ?>
                        <div class="op-card-doc-alert">⚠️ Falta: <?= e($cs['doc_faltante_desc']) ?></div>
                    <?php endif; ?>

                    <!-- Mover rápido -->
                    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onclick="event.stopPropagation();">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="case_id" value="<?= $cs['id'] ?>">
                        <select name="new_status" class="op-card-move" onchange="handleOpMove(this)">
                            <option value="">Mover →</option>
                            <?php foreach ($columns as $sk => $sv): ?>
                                <?php if ($sk !== $colKey): ?>
                                    <option value="<?= $sk ?>"><?= $sv['icon'] ?> <?= $sv['label'] ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal: Documento Faltante -->
<div id="docFaltanteModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#dc2626;margin-bottom:.5rem;">⚠️ Documento Faltante</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:.75rem;">Descreva qual documento está faltando. O CX será notificado para providenciar.</p>
        <textarea id="docFaltanteDesc" rows="3" style="width:100%;padding:.6rem .8rem;font-size:.88rem;border:2px solid #e5e7eb;border-radius:10px;font-family:inherit;outline:none;resize:vertical;" placeholder="Ex: Certidão de nascimento do menor, comprovante de renda..."></textarea>
        <div style="display:flex;gap:.5rem;margin-top:1rem;justify-content:flex-end;">
            <button onclick="closeDocModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmDocFaltante()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#dc2626;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Sinalizar ⚠️</button>
        </div>
    </div>
</div>

<!-- Modal: Processo Distribuído -->
<div id="processoModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.5rem;">🏛️ Dados do Processo Distribuído</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Preencha os dados do processo judicial.</p>
        <div style="display:grid;gap:.75rem;">
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Número do processo *</label>
                <input type="text" id="procNumero" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="0000000-00.0000.0.00.0000">
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Vara / Juízo *</label>
                <input type="text" id="procVara" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Ex: 1ª Vara de Família de Resende">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;">
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Tipo de demanda</label>
                    <input type="text" id="procTipo" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" placeholder="Divórcio, Alimentos...">
                </div>
                <div>
                    <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Data da distribuição</label>
                    <input type="date" id="procData" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="closeProcModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmProcesso()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Salvar Processo →</button>
        </div>
    </div>
</div>

<script>
var _pendingOpForm = null;
var csrfToken = '<?= generate_csrf_token() ?>';

function handleOpMove(select) {
    var status = select.value;
    if (!status) return;
    var form = select.closest('form');

    if (status === 'doc_faltante') {
        _pendingOpForm = form;
        document.getElementById('docFaltanteModal').style.display = 'flex';
        document.getElementById('docFaltanteDesc').focus();
        select.value = '';
        return;
    }

    if (status === 'distribuido') {
        _pendingOpForm = form;
        // Preencher tipo se disponível
        var card = select.closest('.op-card');
        if (card && card.dataset.caseType) {
            document.getElementById('procTipo').value = card.dataset.caseType;
        }
        document.getElementById('processoModal').style.display = 'flex';
        document.getElementById('procNumero').focus();
        select.value = '';
        return;
    }

    form.submit();
}

// Doc Faltante
function closeDocModal() {
    document.getElementById('docFaltanteModal').style.display = 'none';
    _pendingOpForm = null;
}

function confirmDocFaltante() {
    var desc = document.getElementById('docFaltanteDesc').value.trim();
    if (!desc) { document.getElementById('docFaltanteDesc').style.borderColor = '#ef4444'; return; }
    document.getElementById('docFaltanteModal').style.display = 'none';

    if (_pendingOpForm) {
        // Remover o select para evitar conflito de nomes
        var sel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (sel) sel.removeAttribute('name');
        // Adicionar campos
        var input = document.createElement('input');
        input.type = 'hidden'; input.name = 'doc_faltante_desc'; input.value = desc;
        _pendingOpForm.appendChild(input);
        var statusInput = document.createElement('input');
        statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'doc_faltante';
        _pendingOpForm.appendChild(statusInput);
        _pendingOpForm.submit();
    }
}

// Processo Distribuído
function closeProcModal() {
    document.getElementById('processoModal').style.display = 'none';
    _pendingOpForm = null;
}

function confirmProcesso() {
    var numero = document.getElementById('procNumero').value.trim();
    var vara = document.getElementById('procVara').value.trim();
    if (!numero || !vara) {
        if (!numero) document.getElementById('procNumero').style.borderColor = '#ef4444';
        if (!vara) document.getElementById('procVara').style.borderColor = '#ef4444';
        return;
    }
    document.getElementById('processoModal').style.display = 'none';

    if (_pendingOpForm) {
        var fields = {
            'proc_numero': numero,
            'proc_vara': vara,
            'proc_tipo': document.getElementById('procTipo').value,
            'proc_data': document.getElementById('procData').value
        };
        // Remover select para evitar conflito
        var sel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (sel) sel.removeAttribute('name');
        for (var k in fields) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = k; input.value = fields[k];
            _pendingOpForm.appendChild(input);
        }
        var statusInput = document.createElement('input');
        statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'distribuido';
        _pendingOpForm.appendChild(statusInput);
        _pendingOpForm.submit();
    }
}

// Drag and Drop
(function() {
    var dragCard = null;

    document.querySelectorAll('.op-card[draggable]').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            dragCard = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.caseId);
        });
        card.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            document.querySelectorAll('.op-col-body').forEach(function(col) { col.classList.remove('drag-over'); });
            dragCard = null;
        });
    });

    document.querySelectorAll('.op-col-body').forEach(function(col) {
        col.addEventListener('dragover', function(e) { e.preventDefault(); this.classList.add('drag-over'); });
        col.addEventListener('dragleave', function(e) { if (!this.contains(e.relatedTarget)) this.classList.remove('drag-over'); });
        col.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
            var caseId = e.dataTransfer.getData('text/plain');
            var newStatus = this.dataset.status;
            if (!dragCard || !caseId || !newStatus) return;

            if (newStatus === 'doc_faltante') {
                // Simular clique no select para abrir modal
                var form = dragCard.querySelector('form');
                _pendingOpForm = form;
                document.getElementById('docFaltanteModal').style.display = 'flex';
                document.getElementById('docFaltanteDesc').focus();
                return;
            }

            if (newStatus === 'distribuido') {
                var form = dragCard.querySelector('form');
                _pendingOpForm = form;
                if (dragCard.dataset.caseType) document.getElementById('procTipo').value = dragCard.dataset.caseType;
                document.getElementById('processoModal').style.display = 'flex';
                document.getElementById('procNumero').focus();
                return;
            }

            // Mover via AJAX
            var formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('case_id', caseId);
            formData.append('new_status', newStatus);
            formData.append('csrf_token', csrfToken);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    try {
                        var resp = JSON.parse(xhr.responseText);
                        if (resp.error) { showToast(resp.error, 'error'); location.reload(); }
                        else { showToast('Caso movido!'); var empty = col.querySelector('.op-empty'); if (empty) empty.remove(); col.appendChild(dragCard); }
                    } catch(ex) { location.reload(); }
                } else { location.reload(); }
            };
            xhr.send(formData);
        });
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
