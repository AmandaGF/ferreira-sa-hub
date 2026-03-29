<?php
/**
 * Ferreira & Sá Hub — Pré-Processual
 * Fase de preparação antes de ajuizar: coleta de docs, elaboração de peças,
 * análise de viabilidade. Quando ajuizado, vira Processo Judicial.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Pré-Processual';
$pdo = db();
$isColaborador = has_role('colaborador');

// Filtros
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterType = isset($_GET['type']) ? $_GET['type'] : '';
$filterUser = isset($_GET['user']) ? $_GET['user'] : '';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

$statusLabels = array(
    'ativo' => 'Ativo', 'aguardando_docs' => 'Aguardando Docs', 'em_elaboracao' => 'Em Elaboração',
    'em_andamento' => 'Em Andamento', 'aguardando_prazo' => 'Aguardando Prazo',
    'distribuido' => 'Pronto p/ Ajuizar', 'concluido' => 'Ajuizado', 'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso',
);
$statusBadge = array(
    'ativo' => 'info', 'aguardando_docs' => 'warning', 'em_elaboracao' => 'info',
    'em_andamento' => 'info', 'aguardando_prazo' => 'warning', 'distribuido' => 'success',
    'concluido' => 'success', 'arquivado' => 'gestao', 'suspenso' => 'danger',
);
$priorityBadge = array('urgente' => 'danger', 'alta' => 'warning', 'normal' => 'gestao', 'baixa' => 'colaborador');

// Query
$where = array("cs.category = 'pre_processual'");
$params = array();

if ($isColaborador) { $where[] = "cs.responsible_user_id = ?"; $params[] = current_user_id(); }
if ($filterStatus) { $where[] = "cs.status = ?"; $params[] = $filterStatus; }
if ($filterType) { $where[] = "cs.case_type = ?"; $params[] = $filterType; }
if ($filterUser && !$isColaborador) { $where[] = "cs.responsible_user_id = ?"; $params[] = (int)$filterUser; }
if ($search) {
    $where[] = "(cs.title LIKE ? OR cs.internal_number LIKE ? OR c.name LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare(
    "SELECT cs.*, c.name as client_name, c.phone as client_phone, u.name as responsible_name,
     (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id) as total_tasks,
     (SELECT COUNT(*) FROM case_tasks WHERE case_id = cs.id AND status = 'feito') as done_tasks
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE $whereStr ORDER BY FIELD(cs.priority, 'urgente','alta','normal','baixa'), cs.created_at DESC LIMIT 200"
);
$stmt->execute($params);
$casos = $stmt->fetchAll();

// KPIs
$totalPre = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE category = 'pre_processual'")->fetchColumn();
$ativosPre = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE category = 'pre_processual' AND status NOT IN ('concluido','arquivado')")->fetchColumn();

$tipos = $pdo->query("SELECT DISTINCT case_type FROM cases WHERE category = 'pre_processual' AND case_type IS NOT NULL AND case_type != '' ORDER BY case_type")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pre-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.pre-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:140px; }
.pre-stat-val { font-size:1.4rem; font-weight:800; color:var(--petrol-900); }
.pre-stat-lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

.pre-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; }
.pre-filters { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.pre-filter-sel { font-size:.75rem; padding:.35rem .5rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); }

.new-form { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.25rem; margin-bottom:1.25rem; }
.new-form h4 { font-size:.92rem; font-weight:700; color:var(--petrol-900); margin-bottom:1rem; }

.pre-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.pre-table th { background:var(--petrol-900); color:#fff; padding:.55rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; }
.pre-table td { padding:.55rem .75rem; border-bottom:1px solid var(--border); }
.pre-table tr:hover { background:rgba(215,171,144,.04); }
.pre-number { font-family:monospace; font-size:.78rem; color:var(--rose-dark); font-weight:700; }
.case-link { color:var(--petrol-900); font-weight:700; text-decoration:none; }
.case-link:hover { color:var(--rose); }
.client-link { color:var(--petrol-500); font-weight:600; text-decoration:none; }
.client-link:hover { color:var(--rose); }
.tasks-mini { font-size:.72rem; color:var(--text-muted); }
</style>

<!-- KPIs -->
<div class="pre-stats">
    <div class="pre-stat">
        <span style="font-size:1.2rem;">📂</span>
        <div><div class="pre-stat-val"><?= $totalPre ?></div><div class="pre-stat-lbl">Total</div></div>
    </div>
    <div class="pre-stat">
        <span style="font-size:1.2rem;">⚙️</span>
        <div><div class="pre-stat-val"><?= $ativosPre ?></div><div class="pre-stat-lbl">Em preparação</div></div>
    </div>
</div>

<!-- Novo caso pré-processual -->
<?php if (has_min_role('gestao')): ?>
<div class="new-form">
    <h4>+ Novo Caso Pré-Processual</h4>
    <form method="POST" action="<?= module_url('servicos', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <input type="hidden" name="category" value="pre_processual">
        <div class="form-row" style="margin-bottom:.75rem;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Cliente</label>
                <select name="client_id" class="form-select" required>
                    <option value="">— Selecionar —</option>
                    <?php
                    $allClients = $pdo->query("SELECT id, name, cpf FROM clients ORDER BY name")->fetchAll();
                    foreach ($allClients as $cl): ?>
                        <option value="<?= $cl['id'] ?>"><?= e($cl['name']) ?><?= $cl['cpf'] ? ' — ' . e($cl['cpf']) : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Tipo de Ação</label>
                <select name="case_type" class="form-select" required>
                    <option value="">— Selecionar —</option>
                    <option value="Alimentos">Alimentos</option>
                    <option value="Divórcio">Divórcio</option>
                    <option value="Guarda/Convivência">Guarda/Convivência</option>
                    <option value="Inventário">Inventário</option>
                    <option value="Família">Família</option>
                    <option value="Consumidor">Consumidor</option>
                    <option value="Indenização">Indenização</option>
                    <option value="Trabalhista">Trabalhista</option>
                    <option value="Fraude Bancária">Fraude Bancária</option>
                    <option value="Imobiliário">Imobiliário</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>
        </div>
        <div class="form-row" style="margin-bottom:.75rem;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Título / Descrição</label>
                <input type="text" name="title" class="form-input" placeholder="Ex: Ação de alimentos — Maria Silva" required>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Responsável</label>
                <select name="responsible_user_id" class="form-select">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Prioridade</label>
                <select name="priority" class="form-select">
                    <option value="normal">Normal</option>
                    <option value="alta">Alta</option>
                    <option value="urgente">Urgente</option>
                    <option value="baixa">Baixa</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Criar</button>
    </form>
</div>
<?php endif; ?>

<!-- Filtros -->
<div class="pre-toolbar">
    <form method="GET" class="pre-filters">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar..." style="font-size:.8rem;padding:.4rem .75rem;border:1.5px solid var(--border);border-radius:var(--radius);width:220px;">
        <select name="status" class="pre-filter-sel" onchange="this.form.submit()">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        <?php if ($filterStatus || $filterType || $search): ?>
            <a href="<?= module_url('pre_processual') ?>" class="btn btn-outline btn-sm">Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabela -->
<div class="card" style="overflow-x:auto;">
    <?php if (empty($casos)): ?>
        <div class="card-body" style="text-align:center;padding:3rem;">
            <div style="font-size:2rem;margin-bottom:.5rem;">📂</div>
            <h3>Nenhum caso pré-processual</h3>
            <p style="color:var(--text-muted);font-size:.85rem;">Crie um novo caso acima.</p>
        </div>
    <?php else: ?>
        <table class="pre-table">
            <thead><tr>
                <th>Nº Interno</th>
                <th>Cliente</th>
                <th>Caso</th>
                <th>Tipo</th>
                <th>Status</th>
                <th>Prioridade</th>
                <th>Responsável</th>
                <th>Tarefas</th>
                <th>Criado</th>
            </tr></thead>
            <tbody>
                <?php foreach ($casos as $c):
                    $numInterno = $c['internal_number'] ? $c['internal_number'] : 'PRE-' . date('Y', strtotime($c['created_at'])) . '-' . str_pad($c['id'], 3, '0', STR_PAD_LEFT);
                    $totalT = (int)$c['total_tasks'];
                    $doneT = (int)$c['done_tasks'];
                ?>
                <tr>
                    <td><span class="pre-number"><?= e($numInterno) ?></span></td>
                    <td>
                        <?php if ($c['client_id']): ?>
                            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $c['client_id']) ?>" class="client-link"><?= e($c['client_name']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $c['id']) ?>" class="case-link"><?= e($c['title'] ? $c['title'] : 'Caso #' . $c['id']) ?></a></td>
                    <td style="font-size:.78rem;"><?= e($c['case_type'] ? $c['case_type'] : '—') ?></td>
                    <td><span class="badge badge-<?= isset($statusBadge[$c['status']]) ? $statusBadge[$c['status']] : 'gestao' ?>"><?= isset($statusLabels[$c['status']]) ? $statusLabels[$c['status']] : $c['status'] ?></span></td>
                    <td><span class="badge badge-<?= isset($priorityBadge[$c['priority']]) ? $priorityBadge[$c['priority']] : 'gestao' ?>"><?= e($c['priority']) ?></span></td>
                    <td style="font-size:.78rem;"><?= e($c['responsible_name'] ? explode(' ', $c['responsible_name'])[0] : '—') ?></td>
                    <td><span class="tasks-mini"><?= $totalT > 0 ? $doneT . '/' . $totalT : '—' ?></span></td>
                    <td style="font-size:.75rem;color:var(--text-muted);"><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
