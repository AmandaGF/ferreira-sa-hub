<?php
/**
 * Ferreira & Sá Hub — Página de Login
 */

require_once __DIR__ . '/../core/auth.php';

// Página inicial conforme permissões: usuários com acesso restrito (ex: Simone
// só com prev) caem direto na primeira tela que conseguem ver, evitando loop
// de "Acesso Negado".
function _landing_module() {
    $candidatos = array('painel', 'dashboard', 'prev', 'agenda', 'whatsapp_21');
    foreach ($candidatos as $mod) {
        if (can_access($mod)) return $mod;
    }
    return 'painel'; // fallback (require_login decidirá)
}

// Se já está logado, redireciona
if (is_logged_in()) {
    redirect(url('modules/' . _landing_module() . '/'));
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!validate_csrf()) {
        $error = 'Token de segurança inválido. Tente novamente.';
    } elseif (empty($email) || empty($password)) {
        $error = 'Preencha e-mail e senha.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'E-mail inválido.';
    } elseif (is_login_locked($email)) {
        $error = 'Muitas tentativas. Aguarde ' . LOGIN_LOCKOUT_MINUTES . ' minutos.';
    } else {
        // Verificar se conta existe mas está pendente
        $checkStmt = db()->prepare('SELECT is_active FROM users WHERE email = ?');
        $checkStmt->execute(array($email));
        $checkUser = $checkStmt->fetch();

        if ($checkUser && !$checkUser['is_active']) {
            $error = 'Sua conta está aguardando aprovação do administrador.';
        } else {
            $user = authenticate($email, $password);

            if ($user) {
                login_user($user);
                // Limpa flashes de erro antigos (ex: "Faça login..." que ficou da tentativa anterior)
                unset($_SESSION['flash']['error'], $_SESSION['flash']['warning']);
                flash_set('success', 'Bem-vindo(a), ' . $user['name'] . '!');
                redirect(url('modules/' . _landing_module() . '/'));
            } else {
                $error = 'E-mail ou senha incorretos.';
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
    <title>Login — <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Open Sans', system-ui, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #052228 0%, #0b2f36 40%, #173d46 100%);
            padding: 1rem;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
        }

        .login-brand {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-brand-logo {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            margin-bottom: .75rem;
            object-fit: cover;
        }

        .login-brand h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #fff;
        }

        .login-brand p {
            color: #d7ab90;
            font-size: .85rem;
            font-weight: 600;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-top: .25rem;
        }

        .login-card {
            background: #fff;
            border-radius: 24px;
            padding: 2.5rem 2rem;
            box-shadow: 0 20px 60px rgba(0,0,0,.3);
        }

        .login-card h2 {
            font-size: 1.15rem;
            font-weight: 700;
            color: #052228;
            margin-bottom: .35rem;
        }

        .login-card .subtitle {
            font-size: .85rem;
            color: #6b7280;
            margin-bottom: 1.75rem;
        }

        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-label {
            display: block;
            font-size: .82rem;
            font-weight: 600;
            color: #052228;
            margin-bottom: .35rem;
        }

        .form-input {
            width: 100%;
            padding: .75rem 1rem;
            font-family: inherit;
            font-size: .9rem;
            color: #0f1c20;
            background: #f9fafb;
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            outline: none;
            transition: border-color .22s ease, box-shadow .22s ease;
        }

        .form-input:focus {
            border-color: #d7ab90;
            box-shadow: 0 0 0 3px rgba(215,171,144,.2);
            background: #fff;
        }

        .form-input::placeholder { color: #9ca3af; }

        .btn-login {
            width: 100%;
            padding: .85rem;
            font-family: inherit;
            font-size: .95rem;
            font-weight: 700;
            color: #fff;
            background: linear-gradient(135deg, #052228, #173d46);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            transition: all .22s ease;
            margin-top: .5rem;
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(5,34,40,.4);
        }

        .btn-login:active { transform: translateY(0); }

        .error-msg {
            background: #fef2f2;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 12px;
            padding: .7rem 1rem;
            font-size: .85rem;
            font-weight: 500;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            color: rgba(255,255,255,.4);
            font-size: .75rem;
        }

        @media (max-width: 480px) {
            .login-card { padding: 2rem 1.5rem; border-radius: 20px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-brand">
            <img src="<?= url('assets/img/logo-sidebar.png') ?>" alt="Logo" class="login-brand-logo">
            <h1>Ferreira &amp; Sá</h1>
            <p>Hub</p>
        </div>

        <div class="login-card">
            <h2>Entrar no sistema</h2>
            <p class="subtitle">Use seu e-mail corporativo para acessar</p>

            <?php if ($error): ?>
                <div class="error-msg">✕ <?= e($error) ?></div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <?= csrf_input() ?>

                <div class="form-group">
                    <label class="form-label" for="email">E-mail</label>
                    <input type="email" id="email" name="email" class="form-input"
                           placeholder="seu@ferreiraesa.com.br"
                           value="<?= e($_POST['email'] ?? '') ?>"
                           required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Senha</label>
                    <input type="password" id="password" name="password" class="form-input"
                           placeholder="••••••••"
                           required>
                </div>

                <button type="submit" class="btn-login">Entrar</button>

                <div style="text-align:right;margin-top:.75rem;">
                    <a href="<?= url('auth/esqueci_senha.php') ?>" style="color:#6b7280;font-size:.78rem;text-decoration:none;">
                        Esqueci minha senha
                    </a>
                </div>
            </form>

            <div style="text-align:center;margin-top:1.25rem;padding-top:1rem;border-top:1px solid #e5e7eb;">
                <a href="<?= url('auth/registro.php') ?>" style="color:#d7ab90;font-size:.85rem;font-weight:600;text-decoration:none;">
                    Não tem conta? Criar conta →
                </a>
            </div>
        </div>

        <div class="login-footer">
            &copy; <?= date('Y') ?> Ferreira &amp; Sá Advocacia
        </div>
    </div>
</body>
</html>
