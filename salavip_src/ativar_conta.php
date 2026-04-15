<?php
/**
 * Central VIP F&S — Ativacao de Conta
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
$erro  = '';
$token = trim($_GET['token'] ?? '');

// Sem token: erro
if (empty($token)) {
    $tokenValido = false;
    $erro = 'Link invalido ou expirado.';
} else {
    // Buscar usuario pelo token
    $stmt = $pdo->prepare(
        'SELECT id, nome_exibicao, email FROM salavip_usuarios WHERE token_ativacao = ? AND token_expira > NOW() AND ativo = 0 LIMIT 1'
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    $tokenValido = (bool) $user;

    if (!$tokenValido) {
        $erro = 'Link invalido ou expirado.';
    }
}

// Processar POST (definir senha)
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
            // Ativar conta
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                'UPDATE salavip_usuarios SET ativo = 1, senha_hash = ?, token_ativacao = NULL, token_expira = NULL WHERE id = ?'
            );
            $stmt->execute([$hash, $user['id']]);

            sv_flash('success', 'Conta ativada! Faca login.');
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
    <title>Ativar Conta — Central VIP</title>
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
        <p class="login-subtitle">Ativacao de Conta</p>

        <?php if ($erro): ?>
            <div class="error-msg"><?= sv_e($erro) ?></div>
        <?php endif; ?>

        <?php if (!$tokenValido): ?>
            <p style="text-align:center;color:#94a3b8;margin-top:1rem;">
                O link de ativacao e invalido ou ja expirou.<br>
                Entre em contato com o escritorio para solicitar um novo link.
            </p>
            <a href="<?= sv_e(SALAVIP_BASE_URL) ?>/index.php" class="link-forgot" style="margin-top:1.5rem;">Voltar ao login</a>
        <?php else: ?>
            <p style="text-align:center;color:#94a3b8;margin-bottom:1.5rem;">
                Bem-vindo(a), <strong style="color:#c9a94e;"><?= sv_e($user['nome_exibicao']) ?></strong>!<br>
                Defina sua senha para acessar a Central VIP.
            </p>

            <form method="POST" action="<?= sv_e(SALAVIP_BASE_URL) ?>/ativar_conta.php?token=<?= sv_e($token) ?>">
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

                <button type="submit" class="btn-login">Ativar Conta</button>
            </form>

            <a href="<?= sv_e(SALAVIP_BASE_URL) ?>/index.php" class="link-forgot">Voltar ao login</a>
        <?php endif; ?>

    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
