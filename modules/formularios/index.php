<?php
/**
 * Ferreira & Sá Hub — Painel de Formulários (melhorado)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pageTitle = 'Formulários';
$pdo = db();

// Tipos existentes
$types = $pdo->query("SELECT DISTINCT form_type FROM form_submissions ORDER BY form_type")->fetchAll(PDO::FETCH_COLUMN);

// Aba ativa
$activeType = $_GET['type'] ?? ($types[0] ?? '');
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

// Labels dos tipos
$typeLabels = array(
    'convivencia' => 'Convivência',
    'gastos_pensao' => 'Gastos Pensão',
    'cadastro_cliente' => 'Cadastro de Clientes',
    'calculadora_lead' => 'Leads Calculadora',
    'divorcio' => 'Divórcio',
    'alimentos' => 'Alimentos',
    'responsabilidade_civil' => 'Resp. Civil',
);

$statusLabels = array('novo' => 'Novo', 'em_analise' => 'Em análise', 'processado' => 'Processado', 'arquivado' => 'Arquivado');
$statusBadge = array('novo' => 'warning', 'em_analise' => 'info', 'processado' => 'success', 'arquivado' => 'gestao');

// KPIs gerais
$kpis = $pdo->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status = 'novo' THEN 1 ELSE 0 END) as novos,
    SUM(CASE WHEN status = 'em_analise' THEN 1 ELSE 0 END) as em_analise,
    SUM(CASE WHEN status = 'processado' THEN 1 ELSE 0 END) as processados
    FROM form_submissions")->fetch();

// KPIs por tipo
$kpiByType = array();
foreach ($types as $t) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM form_submissions WHERE form_type = ?");
    $stmt->execute(array($t));
    $kpiByType[$t] = (int)$stmt->fetchColumn();
}

// Buscar formulários do tipo ativo
$where = array("fs.form_type = ?");
$params = array($activeType);

if ($filterStatus) { $where[] = "fs.status = ?"; $params[] = $filterStatus; }
if ($search) {
    $where[] = "(fs.client_name LIKE ? OR fs.protocol LIKE ? OR fs.client_phone LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$whereStr = implode(' AND ', $where);
$submissions = $pdo->prepare(
    "SELECT fs.* FROM form_submissions fs WHERE $whereStr ORDER BY fs.created_at DESC LIMIT 200"
);
$submissions->execute($params);
$submissions = $submissions->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.type-tabs { display:flex; gap:0; border-bottom:2px solid var(--border); margin-bottom:1.5rem; overflow-x:auto; }
.type-tab { padding:.6rem 1.25rem; font-size:.82rem; font-weight:600; color:var(--text-muted); cursor:pointer;
    border-bottom:2px solid transparent; margin-bottom:-2px; transition:all var(--transition);
    background:none; border-top:none; border-left:none; border-right:none; white-space:nowrap;
    text-decoration:none; display:flex; align-items:center; gap:.4rem; }
.type-tab:hover { color:var(--petrol-500); }
.type-tab.active { color:var(--petrol-900); border-bottom-color:var(--rose); }
.type-tab .tab-count { font-size:.68rem; background:var(--bg); padding:.1rem .4rem; border-radius:100px; }
.type-tab.active .tab-count { background:var(--rose-light); color:var(--brown); }
</style>

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

<!-- Abas por tipo -->
<div class="type-tabs">
    <?php foreach ($types as $t): ?>
        <a href="?type=<?= urlencode($t) ?>" class="type-tab <?= $activeType === $t ? 'active' : '' ?>">
            <?= isset($typeLabels[$t]) ? $typeLabels[$t] : e($t) ?>
            <span class="tab-count"><?= $kpiByType[$t] ?? 0 ?></span>
        </a>
    <?php endforeach; ?>
</div>

<!-- Filtros + Importar -->
<div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;">
    <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="type" value="<?= e($activeType) ?>">
        <input type="text" name="q" class="form-input" style="font-size:.8rem;padding:.4rem .6rem;width:200px;"
               value="<?= e($search) ?>" placeholder="Nome, protocolo, telefone...">
        <select name="status" class="form-select" style="font-size:.8rem;padding:.4rem;">
            <option value="">Todos os status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
        <a href="?type=<?= urlencode($activeType) ?>" class="btn btn-outline btn-sm">Limpar</a>
    </form>
    <a href="<?= module_url('formularios', 'importar_firebase.php') ?>" class="btn btn-outline btn-sm">📥 Importar do Firebase</a>
</div>

<!-- Lista -->
<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Protocolo</th>
                    <th>Nome</th>
                    <th>Telefone</th>
                    <th>Status</th>
                    <th>Data</th>
                    <th style="width:130px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr><td colspan="6" class="text-center text-muted" style="padding:2rem;">
                        <?= $activeType ? 'Nenhum formulário deste tipo.' : 'Selecione um tipo acima.' ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($submissions as $s): ?>
                    <tr>
                        <td><a href="<?= module_url('formularios', 'ver.php?id=' . $s['id']) ?>" class="font-bold" style="color:var(--petrol-900);"><?= e($s['protocol']) ?></a></td>
                        <td><?= e($s['client_name'] ?: '—') ?></td>
                        <td class="text-sm">
                            <?php if ($s['client_phone']): ?>
                                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $s['client_phone']) ?>" target="_blank" style="color:var(--success);">📱 <?= e($s['client_phone']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= $statusBadge[$s['status']] ?? 'gestao' ?>"><?= $statusLabels[$s['status']] ?? $s['status'] ?></span></td>
                        <td class="text-sm text-muted"><?= data_hora_br($s['created_at']) ?></td>
                        <td>
                            <div class="flex gap-1">
                                <a href="<?= module_url('formularios', 'ver.php?id=' . $s['id']) ?>" class="btn btn-outline btn-sm">👁️</a>
                                <form method="POST" action="<?= module_url('formularios', 'api.php') ?>" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="form_id" value="<?= $s['id'] ?>">
                                    <input type="hidden" name="redirect_type" value="<?= e($activeType) ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" data-confirm="Apagar este formulário? Esta ação não pode ser desfeita." title="Apagar">🗑️</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
