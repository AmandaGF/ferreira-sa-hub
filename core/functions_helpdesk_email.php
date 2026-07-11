<?php
/**
 * Envio de e-mail de notificacao aos responsaveis vinculados a um chamado
 * novo do helpdesk (Amanda 10/07/2026).
 *
 * Chamado por modules/helpdesk/novo.php logo apos gravar assignees. Um unico
 * request pro Brevo com varios destinatarios (evita rate limit). Nao ha
 * fallback SMTP nem retry — se falhar, o chamado ja esta salvo e a
 * notificacao in-app (push_notify) continua funcionando.
 *
 * @return bool true se o Brevo aceitou (2xx), false qualquer outro caso
 */
function enviar_email_novo_chamado($pdo, $ticketId, $ticketData, $userIds)
{
    if (empty($userIds)) return false;
    $ids = array_values(array_unique(array_map('intval', (array)$userIds)));
    if (!$ids) return false;

    // Emails dos responsaveis
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name, email FROM users WHERE id IN ($ph) AND is_active = 1 AND email IS NOT NULL AND email <> ''");
    $st->execute($ids);
    $dest = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$dest) return false;

    // Config Brevo
    $cfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
    foreach ($pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'") as $r) {
        if ($r['chave'] === 'brevo_api_key')      $cfg['key']   = $r['valor'];
        if ($r['chave'] === 'brevo_sender_email') $cfg['email'] = $r['valor'];
        if ($r['chave'] === 'brevo_sender_name')  $cfg['name']  = $r['valor'];
    }
    if (!$cfg['key']) return false;

    $esc = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

    $title       = $ticketData['title'] ?? 'Novo chamado';
    $description = $ticketData['description'] ?? '';
    $category    = $ticketData['category'] ?? '';
    $department  = $ticketData['department'] ?? '';
    $priority    = $ticketData['priority'] ?? 'normal';
    $clientName  = $ticketData['client_name'] ?? '';
    $caseNumber  = $ticketData['case_number'] ?? '';
    $dueDate     = $ticketData['due_date'] ?? '';
    $requester   = $ticketData['requester_name'] ?? '';

    $prioLabel = array('baixa' => 'Baixa', 'normal' => 'Normal', 'urgente' => 'URGENTE');
    $prioCor   = array('baixa' => '#6b7280', 'normal' => '#0369a1', 'urgente' => '#b91c1c');
    $prioBg    = array('baixa' => '#f3f4f6', 'normal' => '#e0f2fe', 'urgente' => '#fee2e2');
    $pLbl = $prioLabel[$priority] ?? 'Normal';
    $pCol = $prioCor[$priority] ?? '#0369a1';
    $pBg  = $prioBg[$priority] ?? '#e0f2fe';

    $urlTicket = 'https://ferreiraesa.com.br' . BASE_URL . '/modules/helpdesk/ver.php?id=' . (int)$ticketId;

    $assunto = ($priority === 'urgente' ? '🔥 URGENTE — ' : '🔔 ') . 'Novo chamado #' . $ticketId . ' — ' . $title;

    // Corpo texto (fallback)
    $texto  = "Novo chamado #$ticketId no Helpdesk\n\n";
    $texto .= "Título: $title\n";
    if ($category)   $texto .= "Categoria: $category\n";
    if ($department) $texto .= "Setor: $department\n";
    $texto .= "Prioridade: $pLbl\n";
    if ($clientName) $texto .= "Cliente: $clientName\n";
    if ($caseNumber) $texto .= "Processo: $caseNumber\n";
    if ($dueDate)    $texto .= "Prazo/SLA: " . date('d/m/Y', strtotime($dueDate)) . "\n";
    if ($requester)  $texto .= "Aberto por: $requester\n";
    if ($description) $texto .= "\nDescrição:\n$description\n";
    $texto .= "\nAbrir: $urlTicket\n";

    $descHtml = $description ? '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:12px 14px;margin:12px 0;font-size:13px;color:#374151;line-height:1.5;white-space:pre-wrap;">' . $esc($description) . '</div>' : '';

    $linhas = '';
    if ($category)   $linhas .= '<tr><td style="padding:5px 0;color:#6b7280;font-size:12px;">Categoria</td><td style="padding:5px 0;font-size:13px;color:#111827;font-weight:600;">' . $esc($category) . '</td></tr>';
    if ($department) $linhas .= '<tr><td style="padding:5px 0;color:#6b7280;font-size:12px;">Setor</td><td style="padding:5px 0;font-size:13px;color:#111827;font-weight:600;">' . $esc($department) . '</td></tr>';
    if ($clientName) $linhas .= '<tr><td style="padding:5px 0;color:#6b7280;font-size:12px;">Cliente</td><td style="padding:5px 0;font-size:13px;color:#111827;font-weight:600;">' . $esc($clientName) . '</td></tr>';
    if ($caseNumber) $linhas .= '<tr><td style="padding:5px 0;color:#6b7280;font-size:12px;">Processo</td><td style="padding:5px 0;font-size:13px;color:#111827;font-weight:600;">' . $esc($caseNumber) . '</td></tr>';
    if ($dueDate)    $linhas .= '<tr><td style="padding:5px 0;color:#6b7280;font-size:12px;">Prazo/SLA</td><td style="padding:5px 0;font-size:13px;color:#b91c1c;font-weight:700;">' . $esc(date('d/m/Y', strtotime($dueDate))) . '</td></tr>';
    if ($requester)  $linhas .= '<tr><td style="padding:5px 0;color:#6b7280;font-size:12px;">Aberto por</td><td style="padding:5px 0;font-size:13px;color:#111827;">' . $esc($requester) . '</td></tr>';

    $html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">
<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:linear-gradient(135deg,#052228,#173d46);padding:22px 24px;">
        <div style="color:#d7ab90;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin:0 0 4px;">Helpdesk · Conecta</div>
        <h1 style="color:#fff;font-size:19px;margin:0;font-family:Georgia,serif;">🔔 Novo chamado #' . (int)$ticketId . '</h1>
    </div>
    <div style="padding:24px;">
        <div style="display:inline-block;background:' . $pBg . ';color:' . $pCol . ';font-size:11px;font-weight:800;padding:4px 12px;border-radius:999px;text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">' . $esc($pLbl) . '</div>
        <h2 style="color:#052228;font-size:18px;margin:0 0 14px;line-height:1.35;">' . $esc($title) . '</h2>
        ' . $descHtml . '
        <table style="width:100%;border-collapse:collapse;margin:14px 0;">' . $linhas . '</table>
        <div style="text-align:center;margin:24px 0 6px;">
            <a href="' . $esc($urlTicket) . '" style="display:inline-block;background:linear-gradient(135deg,#052228,#173d46);color:#fff;padding:12px 28px;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px;">Abrir chamado no Conecta →</a>
        </div>
        <p style="font-size:11px;color:#9ca3af;margin:14px 0 0;text-align:center;line-height:1.5;">Você recebeu este e-mail porque foi marcado como responsável neste chamado.</p>
    </div>
    <div style="background:#f9fafb;padding:12px 24px;font-size:11px;color:#9ca3af;text-align:center;">Ferreira &amp; Sá Advocacia — Conecta Hub</div>
</div>
</body></html>';

    // Brevo aceita ate 99 destinatarios em "to". Em geral <10 responsaveis
    // por chamado, entao sem paginacao.
    $to = array();
    foreach ($dest as $u) {
        $to[] = array('email' => $u['email'], 'name' => $u['name']);
    }

    $data = array(
        'sender'       => array('name' => $cfg['name'], 'email' => $cfg['email']),
        'to'           => $to,
        'subject'      => $assunto,
        'htmlContent'  => $html,
        'textContent'  => $texto,
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

    $ok = ($code >= 200 && $code < 300);
    try {
        audit_log($ok ? 'helpdesk_email_ok' : 'helpdesk_email_fail', 'ticket', (int)$ticketId,
            'destinatarios=' . count($to) . ' brevo_status=' . $code);
    } catch (Exception $e) {}
    return $ok;
}
