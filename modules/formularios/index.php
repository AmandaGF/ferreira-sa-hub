<?php
/**
 * Ferreira & Sá Hub — Painel de Formulários (melhorado)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_access('formularios');

$pageTitle = 'Formulários';
$pdo = db();

// Tipos existentes (do banco + tipos fixos que sempre devem aparecer)
$types = $pdo->query("SELECT DISTINCT form_type FROM form_submissions ORDER BY form_type")->fetchAll(PDO::FETCH_COLUMN);
$tiposFixos = array('cadastro_cliente', 'calculadora_lead', 'convivencia', 'gastos_pensao', 'despesas_mensais');
foreach ($tiposFixos as $tf) {
    if (!in_array($tf, $types)) $types[] = $tf;
}

// Aba ativa
$activeType = $_GET['type'] ?? ($types[0] ?? '');
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['q'] ?? '');

// Labels dos tipos com ícones e cores
$typeLabels = array(
    'cadastro_cliente' => array('label' => 'Cadastro de Clientes', 'icon' => '👤', 'color' => '#052228'),
    'calculadora_lead' => array('label' => 'Leads Calculadora', 'icon' => '🧮', 'color' => '#d97706'),
    'convivencia' => array('label' => 'Convivência', 'icon' => '👨‍👩‍👧', 'color' => '#059669'),
    'gastos_pensao' => array('label' => 'Gastos Pensão (antigo)', 'icon' => '💰', 'color' => '#6a3c2c'),
    'despesas_mensais' => array('label' => 'Despesas Mensais', 'icon' => '📊', 'color' => '#052228'),
    'divorcio' => array('label' => 'Divórcio', 'icon' => '📋', 'color' => '#dc2626'),
    'alimentos' => array('label' => 'Alimentos', 'icon' => '⚖️', 'color' => '#7c3aed'),
    'responsabilidade_civil' => array('label' => 'Resp. Civil', 'icon' => '🏛️', 'color' => '#0284c7'),
);

function getTypeLabel($type, $typeLabels) {
    return isset($typeLabels[$type]) ? $typeLabels[$type]['label'] : $type;
}
function getTypeIcon($type, $typeLabels) {
    return isset($typeLabels[$type]) ? $typeLabels[$type]['icon'] : '📄';
}
function getTypeColor($type, $typeLabels) {
    return isset($typeLabels[$type]) ? $typeLabels[$type]['color'] : '#6b7280';
}

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
.type-tabs { display:flex; gap:.6rem; margin-bottom:1.5rem; overflow-x:auto; padding-bottom:.5rem; flex-wrap:wrap; }
.type-tab {
    padding:.65rem 1.1rem; font-size:.82rem; font-weight:600; cursor:pointer;
    transition:all var(--transition); white-space:nowrap; text-decoration:none;
    display:flex; align-items:center; gap:.5rem;
    border-radius:var(--radius); border:2px solid var(--border);
    background:var(--bg-card); color:var(--text-muted);
}
.type-tab:hover { transform:translateY(-1px); box-shadow:var(--shadow-sm); }
.type-tab.active { color:#fff; border-color:transparent; box-shadow:var(--shadow-md); transform:translateY(-1px); }
.type-tab .tab-icon { font-size:1.1rem; }
.type-tab .tab-count {
    font-size:.7rem; font-weight:800; padding:.1rem .5rem; border-radius:100px;
    background:rgba(255,255,255,.2); color:inherit;
}
.type-tab:not(.active) .tab-count { background:var(--bg); color:var(--text-muted); }
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
    <?php foreach ($types as $t):
        $color = getTypeColor($t, $typeLabels);
        $isActive = ($activeType === $t);
        $style = $isActive ? "background:{$color};color:#fff;border-color:{$color};" : "";
    ?>
        <a href="?type=<?= urlencode($t) ?>" class="type-tab <?= $isActive ? 'active' : '' ?>" style="<?= $style ?>">
            <span class="tab-icon"><?= getTypeIcon($t, $typeLabels) ?></span>
            <?= getTypeLabel($t, $typeLabels) ?>
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
