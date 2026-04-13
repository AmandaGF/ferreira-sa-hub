<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Resetar Naiara: gerar novo token, desativar, reenviar e-mail
$token = bin2hex(random_bytes(32));
$expira = date('Y-m-d H:i:s', strtotime('+72 hours'));

$pdo->prepare("UPDATE salavip_usuarios SET ativo=0, token_ativacao=?, token_expira=? WHERE cliente_id=1107")
    ->execute([$token, $expira]);

echo "Token gerado: $token\n";
echo "Expira: $expira\n";
echo "Link: https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=$token\n\n";

// Enviar e-mail
$cfg = ['key'=>'','email'=>'contato@ferreiraesa.com.br','name'=>'Ferreira & Sá Advocacia'];
$rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
foreach ($rows as $r) {
    if ($r['chave'] === 'brevo_api_key') $cfg['key'] = $r['valor'];
    if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
    if ($r['chave'] === 'brevo_sender_name') $cfg['name'] = $r['valor'];
}

$link = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=' . $token;
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#0f2140,#1a3358);padding:24px;text-align:center;">
        <h1 style="color:#c9a94e;font-size:20px;margin:0;font-family:Georgia,serif;">Sala VIP</h1>
        <p style="color:#94a3b8;font-size:12px;margin:4px 0 0;">Ferreira &amp; Sá Advocacia</p>
    </div>
    <div style="padding:28px;">
        <p style="font-size:15px;color:#374151;margin:0 0 16px;">Olá, <strong>Naiara</strong>!</p>
        <p style="font-size:14px;color:#374151;margin:0 0 16px;line-height:1.6;">Seu acesso à <strong>Sala VIP</strong> do escritório Ferreira &amp; Sá Advocacia foi criado. Através dela, você poderá acompanhar seus processos, enviar documentos e trocar mensagens com nossa equipe.</p>
        <p style="font-size:14px;color:#374151;margin:0 0 20px;">Clique no botão abaixo para definir sua senha e ativar sua conta:</p>
        <div style="text-align:center;margin:24px 0;">
            <a href="' . $link . '" style="display:inline-block;background:linear-gradient(135deg,#b08d6e,#c4a882);color:#0f2140;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px;">Ativar Minha Conta →</a>
        </div>
        <p style="font-size:12px;color:#94a3b8;margin:20px 0 0;">Este link é válido por <strong>72 horas</strong>.</p>
    </div>
    <div style="background:#f9fafb;padding:14px 24px;font-size:11px;color:#9ca3af;text-align:center;">Ferreira &amp; Sá Advocacia — Sala VIP</div>
</div></body></html>';

$data = [
    'sender' => ['name' => $cfg['name'], 'email' => $cfg['email']],
    'to' => [['email' => 'ndourado30@gmail.com', 'name' => 'Naiara Gama Dourado']],
    'subject' => '🔑 Ative sua conta na Sala VIP — Ferreira & Sá Advocacia',
    'htmlContent' => $html,
];

$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($data), CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "E-mail: HTTP $code — $resp\n";
