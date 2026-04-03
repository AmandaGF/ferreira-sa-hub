<?php
/**
 * Descadastro de Newsletter — Página pública
 * URL: /conecta/publico/descadastro.php?email=xxx
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';

$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$confirmado = isset($_GET['confirmar']);
$msg = '';
$sucesso = false;

if ($email && $confirmado) {
    try {
        $pdo = db();
        // Buscar client_id
        $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ? LIMIT 1");
        $stmt->execute(array($email));
        $client = $stmt->fetch();
        $clientId = $client ? (int)$client['id'] : null;

        // Registrar descadastro
        $pdo->prepare("INSERT IGNORE INTO newsletter_descadastros (client_id, email, motivo) VALUES (?, ?, ?)")
            ->execute(array($clientId, $email, 'Solicitado pelo cliente'));

        $sucesso = true;
        $msg = 'Voce foi descadastrado com sucesso. Nao recebera mais nossos e-mails.';
    } catch (Exception $e) {
        $msg = 'Ocorreu um erro. Tente novamente ou entre em contato conosco.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancelar inscricao — Ferreira &amp; Sa Advocacia</title>
    <style>
        body { font-family: Calibri, sans-serif; margin: 0; padding: 0; background: #f4f4f4; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .box { background: #fff; border-radius: 16px; padding: 2.5rem; max-width: 450px; width: 90%; text-align: center; box-shadow: 0 4px 24px rgba(0,0,0,.08); }
        .logo { color: #052228; font-size: 1.2rem; font-weight: 800; margin-bottom: 1.5rem; }
        .logo span { color: #B87333; }
        h2 { color: #052228; margin: 0 0 .5rem; font-size: 1.1rem; }
        p { color: #6b7280; line-height: 1.6; font-size: .9rem; }
        .btn { display: inline-block; padding: 10px 28px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: .9rem; margin-top: 1rem; }
        .btn-confirm { background: #dc2626; color: #fff; }
        .btn-back { background: #052228; color: #fff; }
        .ok { color: #059669; font-weight: 600; }
    </style>
</head>
<body>
<div class="box">
    <div class="logo">FERREIRA &amp; <span>SA</span></div>

    <?php if ($sucesso): ?>
        <h2 class="ok">Descadastro confirmado</h2>
        <p><?= $msg ?></p>
        <a href="https://ferreiraesa.com.br" class="btn btn-back">Ir para o site</a>

    <?php elseif ($email && !$confirmado): ?>
        <h2>Cancelar inscricao</h2>
        <p>Deseja realmente deixar de receber nossos e-mails?</p>
        <p style="font-size:.8rem;color:#94a3b8;"><?= htmlspecialchars($email) ?></p>
        <a href="?email=<?= rawurlencode($email) ?>&confirmar=1" class="btn btn-confirm">Sim, cancelar inscricao</a>
        <br>
        <a href="https://ferreiraesa.com.br" style="font-size:.8rem;color:#94a3b8;display:inline-block;margin-top:1rem;">Nao, quero continuar recebendo</a>

    <?php else: ?>
        <h2>Link invalido</h2>
        <p>Este link de descadastro nao e valido.</p>
        <a href="https://ferreiraesa.com.br" class="btn btn-back">Ir para o site</a>
    <?php endif; ?>

    <p style="font-size:.7rem;color:#ccc;margin-top:2rem;">Ferreira &amp; Sa Advocacia Especializada</p>
</div>
</body>
</html>
