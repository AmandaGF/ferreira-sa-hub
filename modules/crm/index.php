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
        <form method="GET" style="display:flex;gap:.5rem;">
            <input type="text" name="q" class="form-input" style="width:250px;padding:.45rem .75rem;font-size:.82rem;"
                   placeholder="Buscar nome, CPF, telefone..." value="<?= e($_GET['q'] ?? '') ?>">
            <button type="submit" class="btn btn-outline btn-sm">Buscar</button>
        </form>
    </div>

    <?php
    $search = trim($_GET['q'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;

    $where = '';
    $params = [];
    if ($search) {
        $where = "WHERE c.name LIKE ? OR c.cpf LIKE ? OR c.phone LIKE ? OR c.email LIKE ?";
        $like = '%' . $search . '%';
        $params = [$like, $like, $like, $like];
    }

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM clients c $where");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();
    $pag = paginate($total, $perPage, $page);

    $sql = "SELECT c.*,
            (SELECT COUNT(*) FROM cases WHERE client_id = c.id AND status NOT IN ('concluido','arquivado')) as active_cases,
            (SELECT MAX(contacted_at) FROM contacts WHERE client_id = c.id) as last_contact
            FROM clients c $where
            ORDER BY c.created_at DESC
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
                    <th>E-mail</th>
                    <th>Casos ativos</th>
                    <th>Último contato</th>
                    <th>Cadastro</th>
                    <th style="width:80px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding:2rem;">
                        <?= $search ? 'Nenhum cliente encontrado.' : 'Nenhum cliente cadastrado ainda.' ?>
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
                                <a href="https://wa.me/55<?= preg_replace('/\D/', '', $c['phone']) ?>" target="_blank" style="color:var(--success);" title="WhatsApp">
                                    <?= e($c['phone']) ?>
                                </a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="text-sm"><?= e($c['email'] ?: '—') ?></td>
                        <td>
                            <?php if ($c['active_cases'] > 0): ?>
                                <span class="badge badge-info"><?= $c['active_cases'] ?> ativo<?= $c['active_cases'] > 1 ? 's' : '' ?></span>
                            <?php else: ?>
                                <span class="text-muted text-sm">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-muted"><?= data_hora_br($c['last_contact']) ?></td>
                        <td class="text-sm text-muted"><?= data_br($c['created_at']) ?></td>
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
        <?php for ($i = 1; $i <= $pag['total_pages']; $i++): ?>
            <?php $qs = http_build_query(array_merge($_GET, ['page' => $i])); ?>
            <?php if ($i === $pag['current']): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?<?= $qs ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
