<?php
/**
 * Ferreira & Sá Hub — Helpdesk (Chamados)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Helpdesk';
$pdo = db();
$userId = current_user_id();
$isColaborador = has_role('colaborador');

// Filtros
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$search         = trim($_GET['q'] ?? '');

$where = [];
$params = [];

// Admin e Gestão veem tudo. Outros veem: seus chamados + chamados do seu setor
if (!has_role('admin') && !has_role('gestao')) {
    $userSetor = current_user()['setor'] ?? '';
    if ($userSetor) {
        $where[] = "(t.requester_id = ? OR ta.user_id = ? OR t.department = ?)";
        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userSetor;
    } else {
        $where[] = "(t.requester_id = ? OR ta.user_id = ?)";
        $params[] = $userId;
        $params[] = $userId;
    }
}
if ($filterStatus) { $where[] = "t.status = ?"; $params[] = $filterStatus; }
if ($filterPriority) { $where[] = "t.priority = ?"; $params[] = $filterPriority; }
if ($search) {
    $where[] = "(t.title LIKE ? OR t.client_name LIKE ? OR t.case_number LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT t.*, u.name as requester_name,
     GROUP_CONCAT(u2.name SEPARATOR ', ') as assignees,
     (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = t.id) as msg_count
     FROM tickets t
     LEFT JOIN users u ON u.id = t.requester_id
     LEFT JOIN ticket_assignees ta ON ta.ticket_id = t.id
     LEFT JOIN users u2 ON u2.id = ta.user_id
     $whereStr
     GROUP BY t.id
     ORDER BY FIELD(t.priority, 'urgente','normal','baixa'), t.created_at DESC
     LIMIT 100"
);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// KPIs
$kpi = $pdo->query("SELECT
    SUM(CASE WHEN status IN ('aberto','em_andamento','aguardando') THEN 1 ELSE 0 END) as abertos,
    SUM(CASE WHEN priority = 'urgente' AND status IN ('aberto','em_andamento') THEN 1 ELSE 0 END) as urgentes,
    SUM(CASE WHEN status = 'resolvido' AND MONTH(resolved_at) = MONTH(NOW()) THEN 1 ELSE 0 END) as resolvidos_mes
    FROM tickets")->fetch();

$statusLabels = ['aberto' => 'Aberto', 'em_andamento' => 'Em andamento', 'aguardando' => 'Aguardando', 'resolvido' => 'Resolvido', 'cancelado' => 'Cancelado'];
$statusBadge = ['aberto' => 'warning', 'em_andamento' => 'info', 'aguardando' => 'gestao', 'resolvido' => 'success', 'cancelado' => 'danger'];
$priorityBadge = ['urgente' => 'danger', 'normal' => 'gestao', 'baixa' => 'colaborador'];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<!-- KPIs -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon warning">🎫</div>
        <div class="stat-info"><div class="stat-value"><?= $kpi['abertos'] ?? 0 ?></div><div class="stat-label">Abertos</div></div>
    </div>
    <?php if (($kpi['urgentes'] ?? 0) > 0): ?>
    <div class="stat-card">
        <div class="stat-icon danger">🔴</div>
        <div class="stat-info"><div class="stat-value"><?= $kpi['urgentes'] ?></div><div class="stat-label">Urgentes</div></div>
    </div>
    <?php endif; ?>
    <div class="stat-card">
        <div class="stat-icon success">✅</div>
        <div class="stat-info"><div class="stat-value"><?= $kpi['resolvidos_mes'] ?? 0 ?></div><div class="stat-label">Resolvidos este mês</div></div>
    </div>
</div>

<!-- Ações + Filtros -->
<div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="text" name="q" class="form-input" style="font-size:.8rem;padding:.4rem .6rem;width:180px;" value="<?= e($search) ?>" placeholder="Buscar...">
        <select name="status" class="form-select" style="font-size:.8rem;padding:.4rem;">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="priority" class="form-select" style="font-size:.8rem;padding:.4rem;">
            <option value="">Prioridade</option>
            <option value="urgente" <?= $filterPriority === 'urgente' ? 'selected' : '' ?>>Urgente</option>
            <option value="normal" <?= $filterPriority === 'normal' ? 'selected' : '' ?>>Normal</option>
            <option value="baixa" <?= $filterPriority === 'baixa' ? 'selected' : '' ?>>Baixa</option>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
    </form>
    <a href="<?= module_url('helpdesk', 'novo.php') ?>" class="btn btn-primary btn-sm">+ Novo Chamado</a>
</div>

<!-- Lista -->
<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr><th>#</th><th>Título</th><th>Categoria</th><th>Prioridade</th><th>Status</th><th>Solicitante</th><th>Responsáveis</th><th>Msgs</th><th>Data</th></tr></thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:2rem;">Nenhum chamado encontrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($tickets as $t): ?>
                    <tr>
                        <td class="text-sm text-muted"><?= $t['id'] ?></td>
                        <td><a href="<?= module_url('helpdesk', 'ver.php?id=' . $t['id']) ?>" class="font-bold" style="color:var(--petrol-900);"><?= e($t['title']) ?></a></td>
                        <td class="text-sm"><?= e($t['category'] ?: '—') ?></td>
                        <td><span class="badge badge-<?= $priorityBadge[$t['priority']] ?? 'gestao' ?>"><?= e($t['priority']) ?></span></td>
                        <td><span class="badge badge-<?= $statusBadge[$t['status']] ?? 'gestao' ?>"><?= $statusLabels[$t['status']] ?? $t['status'] ?></span></td>
                        <td class="text-sm"><?= e($t['requester_name'] ?? '—') ?></td>
                        <td class="text-sm"><?= e($t['assignees'] ?: '—') ?></td>
                        <td class="text-sm text-center"><?= $t['msg_count'] ?></td>
                        <td class="text-sm text-muted"><?= data_br($t['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
