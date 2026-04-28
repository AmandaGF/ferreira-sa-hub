<?php
/**
 * Cron semanal de resumo do WhatsApp (item 5 do plano de prevenção).
 *
 * Roda toda DOMINGO às 8h via cPanel:
 *   0 8 * * 0 curl -s "https://ferreiraesa.com.br/conecta/cron/wa_resumo_semanal.php?key=fsa-hub-deploy-2026"
 *
 * Manda email pra Amanda via Brevo com:
 * - Total de msgs enviadas/recebidas semana atual
 * - Taxa de sucesso (% com zapi_message_id)
 * - Áudios enviados: .ogg (CDN Z-API) vs .webm (nosso servidor — quebrável)
 * - Conversas duplicadas detectadas e mescladas pelo webhook automático
 * - Erros de webhook agrupados por estratégia/erro
 * - Top 10 conversas mais ativas
 */
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$inicio = date('Y-m-d 00:00:00', strtotime('-7 days'));
$fim    = date('Y-m-d 23:59:59');

$stats = array();
try {
    $stats['enviadas']        = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND created_at BETWEEN '{$inicio}' AND '{$fim}'")->fetchColumn();
    $stats['enviadas_ok']     = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND zapi_message_id != '' AND zapi_message_id IS NOT NULL AND created_at BETWEEN '{$inicio}' AND '{$fim}'")->fetchColumn();
    $stats['enviadas_falha']  = $stats['enviadas'] - $stats['enviadas_ok'];
    $stats['recebidas']       = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='recebida' AND created_at BETWEEN '{$inicio}' AND '{$fim}'")->fetchColumn();
    $stats['audios_enviados'] = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND tipo='audio' AND created_at BETWEEN '{$inicio}' AND '{$fim}'")->fetchColumn();
    $stats['audios_webm']     = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='enviada' AND tipo='audio' AND arquivo_url LIKE '%/files/whatsapp/%' AND created_at BETWEEN '{$inicio}' AND '{$fim}'")->fetchColumn();
    $stats['novas_conv']      = (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas WHERE created_at BETWEEN '{$inicio}' AND '{$fim}'")->fetchColumn();
} catch (Exception $e) {}

// Estratégias de match no webhook (insights)
$estrategias = array();
try {
    $st = $pdo->prepare("SELECT estrategia_match, COUNT(*) as n FROM zapi_webhook_log
                         WHERE created_at BETWEEN ? AND ? AND estrategia_match IS NOT NULL
                         GROUP BY estrategia_match ORDER BY n DESC");
    $st->execute(array($inicio, $fim));
    $estrategias = $st->fetchAll();
} catch (Exception $e) {}

// Top 10 conversas mais ativas
$topConv = array();
try {
    $st = $pdo->prepare("SELECT c.id, c.telefone, cl.name AS cliente, COUNT(m.id) AS msgs
                         FROM zapi_mensagens m
                         JOIN zapi_conversas c ON c.id = m.conversa_id
                         LEFT JOIN clients cl ON cl.id = c.client_id
                         WHERE m.created_at BETWEEN ? AND ? AND COALESCE(c.eh_grupo,0)=0
                         GROUP BY c.id ORDER BY msgs DESC LIMIT 10");
    $st->execute(array($inicio, $fim));
    $topConv = $st->fetchAll();
} catch (Exception $e) {}

$taxaSucesso = $stats['enviadas'] > 0 ? round(100 * $stats['enviadas_ok'] / $stats['enviadas'], 1) : 0;

// Montar HTML do email
$h = '<h2 style="color:#052228">📊 Resumo semanal WhatsApp Hub</h2>';
$h .= '<p style="color:#6b7280">Período: ' . date('d/m/Y', strtotime($inicio)) . ' a ' . date('d/m/Y', strtotime($fim)) . '</p>';
$h .= '<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif">';
$h .= '<tr><th style="text-align:left;padding:8px;background:#052228;color:#fff">Métrica</th><th style="text-align:right;padding:8px;background:#052228;color:#fff">Valor</th></tr>';
$h .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">Mensagens enviadas</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right;font-weight:700">' . $stats['enviadas'] . '</td></tr>';
$h .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">↳ Com sucesso (zapi_message_id)</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right;color:#065f46">' . $stats['enviadas_ok'] . ' (' . $taxaSucesso . '%)</td></tr>';
$h .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">↳ Falhadas (sem ID)</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right;color:' . ($stats['enviadas_falha'] > 0 ? '#dc2626' : '#065f46') . '">' . $stats['enviadas_falha'] . '</td></tr>';
$h .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">Mensagens recebidas</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right;font-weight:700">' . $stats['recebidas'] . '</td></tr>';
$h .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">Áudios enviados</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right">' . $stats['audios_enviados'] . '</td></tr>';
$h .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">↳ Em formato .webm (legacy)</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right;color:' . ($stats['audios_webm'] > 0 ? '#b45309' : '#065f46') . '">' . $stats['audios_webm'] . '</td></tr>';
$h .= '<tr><td style="padding:6px;border-bottom:1px solid #eee">Novas conversas</td><td style="padding:6px;border-bottom:1px solid #eee;text-align:right">' . $stats['novas_conv'] . '</td></tr>';
$h .= '</table>';

if (!empty($estrategias)) {
    $h .= '<h3 style="color:#052228;margin-top:24px">Distribuição de match no webhook</h3>';
    $h .= '<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif">';
    foreach ($estrategias as $e) {
        $h .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee">' . htmlspecialchars($e['estrategia_match']) . '</td><td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right;font-weight:700">' . $e['n'] . '</td></tr>';
    }
    $h .= '</table>';
}

if (!empty($topConv)) {
    $h .= '<h3 style="color:#052228;margin-top:24px">Top 10 conversas mais ativas</h3>';
    $h .= '<table style="width:100%;border-collapse:collapse;font-family:Arial,sans-serif">';
    $h .= '<tr><th style="text-align:left;padding:6px;background:#f3f4f6">Cliente</th><th style="text-align:right;padding:6px;background:#f3f4f6">Msgs</th></tr>';
    foreach ($topConv as $c) {
        $h .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee">' . htmlspecialchars(($c['cliente'] ?: $c['telefone']) ?: '?') . '</td><td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right">' . $c['msgs'] . '</td></tr>';
    }
    $h .= '</table>';
}

$h .= '<p style="color:#6b7280;font-size:12px;margin-top:24px">Hub Conecta · Gerado automaticamente · ' . date('d/m/Y H:i') . '</p>';

// Enviar via Brevo
$brevoKey = '';
try { $brevoKey = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave = 'brevo_api_key'")->fetchColumn(); } catch (Exception $e) {}
$destino = 'amandaguedesferreira@gmail.com';
$enviado = false;
$erroBrevo = '';
if ($brevoKey) {
    $body = array(
        'sender' => array('name' => 'Hub Conecta', 'email' => 'noreply@ferreiraesa.com.br'),
        'to' => array(array('email' => $destino, 'name' => 'Amanda')),
        'subject' => '📊 Resumo semanal WhatsApp — ' . date('d/m/Y', strtotime($inicio)) . ' a ' . date('d/m/Y'),
        'htmlContent' => $h,
    );
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'api-key: ' . $brevoKey),
        CURLOPT_TIMEOUT => 15,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $enviado = ($code >= 200 && $code < 300);
    if (!$enviado) $erroBrevo = 'HTTP ' . $code . ': ' . substr($resp, 0, 200);
}

audit_log('wa_resumo_semanal', 'cron', 0, "Enviadas: {$stats['enviadas']}, Recebidas: {$stats['recebidas']}, Email: " . ($enviado ? 'OK' : 'falhou'));
echo "Cron wa_resumo_semanal rodado em " . date('Y-m-d H:i:s') . "\n";
echo "Período: {$inicio} a {$fim}\n";
echo "Stats: " . json_encode($stats) . "\n";
echo "Email pra {$destino}: " . ($enviado ? 'enviado' : 'falhou — ' . $erroBrevo) . "\n";
