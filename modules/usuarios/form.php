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

// Puxa dados do cadastro de colaborador (Amanda 02/06/2026): se vier ?colab_id=N,
// busca o registro de colaboradores_onboarding e usa como pre-preenchimento.
// Util pra criar conta de quem ja foi onboardado e nao ter que redigitar tudo.
$colab = null;
if (!$editId && (int)($_GET['colab_id'] ?? 0) > 0) {
    try {
        $stC = $pdo->prepare("SELECT id, nome_completo, email_institucional, email_pessoal, telefone_whatsapp, setor, cargo, perfil_cargo FROM colaboradores_onboarding WHERE id = ?");
        $stC->execute(array((int)$_GET['colab_id']));
        $colab = $stC->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {}
}
// Mapeia perfil_cargo do onboarding -> role do hub. Se nao mapear, fica null e
// o select fica no default 'colaborador'.
function _colab_role_default($colab) {
    if (!$colab) return null;
    $cargoLower = mb_strtolower((string)($colab['cargo'] ?? ''), 'UTF-8');
    $setorLower = mb_strtolower((string)($colab['setor'] ?? ''), 'UTF-8');
    if (mb_strpos($cargoLower, 'estagi') !== false || mb_strpos($setorLower, 'estagi') !== false) return 'estagiario';
    if (mb_strpos($setorLower, 'comercial') !== false) return 'comercial';
    if (mb_strpos($setorLower, 'cx') !== false || mb_strpos($setorLower, 'atendimento') !== false) return 'cx';
    if (mb_strpos($setorLower, 'operacional') !== false || mb_strpos($setorLower, 'juridico') !== false || mb_strpos($setorLower, 'jurídico') !== false) return 'operacional';
    return 'colaborador';
}
$_roleFromColab = _colab_role_default($colab);

// Valores para o formulário
$f = [
    'name'  => $_POST['name']  ?? ($user['name']  ?? ($colab['nome_completo'] ?? '')),
    'email' => $_POST['email'] ?? ($user['email'] ?? ($colab['email_institucional'] ?: ($colab['email_pessoal'] ?? '') ?? '')),
    'phone' => $_POST['phone'] ?? ($user['phone'] ?? ($colab['telefone_whatsapp'] ?? '')),
    'setor' => $_POST['setor'] ?? ($user['setor'] ?? ($colab['setor'] ?? '')),
    'role'  => $_POST['role']  ?? ($user['role']  ?? ($_roleFromColab ?? 'colaborador')),
];

// Lista de cadastros do onboarding pra seletor "puxar de" (em modo novo OU edit)
$colabsList = array();
try {
    $colabsList = $pdo->query("SELECT id, nome_completo FROM colaboradores_onboarding WHERE status != 'arquivado' ORDER BY nome_completo")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Sugestao automatica: tenta achar 1 cadastro que combine com o user atual
// por email exato ou nome muito parecido. Se achar, mostra "Puxar de X" em destaque.
$colabSugerido = null;
if ($editId && $user && !empty($colabsList)) {
    foreach ($colabsList as $c) {
        // Match por email institucional eh o mais forte
        $stMatch = $pdo->prepare("SELECT id, email_institucional, email_pessoal FROM colaboradores_onboarding WHERE id = ?");
        $stMatch->execute(array($c['id']));
        $colabFull = $stMatch->fetch(PDO::FETCH_ASSOC);
        if ($colabFull && ($colabFull['email_institucional'] === $user['email'] || $colabFull['email_pessoal'] === $user['email'])) {
            $colabSugerido = $c; break;
        }
    }
    if (!$colabSugerido) {
        // Fallback: match por nome (3+ palavras iguais)
        $palavrasUser = array_filter(explode(' ', mb_strtolower($user['name'], 'UTF-8')), function($p){ return mb_strlen($p) > 2; });
        foreach ($colabsList as $c) {
            $palavrasC = array_filter(explode(' ', mb_strtolower($c['nome_completo'], 'UTF-8')), function($p){ return mb_strlen($p) > 2; });
            $iguais = count(array_intersect($palavrasUser, $palavrasC));
            if ($iguais >= 2) { $colabSugerido = $c; break; }
        }
    }
}

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

    <?php if (!$editId && !empty($colabsList)): ?>
    <!-- Modo Novo: redireciona pra ?colab_id=X que preenche os campos via GET -->
    <div style="background:#fff7ed;border:1.5px dashed #d7ab90;border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1rem;font-size:.85rem;color:#6a3c2c;">
        <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
            <strong>💡 Já tem cadastro no onboarding?</strong>
            <select onchange="if(this.value) location.href='?colab_id='+this.value;" style="font-size:.82rem;padding:.4rem .6rem;border:1.5px solid #d7ab90;border-radius:6px;background:#fff;color:#052228;flex:1;min-width:240px;">
                <option value="">Puxar dados de um cadastro existente...</option>
                <?php foreach ($colabsList as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= ($colab && $colab['id'] == $c['id']) ? 'selected' : '' ?>><?= e($c['nome_completo']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($colab): ?>
    <div style="background:#ecfdf5;border:1px solid #34d399;border-radius:8px;padding:.55rem .85rem;margin-bottom:1rem;font-size:.82rem;color:#065f46;">
        ✓ Dados puxados do cadastro de <strong><?= e($colab['nome_completo']) ?></strong>. Revise antes de criar.
    </div>
    <?php endif; ?>

    <?php if ($editId && !empty($colabsList)): ?>
    <!-- Modo Edit: POST direto pra api.php action=puxar_do_cadastro que faz UPDATE em users -->
    <div style="background:#fff7ed;border:1.5px dashed #d7ab90;border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1rem;font-size:.85rem;color:#6a3c2c;">
        <form method="POST" action="<?= module_url('usuarios', 'api.php') ?>" onsubmit="var s=this.querySelector('select[name=colab_id]'); if(!s.value){alert('Escolha um cadastro primeiro.');return false;} return confirm('Sobrescrever telefone, setor e nome com dados do cadastro escolhido?\n\nO e-mail (login) e a senha NAO sao alterados.');">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="puxar_do_cadastro">
            <input type="hidden" name="user_id" value="<?= $editId ?>">
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;">
                <strong>🔄 Puxar dados do cadastro do onboarding:</strong>
                <select name="colab_id" style="font-size:.82rem;padding:.4rem .6rem;border:1.5px solid #d7ab90;border-radius:6px;background:#fff;color:#052228;flex:1;min-width:240px;">
                    <option value="">Escolha um cadastro...</option>
                    <?php foreach ($colabsList as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= ($colabSugerido && $colabSugerido['id'] == $c['id']) ? 'selected' : '' ?>><?= e($c['nome_completo']) ?><?= ($colabSugerido && $colabSugerido['id'] == $c['id']) ? ' ✨ (sugerido)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-sm" style="background:#fff;border-color:#6a3c2c;color:#6a3c2c;font-weight:700;">Puxar</button>
            </div>
            <div style="font-size:.7rem;color:#9ca3af;margin-top:.4rem;">Sobrescreve telefone, setor e nome. E-mail (login) preservado.</div>
        </form>
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

            <?php if ($editId && $user && !empty($user['email'])): ?>
            <!-- Botao Enviar email de acesso (Amanda 02/06/2026): gera senha temp e envia via Brevo -->
            <div style="margin-top:1rem;padding-top:1rem;border-top:1.5px dashed #d7ab90;">
                <form method="POST" action="<?= module_url('usuarios', 'api.php') ?>" onsubmit="return confirm('Enviar e-mail de acesso para <?= e($user['email']) ?>?\n\nIsso vai gerar uma nova SENHA TEMPORÁRIA e enviar por e-mail com link para entrar. A senha atual sera substituida.');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="enviar_email_acesso">
                    <input type="hidden" name="user_id" value="<?= $editId ?>">
                    <button type="submit" class="btn btn-outline" style="background:#fff7ed;border-color:#d7ab90;color:#6a3c2c;font-weight:700;">
                        ✉️ Enviar e-mail de acesso (gera senha temporária)
                    </button>
                    <span style="font-size:.72rem;color:#6b7280;margin-left:.5rem;">A senha atual será substituída por uma nova de 8 caracteres.</span>
                </form>
            </div>
            <?php endif; ?>
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
