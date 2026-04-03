<?php
/**
 * Ferreira & Sá Hub — Processos Judiciais
 * Somente demandas com número de processo judicial
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Processos Judiciais';
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
    'distribuido' => 'Distribuído', 'concluido' => 'Concluído', 'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso',
);
$statusBadge = array(
    'ativo' => 'info', 'aguardando_docs' => 'warning', 'em_elaboracao' => 'info',
    'em_andamento' => 'info', 'aguardando_prazo' => 'warning', 'distribuido' => 'success',
    'concluido' => 'success', 'arquivado' => 'gestao', 'suspenso' => 'danger',
);
$priorityBadge = array('urgente' => 'danger', 'alta' => 'warning', 'normal' => 'gestao', 'baixa' => 'colaborador');

// Query — só processos judiciais (com case_number OU is_judicial = 1)
$where = array("(cs.case_number IS NOT NULL AND cs.case_number != '')");
$params = array();

if ($isColaborador) {
    $where[] = "cs.responsible_user_id = ?";
    $params[] = current_user_id();
}
if ($filterStatus) { $where[] = "cs.status = ?"; $params[] = $filterStatus; }
if ($filterType) { $where[] = "cs.case_type = ?"; $params[] = $filterType; }
if ($filterUser && !$isColaborador) { $where[] = "cs.responsible_user_id = ?"; $params[] = (int)$filterUser; }
if ($search) {
    $where[] = "(cs.title LIKE ? OR cs.case_number LIKE ? OR c.name LIKE ? OR cs.court LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare(
    "SELECT cs.*, c.name as client_name, c.phone as client_phone, c.cpf as client_cpf,
     u.name as responsible_name
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE $whereStr ORDER BY cs.created_at DESC LIMIT 200"
);
$stmt->execute($params);
$processos = $stmt->fetchAll();

// KPIs
$totalJudiciais = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE case_number IS NOT NULL AND case_number != ''")->fetchColumn();
$ativosJ = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE case_number IS NOT NULL AND case_number != '' AND status NOT IN ('concluido','arquivado')")->fetchColumn();

$tipos = $pdo->query("SELECT DISTINCT case_type FROM cases WHERE case_number IS NOT NULL AND case_number != '' AND case_type IS NOT NULL AND case_type != '' ORDER BY case_type")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.proc-stats { display:flex; gap:.75rem; margin-bottom:1.25rem; flex-wrap:wrap; }
.proc-stat { background:var(--bg-card); border-radius:var(--radius-lg); border:1px solid var(--border); padding:.75rem 1.25rem; display:flex; align-items:center; gap:.75rem; min-width:140px; }
.proc-stat-icon { font-size:1.2rem; }
.proc-stat-val { font-size:1.4rem; font-weight:800; color:var(--petrol-900); }
.proc-stat-lbl { font-size:.68rem; color:var(--text-muted); text-transform:uppercase; letter-spacing:.3px; }

.proc-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.75rem; }
.proc-filters { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; }
.proc-filter-sel { font-size:.75rem; padding:.35rem .5rem; border:1.5px solid var(--border); border-radius:var(--radius); background:var(--bg-card); }

.proc-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.proc-table th { background:var(--petrol-900); color:#fff; padding:.55rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.proc-table td { padding:.55rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.proc-table tr:hover { background:rgba(215,171,144,.04); }
.proc-number { font-family:monospace; font-size:.78rem; color:var(--petrol-500); font-weight:600; }
.case-link { color:var(--petrol-900); font-weight:700; text-decoration:none; }
.case-link:hover { color:var(--rose); }
.client-link { color:var(--petrol-500); font-weight:600; text-decoration:none; }
.client-link:hover { color:var(--rose); }
</style>

<!-- KPIs -->
<div class="proc-stats">
    <div class="proc-stat">
        <span class="proc-stat-icon">⚖️</span>
        <div><div class="proc-stat-val"><?= $totalJudiciais ?></div><div class="proc-stat-lbl">Processos</div></div>
    </div>
    <div class="proc-stat">
        <span class="proc-stat-icon">⚙️</span>
        <div><div class="proc-stat-val"><?= $ativosJ ?></div><div class="proc-stat-lbl">Ativos</div></div>
    </div>
</div>

<!-- Toolbar -->
<div class="proc-toolbar">
    <form method="GET" class="proc-filters">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar nº processo, cliente, vara..." style="font-size:.8rem;padding:.4rem .75rem;border:1.5px solid var(--border);border-radius:var(--radius);width:250px;">
        <select name="status" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Status</option>
            <?php foreach ($statusLabels as $k => $v): ?>
                <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type" class="proc-filter-sel" onchange="this.form.submit()">
            <option value="">Tipo</option>
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
        <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        <?php if ($filterStatus || $filterType || $filterUser || $search): ?>
            <a href="<?= module_url('processos') ?>" class="btn btn-outline btn-sm">Limpar</a>
        <?php endif; ?>
    </form>
    <div style="display:flex;gap:.5rem;">
        <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('crm', 'importar_processos.php') ?>" class="btn btn-outline btn-sm">Importar CSV</a>
        <?php endif; ?>
        <a href="<?= module_url('operacional', 'caso_novo.php') ?>" class="btn btn-primary btn-sm">+ Novo Processo</a>
    </div>
</div>

<!-- Tabela -->
<div class="card" style="overflow-x:auto;">
    <?php if (empty($processos)): ?>
        <div class="card-body" style="text-align:center;padding:3rem;">
            <div style="font-size:2rem;margin-bottom:.5rem;">⚖️</div>
            <h3>Nenhum processo judicial</h3>
            <p style="color:var(--text-muted);font-size:.85rem;">Importe do LegalOne ou cadastre pelo Operacional com número de processo.</p>
        </div>
    <?php else: ?>
        <table class="proc-table">
            <thead><tr>
                <th>Nº Processo</th>
                <th>Cliente</th>
                <th>Título</th>
                <th>Tipo</th>
                <th>Vara / Tribunal</th>
                <th>Status</th>
                <th>Prioridade</th>
                <th>Responsável</th>
                <th>Prazo</th>
            </tr></thead>
            <tbody>
                <?php foreach ($processos as $p):
                    $isOverdue = $p['deadline'] && $p['deadline'] < date('Y-m-d');
                ?>
                <tr>
                    <td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $p['id']) ?>" class="proc-number" style="text-decoration:none;color:inherit;cursor:pointer;" title="Abrir pasta do processo"><?= e($p['case_number']) ?></a></td>
                    <td>
                        <?php if ($p['client_id']): ?>
                            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $p['client_id']) ?>" class="client-link"><?= e($p['client_name']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><a href="<?= module_url('operacional', 'caso_ver.php?id=' . $p['id']) ?>" class="case-link"><?= e($p['title'] ? $p['title'] : 'Processo #' . $p['id']) ?></a></td>
                    <td style="font-size:.78rem;"><?= e($p['case_type'] && $p['case_type'] !== 'outro' ? $p['case_type'] : '—') ?></td>
                    <td style="font-size:.78rem;"><?= e($p['court'] ? $p['court'] : '—') ?></td>
                    <td><span class="badge badge-<?= isset($statusBadge[$p['status']]) ? $statusBadge[$p['status']] : 'gestao' ?>"><?= isset($statusLabels[$p['status']]) ? $statusLabels[$p['status']] : $p['status'] ?></span></td>
                    <td>
                        <select onchange="mudarPrioridade(<?= $p['id'] ?>, this.value, this)" style="font-size:.72rem;padding:2px 4px;border:1px solid #e5e7eb;border-radius:4px;font-weight:600;
                            <?php
                            $cores = array('urgente'=>'background:#fef2f2;color:#dc2626;','alta'=>'background:#fffbeb;color:#d97706;','normal'=>'background:#f0fdf4;color:#059669;','baixa'=>'background:#f8fafc;color:#64748b;');
                            echo isset($cores[$p['priority']]) ? $cores[$p['priority']] : '';
                            ?>">
                            <option value="urgente" <?= $p['priority']==='urgente'?'selected':'' ?>>URGENTE</option>
                            <option value="alta" <?= $p['priority']==='alta'?'selected':'' ?>>ALTA</option>
                            <option value="normal" <?= $p['priority']==='normal'?'selected':'' ?>>NORMAL</option>
                            <option value="baixa" <?= $p['priority']==='baixa'?'selected':'' ?>>BAIXA</option>
                        </select>
                    </td>
                    <td style="font-size:.78rem;"><?= e($p['responsible_name'] ? explode(' ', $p['responsible_name'])[0] : '—') ?></td>
                    <td style="font-size:.78rem;<?= $isOverdue ? 'color:#ef4444;font-weight:700;' : '' ?>"><?= $p['deadline'] ? date('d/m/Y', strtotime($p['deadline'])) : '—' ?><?= $isOverdue ? ' ⚠️' : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php $extraJs = <<<'JSEOF'
function mudarPrioridade(caseId, valor, sel) {
    var cores = {urgente:'background:#fef2f2;color:#dc2626;',alta:'background:#fffbeb;color:#d97706;',normal:'background:#f0fdf4;color:#059669;',baixa:'background:#f8fafc;color:#64748b;'};
    sel.style.cssText = 'font-size:.72rem;padding:2px 4px;border:1px solid #e5e7eb;border-radius:4px;font-weight:600;' + (cores[valor] || '');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/conecta/modules/shared/card_actions.php');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.error) { alert(r.error); return; }
            sel.style.outline = '2px solid #059669';
            setTimeout(function() { sel.style.outline = ''; }, 1500);
        } catch(e) { alert('Erro ao salvar'); }
    };
    xhr.send('action=update_field&entity=case&entity_id=' + caseId + '&field=priority&value=' + valor);
}
JSEOF;
?>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
