<?php
/**
 * Central VIP F&S — Recuperacao de Senha
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Ja logado? Redireciona para dashboard
if (!empty($_SESSION['salavip_user_id'])) {
    header('Location: ' . SALAVIP_BASE_URL . '/pages/dashboard.php');
    exit;
}

$pdo   = sv_db();
$etapa = $_GET['etapa'] ?? '';
$erro  = '';
$sucesso = '';

// =============================================
// ETAPA 2: Redefinir senha (com token)
// =============================================
if ($etapa === 'redefinir') {
    $token = trim($_GET['token'] ?? '');

    if (empty($token)) {
        $tokenValido = false;
        $erro = 'Link invalido ou expirado.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, nome_exibicao FROM salavip_usuarios WHERE token_ativacao = ? AND token_expira > NOW() AND ativo = 1 LIMIT 1'
        );
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        $tokenValido = (bool) $user;

        if (!$tokenValido) {
            $erro = 'Link invalido ou expirado.';
        }
    }

    // Processar POST (nova senha)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValido) {
        $csrf = $_POST['csrf_token'] ?? '';
        if (!salavip_validar_csrf($csrf)) {
            $erro = 'Token de seguranca invalido. Recarregue a pagina.';
        } else {
            $senha        = $_POST['senha'] ?? '';
            $senha_confirm = $_POST['senha_confirm'] ?? '';

            if (empty($senha) || empty($senha_confirm)) {
                $erro = 'Preencha todos os campos.';
            } elseif ($senha !== $senha_confirm) {
                $erro = 'As senhas nao coincidem.';
            } elseif (strlen($senha) < 8) {
                $erro = 'A senha deve ter no minimo 8 caracteres.';
            } elseif (!preg_match('/[A-Z]/', $senha)) {
                $erro = 'A senha deve conter pelo menos uma letra maiuscula.';
            } elseif (!preg_match('/[0-9]/', $senha)) {
                $erro = 'A senha deve conter pelo menos um numero.';
            } else {
                $hash = password_hash($senha, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare(
                    'UPDATE salavip_usuarios SET senha_hash = ?, token_ativacao = NULL, token_expira = NULL WHERE id = ?'
                );
                $stmt->execute([$hash, $user['id']]);

                sv_flash('success', 'Senha redefinida com sucesso! Faca login.');
                header('Location: ' . SALAVIP_BASE_URL . '/index.php');
                exit;
            }
        }
    }

    $csrf_token = salavip_gerar_csrf();
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Redefinir Senha — Central VIP</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Lato:wght@300;400;600;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body>

    <div class="login-container">
        <div class="login-card">

            <div class="login-logo">
                <img src="assets/img/logo.png" alt="Ferreira &amp; Sa Advocacia" onerror="this.style.display='none'">
            </div>

            <h1 class="login-title">Central VIP</h1>
            <p class="login-subtitle">Redefinir Senha</p>

            <?php if ($erro): ?>
                <div class="error-msg"><?= sv_e($erro) ?></div>
            <?php endif; ?>

            <?php if (!$tokenValido): ?>
                <p style="text-align:center;color:#94a3b8;margin-top:1rem;">
                    O link de recuperacao e invalido ou ja expirou.<br>
                    Solicite um novo link na pagina de recuperacao.
                </p>
                <a href="<?= sv_e(SALAVIP_BASE_URL) ?>/recuperar_senha.php" class="link-forgot" style="margin-top:1.5rem;">Solicitar novo link</a>
            <?php else: ?>
                <form method="POST" action="<?= sv_e(SALAVIP_BASE_URL) ?>/recuperar_senha.php?etapa=redefinir&token=<?= sv_e($token) ?>">
                    <input type="hidden" name="csrf_token" value="<?= sv_e($csrf_token) ?>">

                    <div class="form-group">
                        <label class="form-label" for="senha">Nova Senha</label>
                        <input
                            type="password"
                            id="senha"
                            name="senha"
                            class="form-input"
                            placeholder="Minimo 8 caracteres"
                            autocomplete="new-password"
                            required
                            minlength="8"
                        >
                        <button type="button" class="toggle-password" aria-label="Mostrar senha">&#x1F441;</button>
                        <div class="strength-bar" id="strength-bar">
                            <div class="strength-fill" id="strength-fill"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="senha_confirm">Confirmar Senha</label>
                        <input
                            type="password"
                            id="senha_confirm"
                            name="senha_confirm"
                            class="form-input"
                            placeholder="Repita a senha"
                            autocomplete="new-password"
                            required
                            minlength="8"
                        >
                    </div>

                    <button type="submit" class="btn-login">Redefinir Senha</button>
                </form>

                <a href="<?= sv_e(SALAVIP_BASE_URL) ?>/index.php" class="link-forgot">Voltar ao login</a>
            <?php endif; ?>

        </div>
    </div>

    <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// =============================================
// ETAPA 1: Solicitar recuperacao (CPF + email)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!salavip_validar_csrf($csrf)) {
        $erro = 'Token de seguranca invalido. Recarregue a pagina.';
    } else {
        $cpf_raw = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $email   = trim($_POST['email'] ?? '');

        if (empty($cpf_raw) || empty($email)) {
            $erro = 'Preencha todos os campos.';
        } else {
            // Buscar usuario
            $stmt = $pdo->prepare('SELECT id, nome_exibicao, email FROM salavip_usuarios WHERE cpf = ? AND email = ? AND ativo = 1 LIMIT 1');
            $stmt->execute([$cpf_raw, $email]);
            $user = $stmt->fetch();

            if ($user) {
                // Gerar token e salvar
                $token  = salavip_gerar_token();
                $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $stmt = $pdo->prepare('UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ? WHERE id = ?');
                $stmt->execute([$token, $expira, $user['id']]);

                // Montar e enviar e-mail
                $link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                      . SALAVIP_BASE_URL . '/recuperar_senha.php?etapa=redefinir&token=' . urlencode($token);

                $corpo = '
                <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#0a1628;color:#e2e8f0;padding:2rem;border-radius:12px;">
                    <h2 style="color:#c9a94e;font-family:Georgia,serif;">Ferreira &amp; Sa Advocacia</h2>
                    <p>Ola, <strong>' . sv_e($user['nome_exibicao']) . '</strong>!</p>
                    <p>Recebemos uma solicitacao para redefinir sua senha na Central VIP.</p>
                    <p style="text-align:center;margin:2rem 0;">
                        <a href="' . sv_e($link) . '" style="background:#c9a94e;color:#0a1628;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:700;">Redefinir Senha</a>
                    </p>
                    <p style="font-size:.85rem;color:#94a3b8;">Este link expira em 1 hora. Se voce nao solicitou, ignore este e-mail.</p>
                </div>';

                sv_enviar_email($user['email'], 'Redefinir Senha — Central VIP', $corpo);
            }

            // Mensagem neutra (sempre a mesma, independente de encontrar o usuario)
            $sucesso = 'Se os dados corresponderem, voce recebera as instrucoes por e-mail.';
        }
    }
}

$csrf_token = salavip_gerar_csrf();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha — Central VIP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Lato:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <div class="login-logo">
            <img src="assets/img/logo.png" alt="Ferreira &amp; Sa Advocacia" onerror="this.style.display='none'">
        </div>

        <h1 class="login-title">Central VIP</h1>
        <p class="login-subtitle">Recuperar Senha</p>

        <?php if ($erro): ?>
            <div class="error-msg"><?= sv_e($erro) ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="success-msg"><?= sv_e($sucesso) ?></div>
        <?php endif; ?>

        <form method="POST" action="<?= sv_e(SALAVIP_BASE_URL) ?>/recuperar_senha.php">
            <input type="hidden" name="csrf_token" value="<?= sv_e($csrf_token) ?>">

            <div class="form-group">
                <label class="form-label" for="cpf">CPF</label>
                <input
                    type="text"
                    id="cpf"
                    name="cpf"
                    class="form-input"
                    placeholder="000.000.000-00"
                    data-mask="cpf"
                    inputmode="numeric"
                    maxlength="14"
                    required
                    value="<?= sv_e($_POST['cpf'] ?? '') ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="email">E-mail</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="form-input"
                    placeholder="seu@email.com"
                    autocomplete="email"
                    required
                    value="<?= sv_e($_POST['email'] ?? '') ?>"
                >
            </div>

            <button type="submit" class="btn-login">Enviar Instrucoes</button>
        </form>

        <a href="<?= sv_e(SALAVIP_BASE_URL) ?>/index.php" class="link-forgot">Voltar ao login</a>

    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
