<?php
/**
 * Ferreira & Sá Hub — Operacional (Board Kanban)
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
$filterType = isset($_GET['type']) ? $_GET['type'] : '';

// Colunas do board
$columns = array(
    'aguardando_docs' => array('label' => 'Aguardando Docs', 'color' => '#f59e0b', 'icon' => '📄'),
    'em_elaboracao'   => array('label' => 'Em Elaboração',   'color' => '#6366f1', 'icon' => '📝'),
    'em_andamento'    => array('label' => 'Em Execução',     'color' => '#0ea5e9', 'icon' => '⚙️'),
    'aguardando_prazo'=> array('label' => 'Aguardando Prazo','color' => '#d97706', 'icon' => '⏳'),
    'distribuido'     => array('label' => 'Revisão',         'color' => '#8b5cf6', 'icon' => '🔍'),
    'concluido'       => array('label' => 'Concluído',       'color' => '#059669', 'icon' => '✅'),
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
if ($filterType) {
    $where[] = "cs.case_type = ?";
    $params[] = $filterType;
}

$whereStr = implode(' AND ', $where);

$sql = "SELECT cs.*, c.name as client_name, c.phone as client_phone, u.name as responsible_name,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'pendente') as pending_tasks,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'feito') as done_tasks
        FROM cases cs
        LEFT JOIN clients c ON c.id = cs.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE $whereStr
        ORDER BY FIELD(cs.priority, 'urgente','alta','normal','baixa'), cs.deadline ASC, cs.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allCases = $stmt->fetchAll();

// Agrupar por status
$byStatus = array();
foreach (array_keys($columns) as $s) { $byStatus[$s] = array(); }
foreach ($allCases as $cs) {
    $status = $cs['status'];
    if (!isset($byStatus[$status])) {
        // Status não mapeado (ativo, suspenso, etc) vai para aguardando_docs
        $status = 'em_andamento';
    }
    $byStatus[$status][] = $cs;
}

// KPIs
$totalAtivos = 0;
$urgentes = 0;
$comPrazo = 0;
foreach ($allCases as $cs) {
    if ($cs['status'] !== 'concluido' && $cs['status'] !== 'arquivado') {
        $totalAtivos++;
        if ($cs['priority'] === 'urgente') $urgentes++;
        if ($cs['deadline'] && $cs['deadline'] <= date('Y-m-d', strtotime('+7 days'))) $comPrazo++;
    }
}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$priorityColors = array('urgente' => '#ef4444', 'alta' => '#f59e0b', 'normal' => '#6366f1', 'baixa' => '#9ca3af');
$priorityLabels = array('urgente' => 'URGENTE', 'alta' => 'Alta', 'normal' => 'Normal', 'baixa' => 'Baixa');

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.op-topbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; }
.op-topbar h3 { font-size:1rem; font-weight:700; color:var(--petrol-900); }
.op-filters { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
.op-filter-select { font-size:.75rem; padding:.35rem .5rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); color:var(--text); cursor:pointer; }

/* KPI mini */
.op-kpis { display:flex; gap:.75rem; margin-bottom:1rem; flex-wrap:wrap; }
.op-kpi {
    background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border);
    padding:.75rem 1rem; display:flex; align-items:center; gap:.6rem; min-width:140px;
}
.op-kpi-icon { font-size:1.2rem; }
.op-kpi-value { font-size:1.3rem; font-weight:800; color:var(--petrol-900); }
.op-kpi-label { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

/* Board Kanban */
.op-board {
    display:grid;
    grid-template-columns:repeat(<?= count($columns) ?>, 1fr);
    gap:.6rem;
    min-height:500px;
    overflow-x:auto;
}
.op-column { display:flex; flex-direction:column; min-width:0; }
.op-col-header {
    padding:.6rem .75rem; border-radius:var(--radius) var(--radius) 0 0;
    color:#fff; font-weight:700; font-size:.75rem;
    display:flex; justify-content:space-between; align-items:center;
}
.op-col-header .count { background:rgba(255,255,255,.25); padding:.1rem .45rem; border-radius:100px; font-size:.65rem; }
.op-col-body {
    flex:1; background:var(--bg); border:1px solid var(--border); border-top:none;
    border-radius:0 0 var(--radius) var(--radius);
    padding:.4rem; display:flex; flex-direction:column; gap:.4rem;
    min-height:100px; overflow-y:auto; max-height:70vh;
}

/* Case Card */
.op-card {
    background:var(--bg-card); border-radius:var(--radius);
    padding:.7rem .8rem; box-shadow:var(--shadow-sm);
    border-left:4px solid #ccc;
    cursor:grab; transition:all var(--transition);
}
.op-card:hover { box-shadow:var(--shadow-md); transform:translateY(-1px); }
.op-card.dragging { opacity:.4; cursor:grabbing; }
.op-col-body.drag-over { background:rgba(99,102,241,.08); border-color:rgba(99,102,241,.4); }
.op-card-name { font-weight:700; font-size:.82rem; color:var(--petrol-900); margin-bottom:.2rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.op-card-client { font-size:.7rem; color:var(--text-muted); margin-bottom:.3rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.op-card-badges { display:flex; gap:.2rem; flex-wrap:wrap; margin-bottom:.35rem; }
.op-card-badge {
    font-size:.58rem; font-weight:700; padding:.12rem .35rem;
    border-radius:4px; color:#fff; text-transform:uppercase; letter-spacing:.3px;
}
.op-card-footer { display:flex; justify-content:space-between; align-items:center; }
.op-card-resp { font-size:.65rem; color:var(--rose-dark); font-weight:600; }
.op-card-tasks { font-size:.65rem; color:var(--text-muted); display:flex; align-items:center; gap:.3rem; }
.op-card-tasks .mini-bar { width:40px; height:4px; background:var(--border); border-radius:3px; overflow:hidden; display:inline-block; }
.op-card-tasks .mini-fill { height:100%; background:var(--success); border-radius:3px; display:block; }
.op-card-deadline { font-size:.6rem; margin-top:.3rem; }
.op-card-deadline.overdue { color:#ef4444; font-weight:700; }
.op-card-deadline.soon { color:#f59e0b; font-weight:600; }

/* Move select */
.op-card-move {
    margin-top:.4rem; width:100%;
    font-size:.65rem; padding:.2rem .3rem;
    border:1px solid var(--border); border-radius:4px;
    background:var(--bg-card); cursor:pointer;
}

.op-empty { text-align:center; padding:1.5rem .5rem; color:var(--text-muted); font-size:.75rem; }

@media (max-width: 1024px) {
    .op-board { grid-template-columns:repeat(3, 1fr); }
}
@media (max-width: 768px) {
    .op-board { grid-template-columns:repeat(2, 1fr); }
}
@media (max-width: 500px) {
    .op-board { grid-template-columns:1fr; }
}

.page-content { max-width:none !important; padding:.75rem !important; }
</style>

<!-- KPIs -->
<div class="op-kpis">
    <div class="op-kpi">
        <span class="op-kpi-icon">📂</span>
        <div><div class="op-kpi-value"><?= $totalAtivos ?></div><div class="op-kpi-label"><?= $isColaborador ? 'Seus casos' : 'Casos ativos' ?></div></div>
    </div>
    <?php if ($urgentes > 0): ?>
    <div class="op-kpi">
        <span class="op-kpi-icon">🔴</span>
        <div><div class="op-kpi-value"><?= $urgentes ?></div><div class="op-kpi-label">Urgentes</div></div>
    </div>
    <?php endif; ?>
    <?php if ($comPrazo > 0): ?>
    <div class="op-kpi">
        <span class="op-kpi-icon">⏰</span>
        <div><div class="op-kpi-value"><?= $comPrazo ?></div><div class="op-kpi-label">Prazo em 7d</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Filtros -->
<div class="op-topbar">
    <h3>Board Operacional</h3>
    <form method="GET" class="op-filters">
        <select name="priority" class="op-filter-select" onchange="this.form.submit()">
            <option value="">Prioridade</option>
            <option value="urgente" <?= $filterPriority === 'urgente' ? 'selected' : '' ?>>Urgente</option>
            <option value="alta" <?= $filterPriority === 'alta' ? 'selected' : '' ?>>Alta</option>
            <option value="normal" <?= $filterPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="baixa" <?= $filterPriority === 'baixa' ? 'selected' : '' ?>>Baixa</option>
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
            <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm" style="font-size:.7rem;">Limpar</a>
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
                    $isSoon = $cs['deadline'] && !$isOverdue && $cs['deadline'] <= date('Y-m-d', strtotime('+3 days'));
                    $pColor = isset($priorityColors[$cs['priority']]) ? $priorityColors[$cs['priority']] : '#9ca3af';
                ?>
                <div class="op-card" draggable="true" data-case-id="<?= $cs['id'] ?>" style="border-left-color:<?= $pColor ?>;"
                     onclick="if(!event.target.closest('select,form'))window.location='<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>'">
                    <div class="op-card-name"><?= e($cs['title'] ? $cs['title'] : 'Caso #' . $cs['id']) ?></div>
                    <div class="op-card-client">👤 <?= e($cs['client_name'] ? $cs['client_name'] : 'Sem cliente') ?></div>
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
                    <?php if ($cs['deadline']): ?>
                    <div class="op-card-deadline <?= $isOverdue ? 'overdue' : ($isSoon ? 'soon' : '') ?>">
                        <?= $isOverdue ? '⚠️ Vencido ' : ($isSoon ? '⏰ ' : '📅 ') ?><?= date('d/m', strtotime($cs['deadline'])) ?>
                    </div>
                    <?php endif; ?>

                    <!-- Mover rápido -->
                    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onclick="event.stopPropagation();">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="case_id" value="<?= $cs['id'] ?>">
                        <select name="new_status" class="op-card-move" onchange="this.form.submit()">
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

<script>
// Drag and Drop Kanban
(function() {
    var dragCard = null;
    var csrfToken = '<?= csrf_token() ?>';

    // Drag start
    document.querySelectorAll('.op-card[draggable]').forEach(function(card) {
        card.addEventListener('dragstart', function(e) {
            dragCard = this;
            this.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', this.dataset.caseId);
        });
        card.addEventListener('dragend', function() {
            this.classList.remove('dragging');
            document.querySelectorAll('.op-col-body').forEach(function(col) {
                col.classList.remove('drag-over');
            });
            dragCard = null;
        });
    });

    // Drop zones
    document.querySelectorAll('.op-col-body').forEach(function(col) {
        col.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.classList.add('drag-over');
        });
        col.addEventListener('dragleave', function(e) {
            if (!this.contains(e.relatedTarget)) {
                this.classList.remove('drag-over');
            }
        });
        col.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');

            var caseId = e.dataTransfer.getData('text/plain');
            var newStatus = this.dataset.status;

            if (!dragCard || !caseId || !newStatus) return;

            // Mover card visualmente
            var empty = this.querySelector('.op-empty');
            if (empty) empty.remove();
            this.appendChild(dragCard);

            // Atualizar contadores
            updateCounts();

            // Enviar para o servidor
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
                        if (resp.error) {
                            showToast(resp.error, 'error');
                            location.reload();
                        } else {
                            showToast('Caso movido!');
                        }
                    } catch(ex) {
                        location.reload();
                    }
                } else {
                    showToast('Erro ao mover', 'error');
                    location.reload();
                }
            };
            xhr.onerror = function() {
                showToast('Erro de conexão', 'error');
                location.reload();
            };
            xhr.send(formData);
        });
    });

    function updateCounts() {
        document.querySelectorAll('.op-column').forEach(function(col) {
            var body = col.querySelector('.op-col-body');
            var count = body.querySelectorAll('.op-card').length;
            var badge = col.querySelector('.count');
            if (badge) badge.textContent = count;
        });
    }

    // Touch support para mobile
    var touchCard = null;
    var touchClone = null;
    var touchStartX, touchStartY;

    document.querySelectorAll('.op-card[draggable]').forEach(function(card) {
        card.addEventListener('touchstart', function(e) {
            if (e.target.closest('select,form')) return;
            touchCard = this;
            var touch = e.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
        }, {passive: true});
    });

    document.addEventListener('touchmove', function(e) {
        if (!touchCard) return;
        var touch = e.touches[0];
        var dx = Math.abs(touch.clientX - touchStartX);
        var dy = Math.abs(touch.clientY - touchStartY);

        if (dx > 10 || dy > 10) {
            e.preventDefault();
            touchCard.classList.add('dragging');

            // Highlight column under finger
            document.querySelectorAll('.op-col-body').forEach(function(col) {
                var rect = col.getBoundingClientRect();
                if (touch.clientX >= rect.left && touch.clientX <= rect.right &&
                    touch.clientY >= rect.top && touch.clientY <= rect.bottom) {
                    col.classList.add('drag-over');
                } else {
                    col.classList.remove('drag-over');
                }
            });
        }
    }, {passive: false});

    document.addEventListener('touchend', function(e) {
        if (!touchCard) return;
        touchCard.classList.remove('dragging');

        var dropCol = document.querySelector('.op-col-body.drag-over');
        if (dropCol) {
            dropCol.classList.remove('drag-over');
            var caseId = touchCard.dataset.caseId;
            var newStatus = dropCol.dataset.status;

            var empty = dropCol.querySelector('.op-empty');
            if (empty) empty.remove();
            dropCol.appendChild(touchCard);
            updateCounts();

            var formData = new FormData();
            formData.append('action', 'update_status');
            formData.append('case_id', caseId);
            formData.append('new_status', newStatus);
            formData.append('csrf_token', csrfToken);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() { if (xhr.status !== 200) location.reload(); };
            xhr.send(formData);
        }

        document.querySelectorAll('.op-col-body').forEach(function(col) {
            col.classList.remove('drag-over');
        });
        touchCard = null;
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
