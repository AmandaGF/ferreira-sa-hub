<?php
/**
 * Ferreira & Sá Hub — CRM Dashboard
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('colaborador')) { redirect(url('modules/dashboard/')); }

$pageTitle = 'CRM';
$pdo = db();
$isReadOnly = has_role('colaborador');

// KPIs
$totalClientes = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$casosAtivos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('concluido','arquivado')")->fetchColumn();
$contatosHoje = (int)$pdo->query("SELECT COUNT(*) FROM contacts WHERE DATE(contacted_at) = CURDATE()")->fetchColumn();
$formsPendentes = (int)$pdo->query("SELECT COUNT(*) FROM form_submissions WHERE status = 'novo'")->fetchColumn();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon petrol">👥</div>
        <div class="stat-info">
            <div class="stat-value"><?= $totalClientes ?></div>
            <div class="stat-label">Clientes cadastrados</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon success">⚙️</div>
        <div class="stat-info">
            <div class="stat-value"><?= $casosAtivos ?></div>
            <div class="stat-label">Casos ativos</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon rose">📞</div>
        <div class="stat-info">
            <div class="stat-value"><?= $contatosHoje ?></div>
            <div class="stat-label">Contatos hoje</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon warning">📋</div>
        <div class="stat-info">
            <div class="stat-value"><?= $formsPendentes ?></div>
            <div class="stat-label">Formulários pendentes</div>
        </div>
    </div>
</div>

<!-- Barra de ações -->
<div class="card-header" style="border:none;padding:0;margin-bottom:1.5rem;">
    <div></div>
    <?php if (!$isReadOnly): ?>
        <a href="<?= module_url('crm', 'cliente_form.php') ?>" class="btn btn-primary btn-sm">+ Novo Cliente</a>
    <?php endif; ?>
</div>

<!-- Lista de clientes recentes -->
<div class="card">
    <div class="card-header">
        <h3>Clientes</h3>
        <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;">
            <input type="text" name="q" class="form-input" style="width:200px;padding:.45rem .75rem;font-size:.82rem;"
                   placeholder="Buscar nome, CPF, telefone..." value="<?= e($_GET['q'] ?? '') ?>">
            <select name="periodo" class="form-select" style="font-size:.82rem;padding:.45rem;">
                <option value="ultimo_mes" <?= ($_GET['periodo'] ?? 'ultimo_mes') === 'ultimo_mes' ? 'selected' : '' ?>>Último mês</option>
                <option value="3meses" <?= ($_GET['periodo'] ?? '') === '3meses' ? 'selected' : '' ?>>Últimos 3 meses</option>
                <option value="todos" <?= ($_GET['periodo'] ?? '') === 'todos' ? 'selected' : '' ?>>Todos</option>
            </select>
            <select name="status" class="form-select" style="font-size:.82rem;padding:.45rem;">
                <option value="">Todos os status</option>
                <option value="ativo" <?= ($_GET['status'] ?? '') === 'ativo' ? 'selected' : '' ?>>✅ Ativo</option>
                <option value="contrato_assinado" <?= ($_GET['status'] ?? '') === 'contrato_assinado' ? 'selected' : '' ?>>📝 Contrato Assinado</option>
                <option value="cancelou" <?= ($_GET['status'] ?? '') === 'cancelou' ? 'selected' : '' ?>>❌ Cancelou</option>
                <option value="parou_responder" <?= ($_GET['status'] ?? '') === 'parou_responder' ? 'selected' : '' ?>>⏳ Parou de Responder</option>
                <option value="demitido" <?= ($_GET['status'] ?? '') === 'demitido' ? 'selected' : '' ?>>🚫 Demitimos</option>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Buscar</button>
        </form>
    </div>

    <?php
    $search = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;

    $filterStatus = $_GET['status'] ?? '';
    $filterPeriodo = $_GET['periodo'] ?? 'ultimo_mes';
    $where = array();
    $params = array();

    // CRM = somente clientes com formulário preenchido (form_submissions)
    $joins = 'INNER JOIN form_submissions fs ON fs.linked_client_id = c.id AND fs.status != \'arquivado\'';

    if ($search) {
        $where[] = "(c.name LIKE ? OR c.cpf LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)";
        $like = '%' . $search . '%';
        $params = array($like, $like, $like, $like);
    }
    if ($filterStatus) {
        $where[] = "c.client_status = ?";
        $params[] = $filterStatus;
    }
    // Filtro de período
    if ($filterPeriodo === 'ultimo_mes') {
        $where[] = "fs.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
    } elseif ($filterPeriodo === '3meses') {
        $where[] = "fs.created_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)";
    }
    $where = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT c.id) FROM clients c $joins $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag = paginate($total, $perPage, $page);

    $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM cases WHERE client_id = c.id AND status NOT IN ('concluido','arquivado')) as active_cases,
            (SELECT MAX(contacted_at) FROM contacts WHERE client_id = c.id) as last_contact,
            MAX(fs.created_at) as form_date,
            GROUP_CONCAT(DISTINCT fs.form_type SEPARATOR ', ') as form_types
            FROM clients c $joins $where
            GROUP BY c.id
            ORDER BY MAX(fs.created_at) DESC
            LIMIT {$pag['per_page']} OFFSET {$pag['offset']}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll();
    ?>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Telefone</th>
                    <th>Formulário</th>
                    <th>Status</th>
                    <th>Casos ativos</th>
                    <th>Último contato</th>
                    <th>Data formulário</th>
                    <th style="width:80px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr><td colspan="9" class="text-center text-muted" style="padding:2rem;">
                        <?= $search ? 'Nenhum resultado encontrado.' : 'Nenhum formulário recebido neste período.' ?>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($clients as $c): ?>
                    <tr>
                        <td>
                            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $c['id']) ?>" class="font-bold" style="color:var(--petrol-900);">
                                <?= e($c['name']) ?>
                            </a>
                        </td>
                        <td class="text-sm">
                            <?php if ($c['phone']): ?>
                                <a href="#" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $c['phone']) ?>',nome:<?= e(json_encode($c['name'])) ?>,clientId:<?= (int)$c['id'] ?>,mensagem:''});return false;" style="color:var(--success);" title="Enviar WhatsApp pelo Hub">
                                    <?= e($c['phone']) ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-sm"><span class="badge badge-info"><?= e($c['form_types'] ?: '—') ?></span></td>
                        <td>
                            <?php
                            $st = isset($c['client_status']) ? $c['client_status'] : 'ativo';
                            $stMap = array('ativo'=>array('✅','success'), 'contrato_assinado'=>array('📝','info'), 'cancelou'=>array('❌','danger'), 'parou_responder'=>array('⏳','warning'), 'demitido'=>array('🚫','danger'));
                            $stI = isset($stMap[$st]) ? $stMap[$st] : $stMap['ativo'];
                            ?>
                            <span class="badge badge-<?= $stI[1] ?>"><?= $stI[0] ?></span>
                        </td>
                        <td>
                            <?php if ($c['active_cases'] > 0): ?>
                                <span class="badge badge-info"><?= $c['active_cases'] ?> ativo<?= $c['active_cases'] > 1 ? 's' : '' ?></span>
                            <?php else: ?>
                                <span class="text-muted text-sm">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-muted"><?= data_hora_br($c['last_contact']) ?></td>
                        <td class="text-sm text-muted"><?= data_br($c['form_date']) ?></td>
                        <td>
                            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $c['id']) ?>" class="btn btn-outline btn-sm" title="Ver">👁️</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pag['total_pages'] > 1): ?>
    <div class="pagination">
        <?php if ($pag['current'] > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pag['current'] - 1])) ?>">«</a>
        <?php endif; ?>
        <?php
        $tp = $pag['total_pages'];
        $cp = $pag['current'];
        $pages = array(1);
        for ($i = max(2, $cp - 2); $i <= min($tp - 1, $cp + 2); $i++) { $pages[] = $i; }
        if ($tp > 1) $pages[] = $tp;
        $pages = array_unique($pages);
        sort($pages);
        $prev = 0;
        foreach ($pages as $p):
            if ($prev && $p - $prev > 1): ?><span style="color:var(--text-muted);">...</span><?php endif;
            $qs = http_build_query(array_merge($_GET, ['page' => $p]));
            if ($p === $cp): ?>
                <span class="active"><?= $p ?></span>
            <?php else: ?>
                <a href="?<?= $qs ?>"><?= $p ?></a>
            <?php endif;
            $prev = $p;
        endforeach; ?>
        <?php if ($pag['current'] < $tp): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pag['current'] + 1])) ?>">»</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
