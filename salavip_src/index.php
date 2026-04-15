<?php
/**
 * Central VIP F&S — Login
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Já logado? Redireciona para dashboard
if (!empty($_SESSION['salavip_user_id'])) {
    header('Location: ' . SALAVIP_BASE_URL . '/pages/dashboard.php');
    exit;
}

$erro = '';
$sucesso = '';

// Mensagem de sessão expirada
if (isset($_GET['expirado'])) {
    $erro = 'Sua sessão expirou. Faça login novamente.';
}

// Flash messages
$flash = sv_flash_get();
if ($flash) {
    if ($flash['type'] === 'success') {
        $sucesso = $flash['msg'];
    } else {
        $erro = $flash['msg'];
    }
}

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar CSRF
    $csrf = $_POST['csrf_token'] ?? '';
    if (!salavip_validar_csrf($csrf)) {
        $erro = 'Token de segurança inválido. Recarregue a página.';
    } else {
        $cpf_raw = preg_replace('/\D/', '', $_POST['cpf'] ?? '');
        $senha   = $_POST['senha'] ?? '';

        if (empty($cpf_raw) || empty($senha)) {
            $erro = 'Preencha todos os campos.';
        } else {
            $pdo  = sv_db();
            // Buscar por CPF: tanto formatado quanto só dígitos
            $cpf_fmt = '';
            if (strlen($cpf_raw) === 11) {
                $cpf_fmt = substr($cpf_raw,0,3).'.'.substr($cpf_raw,3,3).'.'.substr($cpf_raw,6,3).'-'.substr($cpf_raw,9,2);
            }
            $stmt = $pdo->prepare('SELECT * FROM salavip_usuarios WHERE cpf = ? OR cpf = ? LIMIT 1');
            $stmt->execute([$cpf_raw, $cpf_fmt]);
            $user = $stmt->fetch();

            if (!$user) {
                $erro = 'CPF ou senha incorretos.';
            } elseif ((int)$user['ativo'] === 0) {
                $erro = 'Conta não ativada. Verifique seu e-mail.';
            } elseif (!empty($user['bloqueado_ate']) && strtotime($user['bloqueado_ate']) > time()) {
                $minutos = ceil((strtotime($user['bloqueado_ate']) - time()) / 60);
                $erro = 'Conta bloqueada. Tente novamente em ' . $minutos . ' minuto' . ($minutos > 1 ? 's' : '') . '.';
            } elseif (!password_verify($senha, $user['senha_hash'])) {
                // Incrementar tentativas
                $tentativas = (int)$user['tentativas_login'] + 1;
                if ($tentativas >= 5) {
                    $pdo->prepare('UPDATE salavip_usuarios SET tentativas_login = 0, bloqueado_ate = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?')
                        ->execute([$user['id']]);
                    $erro = 'Conta bloqueada por excesso de tentativas. Tente novamente em 15 minutos.';
                } else {
                    $pdo->prepare('UPDATE salavip_usuarios SET tentativas_login = ? WHERE id = ?')
                        ->execute([$tentativas, $user['id']]);
                    $erro = 'CPF ou senha incorretos.';
                }
            } else {
                // Login bem-sucedido
                $pdo->prepare('UPDATE salavip_usuarios SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_acesso = NOW() WHERE id = ?')
                    ->execute([$user['id']]);

                $_SESSION['salavip_user_id']           = (int)$user['id'];
                $_SESSION['salavip_cliente_id']         = (int)$user['cliente_id'];
                $_SESSION['salavip_nome_exibicao']      = $user['nome_exibicao'];
                $_SESSION['salavip_cpf']                = $user['cpf'];
                $_SESSION['salavip_email']              = $user['email'];
                $_SESSION['salavip_logado_em']           = date('Y-m-d H:i:s');
                $_SESSION['salavip_ultimo_atividade']   = time();

                salavip_log_acesso($pdo, (int)$user['id'], 'login');

                header('Location: ' . SALAVIP_BASE_URL . '/pages/dashboard.php');
                exit;
            }
        }
    }
}

// Gerar CSRF para o form
$csrf_token = salavip_gerar_csrf();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central VIP — Ferreira &amp; Sá Advocacia</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Lato:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<div class="login-container">
    <div class="login-card">

        <div class="login-logo">
            <img src="assets/img/logo.png" alt="Ferreira &amp; Sá Advocacia" onerror="this.style.display='none'">
        </div>

        <h1 class="login-title">Central VIP</h1>
        <p class="login-subtitle">Portal Exclusivo do Cliente</p>

        <?php if ($erro): ?>
            <div class="error-msg"><?= sv_e($erro) ?></div>
        <?php endif; ?>

        <?php if ($sucesso): ?>
            <div class="success-msg"><?= sv_e($sucesso) ?></div>
        <?php endif; ?>

        <form id="login-form" method="POST" action="">
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
                    autocomplete="username"
                    inputmode="numeric"
                    maxlength="14"
                    required
                    value="<?= sv_e(isset($_POST['cpf']) ? $_POST['cpf'] : '') ?>"
                >
            </div>

            <div class="form-group">
                <label class="form-label" for="senha">Senha</label>
                <input
                    type="password"
                    id="senha"
                    name="senha"
                    class="form-input"
                    placeholder="Digite sua senha"
                    autocomplete="current-password"
                    required
                >
                <button type="button" class="toggle-password" aria-label="Mostrar senha">&#x1F441;</button>
            </div>

            <button type="submit" class="btn-login">Entrar</button>
        </form>

        <a href="pages/recuperar-senha.php" class="link-forgot">Esqueci minha senha</a>

    </div>
</div>

<script src="assets/js/app.js"></script>
</body>
</html>
