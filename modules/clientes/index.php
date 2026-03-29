<?php
/**
 * Ferreira & Sá Hub — Clientes / Contatos (Base completa)
 * Diferente do CRM (fluxo comercial), aqui é a base de todos os clientes,
 * incluindo importados do LegalOne que vão direto pro operacional.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Clientes';
$pdo = db();

// Filtros
$search = isset($_GET['q']) ? trim($_GET['q']) : '';
$filterStatus = isset($_GET['status']) ? $_GET['status'] : '';
$filterSource = isset($_GET['source']) ? $_GET['source'] : '';

// Query
$where = array('1=1');
$params = array();

if ($search) {
    $where[] = "(c.name LIKE ? OR c.cpf LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($filterStatus) {
    $where[] = "c.client_status = ?";
    $params[] = $filterStatus;
}
if ($filterSource) {
    $where[] = "c.source = ?";
    $params[] = $filterSource;
}

$whereStr = implode(' AND ', $where);

// Paginação
$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c WHERE $whereStr");
$countStmt->execute($params);
$totalFiltered = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT c.*,
     (SELECT COUNT(*) FROM cases WHERE client_id = c.id) as total_processos,
     (SELECT COUNT(*) FROM cases WHERE client_id = c.id AND status NOT IN ('concluido','arquivado')) as processos_ativos
     FROM clients c
     WHERE $whereStr
     ORDER BY c.name ASC
     LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$clientes = $stmt->fetchAll();

// KPIs
$totalClientes = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$comProcesso = (int)$pdo->query("SELECT COUNT(DISTINCT client_id) FROM cases WHERE client_id IS NOT NULL")->fetchColumn();
$semProcesso = $totalClientes - $comProcesso;
$importados = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE source = 'importacao'")->fetchColumn();

// Fontes distintas
$fontes = $pdo->query("SELECT DISTINCT source FROM clients WHERE source IS NOT NULL AND source != '' ORDER BY source")->fetchAll(PDO::FETCH_COLUMN);

// Status labels
$statusLabels = array(
    'ativo' => 'Ativo', 'contrato_assinado' => 'Contrato Assinado',
    'cancelou' => 'Cancelou', 'parou_responder' => 'Parou de Responder', 'demitido' => 'Demitimos',
);
$statusBadge = array(
    'ativo' => 'info', 'contrato_assinado' => 'success',
    'cancelou' => 'danger', 'parou_responder' => 'warning', 'demitido' => 'danger',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cli-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.cli-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:130px; }
.cli-stat-icon { font-size:1.2rem; }
.cli-stat-val { font-size:1.4rem; font-weight:800; color:var(--petrol-900); }
.cli-stat-lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

.cli-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; }
.cli-filters { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.cli-filter-sel { font-size:.75rem; padding:.35rem .5rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); }
.cli-search input { font-size:.8rem; padding:.4rem .75rem; border:1.5px solid var(--border); border-radius:var(--radius); width:250px; }
.cli-search input:focus { border-color:var(--rose); outline:none; }

.cli-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.cli-table th { background:var(--petrol-900); color:#fff; padding:.55rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.cli-table td { padding:.55rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.cli-table tr:hover { background:rgba(215,171,144,.04); }
.cli-name { font-weight:700; color:var(--petrol-900); text-decoration:none; }
.cli-name:hover { color:var(--rose); }
.cli-cpf { font-size:.72rem; color:var(--text-muted); font-family:monospace; }
.cli-contact { font-size:.75rem; color:var(--text-muted); }
.cli-proc { font-size:.78rem; font-weight:700; }
.cli-proc-active { color:var(--info); }
</style>

<!-- KPIs -->
<div class="cli-stats">
    <div class="cli-stat">
        <span class="cli-stat-icon">👥</span>
        <div><div class="cli-stat-val"><?= $totalClientes ?></div><div class="cli-stat-lbl">Total</div></div>
    </div>
    <div class="cli-stat">
        <span class="cli-stat-icon">📁</span>
        <div><div class="cli-stat-val"><?= $comProcesso ?></div><div class="cli-stat-lbl">Com processo</div></div>
    </div>
    <div class="cli-stat">
        <span class="cli-stat-icon">📋</span>
        <div><div class="cli-stat-val"><?= $semProcesso ?></div><div class="cli-stat-lbl">Sem processo</div></div>
    </div>
    <?php if ($importados > 0): ?>
    <div class="cli-stat">
        <span class="cli-stat-icon">📥</span>
        <div><div class="cli-stat-val"><?= $importados ?></div><div class="cli-stat-lbl">Importados</div></div>
    </div>
    <?php endif; ?>
</div>

<!-- Toolbar -->
<div class="cli-toolbar">
    <form method="GET" class="cli-filters">
        <div class="cli-search" style="display:flex;gap:.35rem;">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar por nome, CPF, telefone, e-mail...">
            <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        </div>
        <select name="status" class="cli-filter-sel" onchange="this.form.submit()">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="source" class="cli-filter-sel" onchange="this.form.submit()">
            <option value="">Origem</option>
            <?php foreach ($fontes as $f): ?>
                <option value="<?= e($f) ?>" <?= $filterSource === $f ? 'selected' : '' ?>><?= e($f) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($search || $filterStatus || $filterSource): ?>
            <a href="<?= module_url('clientes') ?>" class="btn btn-outline btn-sm">Limpar</a>
        <?php endif; ?>
    </form>
    <?php if (has_min_role('gestao')): ?>
    <div style="display:flex;gap:.5rem;">
        <a href="<?= module_url('crm', 'importar.php') ?>" class="btn btn-outline btn-sm">📥 Importar CSV</a>
        <a href="<?= module_url('crm', 'cliente_form.php') ?>" class="btn btn-primary btn-sm">+ Novo Cliente</a>
    </div>
    <?php endif; ?>
</div>

<!-- Tabela -->
<div class="card" style="overflow-x:auto;">
    <?php if (empty($clientes)): ?>
        <div class="card-body" style="text-align:center;padding:3rem;">
            <div style="font-size:2rem;margin-bottom:.5rem;">👥</div>
            <h3>Nenhum cliente encontrado</h3>
            <p style="color:var(--text-muted);font-size:.85rem;">
                <?= $search ? 'Tente outros termos.' : 'Importe do LegalOne ou cadastre manualmente.' ?>
            </p>
        </div>
    <?php else: ?>
        <table class="cli-table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>CPF</th>
                    <th>Contato</th>
                    <th>Origem</th>
                    <th>Status</th>
                    <th>Processos</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clientes as $c): ?>
                <tr>
                    <td>
                        <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $c['id']) ?>" class="cli-name"><?= e($c['name']) ?></a>
                    </td>
                    <td><span class="cli-cpf"><?= e($c['cpf'] ? $c['cpf'] : '—') ?></span></td>
                    <td>
                        <div class="cli-contact">
                            <?php if ($c['phone']): ?>
                                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $c['phone']) ?>" target="_blank" style="color:var(--success);">📱 <?= e($c['phone']) ?></a>
                            <?php endif; ?>
                            <?php if ($c['email']): ?>
                                <div>✉️ <?= e($c['email']) ?></div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td style="font-size:.75rem;"><?= e($c['source'] ? $c['source'] : '—') ?></td>
                    <td>
                        <?php
                        $cs = isset($c['client_status']) ? $c['client_status'] : '';
                        if ($cs && isset($statusLabels[$cs])): ?>
                            <span class="badge badge-<?= isset($statusBadge[$cs]) ? $statusBadge[$cs] : 'gestao' ?>"><?= $statusLabels[$cs] ?></span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:.75rem;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['total_processos'] > 0): ?>
                            <span class="cli-proc cli-proc-active"><?= $c['processos_ativos'] ?> ativo<?= $c['processos_ativos'] != 1 ? 's' : '' ?></span>
                            <span style="font-size:.68rem;color:var(--text-muted);">/ <?= $c['total_processos'] ?> total</span>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:.75rem;">Nenhum</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <form method="POST" action="<?= module_url('crm', 'api.php') ?>" style="display:inline;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete_client">
                            <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm" style="font-size:.68rem;padding:.2rem .4rem;color:var(--danger);border-color:var(--danger);" data-confirm="Tem certeza que deseja EXCLUIR definitivamente o contato '<?= e(addslashes($c['name'])) ?>'? Esta ação não pode ser desfeita." title="Excluir">🗑️</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<?php
    // Montar query string sem page
    $qsParams = $_GET;
    unset($qsParams['page']);
    $qs = http_build_query($qsParams);
    $baseUrl = module_url('clientes') . ($qs ? '?' . $qs . '&' : '?');
?>
<div style="display:flex;justify-content:center;align-items:center;gap:.5rem;margin-top:1.25rem;flex-wrap:wrap;">
    <?php if ($page > 1): ?>
        <a href="<?= $baseUrl ?>page=1" class="btn btn-outline btn-sm" style="font-size:.75rem;">« Primeira</a>
        <a href="<?= $baseUrl ?>page=<?= $page - 1 ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;">‹ Anterior</a>
    <?php endif; ?>

    <?php
    $startP = max(1, $page - 3);
    $endP = min($totalPages, $page + 3);
    for ($p = $startP; $p <= $endP; $p++):
    ?>
        <?php if ($p === $page): ?>
            <span class="btn btn-primary btn-sm" style="font-size:.75rem;cursor:default;"><?= $p ?></span>
        <?php else: ?>
            <a href="<?= $baseUrl ?>page=<?= $p ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;"><?= $p ?></a>
        <?php endif; ?>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
        <a href="<?= $baseUrl ?>page=<?= $page + 1 ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;">Próxima ›</a>
        <a href="<?= $baseUrl ?>page=<?= $totalPages ?>" class="btn btn-outline btn-sm" style="font-size:.75rem;">Última »</a>
    <?php endif; ?>

    <span style="font-size:.72rem;color:var(--text-muted);margin-left:.5rem;">
        Página <?= $page ?> de <?= $totalPages ?> (<?= $totalFiltered ?> contatos)
    </span>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
