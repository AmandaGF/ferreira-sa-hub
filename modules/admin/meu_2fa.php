<?php
/**
 * Ferreira & Sá Hub — Meu 2FA
 *
 * Tela onde cada usuário ativa/desativa o 2FA do PRÓPRIO login no Hub.
 * Fluxo de ativação:
 *   1. Gera chave secreta (160 bits, Base32)
 *   2. Mostra QR code (otpauth://) + chave em texto pra escanear no Google Authenticator
 *   3. User cola o código de 6 dígitos que o app mostrou → confirma
 *   4. Validação OK → salva chave (criptografada) em users_2fa, ativa 2FA
 *
 * Desativação: confirma com código atual + remove linha de users_2fa.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_once __DIR__ . '/../../core/functions_totp.php';

$pdo = db();
totp_ensure_schema($pdo);

$uid = (int)current_user_id();
$user = current_user();
$pageTitle = 'Meu 2FA';

$msgOk = '';
$msgErr = '';

// Estado atual: 2FA já ativo?
$stmt = $pdo->prepare("SELECT secret_encrypted, enabled_at FROM users_2fa WHERE user_id = ?");
$stmt->execute(array($uid));
$row2fa = $stmt->fetch();
$tem2fa = (bool)$row2fa;

// ─── POST: ações ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'ativar') {
        $secret = trim($_POST['secret'] ?? '');
        $codigo = trim($_POST['codigo'] ?? '');

        if (!$secret) {
            $msgErr = 'Chave secreta perdida — reinicie o processo.';
        } elseif (!totp_validar($secret, $codigo)) {
            $msgErr = 'Código inválido. Verifique se o relógio do celular está sincronizado e digite o código mais recente.';
        } else {
            $secEnc = totp_encrypt($secret);
            try {
                $up = $pdo->prepare("INSERT INTO users_2fa (user_id, secret_encrypted, enabled_at) VALUES (?, ?, NOW())
                                     ON DUPLICATE KEY UPDATE secret_encrypted = VALUES(secret_encrypted), enabled_at = NOW()");
                $up->execute(array($uid, $secEnc));
                audit_log('2fa_ativado', 'users_2fa', $uid, 'user=' . $user['name']);
                $msgOk = '✓ 2FA ativado com sucesso! Da próxima vez que você logar no Hub, vai ser pedido o código de 6 dígitos do app.';
                // Refresh do estado
                $row2fa = array('secret_encrypted' => $secEnc, 'enabled_at' => date('Y-m-d H:i:s'));
                $tem2fa = true;
            } catch (Exception $e) {
                $msgErr = 'Erro ao salvar: ' . $e->getMessage();
            }
        }
    } elseif ($acao === 'desativar') {
        $codigo = trim($_POST['codigo'] ?? '');
        if (!$tem2fa) {
            $msgErr = '2FA não está ativo.';
        } else {
            $secret = totp_decrypt($row2fa['secret_encrypted']);
            if (!totp_validar($secret, $codigo)) {
                $msgErr = 'Código inválido. Confirme o código atual do app pra desativar.';
            } else {
                try {
                    $pdo->prepare("DELETE FROM users_2fa WHERE user_id = ?")->execute(array($uid));
                    audit_log('2fa_desativado', 'users_2fa', $uid, 'user=' . $user['name']);
                    $msgOk = '✓ 2FA desativado. Próximos logins exigirão apenas a senha.';
                    $tem2fa = false;
                    $row2fa = null;
                } catch (Exception $e) {
                    $msgErr = 'Erro ao desativar: ' . $e->getMessage();
                }
            }
        }
    }
}

// Se está ATIVANDO (não tem 2FA ainda), gera nova chave (preserva via session se já gerada)
$secretNovo = '';
$qrUrl = '';
if (!$tem2fa) {
    if (!empty($_POST['secret'])) {
        // Erro na validação — preserva a chave já gerada pra não invalidar QR code do app
        $secretNovo = trim($_POST['secret']);
    } else {
        $secretNovo = totp_gerar_secret(32);
    }
    $issuer = 'Ferreira%20%26%20Sa%20Hub';
    $label  = rawurlencode('Hub: ' . $user['name']);
    $otpauth = 'otpauth://totp/' . $label . '?secret=' . $secretNovo . '&issuer=' . $issuer . '&algorithm=SHA1&digits=6&period=30';
    // QR via Google Chart API (mantido por compatibilidade — pode trocar por quickchart.io)
    $qrUrl = 'https://quickchart.io/qr?text=' . urlencode($otpauth) . '&size=240&margin=2';
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width:600px;margin:0 auto;">
    <h2 style="font-size:1.2rem;color:var(--petrol-900);margin-bottom:1rem;">🔐 Meu 2FA (autenticação em dois fatores)</h2>

    <?php if ($msgOk): ?>
        <div style="background:#dcfce7;border:1px solid #86efac;color:#15803d;padding:.7rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.85rem;"><?= e($msgOk) ?></div>
    <?php endif; ?>
    <?php if ($msgErr): ?>
        <div style="background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c;padding:.7rem 1rem;border-radius:10px;margin-bottom:1rem;font-size:.85rem;"><?= e($msgErr) ?></div>
    <?php endif; ?>

    <?php if ($tem2fa): ?>
        <!-- JÁ ATIVO -->
        <div style="background:#ecfdf5;border:2px solid #10b981;border-radius:14px;padding:1.5rem;text-align:center;">
            <div style="font-size:3rem;margin-bottom:.5rem;">✓</div>
            <h3 style="margin:0 0 .3rem;color:#065f46;">2FA está ATIVO</h3>
            <p style="font-size:.85rem;color:#065f46;margin:0 0 .5rem;">
                Ativado em <?= date('d/m/Y H:i', strtotime($row2fa['enabled_at'])) ?>
            </p>
            <p style="font-size:.78rem;color:#065f46;opacity:.85;margin:0 0 1.25rem;">
                A cada login no Hub, depois da senha, você precisa digitar o código de 6 dígitos do seu app autenticador.
            </p>
            <details style="text-align:left;background:#fff;border-radius:10px;padding:.75rem 1rem;">
                <summary style="cursor:pointer;font-size:.85rem;font-weight:600;color:#dc2626;">⚠ Desativar 2FA</summary>
                <p style="font-size:.78rem;color:#6b7280;margin:.5rem 0;">
                    Pra desativar, digite o código atual do seu app autenticador como confirmação. Sem 2FA, seu acesso ao Hub fica menos protegido.
                </p>
                <form method="POST" style="display:flex;gap:.5rem;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="acao" value="desativar">
                    <input type="text" name="codigo" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code"
                           class="form-input" style="font-family:monospace;font-size:1rem;text-align:center;letter-spacing:.3rem;width:140px;"
                           placeholder="000000">
                    <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;padding:.5rem 1rem;border-radius:8px;font-size:.85rem;font-weight:700;cursor:pointer;">Desativar</button>
                </form>
            </details>
        </div>
    <?php else: ?>
        <!-- ATIVAÇÃO -->
        <div style="background:#fff;border:2px solid #e5e7eb;border-radius:14px;padding:1.5rem;">
            <h3 style="margin:0 0 .5rem;font-size:1rem;color:var(--petrol-900);">Ativar 2FA</h3>
            <p style="font-size:.82rem;color:#6b7280;margin:0 0 1rem;">
                A autenticação em dois fatores adiciona uma camada de segurança: além da senha, vai ser pedido um código de 6 dígitos gerado pelo seu celular toda vez que você logar.
            </p>

            <ol style="font-size:.82rem;color:#374151;padding-left:1.25rem;margin-bottom:1rem;">
                <li style="margin-bottom:.4rem;">Instale no celular: <strong>Google Authenticator</strong> (Android/iOS), <strong>Microsoft Authenticator</strong> ou <strong>Authy</strong></li>
                <li style="margin-bottom:.4rem;">Abra o app e <strong>escaneie o QR code</strong> abaixo (botão "+" → "Ler QR code")</li>
                <li style="margin-bottom:.4rem;">O app vai mostrar um código de 6 dígitos que muda a cada 30s — <strong>digite o código atual no campo abaixo</strong> pra confirmar</li>
                <li>Pronto! Próximo login no Hub vai pedir o código depois da senha.</li>
            </ol>

            <div style="text-align:center;background:#f9fafb;border-radius:12px;padding:1.25rem;margin-bottom:1rem;">
                <img src="<?= e($qrUrl) ?>" alt="QR code" style="width:240px;height:240px;border:8px solid #fff;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.08);">
                <div style="margin-top:.75rem;">
                    <div style="font-size:.7rem;color:#6b7280;margin-bottom:.2rem;">Não consegue escanear? Digite essa chave manualmente no app:</div>
                    <code style="font-family:monospace;font-size:.85rem;background:#fff;padding:.4rem .6rem;border-radius:6px;border:1px solid #e5e7eb;letter-spacing:.05rem;display:inline-block;"><?= e($secretNovo) ?></code>
                </div>
            </div>

            <form method="POST" style="display:flex;flex-direction:column;gap:.6rem;">
                <?= csrf_input() ?>
                <input type="hidden" name="acao" value="ativar">
                <input type="hidden" name="secret" value="<?= e($secretNovo) ?>">
                <label style="font-size:.78rem;font-weight:600;color:#374151;">Código atual do app (6 dígitos)</label>
                <input type="text" name="codigo" pattern="\d{6}" maxlength="6" required autocomplete="one-time-code"
                       class="form-input" style="font-family:monospace;font-size:1.4rem;text-align:center;letter-spacing:.6rem;padding:.75rem;"
                       placeholder="000000" autofocus>
                <button type="submit" class="btn btn-primary" style="font-size:.9rem;padding:.7rem;font-weight:700;">Ativar 2FA →</button>
            </form>
        </div>
    <?php endif; ?>

    <div style="margin-top:1rem;font-size:.72rem;color:#94a3b8;text-align:center;">
        💡 Se você perder acesso ao app (celular roubado/quebrado), <a href="<?= module_url('mensagens', 'novo.php?para=1&assunto=Resetar%202FA') ?>" style="color:var(--petrol-500);">avise a Amanda</a> pra resetar manualmente seu 2FA.
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
