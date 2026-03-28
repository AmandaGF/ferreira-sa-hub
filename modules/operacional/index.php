<?php
/**
 * Ferreira & Sá Hub — Painel Operacional (Gestão de Casos)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Operacional';
$pdo = db();
$userId = current_user_id();
$isColaborador = has_role('colaborador');

// Filtros
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$filterUser     = $_GET['user'] ?? '';
$sortBy         = $_GET['sort'] ?? 'priority';

// Construir query
$where = ["cs.status NOT IN ('concluido','arquivado')"];
$params = [];

if ($isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = $userId;
}

if ($filterStatus) {
    $where[] = "cs.status = ?";
    $params[] = $filterStatus;
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

$orderBy = 'cs.created_at DESC';
if ($sortBy === 'priority') {
    $orderBy = "FIELD(cs.priority, 'urgente','alta','normal','baixa'), cs.deadline ASC, cs.created_at DESC";
} elseif ($sortBy === 'deadline') {
    $orderBy = "cs.deadline IS NULL, cs.deadline ASC, cs.created_at DESC";
} elseif ($sortBy === 'status') {
    $orderBy = "FIELD(cs.status, 'aguardando_docs','em_elaboracao','aguardando_prazo','distribuido','em_andamento'), cs.created_at DESC";
}

$sql = "SELECT cs.*, c.name as client_name, c.phone as client_phone, u.name as responsible_name,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'pendente') as pending_tasks,
        (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'feito') as done_tasks
        FROM cases cs
        LEFT JOIN clients c ON c.id = cs.client_id
        LEFT JOIN users u ON u.id = cs.responsible_user_id
        WHERE $whereStr ORDER BY $orderBy";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cases = $stmt->fetchAll();

// KPIs
$totalCasos = count($cases);
$urgentes = 0;
$comPrazo = 0;
foreach ($cases as $cs) {
    if ($cs['priority'] === 'urgente') $urgentes++;
    if ($cs['deadline'] && $cs['deadline'] <= date('Y-m-d', strtotime('+7 days'))) $comPrazo++;
}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$statusLabels = [
    'aguardando_docs' => 'Aguardando docs',
    'em_elaboracao' => 'Em elaboração',
    'aguardando_prazo' => 'Aguardando prazo',
    'distribuido' => 'Distribuído',
    'em_andamento' => 'Em andamento',
    'concluido' => 'Concluído',
    'arquivado' => 'Arquivado',
    'suspenso' => 'Suspenso',
];

$statusBadge = [
    'aguardando_docs' => 'warning', 'em_elaboracao' => 'info', 'aguardando_prazo' => 'warning',
    'distribuido' => 'success', 'em_andamento' => 'info', 'concluido' => 'success',
    'arquivado' => 'gestao', 'suspenso' => 'danger',
];

$priorityBadge = ['urgente' => 'danger', 'alta' => 'warning', 'normal' => 'gestao', 'baixa' => 'colaborador'];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.op-filters { display:flex; gap:.5rem; flex-wrap:wrap; margin-bottom:1.5rem; align-items:flex-end; }
.op-filters .form-group { margin:0; }
.op-filters select { font-size:.8rem; padding:.4rem .6rem; }
.case-row { display:flex; align-items:center; gap:1rem; padding:1rem 1.25rem; border-bottom:1px solid var(--border); transition:background var(--transition); }
.case-row:hover { background:rgba(215,171,144,.04); }
.case-row:last-child { border-bottom:none; }
.case-priority-bar { width:4px; height:40px; border-radius:4px; flex-shrink:0; }
.case-main { flex:1; min-width:0; }
.case-title { font-weight:700; font-size:.9rem; color:var(--petrol-900); }
.case-title a { color:var(--petrol-900); }
.case-title a:hover { color:var(--rose); }
.case-client { font-size:.78rem; color:var(--text-muted); }
.case-badges { display:flex; gap:.35rem; flex-wrap:wrap; margin-top:.25rem; }
.case-meta { display:flex; gap:1.5rem; align-items:center; flex-shrink:0; }
.case-meta-item { text-align:center; }
.case-meta-item label { font-size:.6rem; text-transform:uppercase; color:var(--text-muted); font-weight:700; letter-spacing:.5px; display:block; }
.case-meta-item span { font-size:.82rem; }
.tasks-bar { display:flex; align-items:center; gap:.35rem; font-size:.75rem; color:var(--text-muted); }
.tasks-bar .bar { width:60px; height:6px; background:var(--border); border-radius:4px; overflow:hidden; }
.tasks-bar .bar-fill { height:100%; background:var(--success); border-radius:4px; }
.deadline-warning { color:var(--danger); font-weight:700; }
</style>

<!-- KPIs -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon petrol">📂</div>
        <div class="stat-info"><div class="stat-value"><?= $totalCasos ?></div><div class="stat-label"><?= $isColaborador ? 'Seus casos' : 'Casos ativos' ?></div></div>
    </div>
    <?php if ($urgentes > 0): ?>
    <div class="stat-card">
        <div class="stat-icon danger">🔴</div>
        <div class="stat-info"><div class="stat-value"><?= $urgentes ?></div><div class="stat-label">Urgentes</div></div>
    </div>
    <?php endif; ?>
    <?php if ($comPrazo > 0): ?>
    <div class="stat-card">
        <div class="stat-icon warning">⏰</div>
        <div class="stat-info"><div class="stat-value"><?= $comPrazo ?></div><div class="stat-label">Prazo em 7 dias</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Filtros -->
<div class="op-filters">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
        <div class="form-group">
            <label class="form-label" style="font-size:.7rem;">Status</label>
            <select name="status" class="form-select" style="font-size:.8rem;padding:.4rem;">
                <option value="">Todos</option>
                <?php foreach ($statusLabels as $k => $v): ?>
                    <?php if (!in_array($k, ['concluido', 'arquivado'])): ?>
                        <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" style="font-size:.7rem;">Prioridade</label>
            <select name="priority" class="form-select" style="font-size:.8rem;padding:.4rem;">
                <option value="">Todas</option>
                <option value="urgente" <?= $filterPriority === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                <option value="alta" <?= $filterPriority === 'alta' ? 'selected' : '' ?>>Alta</option>
                <option value="normal" <?= $filterPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="baixa" <?= $filterPriority === 'baixa' ? 'selected' : '' ?>>Baixa</option>
            </select>
        </div>
        <?php if (!$isColaborador): ?>
        <div class="form-group">
            <label class="form-label" style="font-size:.7rem;">Responsável</label>
            <select name="user" class="form-select" style="font-size:.8rem;padding:.4rem;">
                <option value="">Todos</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label class="form-label" style="font-size:.7rem;">Ordenar</label>
            <select name="sort" class="form-select" style="font-size:.8rem;padding:.4rem;">
                <option value="priority" <?= $sortBy === 'priority' ? 'selected' : '' ?>>Prioridade</option>
                <option value="deadline" <?= $sortBy === 'deadline' ? 'selected' : '' ?>>Prazo</option>
                <option value="status" <?= $sortBy === 'status' ? 'selected' : '' ?>>Status</option>
            </select>
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
        <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm">Limpar</a>
    </form>
</div>

<!-- Lista de casos -->
<div class="card">
    <?php if (empty($cases)): ?>
        <div class="card-body empty-state">
            <div class="icon">📂</div>
            <h3>Nenhum caso encontrado</h3>
            <p><?= $isColaborador ? 'Você não tem casos atribuídos.' : 'Nenhum caso ativo com os filtros selecionados.' ?></p>
        </div>
    <?php else: ?>
        <?php
        $priorityColors = ['urgente' => 'var(--danger)', 'alta' => 'var(--warning)', 'normal' => 'var(--info)', 'baixa' => '#9ca3af'];
        foreach ($cases as $cs):
            $totalTasks = $cs['pending_tasks'] + $cs['done_tasks'];
            $taskPercent = $totalTasks > 0 ? round(($cs['done_tasks'] / $totalTasks) * 100) : 0;
            $isOverdue = $cs['deadline'] && $cs['deadline'] < date('Y-m-d');
            $isSoon = $cs['deadline'] && !$isOverdue && $cs['deadline'] <= date('Y-m-d', strtotime('+3 days'));
        ?>
        <div class="case-row">
            <div class="case-priority-bar" style="background:<?= $priorityColors[$cs['priority']] ?? '#9ca3af' ?>;"></div>
            <div class="case-main">
                <div class="case-title">
                    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>"><?= e($cs['title']) ?></a>
                </div>
                <div class="case-client">
                    👤 <?= e($cs['client_name'] ?? 'Sem cliente') ?>
                    <?php if ($cs['client_phone']): ?>
                        · <a href="https://wa.me/55<?= preg_replace('/\D/', '', $cs['client_phone']) ?>" target="_blank" style="color:var(--success);">📱</a>
                    <?php endif; ?>
                    <?php if ($cs['drive_folder_url']): ?>
                        · <a href="<?= e($cs['drive_folder_url']) ?>" target="_blank" style="color:var(--info);">📁 Drive</a>
                    <?php endif; ?>
                </div>
                <div class="case-badges">
                    <span class="badge badge-<?= $statusBadge[$cs['status']] ?? 'gestao' ?>"><?= $statusLabels[$cs['status']] ?? $cs['status'] ?></span>
                    <span class="badge badge-<?= $priorityBadge[$cs['priority']] ?? 'gestao' ?>"><?= e($cs['priority']) ?></span>
                    <?php if ($cs['case_type'] !== 'outro'): ?>
                        <span class="badge badge-gestao"><?= e($cs['case_type']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="case-meta">
                <?php if ($totalTasks > 0): ?>
                <div class="case-meta-item">
                    <label>Tarefas</label>
                    <div class="tasks-bar">
                        <div class="bar"><div class="bar-fill" style="width:<?= $taskPercent ?>%;"></div></div>
                        <span><?= $cs['done_tasks'] ?>/<?= $totalTasks ?></span>
                    </div>
                </div>
                <?php endif; ?>
                <div class="case-meta-item">
                    <label>Responsável</label>
                    <span class="text-sm"><?= e($cs['responsible_name'] ?: '—') ?></span>
                </div>
                <div class="case-meta-item">
                    <label>Prazo</label>
                    <span class="text-sm <?= $isOverdue ? 'deadline-warning' : ($isSoon ? 'text-warning' : '') ?>">
                        <?= $cs['deadline'] ? data_br($cs['deadline']) : '—' ?>
                        <?= $isOverdue ? ' ⚠️' : '' ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
