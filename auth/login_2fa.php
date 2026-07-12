<?php
/**
 * Ferreira & Sá Hub — Login 2FA (etapa 2)
 *
 * Tela acessada APÓS validar email+senha em login.php, quando o user tem 2FA
 * ativo. Pede o código de 6 dígitos do app autenticador. Se OK, completa o
 * login. Se expirou (>5min) ou pending_2fa não existe, devolve pro login.
 */

require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/functions_totp.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Já logado? Vai pro hub
if (is_logged_in()) {
    redirect(url('modules/painel/'));
}

// Tem pending_2fa válido?
$pending = isset($_SESSION['pending_2fa']) ? $_SESSION['pending_2fa'] : null;
if (!$pending || empty($pending['user_id']) || empty($pending['expira_em'])) {
    flash_set('error', 'Sessão de login expirou. Faça login de novo.');
    redirect(url('auth/login.php'));
}
if (time() > $pending['expira_em']) {
    unset($_SESSION['pending_2fa']);
    flash_set('error', 'Você levou mais de 5 minutos pra digitar o código. Faça login de novo.');
    redirect(url('auth/login.php'));
}

$uid = (int)$pending['user_id'];
$pdo = db();
$ust = $pdo->prepare("SELECT id, name, email, role, is_active FROM users WHERE id = ?");
$ust->execute(array($uid));
$user = $ust->fetch();
if (!$user || !$user['is_active']) {
    unset($_SESSION['pending_2fa']);
    flash_set('error', 'Conta inválida. Faça login de novo.');
    redirect(url('auth/login.php'));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Amanda 12/07/2026: CSRF removido do 2FA. Autenticacao de fato aqui e
    // o codigo TOTP do app (muda a cada 30s, ja e' one-time). pending_2fa na
    // sessao ainda controla o fluxo. CSRF stale (PWA cache/cookie perdido)
    // travava usuario mesmo com senha e codigo corretos.
    unset($_SESSION[CSRF_TOKEN_NAME]);
    if (true) {
        $codigo = preg_replace('/\D/', '', $_POST['codigo'] ?? '');
        if (strlen($codigo) !== 6) {
            $error = 'Digite os 6 dígitos.';
        } else {
            // Busca a chave secreta e valida
            $st = $pdo->prepare("SELECT secret_encrypted FROM users_2fa WHERE user_id = ?");
            $st->execute(array($uid));
            $row = $st->fetch();
            if (!$row) {
                // 2FA foi desativado entre o login.php e aqui (corrida) — completa login normalmente
                unset($_SESSION['pending_2fa']);
                login_user($user);
                redirect(url('modules/painel/'));
            }
            $secret = totp_decrypt($row['secret_encrypted']);
            if (!totp_validar($secret, $codigo)) {
                $error = 'Código inválido. Confirme se está digitando o código MAIS RECENTE do app (muda a cada 30s).';
                audit_log('2fa_falhou', 'users_2fa', $uid, 'user=' . $user['name'] . ' codigo_tentado=' . substr($codigo, 0, 2) . '****');
            } else {
                // Sucesso! Limpa pending, completa login.
                unset($_SESSION['pending_2fa']);
                try { $pdo->prepare("UPDATE users_2fa SET last_used_at = NOW() WHERE user_id = ?")->execute(array($uid)); } catch (Exception $e) {}
                login_user($user);
                audit_log('2fa_login_ok', 'users_2fa', $uid, 'user=' . $user['name']);
                unset($_SESSION['flash']['error'], $_SESSION['flash']['warning']);
                flash_set('success', 'Bem-vindo(a), ' . $user['name'] . '!');
                redirect(url('modules/painel/'));
            }
        }
    }
}

$segRestantes = max(0, $pending['expira_em'] - time());
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Verificação 2FA — <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Open Sans', system-ui, sans-serif; min-height:100vh; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg, #052228 0%, #0b2f36 40%, #173d46 100%); padding:1rem; }
        .container { width:100%; max-width:420px; }
        .brand { text-align:center; margin-bottom:2rem; }
        .brand-title { color:#fff; font-size:1.5rem; font-weight:800; letter-spacing:.05rem; }
        .brand-sub { color:rgba(255,255,255,.6); font-size:.82rem; margin-top:.25rem; }
        .card { background:#fff; border-radius:16px; padding:2rem 1.75rem; box-shadow:0 20px 60px rgba(0,0,0,.3); }
        .card-icon { text-align:center; font-size:2.8rem; margin-bottom:.5rem; }
        .card-title { text-align:center; font-size:1.1rem; font-weight:700; color:#052228; margin-bottom:.3rem; }
        .card-sub { text-align:center; font-size:.85rem; color:#6b7280; margin-bottom:1.5rem; }
        .user-info { background:#f9fafb; border-radius:10px; padding:.6rem .85rem; margin-bottom:1.25rem; font-size:.82rem; color:#374151; text-align:center; }
        .user-info strong { color:#052228; }
        label { display:block; font-size:.78rem; font-weight:600; color:#374151; margin-bottom:.4rem; }
        input[name="codigo"] { width:100%; font-family:'Courier New', monospace; font-size:1.8rem; text-align:center; letter-spacing:.6rem; padding:.85rem; border:2px solid #e5e7eb; border-radius:10px; outline:none; }
        input[name="codigo"]:focus { border-color:#B87333; }
        .btn-submit { width:100%; padding:.85rem; background:linear-gradient(135deg, #052228, #173d46); color:#fff; border:none; border-radius:10px; font-size:.95rem; font-weight:700; cursor:pointer; margin-top:1rem; }
        .btn-submit:hover { opacity:.95; }
        .error { background:#fef2f2; color:#b91c1c; border:1px solid #fca5a5; padding:.6rem .85rem; border-radius:8px; font-size:.8rem; margin-bottom:1rem; }
        .timer { text-align:center; font-size:.72rem; color:#94a3b8; margin-top:.5rem; }
        .footer-links { text-align:center; margin-top:1rem; font-size:.78rem; }
        .footer-links a { color:#94a3b8; text-decoration:none; }
        .footer-links a:hover { color:#fff; }
    </style>
</head>
<body>
<div class="container">
    <div class="brand">
        <div class="brand-title">🔐 Verificação em 2 etapas</div>
        <div class="brand-sub">Ferreira & Sá Hub</div>
    </div>
    <div class="card">
        <div class="card-icon">📱</div>
        <h2 class="card-title">Digite o código do app</h2>
        <p class="card-sub">Pegue o código de 6 dígitos no seu Google Authenticator (ou Microsoft Authenticator / Authy).</p>

        <div class="user-info">Logando como <strong><?= e($user['name']) ?></strong></div>

        <?php if ($error): ?>
            <div class="error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_input() ?>
            <label for="codigo">Código de 6 dígitos</label>
            <input type="text" id="codigo" name="codigo" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code" inputmode="numeric" placeholder="000000" autofocus>
            <div class="timer">Você tem <strong id="timerVal"><?= $segRestantes ?></strong> segundos pra digitar o código antes da sessão expirar.</div>
            <button type="submit" class="btn-submit">Confirmar e entrar →</button>
        </form>

        <div class="footer-links">
            <a href="<?= url('auth/login.php') ?>">← Voltar ao login</a>
        </div>
    </div>
</div>
<script>
// Timer visual + auto-submit quando preencher 6 digitos
var seg = <?= (int)$segRestantes ?>;
var t = document.getElementById('timerVal');
setInterval(function(){
    if (seg <= 0) { window.location.href = '<?= url('auth/login.php') ?>'; return; }
    seg--; if (t) t.textContent = seg;
}, 1000);

var inp = document.getElementById('codigo');
inp.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '');
    if (this.value.length === 6) this.form.submit();
});
</script>
</body>
</html>
