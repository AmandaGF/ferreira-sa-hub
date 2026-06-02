<?php
/**
 * Envio de e-mail de acesso ao Hub para usuario novo (Amanda 02/06/2026).
 * Inclui senha temporaria + URL do hub + instrucao de trocar no primeiro acesso.
 * Retorna true em sucesso, false em falha.
 */
function enviar_email_acesso_hub($email, $nome, $senhaTemporaria, $urlLogin) {
    if (!$email || !$senhaTemporaria) return false;
    try {
        $pdo = db();
        $cfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
        $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'brevo_api_key') $cfg['key'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
            if ($r['chave'] === 'brevo_sender_name') $cfg['name'] = $r['valor'];
        }
        if (!$cfg['key']) return false;

        $firstName = explode(' ', $nome)[0];
        $esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">
<div style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#052228,#173d46);padding:24px;text-align:center;">
        <h1 style="color:#d7ab90;font-size:20px;margin:0;font-family:Georgia,serif;">Conecta — F&amp;S Hub</h1>
        <p style="color:#94a3b8;font-size:12px;margin:4px 0 0;">Ferreira &amp; Sá Advocacia</p>
    </div>
    <div style="padding:28px;">
        <p style="font-size:15px;color:#374151;margin:0 0 16px;">Olá, <strong>' . $esc($firstName) . '</strong>! 👋</p>
        <p style="font-size:14px;color:#374151;margin:0 0 16px;line-height:1.6;">Sua conta no <strong>Conecta</strong> (sistema interno do escritório Ferreira &amp; Sá Advocacia) foi criada. Por aqui você acompanha processos, agenda, conversas no WhatsApp e tudo mais que envolve o dia a dia do escritório.</p>

        <div style="background:#fff7ed;border:1.5px solid #d7ab90;border-radius:10px;padding:16px;margin:18px 0;">
            <p style="font-size:12px;color:#6a3c2c;margin:0 0 8px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;">Seus dados de acesso</p>
            <p style="font-size:14px;color:#052228;margin:0 0 6px;"><strong>E-mail:</strong> ' . $esc($email) . '</p>
            <p style="font-size:14px;color:#052228;margin:0;"><strong>Senha temporária:</strong> <code style="background:#fff;padding:3px 8px;border-radius:4px;border:1px solid #d7ab90;font-family:Consolas,monospace;font-size:13px;">' . $esc($senhaTemporaria) . '</code></p>
        </div>

        <div style="text-align:center;margin:24px 0;">
            <a href="' . $esc($urlLogin) . '" style="display:inline-block;background:linear-gradient(135deg,#052228,#173d46);color:#fff;padding:14px 32px;border-radius:10px;text-decoration:none;font-weight:700;font-size:15px;">Entrar no Conecta →</a>
        </div>

        <p style="font-size:13px;color:#92400e;background:#fef3c7;border-left:3px solid #f59e0b;padding:10px 14px;margin:18px 0 0;border-radius:4px;line-height:1.5;">⚠️ <strong>Importante:</strong> troque essa senha temporária no primeiro acesso. Vá em <em>Meu Perfil</em> assim que entrar.</p>

        <p style="font-size:12px;color:#9ca3af;margin:18px 0 0;line-height:1.5;">Se você não esperava este e-mail, ignore. Em caso de dúvida, fale com a Amanda.</p>
    </div>
    <div style="background:#f9fafb;padding:14px 24px;font-size:11px;color:#9ca3af;text-align:center;">Ferreira &amp; Sá Advocacia — Conecta Hub</div>
</div>
</body></html>';

        $data = array(
            'sender' => array('name' => $cfg['name'], 'email' => $cfg['email']),
            'to' => array(array('email' => $email, 'name' => $nome)),
            'subject' => '🔑 Seu acesso ao Conecta — F&S Hub',
            'htmlContent' => $html,
        );

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array('api-key: ' . $cfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_SSL_VERIFYPEER => true,
        ));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300);
    } catch (Exception $e) {
        return false;
    }
}

/** Gera senha temporaria amigavel: 4 letras + 4 numeros (sem 0/O/1/l ambiguos) */
function gerar_senha_temp_hub() {
    $letras = 'ABCDEFGHJKMNPQRSTUVWXYZ'; // sem I/L/O
    $nums = '23456789'; // sem 0/1
    $s = '';
    for ($i = 0; $i < 4; $i++) $s .= $letras[random_int(0, strlen($letras) - 1)];
    for ($i = 0; $i < 4; $i++) $s .= $nums[random_int(0, strlen($nums) - 1)];
    return $s;
}
