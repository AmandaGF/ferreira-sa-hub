<?php
// 27/05/2026 — Cria usuario da Nativania Gama Dourado (comercial),
// inicia segunda 02/06/2026. Senha provisoria aleatoria + e-mail de
// boas-vindas (via Brevo, se configurado).

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }

require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$nome  = 'Nativânia Gama Dourado';
$email = 'douradonativania@gmail.com';
$role  = 'comercial';
$setor = 'Comercial';

// Idempotencia: se ja existir, mostra e sai sem alterar
$st = $pdo->prepare("SELECT id, name, role, is_active FROM users WHERE email = ? OR name = ?");
$st->execute(array($email, $nome));
$ja = $st->fetch();
if ($ja) {
    echo "[ja existe] user#{$ja['id']}  '{$ja['name']}'  role={$ja['role']}  ativo=" . ($ja['is_active'] ? 'sim' : 'nao') . "\n";
    echo "Para reativar/atualizar use /modules/usuarios/form.php?id={$ja['id']}\n";
    exit;
}

// Gera senha provisoria forte (12 chars, sem caracteres ambiguos)
$alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghjkmnpqrstuvwxyz';
$senha = '';
for ($i = 0; $i < 12; $i++) $senha .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];

$hash = password_hash($senha, PASSWORD_DEFAULT);

$pdo->prepare(
    "INSERT INTO users (name, email, password_hash, setor, role, is_active, created_at)
     VALUES (?, ?, ?, ?, ?, 1, NOW())"
)->execute(array($nome, $email, $hash, $setor, $role));
$userId = (int)$pdo->lastInsertId();

try { audit_log('user_created', 'user', $userId, "Onboarding comercial — criado via aplicar_nativania.php"); } catch (Throwable $e) {}

echo "✓ Usuario criado: #$userId  '$nome'\n";
echo "  Email:   $email\n";
echo "  Role:    $role\n";
echo "  Setor:   $setor\n";
echo "  Senha:   $senha     <-- repassa pra ela caso o e-mail nao chegue\n";
echo "  Login:   https://ferreiraesa.com.br/conecta/\n\n";

// Tenta enviar e-mail via Brevo
$cfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
$rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
foreach ($rows as $r) {
    if ($r['chave'] === 'brevo_api_key')      $cfg['key']   = $r['valor'];
    if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
    if ($r['chave'] === 'brevo_sender_name')  $cfg['name']  = $r['valor'];
}

if (!$cfg['key']) {
    echo "[!] Brevo nao configurado — nenhum e-mail enviado. Use a senha acima manualmente.\n";
    exit;
}

$primeiroNome = explode(' ', $nome)[0];
$loginUrl = 'https://ferreiraesa.com.br/conecta/';

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#0f2140,#1a3358);padding:24px;text-align:center;">
        <h1 style="color:#c9a94e;font-size:22px;margin:0;font-family:Georgia,serif;">Hub Conecta</h1>
        <p style="color:#94a3b8;font-size:12px;margin:4px 0 0;">Ferreira &amp; Sá Advocacia</p>
    </div>
    <div style="padding:28px;">
        <p style="font-size:16px;color:#374151;margin:0 0 16px;">Bem-vinda ao time, <strong>' . htmlspecialchars($primeiroNome, ENT_QUOTES, 'UTF-8') . '</strong>! 🎉</p>
        <p style="font-size:14px;color:#374151;margin:0 0 16px;line-height:1.6;">Sua conta no <strong>Hub Conecta</strong> foi criada para você começar como <strong>Comercial</strong> a partir de segunda-feira (02/06/2026).</p>
        <p style="font-size:14px;color:#374151;margin:0 0 12px;line-height:1.6;"><strong>Seus dados de acesso:</strong></p>
        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;margin-bottom:18px;font-family:monospace;font-size:13px;line-height:1.7;">
            <div>👤 <strong>E-mail:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</div>
            <div>🔑 <strong>Senha provisória:</strong> ' . htmlspecialchars($senha, ENT_QUOTES, 'UTF-8') . '</div>
        </div>
        <div style="text-align:center;margin:22px 0;">
            <a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#b08d6e,#c4a882);color:#0f2140;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px;">Entrar no Hub →</a>
        </div>
        <p style="font-size:13px;color:#6b7280;margin:18px 0 8px;line-height:1.6;"><strong>No primeiro login:</strong></p>
        <ol style="font-size:13px;color:#374151;margin:0 0 14px 18px;line-height:1.7;">
            <li>Acesse com a senha provisória acima</li>
            <li>Vá em <em>Configurações → Meu Perfil</em> e troque a senha</li>
            <li>Complete os dados do seu cadastro (telefone, foto, etc)</li>
        </ol>
        <p style="font-size:13px;color:#374151;margin:14px 0 0;line-height:1.6;">Qualquer dúvida, fala com a <strong>Amanda</strong>.<br>Seja muito bem-vinda! 💛</p>
    </div>
    <div style="background:#f9fafb;padding:14px 24px;font-size:11px;color:#9ca3af;text-align:center;">Ferreira &amp; Sá Advocacia — Hub Conecta</div>
</div>
</body></html>';

$data = array(
    'sender'      => array('name' => $cfg['name'], 'email' => $cfg['email']),
    'to'          => array(array('email' => $email, 'name' => $nome)),
    'subject'     => '🎉 Bem-vinda ao Hub Conecta — Ferreira & Sá Advocacia',
    'htmlContent' => $html,
);

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => array('api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
    CURLOPT_POSTFIELDS     => json_encode($data),
    CURLOPT_SSL_VERIFYPEER => true,
));
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code >= 200 && $code < 300) {
    echo "✓ E-mail enviado pra $email (HTTP $code)\n";
} else {
    echo "[!] Falha no envio Brevo (HTTP $code). Resp: " . substr((string)$resp, 0, 300) . "\n";
    echo "    Repassa pra ela manualmente: e-mail + senha acima.\n";
}
