<?php
/**
 * Ferreira & Sá Hub — Processos (Listagem)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Processos';
$pdo = db();
$isColaborador = has_role('colaborador');

// Filtros
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterType = isset($_GET['type']) ? $_GET['type'] : '';
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Status e labels
$statusLabels = array(
    'ativo' => 'Ativo', 'aguardando_docs' => 'Aguardando Docs', 'em_elaboracao' => 'Em Elaboração',
    'em_andamento' => 'Em Andamento', 'aguardando_prazo' => 'Aguardando Prazo',
    'distribuido' => 'Distribuído', 'concluido' => 'Concluído', 'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso',
);
$statusBadge = array(
    'ativo' => 'info', 'aguardando_docs' => 'warning', 'em_elaboracao' => 'info',
    'em_andamento' => 'info', 'aguardando_prazo' => 'warning', 'distribuido' => 'success',
    'concluido' => 'success', 'arquivado' => 'gestao', 'suspenso' => 'danger',
);
$priorityBadge = array('urgente' => 'danger', 'alta' => 'warning', 'normal' => 'gestao', 'baixa' => 'colaborador');

// Query
$where = array('1=1');
$params = array();

if ($isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = current_user_id();
}
if ($filterStatus) {
    $where[] = "cs.status = ?";
    $params[] = $filterStatus;
}
if ($filterType) {
    $where[] = "cs.case_type = ?";
    $params[] = $filterType;
}
if ($filterUser && !$isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = (int)$filterUser;
}
if ($search) {
    $where[] = "(cs.title LIKE ? OR cs.case_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);

$stmt = $pdo->prepare(
    "SELECT cs.*, c.name as client_name, c.phone as client_phone, c.cpf as client_cpf,
     u.name as responsible_name,
     (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id) as total_tasks,
     (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'feito') as done_tasks
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE $whereStr
     ORDER BY cs.created_at DESC
     LIMIT 200"
);
$stmt->execute($params);
$processos = $stmt->fetchAll();

// KPIs
$totalProcessos = (int)$pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn();
$ativos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('concluido','arquivado')")->fetchColumn();
$concluidos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'concluido'")->fetchColumn();

// Tipos de ação distintos (para filtro)
$tipos = $pdo->query("SELECT DISTINCT case_type FROM cases WHERE case_type IS NOT NULL AND case_type != '' ORDER BY case_type")->fetchAll(PDO::FETCH_COLUMN);

// Usuários
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.proc-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.proc-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:140px; }
.proc-stat-icon { font-size:1.3rem; }
.proc-stat-val { font-size:1.5rem; font-weight:800; color:var(--petrol-900); }
.proc-stat-lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

.proc-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; }
.proc-filters { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.proc-filter-sel { font-size:.75rem; padding:.35rem .5rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); }
.proc-search { display:flex; gap:.35rem; }
.proc-search input { font-size:.8rem; padding:.4rem .75rem; border:1.5px solid var(--border); border-radius:var(--radius); width:220px; }
.proc-search input:focus { border-color:var(--rose); outline:none; }

.proc-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.proc-table th { background:var(--petrol-900); color:#fff; padding:.6rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; position:sticky; top:0; }
.proc-table td { padding:.65rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.proc-table tr:hover { background:rgba(215,171,144,.04); }
.proc-table .client-link { color:var(--petrol-900); font-weight:600; text-decoration:none; }
.proc-table .client-link:hover { color:var(--rose); }
.proc-table .case-link { color:var(--petrol-500); font-weight:700; text-decoration:none; }
.proc-table .case-link:hover { color:var(--rose); }
.proc-number { font-family:monospace; font-size:.75rem; color:var(--text-muted); }
.proc-tasks { font-size:.72rem; color:var(--text-muted); }

.proc-actions { display:flex; gap:.5rem; }
</style>

<!-- KPIs -->
<div class="proc-stats">
    <div class="proc-stat">
        <span class="proc-stat-icon">📁</span>
        <div><div class="proc-stat-val"><?= $totalProcessos ?></div><div class="proc-stat-lbl">Total</div></div>
    </div>
    <div class="proc-stat">
        <span class="proc-stat-icon">⚙️</span>
        <div><div class="proc-stat-val"><?= $ativos ?></div><div class="proc-stat-lbl">Ativos</div></div>
    </div>
    <div class="proc-stat">
        <span class="proc-stat-icon">✅</span>
        <div><div class="proc-stat-val"><?= $concluidos ?></div><div class="proc-stat-lbl">Concluídos</div></div>
    </div>
</div>

<!-- Toolbar -->
<div class="proc-toolbar">
    <form method="GET" class="proc-filters">
        <select name="status" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Tipo de Ação</option>
            <?php foreach ($tipos as $t): ?>
                <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (!$isColaborador): ?>
        <select name="user" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Responsável</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterUser == $u['id'] ? 'selected' : '' ?>><?= e(explode(' ', $u['name'])[0]) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <div class="proc-search">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar processo, nº ou cliente...">
            <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        </div>
        <?php if ($filterStatus || $filterType || $filterUser || $search): ?>
            <a href="<?= module_url('processos') ?>" class="btn btn-outline btn-sm">Limpar</a>
        <?php endif; ?>
    </form>
    <div class="proc-actions">
        <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('crm', 'importar_processos.php') ?>" class="btn btn-outline btn-sm">📥 Importar CSV</a>
        <?php endif; ?>
    </div>
</div>

<!-- Tabela -->
<div class="card" style="overflow-x:auto;">
    <?php if (empty($processos)): ?>
        <div class="card-body" style="text-align:center;padding:3rem;">
            <div style="font-size:2rem;margin-bottom:.5rem;">📁</div>
            <h3 style="color:var(--petrol-900);">Nenhum processo encontrado</h3>
            <p style="color:var(--text-muted);font-size:.85rem;">
                <?= $search ? 'Tente outros termos de busca.' : 'Importe processos do LegalOne ou crie pelo Operacional.' ?>
            </p>
        </div>
    <?php else: ?>
        <table class="proc-table">
            <thead>
                <tr>
                    <th>Processo</th>
                    <th>Cliente</th>
                    <th>Nº Processo</th>
                    <th>Tipo</th>
                    <th>Status</th>
                    <th>Prioridade</th>
                    <th>Responsável</th>
                    <th>Tarefas</th>
                    <th>Prazo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($processos as $p):
                    $totalT = (int)$p['total_tasks'];
                    $doneT = (int)$p['done_tasks'];
                    $isOverdue = $p['deadline'] && $p['deadline'] < date('Y-m-d');
                ?>
                <tr>
                    <td>
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $p['id']) ?>" class="case-link">
                            <?= e($p['title'] ? $p['title'] : 'Caso #' . $p['id']) ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($p['client_id']): ?>
                            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $p['client_id']) ?>" class="client-link">
                                <?= e($p['client_name']) ?>
                            </a>
                            <?php if ($p['client_cpf']): ?>
                                <div style="font-size:.68rem;color:var(--text-muted);"><?= e($p['client_cpf']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="proc-number"><?= e($p['case_number'] ? $p['case_number'] : '—') ?></span></td>
                    <td><?= e($p['case_type'] && $p['case_type'] !== 'outro' ? $p['case_type'] : '—') ?></td>
                    <td><span class="badge badge-<?= isset($statusBadge[$p['status']]) ? $statusBadge[$p['status']] : 'gestao' ?>"><?= isset($statusLabels[$p['status']]) ? $statusLabels[$p['status']] : $p['status'] ?></span></td>
                    <td><span class="badge badge-<?= isset($priorityBadge[$p['priority']]) ? $priorityBadge[$p['priority']] : 'gestao' ?>"><?= e($p['priority']) ?></span></td>
                    <td style="font-size:.78rem;"><?= e($p['responsible_name'] ? explode(' ', $p['responsible_name'])[0] : '—') ?></td>
                    <td>
                        <?php if ($totalT > 0): ?>
                            <span class="proc-tasks"><?= $doneT ?>/<?= $totalT ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:.72rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.78rem;<?= $isOverdue ? 'color:#ef4444;font-weight:700;' : '' ?>">
                        <?= $p['deadline'] ? date('d/m/Y', strtotime($p['deadline'])) : '—' ?>
                        <?= $isOverdue ? ' ⚠️' : '' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
