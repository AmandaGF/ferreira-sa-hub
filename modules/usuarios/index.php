<?php
/**
 * Ferreira & Sá Hub — Gestão de Usuários (Admin only)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

$pageTitle = 'Usuários';
$pdo = db();

// Buscar todos os usuários
$stmt = $pdo->query('SELECT * FROM users ORDER BY role DESC, name ASC');
$users = $stmt->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div class="card-header" style="border: none; padding: 0; margin-bottom: 1.5rem;">
    <h3 style="font-size: 1rem;">Equipe (<?= count($users) ?> usuários)</h3>
    <a href="<?= module_url('usuarios', 'form.php') ?>" class="btn btn-primary btn-sm">+ Novo Usuário</a>
</div>

<div class="card">
    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>Setor</th>
                    <th>Perfil</th>
                    <th>Status</th>
                    <th>Último acesso</th>
                    <th style="width: 120px;">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="7" class="text-center text-muted" style="padding: 2rem;">Nenhum usuário cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <div class="flex items-center gap-1">
                                <div style="width:32px;height:32px;border-radius:50%;background:var(--rose-light);color:var(--brown);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.7rem;flex-shrink:0;">
                                    <?= e(mb_strtoupper(mb_substr($u['name'], 0, 2, 'UTF-8'))) ?>
                                </div>
                                <span class="font-bold"><?= e($u['name']) ?></span>
                            </div>
                        </td>
                        <td class="text-sm"><?= e($u['email']) ?></td>
                        <td class="text-sm"><?= e($u['setor'] ?? '—') ?></td>
                        <td><?= role_badge($u['role']) ?></td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge badge-success">Ativo</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-muted"><?= data_hora_br($u['last_login_at']) ?></td>
                        <td>
                            <div class="flex gap-1">
                                <a href="<?= module_url('usuarios', 'form.php?id=' . $u['id']) ?>" class="btn btn-outline btn-sm" title="Editar">✏️</a>
                                <?php if ((int)$u['id'] !== current_user_id()): ?>
                                    <form method="POST" action="<?= module_url('usuarios', 'api.php') ?>" style="display:inline;">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm"
                                                data-confirm="<?= $u['is_active'] ? 'Desativar este usuário?' : 'Reativar este usuário?' ?>"
                                                title="<?= $u['is_active'] ? 'Desativar' : 'Ativar' ?>">
                                            <?= $u['is_active'] ? '🔒' : '🔓' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
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
