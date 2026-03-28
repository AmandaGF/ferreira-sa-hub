<?php
/**
 * Ferreira & Sá Hub — Painel de Formulários
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Formulários';
$pdo = db();

// Filtros
$filterType   = $_GET['type'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;

$where = [];
$params = [];

if ($filterType) {
    $where[] = "fs.form_type = ?";
    $params[] = $filterType;
}
if ($filterStatus) {
    $where[] = "fs.status = ?";
    $params[] = $filterStatus;
}
if ($search) {
    $where[] = "(fs.client_name LIKE ? OR fs.protocol LIKE ? OR fs.client_phone LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like]);
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM form_submissions fs $whereStr");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pag = paginate($total, $perPage, $page);

$stmt = $pdo->prepare(
    "SELECT fs.*, u.name as assigned_name, c.name as linked_client_name
     FROM form_submissions fs
     LEFT JOIN users u ON u.id = fs.assigned_to
     LEFT JOIN clients c ON c.id = fs.linked_client_id
     $whereStr ORDER BY fs.created_at DESC
     LIMIT {$pag['per_page']} OFFSET {$pag['offset']}"
);
$stmt->execute($params);
$submissions = $stmt->fetchAll();

// KPIs
$kpis = $pdo->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status = 'novo' THEN 1 ELSE 0 END) as novos,
    SUM(CASE WHEN status = 'em_analise' THEN 1 ELSE 0 END) as em_analise,
    SUM(CASE WHEN status = 'processado' THEN 1 ELSE 0 END) as processados
    FROM form_submissions")->fetch();

// Tipos de formulário existentes
$types = $pdo->query("SELECT DISTINCT form_type FROM form_submissions ORDER BY form_type")->fetchAll(PDO::FETCH_COLUMN);

$statusLabels = ['novo' => 'Novo', 'em_analise' => 'Em análise', 'processado' => 'Processado', 'arquivado' => 'Arquivado'];
$statusBadge  = ['novo' => 'warning', 'em_analise' => 'info', 'processado' => 'success', 'arquivado' => 'gestao'];

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<!-- KPIs -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon petrol">📋</div>
        <div class="stat-info"><div class="stat-value"><?= $kpis['total'] ?></div><div class="stat-label">Total recebidos</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">🆕</div>
        <div class="stat-info"><div class="stat-value"><?= $kpis['novos'] ?></div><div class="stat-label">Novos</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon info">🔍</div>
        <div class="stat-info"><div class="stat-value"><?= $kpis['em_analise'] ?></div><div class="stat-label">Em análise</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">✅</div>
        <div class="stat-info"><div class="stat-value"><?= $kpis['processados'] ?></div><div class="stat-label">Processados</div></div>
    </div>
</div>

<!-- Filtros -->
<form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.5rem;align-items:flex-end;">
    <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:.7rem;">Buscar</label>
        <input type="text" name="q" class="form-input" style="font-size:.8rem;padding:.4rem .6rem;width:200px;"
               value="<?= e($search) ?>" placeholder="Nome, protocolo, telefone...">
    </div>
    <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:.7rem;">Tipo</label>
        <select name="type" class="form-select" style="font-size:.8rem;padding:.4rem;">
            <option value="">Todos</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= e($t) ?>" <?= $filterType === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group" style="margin:0;">
        <label class="form-label" style="font-size:.7rem;">Status</label>
        <select name="status" class="form-select" style="font-size:.8rem;padding:.4rem;">
            <option value="">Todos</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
    <a href="<?= module_url('formularios') ?>" class="btn btn-outline btn-sm">Limpar</a>
</form>

<!-- Lista -->
<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Protocolo</th>
                    <th>Tipo</th>
                    <th>Nome</th>
                    <th>Telefone</th>
                    <th>Status</th>
                    <th>Cliente vinculado</th>
                    <th>Data</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr><td colspan="8" class="text-center text-muted" style="padding:2rem;">Nenhum formulário recebido.</td></tr>
                <?php else: ?>
                    <?php foreach ($submissions as $s): ?>
                    <tr>
                        <td><a href="<?= module_url('formularios', 'ver.php?id=' . $s['id']) ?>" class="font-bold" style="color:var(--petrol-900);"><?= e($s['protocol']) ?></a></td>
                        <td><span class="badge badge-gestao"><?= e($s['form_type']) ?></span></td>
                        <td><?= e($s['client_name'] ?: '—') ?></td>
                        <td class="text-sm">
                            <?php if ($s['client_phone']): ?>
                                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $s['client_phone']) ?>" target="_blank" style="color:var(--success);"><?= e($s['client_phone']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= $statusBadge[$s['status']] ?? 'gestao' ?>"><?= $statusLabels[$s['status']] ?? $s['status'] ?></span></td>
                        <td class="text-sm"><?= e($s['linked_client_name'] ?: '—') ?></td>
                        <td class="text-sm text-muted"><?= data_hora_br($s['created_at']) ?></td>
                        <td><a href="<?= module_url('formularios', 'ver.php?id=' . $s['id']) ?>" class="btn btn-outline btn-sm">👁️</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pag['total_pages'] > 1): ?>
    <div class="pagination">
        <?php for ($i = 1; $i <= $pag['total_pages']; $i++): ?>
            <?php $qs = http_build_query(array_merge($_GET, ['page' => $i])); ?>
            <?php if ($i === $pag['current']): ?><span class="active"><?= $i ?></span>
            <?php else: ?><a href="?<?= $qs ?>"><?= $i ?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
