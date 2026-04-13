<?php
/**
 * Ferreira & Sa Hub -- Sala VIP -- Gerenciar Acessos de Clientes
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pageTitle = 'Clientes com Acesso — Sala VIP';
$pdo = db();

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    // ── Reenviar Link (regenerar token) ─────────────────
    if ($action === 'reenviar_link') {
        $newToken = bin2hex(random_bytes(32));
        $pdo->prepare(
            "UPDATE salavip_usuarios SET token = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$newToken, $id]);
        audit_log('salavip_reenviar_link', 'salavip_usuarios', $id, "Novo token gerado");
        flash_set('success', 'Link de acesso regenerado. Envie o novo link ao cliente.');
        redirect(module_url('salavip', 'acessos.php'));
    }

    // ── Resetar Senha ───────────────────────────────────
    if ($action === 'resetar_senha') {
        $tempPassword = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        $pdo->prepare(
            "UPDATE salavip_usuarios SET senha = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$hash, $id]);
        audit_log('salavip_resetar_senha', 'salavip_usuarios', $id);
        flash_set('success', 'Senha resetada. Nova senha temporaria: <strong>' . $tempPassword . '</strong> — Anote antes de sair desta pagina!');
        redirect(module_url('salavip', 'acessos.php'));
    }

    // ── Ativar / Desativar ──────────────────────────────
    if ($action === 'toggle_status') {
        $current = $pdo->prepare("SELECT status FROM salavip_usuarios WHERE id = ?");
        $current->execute([$id]);
        $currentStatus = $current->fetchColumn();

        $newStatus = ($currentStatus === 'ativo') ? 'bloqueado' : 'ativo';
        $pdo->prepare(
            "UPDATE salavip_usuarios SET status = ?, updated_at = NOW() WHERE id = ?"
        )->execute([$newStatus, $id]);
        audit_log('salavip_toggle_status', 'salavip_usuarios', $id, "Status: $currentStatus -> $newStatus");
        flash_set('success', 'Status alterado para: ' . $newStatus);
        redirect(module_url('salavip', 'acessos.php'));
    }
}

// ── Listar usuarios ─────────────────────────────────────
$search = trim($_GET['q'] ?? '');
$where = '1=1';
$params = array();

if ($search) {
    $where .= " AND (c.name LIKE ? OR c.cpf LIKE ? OR c.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$usuarios = $pdo->prepare(
    "SELECT su.*, c.name as client_name, c.cpf, c.email, c.phone
     FROM salavip_usuarios su
     JOIN clients c ON c.id = su.client_id
     WHERE $where
     ORDER BY c.name ASC"
);
$usuarios->execute($params);
$usuarios = $usuarios->fetchAll();

$statusBadge = array(
    'ativo' => 'success', 'pendente' => 'warning', 'bloqueado' => 'danger'
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.acc-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.acc-table th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.acc-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); vertical-align:middle; }
.acc-table tr:hover { background:rgba(215,171,144,.04); }
.acc-cpf { font-size:.72rem; color:var(--text-muted); font-family:monospace; }
</style>

<a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<div class="card">
    <div class="card-header" style="justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
        <h3>Clientes com Acesso (<?= count($usuarios) ?>)</h3>
        <form method="GET" style="display:flex;gap:.4rem;">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar nome, CPF, email..." class="form-control" style="font-size:.78rem;width:220px;">
            <button type="submit" class="btn btn-outline btn-sm">Buscar</button>
            <?php if ($search): ?>
                <a href="<?= module_url('salavip', 'acessos.php') ?>" class="btn btn-outline btn-sm">Limpar</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($usuarios)): ?>
            <div style="text-align:center;padding:2rem;">
                <p class="text-muted text-sm">Nenhum cliente com acesso encontrado.</p>
            </div>
        <?php else: ?>
            <table class="acc-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>CPF</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Ultimo Acesso</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td style="font-weight:600;"><?= e($u['client_name']) ?></td>
                            <td class="acc-cpf"><?= e($u['cpf'] ?? '—') ?></td>
                            <td class="text-sm"><?= e($u['email'] ?? '—') ?></td>
                            <td>
                                <span class="badge badge-<?= $statusBadge[$u['status']] ?? 'gestao' ?>">
                                    <?= ucfirst(e($u['status'])) ?>
                                </span>
                            </td>
                            <td class="text-sm text-muted">
                                <?= $u['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($u['ultimo_acesso'])) : '—' ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
                                    <!-- Reenviar Link -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Regenerar o link de acesso?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="reenviar_link">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" title="Reenviar Link">&#128279;</button>
                                    </form>

                                    <!-- Resetar Senha -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Resetar a senha deste cliente?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="resetar_senha">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" title="Resetar Senha">&#128272;</button>
                                    </form>

                                    <!-- Ativar/Desativar -->
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $u['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?> este acesso?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" title="<?= $u['status'] === 'ativo' ? 'Desativar' : 'Ativar' ?>" style="color:<?= $u['status'] === 'ativo' ? 'var(--danger)' : 'var(--success)' ?>;">
                                            <?= $u['status'] === 'ativo' ? '&#9940;' : '&#9989;' ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
