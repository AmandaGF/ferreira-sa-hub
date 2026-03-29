<?php
/**
 * Ferreira & Sá Hub — Extrajudicial
 * Demandas que não geram processo judicial: inventário extrajudicial,
 * divórcio em cartório, escrituras, contratos, consultorias, etc.
 * Cada serviço recebe número interno: EXT-2026-001
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Extrajudicial';
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
    'distribuido' => 'Revisão', 'concluido' => 'Concluído', 'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso',
);
$statusBadge = array(
    'ativo' => 'info', 'aguardando_docs' => 'warning', 'em_elaboracao' => 'info',
    'em_andamento' => 'info', 'aguardando_prazo' => 'warning', 'distribuido' => 'success',
    'concluido' => 'success', 'arquivado' => 'gestao', 'suspenso' => 'danger',
);
$priorityBadge = array('urgente' => 'danger', 'alta' => 'warning', 'normal' => 'gestao', 'baixa' => 'colaborador');

// Query — serviços administrativos = cases SEM número de processo judicial
$where = array("cs.category = 'extrajudicial'");
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
    "SELECT cs.*, c.name as client_name, c.phone as client_phone, u.name as responsible_name
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE $whereStr ORDER BY cs.created_at DESC LIMIT 200"
);
$stmt->execute($params);
$servicos = $stmt->fetchAll();

// KPIs
$totalServicos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE category = 'extrajudicial'")->fetchColumn();
$ativosS = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE (category = 'extrajudicial') AND status NOT IN ('concluido','arquivado')")->fetchColumn();

$tipos = $pdo->query("SELECT DISTINCT case_type FROM cases WHERE (category = 'extrajudicial') AND case_type IS NOT NULL AND case_type != '' ORDER BY case_type")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.srv-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.srv-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:140px; }
.srv-stat-icon { font-size:1.2rem; }
.srv-stat-val { font-size:1.4rem; font-weight:800; color:var(--petrol-900); }
.srv-stat-lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

.srv-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; }
.srv-filters { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.srv-filter-sel { font-size:.75rem; padding:.35rem .5rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); }

.srv-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.srv-table th { background:var(--petrol-900); color:#fff; padding:.55rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.srv-table td { padding:.55rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.srv-table tr:hover { background:rgba(215,171,144,.04); }
.srv-number { font-family:monospace; font-size:.78rem; color:var(--rose-dark); font-weight:700; }
.case-link { color:var(--petrol-900); font-weight:700; text-decoration:none; }
.case-link:hover { color:var(--rose); }
.client-link { color:var(--petrol-500); font-weight:600; text-decoration:none; }
.client-link:hover { color:var(--rose); }

.new-srv-form { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:1.25rem; margin-bottom:1.25rem; }
.new-srv-form h4 { font-size:.92rem; font-weight:700; color:var(--petrol-900); margin-bottom:1rem; }
</style>

<!-- KPIs -->
<div class="srv-stats">
    <div class="srv-stat">
        <span class="srv-stat-icon">📋</span>
        <div><div class="srv-stat-val"><?= $totalServicos ?></div><div class="srv-stat-lbl">Total</div></div>
    </div>
    <div class="srv-stat">
        <span class="srv-stat-icon">⚙️</span>
        <div><div class="srv-stat-val"><?= $ativosS ?></div><div class="srv-stat-lbl">Ativos</div></div>
    </div>
</div>

<!-- Novo Serviço -->
<?php if (has_min_role('gestao')): ?>
<div class="new-srv-form">
    <h4>+ Novo Serviço Administrativo</h4>
    <form method="POST" action="<?= module_url('servicos', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
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
                <label class="form-label">Tipo de Serviço</label>
                <select name="case_type" class="form-select" required>
                    <option value="">— Selecionar —</option>
                    <option value="Inventário Extrajudicial">Inventário Extrajudicial</option>
                    <option value="Divórcio Extrajudicial">Divórcio Extrajudicial</option>
                    <option value="Escritura Pública">Escritura Pública</option>
                    <option value="Usucapião Extrajudicial">Usucapião Extrajudicial</option>
                    <option value="Consultoria Jurídica">Consultoria Jurídica</option>
                    <option value="Elaboração de Contrato">Elaboração de Contrato</option>
                    <option value="Mediação">Mediação</option>
                    <option value="Notificação Extrajudicial">Notificação Extrajudicial</option>
                    <option value="Outro">Outro</option>
                </select>
            </div>
        </div>
        <div class="form-row" style="margin-bottom:.75rem;">
            <div class="form-group" style="margin:0;">
                <label class="form-label">Título / Descrição</label>
                <input type="text" name="title" class="form-input" placeholder="Ex: Inventário da família Silva" required>
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
        <button type="submit" class="btn btn-primary btn-sm">Criar Serviço</button>
    </form>
</div>
<?php endif; ?>

<!-- Toolbar -->
<div class="srv-toolbar">
    <form method="GET" class="srv-filters">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar serviço, nº interno, cliente..." style="font-size:.8rem;padding:.4rem .75rem;border:1.5px solid var(--border);border-radius:var(--radius);width:250px;">
        <select name="status" class="srv-filter-sel" onchange="this.form.submit()">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="srv-filter-sel" onchange="this.form.submit()">
            <option value="">Tipo</option>
            <?php foreach ($tipos as $t): ?>
                <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        <?php if ($filterStatus || $filterType || $filterUser || $search): ?>
            <a href="<?= module_url('servicos') ?>" class="btn btn-outline btn-sm">Limpar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Tabela -->
<div class="card" style="overflow-x:auto;">
    <?php if (empty($servicos)): ?>
        <div class="card-body" style="text-align:center;padding:3rem;">
            <div style="font-size:2rem;margin-bottom:.5rem;">📋</div>
            <h3>Nenhum serviço administrativo</h3>
            <p style="color:var(--text-muted);font-size:.85rem;">Crie um novo serviço extrajudicial acima.</p>
        </div>
    <?php else: ?>
        <table class="srv-table">
            <thead><tr>
                <th>Nº Interno</th>
                <th>Cliente</th>
                <th>Serviço</th>
                <th>Tipo</th>
                <th>Status</th>
                <th>Prioridade</th>
                <th>Responsável</th>
                <th>Criado em</th>
            </tr></thead>
            <tbody>
                <?php foreach ($servicos as $s):
                    // Gerar número interno visual: ADM-ANO-ID
                    $numInterno = isset($s['internal_number']) && $s['internal_number'] ? $s['internal_number'] : 'ADM-' . date('Y', strtotime($s['created_at'])) . '-' . str_pad($s['id'], 3, '0', STR_PAD_LEFT);
                ?>
                <tr>
                    <td><span class="srv-number"><?= e($numInterno) ?></span></td>
                    <td>
                        <?php if ($s['client_id']): ?>
                            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $s['client_id']) ?>" class="client-link"><?= e($s['client_name']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $s['id']) ?>" class="case-link"><?= e($s['title'] ? $s['title'] : 'Serviço #' . $s['id']) ?></a></td>
                    <td style="font-size:.78rem;"><?= e($s['case_type'] ? $s['case_type'] : '—') ?></td>
                    <td><span class="badge badge-<?= isset($statusBadge[$s['status']]) ? $statusBadge[$s['status']] : 'gestao' ?>"><?= isset($statusLabels[$s['status']]) ? $statusLabels[$s['status']] : $s['status'] ?></span></td>
                    <td><span class="badge badge-<?= isset($priorityBadge[$s['priority']]) ? $priorityBadge[$s['priority']] : 'gestao' ?>"><?= e($s['priority']) ?></span></td>
                    <td style="font-size:.78rem;"><?= e($s['responsible_name'] ? explode(' ', $s['responsible_name'])[0] : '—') ?></td>
                    <td style="font-size:.75rem;color:var(--text-muted);"><?= date('d/m/Y', strtotime($s['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
