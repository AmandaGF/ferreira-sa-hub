<?php
/**
 * Cron Job — Resumo semanal de prazos por e-mail
 * Rodar toda segunda-feira às 07h via cron do cPanel
 * Comando: php /home/ferre315/public_html/conecta/cron/resumo_semanal_prazos.php
 *
 * Também pode ser chamado via HTTP: ?key=fsa-hub-deploy-2026
 */

if (php_sapi_name() !== 'cli' && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';

$pdo = db();

echo "=== Resumo Semanal de Prazos — " . date('d/m/Y H:i') . " ===\n\n";

// Buscar config Brevo
$brevoCfg = array('key' => '', 'email' => 'contato@ferreiraesa.com.br', 'name' => 'Ferreira & Sá Advocacia');
try {
    $rows = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'brevo_%'")->fetchAll();
    foreach ($rows as $r) {
        if ($r['chave'] === 'brevo_api_key') $brevoCfg['key'] = $r['valor'];
        if ($r['chave'] === 'brevo_sender_email') $brevoCfg['email'] = $r['valor'];
        if ($r['chave'] === 'brevo_sender_name') $brevoCfg['name'] = $r['valor'];
    }
} catch (Exception $e) {}

if (!$brevoCfg['key']) { echo "ERRO: Brevo não configurado.\n"; exit; }

// Prazos da semana (próximos 7 dias)
$hoje = date('Y-m-d');
$em7d = date('Y-m-d', strtotime('+7 days'));

$stmt = $pdo->prepare(
    "SELECT p.*, cs.title as case_title, cs.responsible_user_id, cl.name as client_name, u.name as resp_name
     FROM prazos_processuais p
     LEFT JOIN cases cs ON cs.id = p.case_id
     LEFT JOIN clients cl ON cl.id = p.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE p.concluido = 0 AND p.prazo_fatal BETWEEN ? AND ?
     ORDER BY p.prazo_fatal ASC"
);
$stmt->execute(array($hoje, $em7d));
$prazos = $stmt->fetchAll();

// Prazos atrasados
$stmtAtrasados = $pdo->prepare(
    "SELECT p.*, cs.title as case_title, cl.name as client_name
     FROM prazos_processuais p
     LEFT JOIN cases cs ON cs.id = p.case_id
     LEFT JOIN clients cl ON cl.id = p.client_id
     WHERE p.concluido = 0 AND p.prazo_fatal < ?
     ORDER BY p.prazo_fatal ASC"
);
$stmtAtrasados->execute(array($hoje));
$atrasados = $stmtAtrasados->fetchAll();

if (empty($prazos) && empty($atrasados)) {
    echo "Nenhum prazo na semana e nenhum atrasado. Nenhum e-mail enviado.\n";
    exit;
}

// Buscar todos os destinatários (admin + gestão + operacional)
$users = $pdo->query("SELECT id, name, email FROM users WHERE role IN ('admin','gestao','operacional') AND is_active = 1 AND email IS NOT NULL AND email != ''")->fetchAll();

if (empty($users)) { echo "Nenhum usuário para enviar.\n"; exit; }

// Montar tabela HTML de prazos
$semana = date('d/m') . ' a ' . date('d/m', strtotime('+7 days'));
$totalPrazos = count($prazos);
$totalAtrasados = count($atrasados);

$rowsHtml = '';
foreach ($atrasados as $p) {
    $dias = abs((int)((strtotime($p['prazo_fatal']) - strtotime($hoje)) / 86400));
    $rowsHtml .= '<tr style="background:#fef2f2;">
        <td style="padding:6px 10px;border-bottom:1px solid #eee;font-weight:700;color:#dc2626;">ATRASADO (' . $dias . 'd)</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;font-weight:600;">' . htmlspecialchars($p['descricao_acao'], ENT_QUOTES, 'UTF-8') . '</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($p['case_title'] ?: $p['numero_processo'] ?: '—', ENT_QUOTES, 'UTF-8') . '</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($p['client_name'] ?: '—', ENT_QUOTES, 'UTF-8') . '</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;font-family:monospace;">' . date('d/m/Y', strtotime($p['prazo_fatal'])) . '</td>
    </tr>';
}

foreach ($prazos as $p) {
    $diasR = (int)((strtotime($p['prazo_fatal']) - strtotime($hoje)) / 86400);
    $cor = $diasR <= 1 ? '#fef2f2' : ($diasR <= 3 ? '#fffbeb' : '#fff');
    $label = $diasR === 0 ? 'HOJE' : ($diasR === 1 ? 'Amanhã' : $diasR . ' dias');
    $rowsHtml .= '<tr style="background:' . $cor . ';">
        <td style="padding:6px 10px;border-bottom:1px solid #eee;font-weight:700;' . ($diasR <= 1 ? 'color:#dc2626;' : ($diasR <= 3 ? 'color:#d97706;' : 'color:#059669;')) . '">' . $label . '</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;font-weight:600;">' . htmlspecialchars($p['descricao_acao'], ENT_QUOTES, 'UTF-8') . '</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($p['case_title'] ?: $p['numero_processo'] ?: '—', ENT_QUOTES, 'UTF-8') . '</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;">' . htmlspecialchars($p['client_name'] ?: '—', ENT_QUOTES, 'UTF-8') . '</td>
        <td style="padding:6px 10px;border-bottom:1px solid #eee;font-family:monospace;">' . date('d/m/Y', strtotime($p['prazo_fatal'])) . '</td>
    </tr>';
}

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:Arial,sans-serif;background:#f4f4f7;padding:20px;">
<div style="max-width:650px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <div style="background:#052228;padding:20px 24px;">
        <h1 style="color:#fff;font-size:18px;margin:0;">📅 Resumo Semanal de Prazos</h1>
        <p style="color:#94a3b8;font-size:13px;margin:6px 0 0;">Semana ' . $semana . '</p>
    </div>
    <div style="padding:24px;">';

if ($totalAtrasados > 0) {
    $html .= '<div style="background:#fef2f2;border-left:4px solid #dc2626;padding:12px 16px;border-radius:0 8px 8px 0;margin-bottom:16px;">
        <strong style="color:#dc2626;">🚨 ' . $totalAtrasados . ' prazo(s) ATRASADO(S)!</strong>
    </div>';
}

$html .= '<p style="font-size:14px;color:#374151;margin:0 0 16px;"><strong>' . $totalPrazos . '</strong> prazo(s) nesta semana' . ($totalAtrasados > 0 ? ' + <strong style="color:#dc2626;">' . $totalAtrasados . ' atrasado(s)</strong>' : '') . '</p>

    <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead><tr style="background:#f0f4f7;">
            <th style="padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;color:#666;">Prazo</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;color:#666;">Descrição</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;color:#666;">Processo</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;color:#666;">Cliente</th>
            <th style="padding:8px 10px;text-align:left;font-size:11px;text-transform:uppercase;color:#666;">Data</th>
        </tr></thead>
        <tbody>' . $rowsHtml . '</tbody>
    </table>

    <div style="margin-top:20px;text-align:center;">
        <a href="https://ferreiraesa.com.br/conecta/modules/prazos/" style="display:inline-block;background:#052228;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:700;font-size:14px;">Ver todos os prazos →</a>
    </div>
    </div>
    <div style="background:#f9fafb;padding:14px 24px;font-size:12px;color:#9ca3af;text-align:center;">
        Ferreira & Sá Advocacia — Portal Ferreira & Sá HUB
    </div>
</div>
</body></html>';

// Enviar para cada usuário
$enviados = 0;
foreach ($users as $u) {
    $data = array(
        'sender' => array('name' => $brevoCfg['name'], 'email' => $brevoCfg['email']),
        'to' => array(array('email' => $u['email'], 'name' => $u['name'])),
        'subject' => '📅 Prazos da semana (' . $semana . ')' . ($totalAtrasados > 0 ? ' — ' . $totalAtrasados . ' ATRASADO(S)!' : ''),
        'htmlContent' => $html,
    );

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => array('api-key: ' . $brevoCfg['key'], 'Content-Type: application/json', 'Accept: application/json'),
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 400) {
        $enviados++;
        echo "  [OK] " . $u['name'] . " (" . $u['email'] . ")\n";
    } else {
        echo "  [ERRO] " . $u['name'] . " — HTTP $code\n";
    }
}

echo "\nEnviados: $enviados / " . count($users) . "\n";
echo "=== FIM ===\n";
