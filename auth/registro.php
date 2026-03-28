<?php
/**
 * Ferreira & Sá Hub — Auto-cadastro de colaborador (pendente aprovação)
 */

require_once __DIR__ . '/../core/auth.php';

// Se já está logado, redireciona
if (is_logged_in()) {
    redirect(url('modules/dashboard/'));
}

$errors = array();
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) {
        $errors[] = 'Token de segurança inválido. Tente novamente.';
    }

    $name     = clean_str($_POST['name'] ?? '', 120);
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $setor    = clean_str($_POST['setor'] ?? '', 60);

    if (empty($name)) $errors[] = 'Nome é obrigatório.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'E-mail válido é obrigatório.';
    if (strlen($password) < 6) $errors[] = 'Senha deve ter pelo menos 6 caracteres.';
    if ($password !== $password2) $errors[] = 'As senhas não conferem.';
    if (empty($setor)) $errors[] = 'Selecione seu setor.';

    // Verificar e-mail duplicado
    if (empty($errors)) {
        $stmt = db()->prepare('SELECT id, is_active FROM users WHERE email = ?');
        $stmt->execute(array($email));
        $existing = $stmt->fetch();
        if ($existing) {
            if (!$existing['is_active']) {
                $errors[] = 'Este e-mail já está cadastrado e aguardando aprovação.';
            } else {
                $errors[] = 'Este e-mail já está cadastrado.';
            }
        }
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare(
            'INSERT INTO users (name, email, password_hash, role, is_active, setor) VALUES (?, ?, ?, ?, 0, ?)'
        )->execute(array($name, $email, $hash, 'colaborador', $setor));

        audit_log('user_self_register', 'user', (int)db()->lastInsertId(), 'Aguardando aprovação');
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Criar Conta — <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Open Sans', system-ui, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #052228 0%, #0b2f36 40%, #173d46 100%);
            padding: 1rem;
        }
        .container { width: 100%; max-width: 440px; }
        .brand { text-align: center; margin-bottom: 2rem; }
        .brand-logo {
            width: 60px; height: 60px; background: #d7ab90; border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.5rem; font-weight: 800; color: #052228; margin-bottom: .75rem;
        }
        .brand h1 { font-size: 1.5rem; font-weight: 800; color: #fff; }
        .brand p { color: #d7ab90; font-size: .85rem; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; margin-top: .25rem; }
        .card {
            background: #fff; border-radius: 24px; padding: 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }
        .card h2 { font-size: 1.1rem; font-weight: 700; color: #052228; margin-bottom: .25rem; }
        .card .subtitle { font-size: .82rem; color: #6b7280; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: .82rem; font-weight: 600; color: #052228; margin-bottom: .3rem; }
        .form-input, .form-select {
            width: 100%; padding: .7rem 1rem; font-family: inherit; font-size: .88rem;
            color: #0f1c20; background: #f9fafb; border: 1.5px solid #e5e7eb;
            border-radius: 12px; outline: none; transition: .22s ease;
        }
        .form-input:focus, .form-select:focus { border-color: #d7ab90; box-shadow: 0 0 0 3px rgba(215,171,144,.2); background: #fff; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .btn {
            width: 100%; padding: .85rem; font-family: inherit; font-size: .95rem; font-weight: 700;
            color: #fff; background: linear-gradient(135deg, #052228, #173d46);
            border: none; border-radius: 12px; cursor: pointer; transition: .22s ease; margin-top: .5rem;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(5,34,40,.4); }
        .error-msg {
            background: #fef2f2; color: #dc2626; border: 1px solid #fecaca;
            border-radius: 12px; padding: .7rem 1rem; font-size: .82rem; margin-bottom: 1rem;
        }
        .success-box {
            text-align: center; padding: 1.5rem 0;
        }
        .success-box .check {
            width: 64px; height: 64px; background: #ecfdf5; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 2rem; margin-bottom: 1rem;
        }
        .success-box h2 { color: #059669; font-size: 1.1rem; margin-bottom: .5rem; }
        .success-box p { color: #6b7280; font-size: .88rem; }
        .link { text-align: center; margin-top: 1.25rem; }
        .link a { color: rgba(255,255,255,.6); font-size: .82rem; text-decoration: none; }
        .link a:hover { color: #d7ab90; }
        @media (max-width: 480px) { .card { padding: 1.5rem; } .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="brand">
            <div class="brand-logo">F&S</div>
            <h1>Ferreira &amp; Sá</h1>
            <p>Hub</p>
        </div>

        <div class="card">
            <?php if ($success): ?>
                <div class="success-box">
                    <div class="check">✓</div>
                    <h2>Cadastro enviado!</h2>
                    <p>Sua conta foi criada e está <strong>aguardando aprovação</strong> do administrador.</p>
                    <p style="margin-top:.75rem;">Você receberá acesso assim que for aprovado.</p>
                </div>
            <?php else: ?>
                <h2>Criar conta</h2>
                <p class="subtitle">Preencha seus dados. O admin aprovará seu acesso.</p>

                <?php if (!empty($errors)): ?>
                    <div class="error-msg">
                        <?php foreach ($errors as $e): ?>
                            ✕ <?= e($e) ?><br>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_input() ?>

                    <div class="form-group">
                        <label class="form-label">Nome completo *</label>
                        <input type="text" name="name" class="form-input" required
                               value="<?= e($_POST['name'] ?? '') ?>" placeholder="Seu nome completo">
                    </div>

                    <div class="form-group">
                        <label class="form-label">E-mail *</label>
                        <input type="email" name="email" class="form-input" required
                               value="<?= e($_POST['email'] ?? '') ?>" placeholder="seu@email.com">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Setor *</label>
                        <select name="setor" class="form-select" required>
                            <option value="">— Selecione seu setor —</option>
                            <option value="Operacional" <?= ($_POST['setor'] ?? '') === 'Operacional' ? 'selected' : '' ?>>Operacional</option>
                            <option value="Comercial" <?= ($_POST['setor'] ?? '') === 'Comercial' ? 'selected' : '' ?>>Comercial</option>
                            <option value="Financeiro" <?= ($_POST['setor'] ?? '') === 'Financeiro' ? 'selected' : '' ?>>Financeiro</option>
                            <option value="Administrativo" <?= ($_POST['setor'] ?? '') === 'Administrativo' ? 'selected' : '' ?>>Administrativo</option>
                            <option value="Marketing" <?= ($_POST['setor'] ?? '') === 'Marketing' ? 'selected' : '' ?>>Marketing</option>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Senha *</label>
                            <input type="password" name="password" class="form-input" required
                                   placeholder="Mínimo 6 caracteres" minlength="6">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirmar senha *</label>
                            <input type="password" name="password2" class="form-input" required
                                   placeholder="Repita a senha">
                        </div>
                    </div>

                    <button type="submit" class="btn">Criar Conta</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="link">
            <a href="<?= url('auth/login.php') ?>">Já tem conta? Faça login</a>
        </div>
    </div>
</body>
</html>
