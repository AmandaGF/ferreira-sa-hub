<?php
/**
 * Ferreira & Sá Hub — Criar/Editar Usuário
 */

require_once __DIR__ . '/../../core/middleware.php';
require_role('admin');

$pageTitle = 'Usuário';
$pdo = db();
$errors = [];
$user = null;

// Modo edição
$editId = (int)($_GET['id'] ?? 0);
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $user = $stmt->fetch();
    if (!$user) {
        flash_set('error', 'Usuário não encontrado.');
        redirect(module_url('usuarios'));
    }
    $pageTitle = 'Editar Usuário';
} else {
    $pageTitle = 'Novo Usuário';
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Token de segurança inválido.';
    }

    $name   = clean_str($_POST['name'] ?? '', 120);
    $email  = trim($_POST['email'] ?? '');
    $phone  = clean_str($_POST['phone'] ?? '', 40);
    $setor  = clean_str($_POST['setor'] ?? '', 60);
    $role   = $_POST['role'] ?? 'colaborador';
    $password = $_POST['password'] ?? '';

    // Validações
    if (empty($name)) $errors[] = 'Nome é obrigatório.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail válido é obrigatório.';
    if (!in_array($role, array('admin', 'gestao', 'comercial', 'cx', 'operacional', 'colaborador', 'estagiario'))) $errors[] = 'Perfil inválido.';

    // Verificar email duplicado
    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $stmt->execute([$email, $editId]);
        if ($stmt->fetch()) {
            $errors[] = 'Este e-mail já está cadastrado.';
        }
    }

    // Senha obrigatória apenas para novo usuário
    if (!$editId && strlen($password) < 6) {
        $errors[] = 'Senha deve ter pelo menos 6 caracteres.';
    }

    if (empty($errors)) {
        if ($editId) {
            // Atualizar
            $sql = 'UPDATE users SET name = ?, email = ?, phone = ?, setor = ?, role = ?, updated_at = NOW() WHERE id = ?';
            $params = [$name, $email, $phone ?: null, $setor ?: null, $role, $editId];

            if (!empty($password)) {
                $sql = 'UPDATE users SET name = ?, email = ?, phone = ?, setor = ?, role = ?, password_hash = ?, updated_at = NOW() WHERE id = ?';
                $params = [$name, $email, $phone ?: null, $setor ?: null, $role, password_hash($password, PASSWORD_DEFAULT), $editId];
            }

            $pdo->prepare($sql)->execute($params);
            audit_log('user_updated', 'user', $editId, "Atualizado por admin");
            flash_set('success', 'Usuário atualizado com sucesso.');
        } else {
            // Criar
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, phone, setor, role) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $email, $hash, $phone ?: null, $setor ?: null, $role]);
            $newId = (int)$pdo->lastInsertId();
            audit_log('user_created', 'user', $newId, "Criado por admin");
            flash_set('success', 'Usuário criado com sucesso.');
        }

        redirect(module_url('usuarios'));
    }
}

// Valores para o formulário
$f = [
    'name'  => $_POST['name']  ?? ($user['name']  ?? ''),
    'email' => $_POST['email'] ?? ($user['email'] ?? ''),
    'phone' => $_POST['phone'] ?? ($user['phone'] ?? ''),
    'setor' => $_POST['setor'] ?? ($user['setor'] ?? ''),
    'role'  => $_POST['role']  ?? ($user['role']  ?? 'colaborador'),
];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width: 600px;">
    <a href="<?= module_url('usuarios') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <span class="alert-icon">✕</span>
            <div><?= implode('<br>', array_map('e', $errors)) ?></div>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="POST">
                <?= csrf_input() ?>

                <div class="form-group">
                    <label class="form-label" for="name">Nome completo *</label>
                    <input type="text" id="name" name="name" class="form-input"
                           value="<?= e($f['name']) ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="email">E-mail *</label>
                        <input type="email" id="email" name="email" class="form-input"
                               value="<?= e($f['email']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="phone">Telefone</label>
                        <input type="text" id="phone" name="phone" class="form-input"
                               value="<?= e($f['phone']) ?>" placeholder="(00) 00000-0000">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="setor">Setor</label>
                        <input type="text" id="setor" name="setor" class="form-input"
                               value="<?= e($f['setor']) ?>" placeholder="Ex: Operacional, Comercial...">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="role">Perfil de acesso *</label>
                        <select id="role" name="role" class="form-select">
                            <option value="estagiario" <?= $f['role'] === 'estagiario' ? 'selected' : '' ?>>Estagiário</option>
                            <option value="colaborador" <?= $f['role'] === 'colaborador' ? 'selected' : '' ?>>Colaborador</option>
                            <option value="comercial" <?= $f['role'] === 'comercial' ? 'selected' : '' ?>>Comercial</option>
                            <option value="cx" <?= $f['role'] === 'cx' ? 'selected' : '' ?>>CX</option>
                            <option value="operacional" <?= $f['role'] === 'operacional' ? 'selected' : '' ?>>Operacional</option>
                            <option value="gestao" <?= $f['role'] === 'gestao' ? 'selected' : '' ?>>Gestão</option>
                            <option value="admin" <?= $f['role'] === 'admin' ? 'selected' : '' ?>>Administrador</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">
                        <?= $editId ? 'Nova senha (deixe em branco para manter)' : 'Senha *' ?>
                    </label>
                    <input type="password" id="password" name="password" class="form-input"
                           placeholder="Mínimo 6 caracteres"
                           <?= $editId ? '' : 'required minlength="6"' ?>>
                </div>

                <div class="card-footer" style="border-top: none; padding: 1rem 0 0;">
                    <a href="<?= module_url('usuarios') ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary"><?= $editId ? 'Salvar' : 'Criar Usuário' ?></button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($editId && $user): ?>
    <!-- Permissões individuais -->
    <?php
    $userPerms = get_user_permissions($editId, $user['role']);
    $moduleLabels = module_permission_labels();

    // POST: salvar permissões
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['perm_action'] ?? '') === 'salvar_permissoes') {
        // Já processado acima mas vamos tratar aqui separado
    }
    ?>
    <div class="card" style="margin-top:1.25rem;">
        <div class="card-header">
            <h3>Permissões de Acesso</h3>
            <span style="font-size:.72rem;color:var(--text-muted);">Perfil: <?= role_label($user['role']) ?> — Altere apenas o que for diferente do padrão</span>
        </div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('usuarios', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_permissions">
                <input type="hidden" name="user_id" value="<?= $editId ?>">

                <table style="width:100%;border-collapse:collapse;font-size:.82rem;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border);">
                            <th style="text-align:left;padding:.5rem;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Módulo</th>
                            <th style="text-align:center;padding:.5rem;font-size:.72rem;width:80px;">Padrão</th>
                            <th style="text-align:center;padding:.5rem;font-size:.72rem;width:140px;">Acesso</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($moduleLabels as $mod => $label):
                        $perm = isset($userPerms[$mod]) ? $userPerms[$mod] : array('default' => false, 'override' => null, 'effective' => false);
                        $defaultIcon = $perm['default'] ? '✅' : '❌';
                    ?>
                        <tr style="border-bottom:1px solid rgba(0,0,0,.05);">
                            <td style="padding:.45rem .5rem;font-weight:600;"><?= $label ?></td>
                            <td style="text-align:center;padding:.45rem;font-size:.85rem;"><?= $defaultIcon ?></td>
                            <td style="text-align:center;padding:.45rem;">
                                <select name="perm[<?= $mod ?>]" class="form-select" style="font-size:.78rem;padding:.25rem .5rem;width:auto;display:inline;<?= $perm['override'] !== null ? 'border-color:#B87333;' : '' ?>">
                                    <option value="default" <?= $perm['override'] === null ? 'selected' : '' ?>>Padrão do perfil</option>
                                    <option value="1" <?= $perm['override'] === 1 ? 'selected' : '' ?>>✅ Liberar</option>
                                    <option value="0" <?= $perm['override'] === 0 ? 'selected' : '' ?>>🚫 Bloquear</option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
                    <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Salvar Permissões</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
