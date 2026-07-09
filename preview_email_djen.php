<?php
/**
 * Preview do email diário do DJEN — Amanda 09/07/2026.
 * Roda o gerador de HTML com publicações reais das últimas semanas
 * pra Amanda ver como fica antes de mandar por email.
 *
 * Uso: /conecta/preview_email_djen.php?key=fsa-hub-deploy-2026
 * Opcional: &dias=7 pra pegar últimos 7 dias em vez de 30
 * Opcional: &enviar=1 pra enviar de verdade pro email dela
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/cron/claudin_config.php';

// Precisa ativar CLAUDIN_NO_AUTORUN pra include não disparar cron
define('CLAUDIN_NO_AUTORUN', true);
require_once __DIR__ . '/cron/djen_monitor.php';

$pdo = db();
$dias = (int)($_GET['dias'] ?? 30);
if ($dias < 1 || $dias > 90) $dias = 30;

// Pega range das últimas N dias pra ter dados de exemplo
$stCount = $pdo->prepare("SELECT
    COUNT(*) AS tot,
    SUM(CASE WHEN case_id IS NULL THEN 1 ELSE 0 END) AS pend
    FROM case_publicacoes
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
$stCount->execute(array($dias));
$counts = $stCount->fetch(PDO::FETCH_ASSOC) ?: array('tot' => 0, 'pend' => 0);

// Pega DATA da publicação mais recente pra simular "dia de hoje"
$stD = $pdo->prepare("SELECT MAX(data_disponibilizacao) FROM case_publicacoes WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)");
$stD->execute(array($dias));
$dataAlvo = (string)$stD->fetchColumn();
if (!$dataAlvo) $dataAlvo = date('Y-m-d');

// Monkeypatch: força a query da função a pegar as N dias (não só hoje)
// via variável global que a função pode consultar. Como não é elegante,
// vou fazer inline: chamo a função com um data válida e depois ela query.
// Mas nossa função filtra "data_disponibilizacao = ? OR DATE(created_at) = CURDATE()"
// então vou modificar a query direto aqui pra preview.

// Reimplemento o corpo do email inline com filtro flexivel pro preview
$st = $pdo->prepare(
    "SELECT p.id, p.case_id, p.data_disponibilizacao, p.tribunal, p.tipo_publicacao,
            p.prazo_dias, p.data_prazo_fim, p.resumo_ia, p.orientacao_ia, p.conteudo,
            p.created_at,
            c.title AS case_title, c.case_number,
            cl.name AS client_name
     FROM case_publicacoes p
     LEFT JOIN cases c ON c.id = p.case_id
     LEFT JOIN clients cl ON cl.id = c.client_id
     WHERE p.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
     ORDER BY p.tribunal, p.created_at DESC
     LIMIT 30"
);
$st->execute(array($dias));
$pubs = $st->fetchAll(PDO::FETCH_ASSOC);

if (!$pubs) {
    die("Nenhuma publicacao nas ultimas $dias dias. Tenta ?dias=90.\n");
}

// Simula contadores baseado nos dados reais
$imported = count(array_filter($pubs, function($p){ return !empty($p['case_id']); }));
$pending  = count(array_filter($pubs, function($p){ return empty($p['case_id']); }));
$contadores = array(
    'imported'    => $imported,
    'duplicated'  => 0,
    'pending'     => $pending,
    'errors'      => 0,
    'total_parsed' => count($pubs),
);

// Chama a função — mas ela filtra por data_disponibilizacao=$dataAlvo.
// Pra preview mostrar tudo, vou fazer uma versão inline que reusa a lógica:
// pego a data mais popular e uso como alvo. Se poucas publicações batem,
// vou hackear temporariamente.

// Truque: chamo a função direto e forço todas as publicações caírem no filtro.
// Como a query dela roda no PDO, vou criar view temporária... Não. Mais simples:
// duplica a função pra preview (temporario).

function preview_montar_email($pdo, $pubs, $contadores, $horario, $dataFmt) {
    $totalCap = count($pubs);
    $assunto = '📬 [PREVIEW] Recortes DJEN ' . $dataFmt . ' — ' . $totalCap . ' publicações';

    $porTribunal = array();
    foreach ($pubs as $p) {
        $key = trim((string)$p['tribunal']) ?: 'Sem tribunal identificado';
        if (!isset($porTribunal[$key])) $porTribunal[$key] = array();
        $porTribunal[$key][] = $p;
    }

    $tiposLbl = array(
        'intimacao' => '📢 Intimação', 'citacao' => '📩 Citação',
        'despacho' => '⚖️ Despacho', 'decisao' => '⚖️ Decisão',
        'sentenca' => '🏛️ Sentença', 'acordao' => '🏛️ Acórdão',
        'edital' => '📃 Edital', 'outro' => '📄 Publicação',
    );

    $h = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Preview email DJEN</title></head>'
       . '<body style="margin:0;background:#faf7f2;font-family:Georgia,\'Times New Roman\',serif;color:#1a1a1f;">'
       . '<div style="max-width:660px;margin:0 auto;background:#faf7f2;">'
       . '<div style="background:#c76e15;color:#fff;padding:.7rem 1rem;text-align:center;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.85rem;font-weight:600;">🔍 PREVIEW — assim que o email vai chegar no seu inbox</div>'
       . '<div style="background:linear-gradient(135deg,#052228,#0a3238);color:#fff;padding:1.6rem 1.4rem;border-radius:0 0 12px 12px;">'
       . '<div style="font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:#B87333;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-weight:700;margin-bottom:.4rem;">Ferreira &amp; Sá Advocacia · Recortes do dia</div>'
       . '<h1 style="margin:0;font-size:1.6rem;font-weight:600;line-height:1.2;font-family:Georgia,serif;">' . $totalCap . ' publicações capturadas em ' . $dataFmt . '</h1>'
       . '<p style="margin:.6rem 0 0;font-size:.9rem;opacity:.85;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">Execução ' . $horario . 'h · '
       . (int)$contadores['imported'] . ' importadas · ' . (int)$contadores['duplicated'] . ' duplicadas · '
       . (int)$contadores['pending'] . ' aguardando vínculo</p>'
       . '</div>';

    foreach ($porTribunal as $trib => $itens) {
        $h .= '<div style="padding:1.4rem 1.2rem .4rem;">'
            . '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.68rem;letter-spacing:.12em;text-transform:uppercase;color:#B87333;font-weight:700;padding-bottom:.4rem;border-bottom:1px solid #d5cdba;margin-bottom:.9rem;">🏛️ ' . htmlspecialchars($trib, ENT_QUOTES, 'UTF-8') . ' · ' . count($itens) . '</div>'
            . '</div>';

        foreach ($itens as $p) {
            $tipoLbl = $tiposLbl[$p['tipo_publicacao']] ?? '📄 Publicação';
            $temPasta = !empty($p['case_id']);
            $urlPasta = $temPasta ? ('https://ferreiraesa.com.br/conecta/modules/operacional/caso_ver.php?id=' . (int)$p['case_id']) : '';

            $prazoTxt = '';
            if (!empty($p['data_prazo_fim'])) {
                $diasRest = (int)((strtotime($p['data_prazo_fim']) - strtotime(date('Y-m-d'))) / 86400);
                $corPrazo = $diasRest <= 3 ? '#a33a2a' : ($diasRest <= 7 ? '#c76e15' : '#065f46');
                $prazoTxt = '<div style="display:inline-block;background:' . $corPrazo . '15;border:1px solid ' . $corPrazo . '40;color:' . $corPrazo . ';padding:4px 10px;border-radius:6px;font-size:.75rem;font-weight:700;margin-top:.5rem;">⏰ Prazo fatal ' . date('d/m/Y', strtotime($p['data_prazo_fim'])) . ' (' . $diasRest . 'd)</div>';
            }

            $h .= '<div style="margin:0 1.2rem 1rem;background:#fff;border:1px solid #e3ddcf;border-left:4px solid #B87333;border-radius:8px;padding:1rem 1.1rem;box-shadow:0 1px 2px rgba(5,34,40,.04);">'
                . '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.72rem;color:#6b6559;letter-spacing:.06em;text-transform:uppercase;font-weight:600;margin-bottom:.4rem;">'
                . htmlspecialchars($tipoLbl, ENT_QUOTES, 'UTF-8')
                . ' · ' . date('d/m/Y', strtotime($p['data_disponibilizacao']))
                . '</div>'
                . '<div style="font-family:Georgia,serif;font-size:1.05rem;color:#052228;font-weight:600;line-height:1.35;margin-bottom:.15rem;">'
                . ($temPasta ? htmlspecialchars($p['case_title'] ?: 'Caso #' . $p['case_id'], ENT_QUOTES, 'UTF-8') : '<span style="color:#a33a2a;">⚠️ Sem pasta vinculada</span>')
                . '</div>';

            $meta = array();
            if (!empty($p['client_name'])) $meta[] = '👤 ' . htmlspecialchars($p['client_name'], ENT_QUOTES, 'UTF-8');
            if (!empty($p['case_number'])) $meta[] = '<span style="font-family:ui-monospace,Consolas,monospace;color:#4a4740;">' . htmlspecialchars($p['case_number'], ENT_QUOTES, 'UTF-8') . '</span>';
            if ($meta) $h .= '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.82rem;color:#4a4740;margin-bottom:.5rem;">' . implode(' · ', $meta) . '</div>';

            if (!empty($p['resumo_ia'])) {
                $h .= '<div style="background:#faf7f2;border-left:2px solid #B87333;padding:.55rem .75rem;font-size:.85rem;line-height:1.5;color:#1a1a1f;font-family:Georgia,serif;margin:.4rem 0;border-radius:0 6px 6px 0;">'
                    . '<span style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.65rem;font-weight:700;color:#B87333;letter-spacing:.06em;text-transform:uppercase;">🤖 Resumo IA</span><br>'
                    . nl2br(htmlspecialchars(mb_substr($p['resumo_ia'], 0, 500), ENT_QUOTES, 'UTF-8')) . '</div>';
                if (!empty($p['orientacao_ia'])) {
                    $h .= '<div style="background:#fff7ed;border-left:2px solid #c76e15;padding:.55rem .75rem;font-size:.82rem;line-height:1.5;color:#3d2b0e;font-family:Georgia,serif;margin:.4rem 0;border-radius:0 6px 6px 0;">'
                        . '<span style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.65rem;font-weight:700;color:#c76e15;letter-spacing:.06em;text-transform:uppercase;">💡 O que fazer</span><br>'
                        . nl2br(htmlspecialchars(mb_substr($p['orientacao_ia'], 0, 400), ENT_QUOTES, 'UTF-8')) . '</div>';
                }
            }

            $conteudoCompleto = trim((string)$p['conteudo']);
            if ($conteudoCompleto !== '') {
                $temHtml = (strip_tags($conteudoCompleto) !== $conteudoCompleto);
                $LIMITE = 4000;
                $foiCortado = false;
                if (mb_strlen($conteudoCompleto) > $LIMITE) {
                    $conteudoCompleto = mb_substr($conteudoCompleto, 0, $LIMITE);
                    $foiCortado = true;
                }
                if ($temHtml) {
                    $conteudoRender = strip_tags($conteudoCompleto, '<p><br><b><strong><i><em><ul><ol><li><span><div>');
                } else {
                    $conteudoRender = nl2br(htmlspecialchars($conteudoCompleto, ENT_QUOTES, 'UTF-8'));
                }
                $h .= '<div style="background:#fdfcf9;border:1px solid #e3ddcf;padding:.7rem .9rem;font-size:.82rem;line-height:1.6;color:#1a1a1f;font-family:Georgia,serif;margin:.5rem 0;border-radius:6px;">'
                    . '<div style="font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-size:.65rem;font-weight:700;color:#052228;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.4rem;padding-bottom:.35rem;border-bottom:1px solid #e3ddcf;">📄 Íntegra da publicação</div>'
                    . '<div style="color:#1a1a1f;">' . $conteudoRender . '</div>'
                    . ($foiCortado ? '<div style="margin-top:.5rem;padding-top:.4rem;border-top:1px dashed #d5cdba;font-size:.72rem;color:#8a8378;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;font-style:italic;">… texto truncado (' . number_format(mb_strlen(trim((string)$p['conteudo']))) . ' caracteres no total). Ver íntegra completa na pasta →</div>' : '')
                    . '</div>';
            }

            if ($prazoTxt) $h .= $prazoTxt;
            $h .= '<div style="margin-top:.9rem;">';
            if ($temPasta) {
                $h .= '<a href="' . htmlspecialchars($urlPasta, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#052228;color:#fff;padding:.55rem 1.1rem;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:600;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">Abrir pasta →</a> ';
            } else {
                $h .= '<a href="https://ferreiraesa.com.br/conecta/modules/admin/djen_importar.php" style="display:inline-block;background:#a33a2a;color:#fff;padding:.55rem 1.1rem;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:600;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">Vincular manualmente →</a> ';
            }
            $h .= '</div></div>';
        }
    }

    $h .= '<div style="margin-top:1.5rem;padding:1.4rem 1.2rem;background:#052228;color:#f2ede2;border-radius:12px 12px 0 0;text-align:center;font-family:-apple-system,\'Segoe UI\',Arial,sans-serif;">'
        . '<p style="margin:0 0 .5rem;font-size:.85rem;">'
        . '<a href="https://ferreiraesa.com.br/conecta/modules/admin/djen_importar.php" style="color:#d29a5f;text-decoration:underline;font-weight:600;">Ver todas as publicações no Hub Conecta →</a></p>'
        . '<p style="margin:0;font-size:.7rem;color:#8a8378;letter-spacing:.06em;">Claudin · Monitoramento diário do DJEN · Ferreira &amp; Sá Advocacia</p>'
        . '</div>'
        . '</div></body></html>';

    return array('assunto' => $assunto, 'html' => $h);
}

$dataFmt = date('d/m/Y', strtotime($dataAlvo));
$email = preview_montar_email($pdo, $pubs, $contadores, '08', $dataFmt);

// Salva HTML pra Amanda abrir no browser
$fname = 'preview_djen_' . date('Ymd_His') . '.html';
file_put_contents(__DIR__ . '/' . $fname, $email['html']);

// Se ?enviar=1, dispara email de verdade pra Amanda
$enviado = false;
if (!empty($_GET['enviar'])) {
    require_once __DIR__ . '/core/functions_notify.php';
    $enviado = send_brevo_email_simple(
        'amandaguedesferreira@gmail.com',
        'Amanda Guedes Ferreira',
        '[PREVIEW] ' . $email['assunto'],
        $email['html']
    );
}

// Retorno info
header('Content-Type: text/html; charset=utf-8');
echo '<div style="font-family:sans-serif;padding:20px;">';
echo '<h2>✓ Preview gerado</h2>';
echo '<p>Publicações incluídas: <b>' . count($pubs) . '</b> (últimos ' . $dias . ' dias)</p>';
echo '<p><a href="' . $fname . '" style="background:#052228;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:600;">👉 Abrir preview no browser</a></p>';
echo '<p><a href="?key=fsa-hub-deploy-2026&enviar=1&dias=' . $dias . '" style="color:#c76e15;">Enviar cópia por email pra amandaguedesferreira@gmail.com</a></p>';
if ($enviado) echo '<p style="background:#dcfce7;padding:10px;border-radius:6px;color:#166534;">✓ Email enviado com sucesso</p>';
elseif (isset($_GET['enviar'])) echo '<p style="background:#fee2e2;padding:10px;border-radius:6px;color:#991b1b;">✗ Falha no envio (verifique brevo_api_key)</p>';
echo '</div>';
