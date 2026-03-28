<?php
/**
 * Ferreira & Sá Hub — Esqueci minha senha / Redefinir senha
 *
 * Como o TurboCloud bloqueia exec/mail em PHP,
 * usamos um sistema de token temporário que o admin pode compartilhar,
 * OU o próprio colaborador redefine se souber o e-mail.
 */

require_once __DIR__ . '/../core/auth.php';

if (is_logged_in()) {
    redirect(url('modules/dashboard/'));
}

$step = $_GET['step'] ?? 'email';
$token = $_GET['token'] ?? '';
$error = '';
$success = false;

// ─── Passo 1: Informar e-mail ───────────────────────────
if ($step === 'email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { $error = 'Token inválido.'; }
    else {
        $email = trim($_POST['email'] ?? '');
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail válido.';
        } else {
            $stmt = db()->prepare('SELECT id, name, email FROM users WHERE email = ? AND is_active = 1');
            $stmt->execute(array($email));
            $user = $stmt->fetch();

            if ($user) {
                // Gerar token de redefinição (válido por 1 hora)
                $resetToken = bin2hex(random_bytes(20));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Salvar no audit_log como registro temporário
                db()->prepare(
                    "INSERT INTO audit_log (user_id, action, entity_type, details, ip_address, created_at)
                     VALUES (?, 'password_reset_token', 'user', ?, ?, ?)"
                )->execute(array($user['id'], $resetToken . '|' . $expires, $_SERVER['REMOTE_ADDR'] ?? '', date('Y-m-d H:i:s')));

                $step = 'token_gerado';
                $resetLink = url('auth/esqueci_senha.php?step=reset&token=' . $resetToken);
            } else {
                // Não revelar se o e-mail existe ou não
                $step = 'token_gerado';
                $resetLink = '';
            }
        }
    }
}

// ─── Passo 2: Redefinir senha com token ─────────────────
if ($step === 'reset' && !empty($token)) {
    // Verificar token
    $stmt = db()->prepare(
        "SELECT al.user_id, al.details, u.name, u.email FROM audit_log al
         JOIN users u ON u.id = al.user_id
         WHERE al.action = 'password_reset_token' AND al.details LIKE ?
         ORDER BY al.created_at DESC LIMIT 1"
    );
    $stmt->execute(array($token . '%'));
    $record = $stmt->fetch();

    $tokenValid = false;
    $tokenUser = null;

    if ($record) {
        $parts = explode('|', $record['details']);
        $storedToken = $parts[0] ?? '';
        $expiresAt = $parts[1] ?? '';

        if ($storedToken === $token && $expiresAt > date('Y-m-d H:i:s')) {
            $tokenValid = true;
            $tokenUser = $record;
        }
    }

    if (!$tokenValid) {
        $error = 'Link inválido ou expirado. Solicite um novo.';
        $step = 'email';
    }

    // Processar nova senha
    if ($tokenValid && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validate_csrf()) { $error = 'Token de segurança inválido.'; }
        else {
            $newPass = $_POST['password'] ?? '';
            $newPass2 = $_POST['password2'] ?? '';

            if (strlen($newPass) < 6) {
                $error = 'Senha deve ter pelo menos 6 caracteres.';
            } elseif ($newPass !== $newPass2) {
                $error = 'As senhas não conferem.';
            } else {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute(array($hash, $tokenUser['user_id']));

                // Invalidar token (apagar)
                db()->prepare("DELETE FROM audit_log WHERE action = 'password_reset_token' AND user_id = ?")
                    ->execute(array($tokenUser['user_id']));

                audit_log('password_reset_self', 'user', (int)$tokenUser['user_id']);
                $success = true;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Redefinir Senha — <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Open Sans', system-ui, sans-serif;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #052228 0%, #0b2f36 40%, #173d46 100%);
            padding: 1rem;
        }
        .container { width: 100%; max-width: 420px; }
        .brand { text-align: center; margin-bottom: 2rem; }
        .brand-logo {
            width: 60px; height: 60px; background: #d7ab90; border-radius: 16px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 1.5rem; font-weight: 800; color: #052228; margin-bottom: .75rem;
        }
        .brand h1 { font-size: 1.5rem; font-weight: 800; color: #fff; }
        .brand p { color: #d7ab90; font-size: .85rem; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase; margin-top: .25rem; }
        .card { background: #fff; border-radius: 24px; padding: 2rem; box-shadow: 0 20px 60px rgba(0,0,0,.3); }
        .card h2 { font-size: 1.1rem; font-weight: 700; color: #052228; margin-bottom: .25rem; }
        .card .subtitle { font-size: .82rem; color: #6b7280; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: .82rem; font-weight: 600; color: #052228; margin-bottom: .3rem; }
        .form-input {
            width: 100%; padding: .7rem 1rem; font-family: inherit; font-size: .88rem;
            color: #0f1c20; background: #f9fafb; border: 1.5px solid #e5e7eb;
            border-radius: 12px; outline: none; transition: .22s ease;
        }
        .form-input:focus { border-color: #d7ab90; box-shadow: 0 0 0 3px rgba(215,171,144,.2); background: #fff; }
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
        .success-box { text-align: center; padding: 1.5rem 0; }
        .success-box .check {
            width: 64px; height: 64px; background: #ecfdf5; border-radius: 50%;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 2rem; margin-bottom: 1rem;
        }
        .success-box h2 { color: #059669; font-size: 1.1rem; margin-bottom: .5rem; }
        .success-box p { color: #6b7280; font-size: .88rem; }
        .info-box {
            background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 12px;
            padding: 1rem; font-size: .82rem; color: #0284c7; margin: 1rem 0;
        }
        .info-box code {
            display: block; margin-top: .5rem; padding: .5rem; background: #e0f2fe;
            border-radius: 8px; word-break: break-all; font-size: .78rem; color: #052228;
        }
        .link { text-align: center; margin-top: 1.25rem; }
        .link a { color: rgba(255,255,255,.6); font-size: .82rem; text-decoration: none; }
        .link a:hover { color: #d7ab90; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
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
                    <h2>Senha redefinida!</h2>
                    <p>Sua nova senha foi salva. Você já pode fazer login.</p>
                    <a href="<?= url('auth/login.php') ?>" class="btn" style="display:inline-block;text-decoration:none;text-align:center;margin-top:1rem;">
                        Ir para o Login →
                    </a>
                </div>

            <?php elseif ($step === 'token_gerado'): ?>
                <h2>Link de redefinição gerado</h2>
                <p class="subtitle">Envie este link para o colaborador redefinir a senha.</p>

                <?php if ($resetLink): ?>
                    <div class="info-box">
                        <strong>Link de redefinição (válido por 1 hora):</strong>
                        <code id="resetLink"><?= e($resetLink) ?></code>
                    </div>
                    <button onclick="copyLink()" class="btn" style="background:#059669;">📋 Copiar link</button>
                    <p style="font-size:.75rem;color:#6b7280;margin-top:.75rem;text-align:center;">
                        Envie este link pelo WhatsApp para o colaborador. Ele poderá criar uma nova senha.
                    </p>
                    <script>
                    function copyLink() {
                        var text = document.getElementById('resetLink').textContent;
                        if (navigator.clipboard) { navigator.clipboard.writeText(text); }
                        else {
                            var ta = document.createElement('textarea');
                            ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
                            document.body.appendChild(ta); ta.select();
                            document.execCommand('copy'); document.body.removeChild(ta);
                        }
                        alert('Link copiado!');
                    }
                    </script>
                <?php else: ?>
                    <div class="info-box">
                        Se o e-mail informado estiver cadastrado, o link de redefinição foi gerado.
                        Contate o administrador.
                    </div>
                <?php endif; ?>

            <?php elseif ($step === 'reset' && $tokenValid): ?>
                <h2>Nova senha</h2>
                <p class="subtitle">Olá, <?= e($tokenUser['name']) ?>! Defina sua nova senha.</p>

                <?php if ($error): ?>
                    <div class="error-msg">✕ <?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <label class="form-label">Nova senha *</label>
                        <input type="password" name="password" class="form-input" required placeholder="Mínimo 6 caracteres" minlength="6">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirmar nova senha *</label>
                        <input type="password" name="password2" class="form-input" required placeholder="Repita a senha">
                    </div>
                    <button type="submit" class="btn">Redefinir Senha</button>
                </form>

            <?php else: ?>
                <h2>Esqueci minha senha</h2>
                <p class="subtitle">Informe seu e-mail para gerar o link de redefinição.</p>

                <?php if ($error): ?>
                    <div class="error-msg">✕ <?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST">
                    <?= csrf_input() ?>
                    <div class="form-group">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-input" required
                               placeholder="seu@email.com" value="<?= e($_POST['email'] ?? '') ?>" autofocus>
                    </div>
                    <button type="submit" class="btn">Gerar link de redefinição</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="link">
            <a href="<?= url('auth/login.php') ?>">← Voltar para o login</a>
        </div>
    </div>
</body>
</html>
