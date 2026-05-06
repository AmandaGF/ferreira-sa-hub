<?php
/**
 * Atalho do Hub para a página de boas-vindas da própria colaboradora.
 *
 * Quando a colaboradora já está logada no Hub, esse arquivo:
 *   1. Verifica se ela tem cadastro de onboarding ativo (match por email institucional)
 *   2. Se sim: cria a session de auth da página pública e redireciona pra lá
 *      (pula a tela de login com nome + data de nascimento)
 *   3. Se não: mostra mensagem explicativa
 *
 * Acesso pelo menu lateral "👋 Boas-Vindas".
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$user = current_user();

// Procura cadastro de onboarding pelo email institucional
$reg = null;
try {
    $st = $pdo->prepare("SELECT id, token, nome_completo
                         FROM colaboradores_onboarding
                         WHERE email_institucional = ? AND status != 'arquivado'
                         ORDER BY created_at DESC LIMIT 1");
    $st->execute(array($user['email']));
    $reg = $st->fetch();
} catch (Exception $e) { $reg = null; }

if ($reg) {
    // Cria session de auth da pagina publica e redireciona
    @session_start();
    $sessKey = 'onb_auth_' . $reg['token'];
    $_SESSION[$sessKey] = true;
    // Marca acesso
    try {
        $pdo->prepare("UPDATE colaboradores_onboarding
                       SET ultimo_acesso_em = NOW(),
                           status = IF(status='pendente','ativo',status)
                       WHERE id = ?")
            ->execute(array($reg['id']));
    } catch (Exception $e) {}

    // Monta URL absoluta da pagina publica
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    // Calcula base path (sem '/modules/onboarding')
    $scriptDir = rtrim(dirname($_SERVER['PHP_SELF']), '/'); // /conecta/modules/onboarding
    $basePath = preg_replace('#/modules/onboarding/?$#', '', $scriptDir); // /conecta
    header('Location: ' . $baseUrl . $basePath . '/publico/onboarding/?token=' . urlencode($reg['token']));
    exit;
}

// Sem onboarding vinculado — mostra pagina explicativa
$pageTitle = 'Página de Boas-Vindas';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<div class="card" style="max-width:680px;margin:2rem auto;">
    <div class="card-body" style="text-align:center;padding:3rem 1.5rem;">
        <div style="font-size:4rem;margin-bottom:1rem;">👋</div>
        <h2 style="color:#052228;margin-bottom:1rem;">Sem página de boas-vindas vinculada</h2>
        <p style="color:#6b7280;line-height:1.6;">
            Não encontramos um cadastro de onboarding para o seu e-mail institucional
            <strong><?= e($user['email']) ?></strong>.
        </p>
        <p style="color:#6b7280;line-height:1.6;margin-top:.6rem;">
            Se você é uma nova colaboradora ou colaborador, fale com a <strong>Dra. Amanda Ferreira</strong>
            ou com o <strong>Dr. Luiz Eduardo de Sá</strong> para que cadastrem você.
        </p>
        <a href="<?= module_url('painel') ?>" class="btn btn-primary" style="margin-top:1.5rem;">← Voltar ao painel</a>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
