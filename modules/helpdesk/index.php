<?php
/**
 * Ferreira & Sá Hub — Helpdesk (Chamados)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Helpdesk';
$pdo = db();
$userId = current_user_id();
// Filtros
$filterStatus   = $_GET['status'] ?? '';
$filterPriority = $_GET['priority'] ?? '';
$search         = trim($_GET['q'] ?? '');

$where = [];
$params = [];

// Equipe vê tudo. Colaborador/estagiário vê só os seus
if (has_role('colaborador') || has_role('estagiario')) {
    $where[] = "(t.requester_id = ? OR ta.user_id = ?)";
    $params[] = $userId;
    $params[] = $userId;
}
$showArquivados = isset($_GET['arquivados']) && $_GET['arquivados'] === '1';
// Aba: 'equipe' (default), 'clientes' (origem='salavip')
$filterOrigem = $_GET['origem'] ?? 'equipe';
if ($filterOrigem === 'clientes') {
    $where[] = "t.origem = 'salavip'";
} else {
    $where[] = "(t.origem IS NULL OR t.origem != 'salavip')";
}
if ($filterStatus) {
    $where[] = "t.status = ?"; $params[] = $filterStatus;
} elseif (!$showArquivados) {
    // Por padrão, ocultar resolvidos e cancelados
    $where[] = "t.status NOT IN ('resolvido','cancelado')";
}
if ($filterPriority) { $where[] = "t.priority = ?"; $params[] = $filterPriority; }
$filterCategory = $_GET['category'] ?? '';
$filterAssignee = (int)($_GET['assignee'] ?? 0);
if ($filterCategory) { $where[] = "t.category = ?"; $params[] = $filterCategory; }
if ($filterAssignee) { $where[] = "ta.user_id = ?"; $params[] = $filterAssignee; }
if ($search) {
    $where[] = "(t.title LIKE ? OR t.client_name LIKE ? OR t.case_number LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, array($like, $like, $like));
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Ordenação
$sortParam = $_GET['sort'] ?? '';
$sortDir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$validSorts = array(
    'id' => 't.id',
    'titulo' => 't.title',
    'prioridade' => "FIELD(t.priority,'urgente','normal','baixa')",
    'status' => "FIELD(t.status,'aberto','em_andamento','aguardando','resolvido','cancelado')",
    'data' => 't.created_at',
    'atualizado' => 't.updated_at',
);
if ($sortParam && isset($validSorts[$sortParam])) {
    $orderBy = $validSorts[$sortParam] . ' ' . $sortDir;
} else {
    $orderBy = "FIELD(t.status, 'aberto','em_andamento','aguardando','resolvido','cancelado'), t.created_at DESC";
}

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
     ORDER BY $orderBy
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

$statusLabels = array('aberto' => 'Aberto', 'em_andamento' => 'Em andamento', 'aguardando' => 'Aguardando', 'resolvido' => 'Resolvido', 'cancelado' => 'Cancelado');
$statusBadge = array('aberto' => 'warning', 'em_andamento' => 'info', 'aguardando' => 'gestao', 'resolvido' => 'success', 'cancelado' => 'danger');
$statusIcons = array('aberto' => '🟡', 'em_andamento' => '🔵', 'aguardando' => '🟠', 'resolvido' => '✅', 'cancelado' => '❌');
$priorityBadge = array('urgente' => 'danger', 'normal' => 'gestao', 'baixa' => 'colaborador');

// Contadores por status (respeitando aba origem)
$origemFilter = $filterOrigem === 'clientes'
    ? "origem = 'salavip'"
    : "(origem IS NULL OR origem != 'salavip')";
$statusCounts = array();
try {
    $scRows = $pdo->query("SELECT status, COUNT(*) as cnt FROM tickets WHERE $origemFilter GROUP BY status")->fetchAll();
    foreach ($scRows as $sc) $statusCounts[$sc['status']] = (int)$sc['cnt'];
} catch (Exception $e) {}
$totalTickets = array_sum($statusCounts);

// Contadores por origem (para badges nas abas)
$countEquipe = 0;
$countClientes = 0;
try {
    $countEquipe = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE (origem IS NULL OR origem != 'salavip') AND status NOT IN ('resolvido','cancelado')")->fetchColumn();
    $countClientes = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE origem = 'salavip' AND status NOT IN ('resolvido','cancelado')")->fetchColumn();
} catch (Exception $e) {}

// Categorias existentes
$categorias = array();
try { $categorias = $pdo->query("SELECT DISTINCT category FROM tickets WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN); } catch (Exception $e) {}

// Usuários para filtro de responsável
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.hd-topbar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1rem; }
.hd-filters { display:flex; flex-direction:column; gap:.6rem; margin-bottom:1.25rem; }
.hd-filter-row { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.hd-filter-label { font-size:.68rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px; min-width:70px; }
.hd-pill { display:inline-flex; align-items:center; gap:4px; padding:5px 12px; border-radius:100px; font-size:.75rem; font-weight:500; border:1.5px solid var(--border); background:var(--bg-card); color:var(--text-muted); cursor:pointer; text-decoration:none; transition:all .15s; white-space:nowrap; }
.hd-pill:hover { border-color:var(--petrol-300); color:var(--petrol-900); }
.hd-pill.active { background:var(--petrol-900); color:#fff; border-color:var(--petrol-900); }
.hd-pill .cnt { background:rgba(0,0,0,.1); padding:1px 6px; border-radius:100px; font-size:.65rem; font-weight:700; }
.hd-pill.active .cnt { background:rgba(255,255,255,.25); }
.hd-search { display:flex; gap:.4rem; align-items:center; }
.hd-search input { font-size:.82rem; padding:.45rem .75rem; border:1.5px solid var(--border); border-radius:var(--radius); width:220px; }
.hd-search input:focus { border-color:var(--rose); outline:none; }
</style>

<!-- Abas Equipe vs Clientes -->
<div style="display:flex;gap:0;border-bottom:2px solid var(--border);margin-bottom:1rem;">
    <a href="<?= module_url('helpdesk') ?>?origem=equipe" style="padding:.7rem 1.4rem;font-size:.85rem;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $filterOrigem === 'equipe' ? '#B87333' : 'transparent' ?>;color:<?= $filterOrigem === 'equipe' ? '#B87333' : 'var(--text-muted)' ?>;margin-bottom:-2px;display:flex;align-items:center;gap:.4rem;">
        👥 Chamados Internos
        <?php if ($countEquipe > 0): ?>
            <span style="background:<?= $filterOrigem === 'equipe' ? '#B87333' : 'var(--text-muted)' ?>;color:#fff;padding:1px 8px;border-radius:9999px;font-size:.65rem;font-weight:700;"><?= $countEquipe ?></span>
        <?php endif; ?>
    </a>
    <a href="<?= module_url('helpdesk') ?>?origem=clientes" style="padding:.7rem 1.4rem;font-size:.85rem;font-weight:700;text-decoration:none;border-bottom:3px solid <?= $filterOrigem === 'clientes' ? '#B87333' : 'transparent' ?>;color:<?= $filterOrigem === 'clientes' ? '#B87333' : 'var(--text-muted)' ?>;margin-bottom:-2px;display:flex;align-items:center;gap:.4rem;">
        🌟 Chamados de Clientes (Sala VIP)
        <?php if ($countClientes > 0): ?>
            <span style="background:<?= $filterOrigem === 'clientes' ? '#B87333' : '#dc2626' ?>;color:#fff;padding:1px 8px;border-radius:9999px;font-size:.65rem;font-weight:700;"><?= $countClientes ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- Top bar -->
<div class="hd-topbar">
    <div style="display:flex;align-items:center;gap:1rem;">
        <div style="font-size:.82rem;color:var(--text-muted);"><?= $totalTickets ?> chamado<?= $totalTickets !== 1 ? 's' : '' ?></div>
        <?php if ($filterStatus || $filterPriority || $filterCategory || $filterAssignee || $search): ?>
            <a href="<?= module_url('helpdesk') ?>?origem=<?= e($filterOrigem) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Limpar filtros</a>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:.5rem;">
        <?php if ($showArquivados): ?>
            <a href="<?= module_url('helpdesk') ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;">✕ Ocultar arquivados</a>
        <?php else: ?>
            <a href="<?= module_url('helpdesk') ?>?arquivados=1" class="btn btn-outline btn-sm" style="font-size:.75rem;">📦 Mostrar arquivados</a>
        <?php endif; ?>
        <a href="<?= module_url('helpdesk', 'novo.php') ?>" class="btn btn-primary btn-sm">+ Novo Chamado</a>
    </div>
</div>

<!-- Filtros -->
<div class="hd-filters">
    <!-- Status -->
    <div class="hd-filter-row">
        <span class="hd-filter-label">Status</span>
        <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'q'=>$search,'priority'=>$filterPriority,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= !$filterStatus ? 'active' : '' ?>">Todos <span class="cnt"><?= $totalTickets ?></span></a>
        <?php foreach ($statusLabels as $sk => $sv): ?>
            <?php $cnt = $statusCounts[$sk] ?? 0; if ($cnt === 0 && $sk !== $filterStatus) continue; ?>
            <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'status'=>$sk,'q'=>$search,'priority'=>$filterPriority,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= $filterStatus === $sk ? 'active' : '' ?>"><?= $statusIcons[$sk] ?? '' ?> <?= $sv ?> <span class="cnt"><?= $cnt ?></span></a>
        <?php endforeach; ?>
    </div>

    <!-- Prioridade -->
    <div class="hd-filter-row">
        <span class="hd-filter-label">Prioridade</span>
        <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'status'=>$filterStatus,'q'=>$search,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= !$filterPriority ? 'active' : '' ?>">Todas</a>
        <?php foreach (array('urgente'=>'🔴 Urgente','normal'=>'🟢 Normal','baixa'=>'⚪ Baixa') as $pk => $pv): ?>
            <a href="<?= module_url('helpdesk') ?>?<?= http_build_query(array_filter(array('origem'=>$filterOrigem,'status'=>$filterStatus,'priority'=>$pk,'q'=>$search,'category'=>$filterCategory,'assignee'=>$filterAssignee))) ?>" class="hd-pill <?= $filterPriority === $pk ? 'active' : '' ?>"><?= $pv ?></a>
        <?php endforeach; ?>
    </div>

    <!-- Categoria + Responsável + Busca -->
    <div class="hd-filter-row">
        <span class="hd-filter-label">Mais</span>
        <?php if (!empty($categorias)): ?>
        <select onchange="filtrarHelpdesk('category',this.value)" style="font-size:.78rem;padding:4px 8px;border:1.5px solid var(--border);border-radius:100px;background:var(--bg-card);cursor:pointer;">
            <option value="">Categoria</option>
            <?php foreach ($categorias as $cat): ?>
                <option value="<?= e($cat) ?>" <?= $filterCategory === $cat ? 'selected' : '' ?>><?= e($cat) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <select onchange="filtrarHelpdesk('assignee',this.value)" style="font-size:.78rem;padding:4px 8px;border:1.5px solid var(--border);border-radius:100px;background:var(--bg-card);cursor:pointer;">
            <option value="">Responsável</option>
            <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $filterAssignee === (int)$u['id'] ? 'selected' : '' ?>><?= e(explode(' ',$u['name'])[0]) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="hd-search">
            <form method="GET">
                <input type="hidden" name="origem" value="<?= e($filterOrigem) ?>">
                <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
                <?php if ($filterPriority): ?><input type="hidden" name="priority" value="<?= e($filterPriority) ?>"><?php endif; ?>
                <?php if ($filterCategory): ?><input type="hidden" name="category" value="<?= e($filterCategory) ?>"><?php endif; ?>
                <?php if ($filterAssignee): ?><input type="hidden" name="assignee" value="<?= $filterAssignee ?>"><?php endif; ?>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar título, cliente, processo...">
                <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;">🔍</button>
            </form>
        </div>
    </div>
</div>

<!-- KPIs rápidos -->
<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:.5rem 1rem;display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.1rem;">🎫</span>
        <div><div style="font-size:1.2rem;font-weight:800;color:var(--petrol-900);"><?= $kpi['abertos'] ?? 0 ?></div><div style="font-size:.65rem;color:var(--text-muted);">Abertos</div></div>
    </div>
    <?php if (($kpi['urgentes'] ?? 0) > 0): ?>
    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius);padding:.5rem 1rem;display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.1rem;">🔴</span>
        <div><div style="font-size:1.2rem;font-weight:800;color:#dc2626;"><?= $kpi['urgentes'] ?></div><div style="font-size:.65rem;color:#dc2626;">Urgentes</div></div>
    </div>
    <?php endif; ?>
    <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:.5rem 1rem;display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.1rem;">✅</span>
        <div><div style="font-size:1.2rem;font-weight:800;color:#059669;"><?= $kpi['resolvidos_mes'] ?? 0 ?></div><div style="font-size:.65rem;color:var(--text-muted);">Resolvidos mês</div></div>
    </div>
</div>

<script>
function filtrarHelpdesk(param, value) {
    var url = new URL(window.location.href);
    if (value) url.searchParams.set(param, value);
    else url.searchParams.delete(param);
    window.location.href = url.toString();
}
</script>

<!-- Lista -->
<div class="card">
    <div class="table-wrapper">
        <table>
            <thead><tr>
                <?php
                function sortLink($col, $label, $currentSort, $currentDir, $params) {
                    $newDir = ($currentSort === $col && $currentDir === 'ASC') ? 'desc' : 'asc';
                    $arrow = '';
                    if ($currentSort === $col) $arrow = $currentDir === 'ASC' ? ' ▲' : ' ▼';
                    $p = array_merge($params, array('sort' => $col, 'dir' => $newDir));
                    return '<a href="?' . http_build_query(array_filter($p)) . '" style="color:inherit;text-decoration:none;white-space:nowrap;">' . $label . $arrow . '</a>';
                }
                $sp = array('status'=>$filterStatus,'priority'=>$filterPriority,'q'=>$search,'category'=>$filterCategory,'assignee'=>$filterAssignee);
                ?>
                <th><?= sortLink('id','#',$sortParam,$sortDir,$sp) ?></th>
                <th><?= sortLink('titulo','Título',$sortParam,$sortDir,$sp) ?></th>
                <th>Categoria</th>
                <th><?= sortLink('prioridade','Prioridade',$sortParam,$sortDir,$sp) ?></th>
                <th><?= sortLink('status','Status',$sortParam,$sortDir,$sp) ?></th>
                <th>Solicitante</th>
                <th>Responsáveis</th>
                <th>Msgs</th>
                <th><?= sortLink('data','Abertura',$sortParam,$sortDir,$sp) ?></th>
                <th><?= sortLink('atualizado','Atualizado',$sortParam,$sortDir,$sp) ?></th>
            </tr></thead>
            <tbody>
                <?php if (empty($tickets)): ?>
                    <tr><td colspan="10" class="text-center text-muted" style="padding:2rem;">Nenhum chamado encontrado.</td></tr>
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
                        <td class="text-sm text-muted"><?= data_br($t['updated_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
