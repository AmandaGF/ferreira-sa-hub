<?php
/**
 * Ferreira & Sá Hub — Operacional (Kanban)
 * Fluxo: Contrato Assinado → Pasta Apta → Em Execução →
 *        Doc Faltante → Aguardando Distribuição → Processo Distribuído
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_view_operacional()) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Operacional';
$pdo = db();
$userId = current_user_id();
$isColaborador = has_role('colaborador');
$canMove = can_move_operacional();

// Filtros
$filterPriority = isset($_GET['priority']) ? $_GET['priority'] : '';
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$filterSearch = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterMonth = isset($_GET['mes']) ? $_GET['mes'] : '';

// Colunas do board (conforme doc técnico v2)
$columns = array(
    'aguardando_docs'        => array('label' => 'Contrato — Aguardando Docs', 'color' => '#f59e0b', 'icon' => '📄'),
    'em_elaboracao'          => array('label' => 'Pasta Apta',                  'color' => '#059669', 'icon' => '✔️'),
    'em_andamento'           => array('label' => 'Em Execução',                 'color' => '#0ea5e9', 'icon' => '⚙️'),
    'doc_faltante'           => array('label' => 'Doc Faltante',                'color' => '#dc2626', 'icon' => '⚠️'),
    'aguardando_prazo'       => array('label' => 'Aguard. Distribuição',        'color' => '#8b5cf6', 'icon' => '⏳'),
    'distribuido'            => array('label' => 'Processo Distribuído',         'color' => '#15803d', 'icon' => '🏛️'),
    'parceria_previdenciario'=> array('label' => 'Parceria',                    'color' => '#06b6d4', 'icon' => '🤝'),
    'cancelado'              => array('label' => 'Cancelado',                   'color' => '#6b7280', 'icon' => '❌'),
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
if ($filterSearch) {
    $where[] = "(cs.title LIKE ? OR c.name LIKE ? OR cs.case_type LIKE ? OR cs.case_number LIKE ?)";
    $s = "%$filterSearch%";
    $params[] = $s; $params[] = $s; $params[] = $s; $params[] = $s;
}
if ($filterMonth) {
    $where[] = "DATE_FORMAT(cs.created_at, '%Y-%m') = ?";
    $params[] = $filterMonth;
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
// Regra: "distribuido" só aparece no Kanban no mês da distribuição.
// Meses anteriores ficam ocultos do Kanban (mas continuam no banco como "distribuido").
$byStatus = array();
foreach (array_keys($columns) as $s) { $byStatus[$s] = array(); }
$mesAtual = date('Y-m');
foreach ($allCases as $cs) {
    $status = $cs['status'];
    // Distribuídos de meses anteriores: ocultar do Kanban (não exibir)
    if ($status === 'distribuido') {
        $mesDistrib = $cs['distribution_date'] ? date('Y-m', strtotime($cs['distribution_date'])) : date('Y-m', strtotime($cs['updated_at']));
        if ($mesDistrib < $mesAtual) {
            continue; // Pula — não aparece no Kanban nem na tabela
        }
    }
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
$parceirosAtivos = array();
try { $parceirosAtivos = $pdo->query("SELECT id, nome, area FROM parceiros WHERE ativo = 1 ORDER BY nome")->fetchAll(); } catch (Exception $e) {}
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

<!-- Banner: Documentos Pendentes (colapsável) -->
<?php if (!empty($docsPendentes)):
    // Agrupar por cliente
    $docsByClient = array();
    foreach ($docsPendentes as $dp) {
        $key = $dp['client_name'] ?: 'Caso #' . $dp['case_id'];
        if (!isset($docsByClient[$key])) $docsByClient[$key] = array();
        $docsByClient[$key][] = $dp;
    }
?>
<div style="background:#fef2f2;border:2px solid #fecaca;border-radius:12px;padding:.75rem 1rem;margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;" onclick="var el=document.getElementById('docsExpand');el.style.display=el.style.display==='none'?'block':'none';this.querySelector('.chevron').textContent=el.style.display==='none'?'▸':'▾';">
        <div style="display:flex;align-items:center;gap:.5rem;">
            <span style="font-size:1rem;">⚠️</span>
            <strong style="font-size:.82rem;color:#dc2626;"><?= count($docsPendentes) ?> doc(s) faltante(s) em <?= count($docsByClient) ?> cliente(s)</strong>
        </div>
        <span class="chevron" style="font-size:.8rem;color:#dc2626;">▸</span>
    </div>
    <div id="docsExpand" style="display:none;margin-top:.5rem;">
        <?php foreach ($docsByClient as $clientName => $docs): ?>
        <div style="padding:.4rem 0;border-top:1px solid #fecaca;">
            <div style="font-size:.78rem;font-weight:700;color:#052228;">👤 <?= e($clientName) ?></div>
            <?php foreach ($docs as $dp): ?>
            <div style="display:flex;align-items:center;gap:.5rem;padding:.15rem 0 .15rem 1.2rem;">
                <span style="font-size:.72rem;color:#dc2626;font-weight:600;">→ <?= e($dp['descricao']) ?></span>
                <span style="font-size:.6rem;color:#6b7280;margin-left:auto;"><?= date('d/m H:i', strtotime($dp['solicitado_em'])) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="op-kpis">
    <div class="op-kpi"><span style="font-size:1rem;">📂</span><div><div class="op-kpi-value"><?= $totalAtivos ?></div><div class="op-kpi-label"><?= $isColaborador ? 'Seus casos' : 'Casos ativos' ?></div></div></div>
    <?php if ($urgentes > 0): ?><div class="op-kpi"><span style="font-size:1rem;">🔴</span><div><div class="op-kpi-value"><?= $urgentes ?></div><div class="op-kpi-label">Urgentes</div></div></div><?php endif; ?>
    <?php if ($docsFaltantes > 0): ?><div class="op-kpi"><span style="font-size:1rem;">⚠️</span><div><div class="op-kpi-value"><?= $docsFaltantes ?></div><div class="op-kpi-label">Doc faltante</div></div></div><?php endif; ?>
</div>

<!-- Filtros + Toggle -->
<div class="op-topbar">
    <div style="display:flex;align-items:center;gap:1rem;">
        <h3 style="margin:0;">Kanban Operacional</h3>
        <div style="display:flex;border:2px solid var(--petrol-900);border-radius:10px;overflow:hidden;">
            <button onclick="toggleOpView('kanban')" id="btnOpKanban" style="padding:7px 18px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:var(--petrol-900);color:#fff;transition:all .2s;">📋 Kanban</button>
            <button onclick="toggleOpView('tabela')" id="btnOpTabela" style="padding:7px 18px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#fff;color:var(--petrol-900);transition:all .2s;">📊 Tabela</button>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <a href="<?= module_url('operacional', 'caso_novo.php') ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;">+ Novo Processo</a>
        <a href="<?= module_url('planilha', 'importar.php?destino=operacional') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Importar CSV</a>
        <form method="GET" class="op-filters">
            <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="Buscar nome, tipo, nº..." class="op-filter-select" style="min-width:160px;" onkeydown="if(event.key==='Enter')this.form.submit()">
            <input type="month" name="mes" value="<?= e($filterMonth) ?>" class="op-filter-select" style="min-width:120px;" onchange="this.form.submit()">
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
            <?php if ($filterPriority || $filterUser || $filterSearch || $filterMonth): ?>
                <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm" style="font-size:.65rem;">Limpar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Board Kanban -->
<div class="op-board" id="viewOpKanban">
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
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                        <div class="op-card-name" style="flex:1;"><?= e($cs['title'] ?: 'Caso #' . $cs['id']) ?></div>
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>" onclick="event.stopPropagation();" target="_blank" title="Abrir pasta do processo" style="font-size:.85rem;text-decoration:none;flex-shrink:0;margin-left:4px;">📂</a>
                    </div>
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
                        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>" onclick="event.stopPropagation();" target="_blank" class="op-card-process" style="text-decoration:none;display:block;" title="Abrir pasta do processo">🏛️ <?= e($cs['case_number']) ?></a>
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

<!-- Visão Tabela Operacional -->
<div id="viewOpTabela" style="display:none;">
<style>
.tbl-toolbar { display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-bottom:.75rem;padding:.5rem .75rem;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg); }
.tbl-filter { font-size:.8rem;padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;color:var(--text);min-width:140px; }
.tbl-filter:focus { border-color:var(--rose);outline:none; }
.tbl-count { margin-left:auto;font-size:.78rem;color:var(--text-muted);font-weight:600; }
.tbl-csv { padding:6px 16px;background:var(--success);color:#fff;border:none;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer; }
.tbl-csv:hover { opacity:.9; }
.tbl-wrap { border-radius:var(--radius-lg);overflow:hidden;border:1px solid var(--border);box-shadow:var(--shadow-sm); }
.tbl-grid { width:100%;border-collapse:collapse;font-size:.82rem; }
.tbl-grid thead { position:sticky;top:0;z-index:2; }
.tbl-grid th { background:linear-gradient(180deg,var(--petrol-900),var(--petrol-700));color:#fff;padding:10px 12px;text-align:left;font-size:.75rem;font-weight:700;letter-spacing:.3px;text-transform:uppercase;cursor:pointer;user-select:none;white-space:nowrap;border-right:1px solid rgba(255,255,255,.15); }
.tbl-grid th:hover { background:var(--petrol-500); }
.tbl-grid th:last-child { border-right:none; }
.tbl-grid td { padding:8px 12px;border-bottom:1px solid #eee;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:250px; }
.tbl-grid tbody tr { cursor:pointer;transition:background .15s; }
.tbl-grid tbody tr:nth-child(even) { background:#fafbfc; }
.tbl-grid tbody tr:hover { background:rgba(215,171,144,.15); }
.tbl-grid .cell-name { font-weight:700;color:var(--petrol-900); }
.tbl-grid .cell-resp { color:var(--rose-dark);font-weight:600; }
.tbl-badge { display:inline-block;padding:3px 10px;border-radius:12px;font-size:.7rem;font-weight:700;color:#fff; }
.tbl-badge-sm { display:inline-block;padding:2px 8px;border-radius:4px;font-size:.68rem;font-weight:700;color:#fff; }
.tbl-grid .cell-proc { font-size:.78rem;color:var(--petrol-500);font-weight:600; }
.tbl-grid .cell-move select { font-size:.78rem;padding:4px 8px;border:1.5px solid var(--border);border-radius:8px;background:#fff;cursor:pointer;color:var(--text); }
.tbl-grid .cell-move select:hover { border-color:var(--rose); }
.tbl-pag { display:flex;justify-content:center;gap:4px;margin-top:1rem; }
.tbl-pag a { padding:6px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.78rem;text-decoration:none;font-weight:600;color:var(--text);transition:all .15s; }
.tbl-pag a:hover { background:var(--petrol-100);border-color:var(--petrol-500); }
.tbl-pag a.active { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
/* Linhas coloridas por status */
.tbl-grid tbody tr[data-status="aguardando_docs"] { border-left:4px solid #f59e0b; }
.tbl-grid tbody tr[data-status="em_elaboracao"] { border-left:4px solid #059669; background:rgba(5,150,105,.04) !important; }
.tbl-grid tbody tr[data-status="em_andamento"] { border-left:4px solid #0ea5e9; background:rgba(14,165,233,.04) !important; }
.tbl-grid tbody tr[data-status="doc_faltante"] { border-left:4px solid #dc2626; background:rgba(220,38,38,.06) !important; }
.tbl-grid tbody tr[data-status="aguardando_prazo"] { border-left:4px solid #8b5cf6; }
.tbl-grid tbody tr[data-status="distribuido"] { border-left:4px solid #15803d; background:rgba(21,128,61,.04) !important; }
.tbl-grid tbody tr[data-status="parceria_previdenciario"] { border-left:4px solid #06b6d4; }
.tbl-grid tbody tr[data-status="cancelado"] { border-left:4px solid #6b7280; opacity:.7; }
</style>
<?php
$allCasesFlat = array();
foreach ($byStatus as $statusKey => $statusCases) {
    foreach ($statusCases as $cs) { $cs['_status_key'] = $statusKey; $allCasesFlat[] = $cs; }
}
$opPage = max(1, (int)($_GET['op'] ?? 1));
$opPerPage = 25;
$opTotalPages = max(1, ceil(count($allCasesFlat) / $opPerPage));
if ($opPage > $opTotalPages) $opPage = $opTotalPages;
$opOffset = ($opPage - 1) * $opPerPage;
$pageCases = array_slice($allCasesFlat, $opOffset, $opPerPage);
$opTipos = array();
foreach ($allCasesFlat as $cs) { if ($cs['case_type'] && $cs['case_type'] !== 'outro' && !in_array($cs['case_type'], $opTipos)) $opTipos[] = $cs['case_type']; }
sort($opTipos);
?>
<div class="tbl-toolbar">
    <select id="filterOpStatus" onchange="filterOpTable()" class="tbl-filter">
        <option value="">Todos os status</option>
        <?php foreach ($columns as $ck => $cv): ?><option value="<?= $ck ?>"><?= $cv['icon'] ?> <?= $cv['label'] ?></option><?php endforeach; ?>
    </select>
    <select id="filterOpResp" onchange="filterOpTable()" class="tbl-filter">
        <option value="">Todos responsáveis</option>
        <?php foreach ($users as $u): ?><option value="<?= e($u['name']) ?>"><?= e(explode(' ', $u['name'])[0]) ?></option><?php endforeach; ?>
    </select>
    <select id="filterOpType" onchange="filterOpTable()" class="tbl-filter">
        <option value="">Todos os tipos</option>
        <?php foreach ($opTipos as $t): ?><option value="<?= e($t) ?>"><?= e($t) ?></option><?php endforeach; ?>
    </select>
    <span class="tbl-count"><?= count($allCasesFlat) ?> casos</span>
    <button onclick="exportTableCSV('opTableBody','operacional')" class="tbl-csv">Exportar CSV</button>
</div>
<div class="tbl-wrap" style="max-height:72vh;overflow-y:auto;">
<table class="tbl-grid" id="opTableBody">
<thead><tr>
    <th onclick="sortTbl('opTableBody',0)" style="width:40px;text-align:center;">#</th>
    <th onclick="sortTbl('opTableBody',1)">Caso</th>
    <th onclick="sortTbl('opTableBody',2)">Tipo de Ação</th>
    <th onclick="sortTbl('opTableBody',3)">Responsável</th>
    <th onclick="sortTbl('opTableBody',4)">Status</th>
    <th onclick="sortTbl('opTableBody',5)">Prioridade</th>
    <th onclick="sortTbl('opTableBody',6)">Nº Processo</th>
    <th onclick="sortTbl('opTableBody',7)">Cadastro</th>
    <th style="cursor:default;">Mover</th>
</tr></thead>
<tbody>
<?php $n = $opOffset + 1; foreach ($pageCases as $cs):
    $sk = $cs['_status_key']; $ci = $columns[$sk];
    $pColor = isset($priorityColors[$cs['priority']]) ? $priorityColors[$cs['priority']] : '#9ca3af';
    $pLabel = isset($priorityLabels[$cs['priority']]) ? $priorityLabels[$cs['priority']] : $cs['priority'];
?>
<tr data-status="<?= $sk ?>" data-resp="<?= e($cs['responsible_name'] ?? '') ?>" data-type="<?= e($cs['case_type'] ?? '') ?>" data-case-type="<?= e($cs['case_type'] ?? '') ?>" onclick="if(!event.target.closest('select,form'))window.location='<?= module_url('operacional', 'caso_ver.php?id=' . $cs['id']) ?>'">
    <td style="text-align:center;color:#999;font-size:.75rem;"><?= $n++ ?></td>
    <td class="cell-name"><?= e($cs['title'] ?: 'Caso #' . $cs['id']) ?></td>
    <td><?= e($cs['case_type'] !== 'outro' ? ($cs['case_type'] ?? '') : '') ?></td>
    <td class="cell-resp"><?= e($cs['responsible_name'] ? explode(' ', $cs['responsible_name'])[0] : '—') ?></td>
    <td><span class="tbl-badge" style="background:<?= $ci['color'] ?>;"><?= $ci['icon'] ?> <?= $ci['label'] ?></span></td>
    <td><span class="tbl-badge-sm" style="background:<?= $pColor ?>;"><?= $pLabel ?></span></td>
    <td class="cell-proc"><?= e($cs['case_number'] ?? '') ?></td>
    <td><?= date('d/m/Y', strtotime($cs['created_at'])) ?></td>
    <td class="cell-move" onclick="event.stopPropagation();">
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="case_id" value="<?= $cs['id'] ?>">
            <select name="new_status" onchange="handleOpMove(this)">
                <option value="">Mover →</option>
                <?php foreach ($columns as $csk => $csv): if ($csk !== $sk): ?>
                    <option value="<?= $csk ?>"><?= $csv['icon'] ?> <?= $csv['label'] ?></option>
                <?php endif; endforeach; ?>
            </select>
        </form>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($pageCases)): ?>
<tr><td colspan="9" style="text-align:center;color:#999;padding:2rem;">Nenhum caso encontrado.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($opTotalPages > 1): ?>
<div class="tbl-pag">
    <?php for ($p = 1; $p <= $opTotalPages; $p++): ?>
        <a href="?op=<?= $p ?><?= $filterPriority ? '&priority=' . e($filterPriority) : '' ?><?= $filterUser ? '&user=' . e($filterUser) : '' ?>" class="<?= $p === $opPage ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
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

<!-- Modal: Processo Distribuído / Extrajudicial -->
<div id="processoModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:540px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 id="procModalTitle" style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.5rem;">🏛️ Dados do Processo Distribuído</h3>

        <!-- Toggle: Judicial / Extrajudicial -->
        <div style="display:flex;border:2px solid var(--petrol-900);border-radius:10px;overflow:hidden;margin-bottom:1rem;">
            <button type="button" id="btnJudicial" onclick="toggleProcType('judicial')" style="flex:1;padding:8px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:var(--petrol-900);color:#fff;transition:all .2s;">🏛️ Judicial</button>
            <button type="button" id="btnExtrajudicial" onclick="toggleProcType('extrajudicial')" style="flex:1;padding:8px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#fff;color:var(--petrol-900);transition:all .2s;">📋 Extrajudicial</button>
        </div>

        <!-- Campos Judiciais -->
        <div id="camposJudicial" style="display:grid;gap:.75rem;">
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

        <!-- Campos Extrajudiciais -->
        <div id="camposExtrajudicial" style="display:none;grid-template-columns:1fr;gap:.75rem;">
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Tipo de serviço extrajudicial *</label>
                <select id="extraTipo" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                    <option value="">Selecione...</option>
                    <option value="Inventário">Inventário</option>
                    <option value="Divórcio Extrajudicial">Divórcio Extrajudicial</option>
                    <option value="Escritura">Escritura</option>
                    <option value="Usucapião">Usucapião</option>
                    <option value="Consultoria">Consultoria</option>
                    <option value="Contratos">Contratos</option>
                    <option value="Mediação">Mediação</option>
                    <option value="Notificação Extrajudicial">Notificação Extrajudicial</option>
                    <option value="Acordo Extrajudicial">Acordo Extrajudicial</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Descrição do que foi feito *</label>
                <textarea id="extraDescricao" rows="3" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;resize:vertical;" placeholder="Descreva o serviço realizado, cartório, partes envolvidas..."></textarea>
            </div>
            <div>
                <label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Data de conclusão</label>
                <input type="date" id="extraData" style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;" value="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <input type="hidden" id="procCategory" value="judicial">

        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="closeProcModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmProcesso()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Salvar →</button>
        </div>
    </div>
</div>

<!-- Modal: Selecionar Parceiro -->
<div id="parceiroModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:480px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:.5rem;">🤝 Selecionar Parceiro</h3>
        <p style="font-size:.78rem;color:#6b7280;margin-bottom:1rem;">Selecione o advogado parceiro para este caso.</p>
        <select id="parceiroSelect" style="width:100%;padding:.55rem .75rem;font-size:.88rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
            <option value="">Selecione o parceiro...</option>
            <?php foreach ($parceirosAtivos as $parc): ?>
                <option value="<?= $parc['id'] ?>"><?= e($parc['nome']) ?><?= $parc['area'] ? ' — ' . e($parc['area']) : '' ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (empty($parceirosAtivos)): ?>
        <p style="font-size:.75rem;color:#dc2626;margin-top:.5rem;">Nenhum parceiro cadastrado. <a href="<?= module_url('parceiros') ?>">Cadastrar parceiro</a></p>
        <?php endif; ?>
        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="closeParceiroModal()" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;">Cancelar</button>
            <button onclick="confirmParceiro()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-family:inherit;font-size:.82rem;font-weight:700;">Confirmar →</button>
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

    if (status === 'parceria_previdenciario') {
        _pendingOpForm = form;
        document.getElementById('parceiroModal').style.display = 'flex';
        select.value = '';
        return;
    }

    if (status === 'distribuido') {
        _pendingOpForm = form;
        var card = select.closest('.op-card') || select.closest('tr');
        if (card && card.dataset.caseType) {
            document.getElementById('procTipo').value = card.dataset.caseType;
        }
        // Reset para judicial por padrão
        toggleProcType('judicial');
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

// Parceiro
function closeParceiroModal() {
    document.getElementById('parceiroModal').style.display = 'none';
    _pendingOpForm = null;
}

function confirmParceiro() {
    var parceiroId = document.getElementById('parceiroSelect').value;
    if (!parceiroId) { document.getElementById('parceiroSelect').style.borderColor = '#ef4444'; return; }
    document.getElementById('parceiroModal').style.display = 'none';

    if (_pendingOpForm) {
        var sel = _pendingOpForm.querySelector('select[name="new_status"]');
        if (sel) sel.removeAttribute('name');
        var statusInput = document.createElement('input');
        statusInput.type = 'hidden'; statusInput.name = 'new_status'; statusInput.value = 'parceria_previdenciario';
        _pendingOpForm.appendChild(statusInput);
        var parcInput = document.createElement('input');
        parcInput.type = 'hidden'; parcInput.name = 'parceiro_id'; parcInput.value = parceiroId;
        _pendingOpForm.appendChild(parcInput);
        _pendingOpForm.submit();
    }
}

// Toggle Judicial / Extrajudicial
function toggleProcType(type) {
    var judicial = document.getElementById('camposJudicial');
    var extra = document.getElementById('camposExtrajudicial');
    var btnJ = document.getElementById('btnJudicial');
    var btnE = document.getElementById('btnExtrajudicial');
    var title = document.getElementById('procModalTitle');
    document.getElementById('procCategory').value = type;
    if (type === 'extrajudicial') {
        judicial.style.display = 'none';
        extra.style.display = 'grid';
        btnJ.style.background = '#fff'; btnJ.style.color = 'var(--petrol-900)';
        btnE.style.background = 'var(--petrol-900)'; btnE.style.color = '#fff';
        title.innerHTML = '📋 Serviço Extrajudicial';
    } else {
        judicial.style.display = 'grid';
        extra.style.display = 'none';
        btnE.style.background = '#fff'; btnE.style.color = 'var(--petrol-900)';
        btnJ.style.background = 'var(--petrol-900)'; btnJ.style.color = '#fff';
        title.innerHTML = '🏛️ Dados do Processo Distribuído';
    }
}

function confirmProcesso() {
    var category = document.getElementById('procCategory').value;

    if (category === 'extrajudicial') {
        var tipo = document.getElementById('extraTipo').value;
        var desc = document.getElementById('extraDescricao').value.trim();
        if (!tipo) { document.getElementById('extraTipo').style.borderColor = '#ef4444'; return; }
        if (!desc) { document.getElementById('extraDescricao').style.borderColor = '#ef4444'; return; }
        document.getElementById('processoModal').style.display = 'none';

        if (_pendingOpForm) {
            var sel = _pendingOpForm.querySelector('select[name="new_status"]');
            if (sel) sel.removeAttribute('name');
            var fields = {
                'proc_tipo': tipo,
                'proc_data': document.getElementById('extraData').value,
                'proc_numero': '',
                'proc_vara': desc,
                'proc_category': 'extrajudicial'
            };
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
    } else {
        var numero = document.getElementById('procNumero').value.trim();
        var vara = document.getElementById('procVara').value.trim();
        if (!numero || !vara) {
            if (!numero) document.getElementById('procNumero').style.borderColor = '#ef4444';
            if (!vara) document.getElementById('procVara').style.borderColor = '#ef4444';
            return;
        }
        document.getElementById('processoModal').style.display = 'none';

        if (_pendingOpForm) {
            var sel = _pendingOpForm.querySelector('select[name="new_status"]');
            if (sel) sel.removeAttribute('name');
            var fields = {
                'proc_numero': numero,
                'proc_vara': vara,
                'proc_tipo': document.getElementById('procTipo').value,
                'proc_data': document.getElementById('procData').value,
                'proc_category': 'judicial'
            };
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

            if (newStatus === 'parceria_previdenciario') {
                var form = dragCard.querySelector('form');
                _pendingOpForm = form;
                document.getElementById('parceiroModal').style.display = 'flex';
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

<script>
// Toggle Kanban / Tabela
function toggleOpView(view) {
    var k = document.getElementById('viewOpKanban');
    var t = document.getElementById('viewOpTabela');
    var bk = document.getElementById('btnOpKanban');
    var bt = document.getElementById('btnOpTabela');
    if (view === 'tabela') {
        k.style.display = 'none'; t.style.display = 'block';
        bk.style.background = 'var(--bg-card)'; bk.style.color = 'var(--text)';
        bt.style.background = 'var(--petrol-900)'; bt.style.color = '#fff';
    } else {
        k.style.display = 'grid'; t.style.display = 'none';
        bt.style.background = 'var(--bg-card)'; bt.style.color = 'var(--text)';
        bk.style.background = 'var(--petrol-900)'; bk.style.color = '#fff';
    }
    try { localStorage.setItem('operacional_view', view); } catch(e) {}
}
try { var saved = localStorage.getItem('operacional_view'); if (saved === 'tabela') toggleOpView('tabela'); } catch(e) {}

// Filtros tabela operacional
function filterOpTable() {
    var status = document.getElementById('filterOpStatus').value;
    var resp = document.getElementById('filterOpResp').value;
    var tipo = document.getElementById('filterOpType').value;
    var rows = document.querySelectorAll('#opTableBody tbody tr');
    rows.forEach(function(row) {
        if (!row.dataset.status) return;
        var show = true;
        if (status && row.dataset.status !== status) show = false;
        if (resp && row.dataset.resp !== resp) show = false;
        if (tipo && row.dataset.type !== tipo) show = false;
        row.style.display = show ? '' : 'none';
    });
}

// Ordenar e exportar (compartilhados)
var _sortDirs = {};
function sortTbl(tableId, colIdx) {
    var table = document.getElementById(tableId);
    var tbody = table.querySelector('tbody');
    var selector = tableId === 'opTableBody' ? 'tr[data-status]' : 'tr[data-stage]';
    var rows = Array.from(tbody.querySelectorAll(selector));
    var dir = _sortDirs[tableId + '_' + colIdx] === 'asc' ? 'desc' : 'asc';
    _sortDirs[tableId + '_' + colIdx] = dir;
    rows.sort(function(a, b) {
        var av = (a.cells[colIdx] && a.cells[colIdx].textContent.trim()) || '';
        var bv = (b.cells[colIdx] && b.cells[colIdx].textContent.trim()) || '';
        var an = parseFloat(av.replace(/[^\d.-]/g, ''));
        var bn = parseFloat(bv.replace(/[^\d.-]/g, ''));
        if (!isNaN(an) && !isNaN(bn)) return dir === 'asc' ? an - bn : bn - an;
        return dir === 'asc' ? av.localeCompare(bv, 'pt-BR') : bv.localeCompare(av, 'pt-BR');
    });
    rows.forEach(function(r) { tbody.appendChild(r); });
}
function exportTableCSV(tableId, name) {
    var table = document.getElementById(tableId);
    var csv = [];
    table.querySelectorAll('tr').forEach(function(row) {
        if (row.style.display === 'none') return;
        var cols = [];
        var lastIdx = row.cells.length - 1;
        row.querySelectorAll('th, td').forEach(function(cell, i) {
            if (i === lastIdx) return;
            cols.push('"' + cell.textContent.replace(/"/g, '""').trim() + '"');
        });
        csv.push(cols.join(';'));
    });
    var blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = name + '_' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
}
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
