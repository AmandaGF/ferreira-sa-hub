<?php
/**
 * Ferreira e Sá — Treinamento obrigatório de audiência remota.
 *
 * URL: publico/treinamento_audiencia.php?t=TOKEN
 *
 * Fluxos:
 *   GET  → renderiza a cartilha, o form de aceite e (se já assinou) o certificado
 *   POST → grava aceite e redireciona pra ?t=TOKEN&ok=1
 *
 * Killswitch: configuracoes.treinamento_audiencia_ativo — se '0', o link
 * ainda funciona pra clientes que já receberam (não deixa cliente na mão),
 * só bloqueia geração de novos links no case_ver / cron / etc.
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/google_drive.php';
require_once __DIR__ . '/../core/functions_treinamento_audiencia.php';

@session_start();
$pdo = db();

$token = isset($_GET['t']) ? trim($_GET['t']) : '';
if (!$token || !preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Link inválido</title>';
    echo '<div style="font-family:system-ui;text-align:center;padding:4rem;color:#052228;"><h1>Link inválido</h1><p>Este link de treinamento não é válido. Fale com o escritório pelo WhatsApp <b>(24) 9.9205-0096</b>.</p></div>';
    exit;
}

$reg = treinamento_audiencia_buscar_por_token($pdo, $token);
if (!$reg) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Link não encontrado</title>';
    echo '<div style="font-family:system-ui;text-align:center;padding:4rem;color:#052228;"><h1>Link não encontrado</h1><p>Este treinamento não existe ou foi removido. Fale com o escritório pelo WhatsApp <b>(24) 9.9205-0096</b>.</p></div>';
    exit;
}

// ─── AJAX: recebe PDF em base64 e sobe pro Drive do case ──────────
if (($_GET['acao'] ?? '') === 'salvar_pdf' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (empty($reg['aceite_em'])) {
        echo json_encode(array('ok' => false, 'error' => 'nao_assinado'));
        exit;
    }
    if (!empty($reg['certificado_url'])) {
        // Idempotente: se já subiu, retorna a URL guardada (não sobe de novo)
        echo json_encode(array('ok' => true, 'url' => $reg['certificado_url'], 'ja_salvo' => true));
        exit;
    }

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $base64 = isset($body['pdf_base64']) ? (string)$body['pdf_base64'] : '';
    if (!$base64 || strlen($base64) < 1000) {
        echo json_encode(array('ok' => false, 'error' => 'pdf_invalido'));
        exit;
    }

    // Busca pasta do Drive do case
    $stFolder = $pdo->prepare("SELECT drive_folder_url FROM cases WHERE id = ?");
    $stFolder->execute(array((int)$reg['case_id']));
    $driveFolderUrl = (string)$stFolder->fetchColumn();
    if (!$driveFolderUrl) {
        echo json_encode(array('ok' => false, 'error' => 'case_sem_pasta_drive'));
        exit;
    }

    // Cria/pega subpasta "CERTIFICADOS"
    $sub = drive_get_or_create_subfolder($driveFolderUrl, 'CERTIFICADOS');
    if (empty($sub['success'])) {
        echo json_encode(array('ok' => false, 'error' => 'subpasta_falhou', 'detail' => $sub['error'] ?? ''));
        exit;
    }

    // Nome do arquivo: certificado_treinamento_audiencia_[data]_[nome].pdf
    $nomeSlug = preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower(trim($reg['aceite_nome'])));
    $nomeSlug = trim($nomeSlug, '_');
    $dataStr = date('Ymd_Hi', strtotime($reg['aceite_em']));
    $fileName = 'certificado_treinamento_audiencia_' . $dataStr . '_' . $nomeSlug . '.pdf';

    $up = upload_file_to_drive_base64($sub['folderId'], $fileName, $base64, 'application/pdf');
    if (empty($up['success'])) {
        echo json_encode(array('ok' => false, 'error' => 'upload_falhou', 'detail' => $up['error'] ?? ''));
        exit;
    }

    // Guarda URL no registro
    try {
        $pdo->prepare(
            "UPDATE treinamento_audiencia_aceites
             SET certificado_url = ?, certificado_gerado_em = NOW()
             WHERE id = ?"
        )->execute(array($up['fileUrl'], (int)$reg['id']));
    } catch (Exception $e) {}

    // Cria andamento no case pra ficar visível na timeline
    try {
        $andTexto = 'Cliente ' . $reg['aceite_nome'] . ' concluiu o Treinamento Obrigatório de Audiência Remota em '
                  . date('d/m/Y H:i', strtotime($reg['aceite_em'])) . '. Certificado assinado eletronicamente (IP '
                  . ($reg['aceite_ip'] ?: '—') . ') arquivado em ' . $up['fileUrl'];
        $pdo->prepare(
            "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, visivel_cliente, created_at)
             VALUES (?, NOW(), 'treinamento_audiencia', ?, ?, 1, NOW())"
        )->execute(array((int)$reg['case_id'], $andTexto, (int)$reg['criado_por']));
    } catch (Exception $e) { /* não bloqueia se falhar */ }

    echo json_encode(array('ok' => true, 'url' => $up['fileUrl']));
    exit;
}

$termo = treinamento_audiencia_termo_texto();
$mensagemErro = '';
$mensagemOk = '';

// ─── POST: gravar aceite ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($reg['aceite_em'])) {
    $dados = array(
        'nome' => $_POST['nome'] ?? '',
        'cpf' => $_POST['cpf'] ?? '',
        'checks' => array(
            'leu_cartilha' => !empty($_POST['leu_cartilha']),
            'testou_camera' => !empty($_POST['testou_camera']),
            'testou_mic' => !empty($_POST['testou_mic']),
            'testou_internet' => !empty($_POST['testou_internet']),
            'fez_simulacao' => !empty($_POST['fez_simulacao']),
            'aceita_termo' => !empty($_POST['aceita_termo']),
        ),
    );
    $res = treinamento_audiencia_registrar_aceite($pdo, (int)$reg['id'], $dados);
    if (!empty($res['ok'])) {
        header('Location: treinamento_audiencia.php?t=' . urlencode($token) . '&ok=1');
        exit;
    } else {
        $mapaErros = array(
            'nome_curto' => 'Por favor, digite seu nome completo.',
            'cpf_invalido' => 'CPF inválido. Digite os 11 dígitos.',
            'cpf_nao_confere' => 'O CPF informado não confere com o cadastrado no processo. Verifique se está usando o CPF do titular do processo, não de outra pessoa. Se o problema persistir, fale com o escritório pelo WhatsApp (24) 9.9205-0096.',
        );
        $motivo = $res['motivo'] ?? '';
        if (isset($mapaErros[$motivo])) $mensagemErro = $mapaErros[$motivo];
        elseif (strpos($motivo, 'checkbox_faltando') === 0) $mensagemErro = 'Marque TODAS as confirmações antes de assinar.';
        else $mensagemErro = 'Não foi possível salvar. Tente novamente ou fale com o escritório.';
    }
    // Se erro, recarrega dados atuais pra manter form preenchido
    $reg = treinamento_audiencia_buscar_por_token($pdo, $token);
}

// Recarga após POST ok
if (isset($_GET['ok'])) {
    $mensagemOk = 'Assinatura registrada com sucesso!';
    $reg = treinamento_audiencia_buscar_por_token($pdo, $token);
}

$jaAssinado = !empty($reg['aceite_em']);
$nomeCliente = $reg['aceite_nome'] ?: $reg['client_name'] ?: '';
$primeiroNome = trim(explode(' ', $nomeCliente)[0]);

// Formata data da audiência
$audienciaFmt = '';
if (!empty($reg['audiencia_data_hora'])) {
    $ts = strtotime($reg['audiencia_data_hora']);
    if ($ts) {
        $dias = array('Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado');
        $meses = array('','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
        $audienciaFmt = $dias[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts) . ' às ' . date('H:i', $ts);
    }
}

// Função de escape
if (!function_exists('e')) {
    function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
?><!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Treinamento de Audiência Remota — Ferreira &amp; Sá Advocacia</title>
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root{
            --petrol-900:#052228;
            --petrol-700:#173d46;
            --rose:#d7ab90;
            --brown:#8C5A3B;
            --bg:#F7F3EE;
            --card:#fff;
            --text:#2B2B2B;
            --muted:#5b6a70;
            --success:#168821;
            --danger:#c8544a;
            --border:rgba(140,90,59,.18);
            --shadow:0 8px 24px rgba(5,34,40,.08);
        }
        *{ box-sizing:border-box; }
        html{ scroll-behavior:smooth; }
        body{
            margin:0; font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
            background:var(--bg); color:var(--text); line-height:1.6;
            padding: 16px;
        }
        .wrap{ max-width: 880px; margin: 0 auto; }

        /* HERO */
        .hero{
            background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
            color:#fff; border-radius: 20px;
            padding: 28px 24px; margin-bottom: 20px;
            box-shadow: var(--shadow);
            position: relative; overflow: hidden;
        }
        .hero::before{
            content:""; position:absolute; top:-40px; right:-40px;
            width: 220px; height: 220px; border-radius: 50%;
            background: radial-gradient(circle, rgba(215,171,144,.28) 0%, transparent 70%);
            pointer-events: none;
        }
        .brand{ font-size: 12px; letter-spacing: .16em; color: var(--rose); text-transform: uppercase; font-weight: 700; }
        .hero h1{ margin: 8px 0 8px; font-size: 24px; line-height: 1.25; font-weight: 800; letter-spacing:-.01em; }
        @media (min-width: 720px){ .hero{ padding: 40px 40px; } .hero h1{ font-size: 30px; } }
        .hero-meta{ display:grid; gap: 10px; margin-top: 16px; font-size: 14.5px; color: rgba(255,255,255,.94); }
        .hero-meta .row{ display:flex; align-items:center; gap: 10px; }
        .hero-meta b{ color: var(--rose); font-weight: 700; }

        /* Card genérico */
        .card{
            background: var(--card); border-radius: 18px;
            padding: 22px; margin-bottom: 18px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
        }
        @media (min-width: 720px){ .card{ padding: 28px 32px; } }
        .card h2{ margin: 0 0 10px; font-size: 20px; color: var(--petrol-900); letter-spacing: -.01em; }
        .card > p{ margin: 0 0 10px; color: var(--text); }

        /* Passos com número */
        .steps{ display:grid; gap: 14px; margin: 16px 0 0; }
        .step{
            display:grid; grid-template-columns: 42px 1fr; gap: 14px;
            padding: 14px 16px; border-radius: 14px;
            background: linear-gradient(180deg,#FFFDFB,#FBF6F1);
            border:1px solid var(--border);
        }
        .step-num{
            width:42px; height:42px; border-radius:50%;
            background:linear-gradient(135deg,var(--brown),#B08050);
            color:#fff; font-weight:800; font-size:18px;
            display:inline-flex; align-items:center; justify-content:center;
            box-shadow:0 4px 12px rgba(140,90,59,.28);
        }
        .step b{ display:block; font-size: 15px; margin-bottom: 4px; color: var(--petrol-900); }
        .step p{ margin: 0; font-size: 14.5px; color: var(--muted); line-height: 1.55; }
        .step a.btn-mini{
            display: inline-flex; align-items:center; gap: 6px;
            margin-top: 8px; padding: 8px 14px;
            background: var(--petrol-900); color:#fff; text-decoration:none;
            border-radius: 10px; font-weight: 700; font-size: 13px;
        }
        .step a.btn-mini:hover{ background: var(--petrol-700); }

        /* Cartilha embed link */
        .cartilha-link{
            display:flex; align-items:center; justify-content:space-between;
            gap: 16px; padding: 16px 18px; margin: 14px 0 0;
            background: linear-gradient(135deg,#FBF6F1,#F3E9DF);
            border-radius: 14px; border: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .cartilha-link b{ display:block; margin-bottom: 2px; color: var(--petrol-900); }
        .cartilha-link p{ margin: 0; font-size: 13.5px; color: var(--muted); }
        .cartilha-link a{
            background: var(--brown); color:#fff; text-decoration:none;
            padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 14px;
            white-space: nowrap;
        }

        /* Termo */
        .termo-box{
            background:#FDF9F4; border: 1.5px solid var(--border);
            border-radius: 14px; padding: 18px 20px; margin-top: 14px;
            max-height: 400px; overflow-y: auto;
            font-size: 14px; line-height: 1.65;
        }
        .termo-box h3{ margin: 0 0 8px; font-size: 15px; color: var(--petrol-900); }
        .termo-box .clausula{ margin: 10px 0; }
        .termo-box .clausula b{ display:block; color: var(--brown); font-size: 13.5px; text-transform:uppercase; letter-spacing:.05em; margin-bottom: 4px; }
        .termo-box .clausula p{ margin: 0; text-align: justify; }

        /* Form aceite */
        .checkbox-list{ display:grid; gap: 10px; margin: 14px 0; }
        .checkbox-list label{
            display:flex; align-items:flex-start; gap: 12px;
            padding: 12px 14px; border-radius: 12px;
            background: #FBF6F1; border: 1.5px solid transparent;
            cursor: pointer; transition: all .18s ease;
        }
        .checkbox-list label:hover{ background: #F3E9DF; }
        .checkbox-list label.checked{ border-color: var(--success); background: #ecfdf5; }
        .checkbox-list input[type=checkbox]{
            width: 20px; height: 20px; margin-top: 1px; flex-shrink: 0;
            accent-color: var(--success);
        }
        .checkbox-list span{ font-size: 14.5px; line-height: 1.5; }

        .form-row{ display:grid; gap: 12px; margin: 14px 0; }
        @media (min-width: 620px){ .form-row.dois{ grid-template-columns: 1fr 200px; } }
        .form-row label{ display:block; font-size: 13px; font-weight: 700; margin-bottom: 4px; color: var(--petrol-900); text-transform: uppercase; letter-spacing: .04em; }
        .form-row input[type=text]{
            width:100%; padding: 12px 14px;
            font-size: 15px; border-radius: 10px;
            border: 1.5px solid var(--border); background:#fff;
        }
        .form-row input[type=text]:focus{ outline:none; border-color: var(--brown); }

        .btn-primary{
            display:inline-flex; align-items:center; justify-content:center; gap: 10px;
            background: linear-gradient(135deg, var(--brown), #B08050);
            color:#fff; border:0; cursor:pointer;
            padding: 16px 28px; border-radius: 14px;
            font-size: 16px; font-weight: 800;
            box-shadow: 0 8px 20px rgba(140,90,59,.32);
            transition: transform .18s ease;
            width: 100%;
        }
        .btn-primary:hover{ transform: translateY(-2px); }
        .btn-primary:disabled{ opacity:.55; cursor:not-allowed; transform:none; }

        /* Erro/OK */
        .msg{ padding: 14px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 600; }
        .msg-err{ background:#fde8e8; color: var(--danger); border:1px solid #fbcaca; }
        .msg-ok{ background:#ecfdf5; color: var(--success); border:1px solid #bbf7d0; }

        /* ═══════════════ CERTIFICADO ESTILO DIPLOMA ═══════════════ */
        .cert-wrap{
            /* Papel do diploma — proporção paisagem */
            background:
              radial-gradient(ellipse at top left, rgba(215,171,144,.10), transparent 50%),
              radial-gradient(ellipse at bottom right, rgba(140,90,59,.08), transparent 50%),
              linear-gradient(180deg, #FDFAF5 0%, #F7EFE3 100%);
            border-radius: 12px;
            padding: 30px 26px;
            position: relative;
            box-shadow: 0 24px 60px rgba(5,34,40,.16);
            font-family: Georgia, "Times New Roman", serif;
            color: var(--petrol-900);
            overflow: hidden;
        }
        @media (min-width: 720px){ .cert-wrap{ padding: 56px 60px; } }

        /* Moldura decorativa dupla */
        .cert-wrap::before{
            content:"";
            position: absolute; inset: 12px;
            border: 2px solid var(--brown);
            border-radius: 6px;
            pointer-events: none;
        }
        .cert-wrap::after{
            content:"";
            position: absolute; inset: 18px;
            border: 1px solid rgba(140,90,59,.35);
            border-radius: 4px;
            pointer-events: none;
        }

        .cert-content{ position: relative; z-index: 1; text-align: center; }

        /* Cabeçalho: logo oficial do escritório */
        .cert-logo{
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 8px;
        }
        .cert-logo img{
            display: block;
            width: 100%;
            max-width: 320px;
            height: auto;
        }
        @media (min-width: 720px){
            .cert-logo img{ max-width: 440px; }
        }

        /* Divisor ornamental */
        .cert-div{
            display: flex; align-items: center; justify-content: center;
            gap: 12px; margin: 8px 0 20px;
        }
        .cert-div::before, .cert-div::after{
            content:""; flex: 1; max-width: 90px;
            height: 1px; background: linear-gradient(90deg, transparent, var(--brown), transparent);
        }
        .cert-div span{
            color: var(--brown); font-size: 14px;
            width: 8px; height: 8px; border-radius: 50%;
            background: var(--brown); display: inline-block;
        }

        /* Título */
        .cert-title{
            font-family: Georgia, serif;
            font-size: 30px;
            font-weight: 400;
            letter-spacing: .28em;
            color: var(--petrol-900);
            margin: 0 0 4px;
            text-transform: uppercase;
        }
        @media (min-width: 720px){ .cert-title{ font-size: 40px; } }
        .cert-sub{
            font-size: 13px;
            color: var(--brown);
            letter-spacing: .22em;
            text-transform: uppercase;
            margin: 0 0 24px;
        }

        /* Texto formal */
        .cert-body{
            font-size: 15px;
            line-height: 1.85;
            color: #2B2B2B;
            max-width: 640px;
            margin: 12px auto 0;
            text-align: justify;
            text-align-last: center;
        }
        @media (min-width: 720px){ .cert-body{ font-size: 16px; line-height: 1.9; } }
        .cert-body p{ margin: 0 0 12px; }

        /* Nome do cliente em destaque */
        .cert-nome{
            font-family: "Brush Script MT", "Snell Roundhand", Georgia, cursive;
            font-size: 34px;
            color: var(--petrol-900);
            margin: 18px 0 4px;
            font-weight: 400;
            font-style: italic;
            letter-spacing: .01em;
            line-height: 1.15;
        }
        @media (min-width: 720px){ .cert-nome{ font-size: 46px; margin: 26px 0 6px; } }
        .cert-nome-linha{
            border-top: 1px solid rgba(140,90,59,.42);
            max-width: 500px;
            margin: 0 auto 4px;
        }
        .cert-cpf{
            font-family: system-ui, sans-serif;
            font-size: 12px;
            color: var(--muted);
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        /* Linha formal de registro (IP + hora) */
        .cert-registro{
            margin: 24px auto 0;
            max-width: 640px;
            padding: 12px 18px;
            border-top: 1px dashed rgba(140,90,59,.32);
            border-bottom: 1px dashed rgba(140,90,59,.32);
            font-family: Georgia, serif;
            font-size: 13.5px;
            color: var(--petrol-900);
            line-height: 1.65;
            text-align: center;
            font-style: italic;
        }
        .cert-registro b{
            font-style: normal;
            font-family: ui-monospace, "Courier New", monospace;
            font-weight: 700;
            color: var(--brown);
            font-size: 13px;
            letter-spacing: .02em;
        }
        @media (min-width: 720px){
            .cert-registro{ font-size: 14.5px; padding: 14px 24px; }
            .cert-registro b{ font-size: 14px; }
        }

        /* Rodapé com assinatura + selo */
        .cert-foot{
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            margin-top: 30px;
            padding-top: 22px;
            border-top: 1px solid rgba(140,90,59,.22);
            text-align: center;
        }
        @media (min-width: 720px){
            .cert-foot{ grid-template-columns: 1fr auto 1fr; gap: 40px; align-items: end; text-align: left; }
        }
        .cert-sig{
            text-align: center;
        }
        .cert-sig-line{
            font-family: "Brush Script MT", "Snell Roundhand", Georgia, cursive;
            font-size: 24px;
            color: var(--petrol-900);
            font-style: italic;
            margin-bottom: 2px;
            border-bottom: 1px solid rgba(140,90,59,.42);
            padding-bottom: 4px;
            display: inline-block;
            min-width: 200px;
        }
        .cert-sig-nome{
            font-family: system-ui, sans-serif;
            font-size: 12px; font-weight: 700;
            color: var(--petrol-900);
            letter-spacing: .04em;
            margin-top: 6px;
        }
        .cert-sig-role{
            font-family: system-ui, sans-serif;
            font-size: 11px;
            color: var(--muted);
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        /* Monograma F&S como selo institucional */
        .cert-selo{
            width: 92px; height: 92px;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 6px 18px rgba(5,34,40,.35);
            margin: 0 auto;
            display: block;
        }
        .cert-selo img{
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .cert-meta{
            font-family: system-ui, sans-serif;
            font-size: 11px;
            color: var(--muted);
            letter-spacing: .04em;
            line-height: 1.55;
        }
        .cert-meta strong{
            color: var(--petrol-900);
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            font-size: 10.5px;
            display: block;
            margin-bottom: 3px;
        }
        .cert-hash-box{
            font-family: ui-monospace, "Courier New", monospace;
            font-size: 10.5px;
            color: var(--muted);
            background: rgba(255,255,255,.7);
            border: 1px dashed rgba(140,90,59,.3);
            border-radius: 6px;
            padding: 8px 12px;
            margin: 22px auto 0;
            word-break: break-all;
            max-width: 620px;
            text-align: center;
        }

        /* Print: força paisagem no A4 e mostra APENAS o certificado limpo */
        @media print {
            @page { size: A4 landscape; margin: 0; }
            html, body{
                background:#fff !important;
                margin: 0 !important; padding: 0 !important;
                width: 100%;
            }
            /* Esconde TUDO do body por padrão */
            body > *{ display: none !important; }
            /* Mostra só o wrapper que contém o certificado */
            body > .wrap{ display: block !important; }
            .wrap > *{ display: none !important; }
            .wrap > .cert-wrap{ display: block !important; }
            /* Certificado ocupa a folha inteira */
            .cert-wrap{
                box-shadow: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                padding: 12mm 16mm !important;
                width: 100% !important;
                min-height: 100vh;
                page-break-inside: avoid;
            }
            .cert-wrap::before{ inset: 6mm !important; }
            .cert-wrap::after{ inset: 8mm !important; }
            .no-print{ display: none !important; }
        }

        .footer{
            text-align:center; margin: 30px 0 6px;
            font-size: 12px; color: var(--muted);
        }

        @media print{
            body{ background:#fff; padding: 0; }
            .no-print{ display:none !important; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <!-- HERO -->
    <div class="hero">
        <div class="brand">Ferreira &amp; Sá Advocacia</div>
        <h1><?= $jaAssinado ? 'Treinamento concluído' : 'Treinamento Obrigatório — Audiência por Videoconferência' ?></h1>
        <div class="hero-meta">
            <?php if ($primeiroNome): ?>
                <div class="row">👤 <span><b>Cliente:</b> <?= e($nomeCliente) ?></span></div>
            <?php endif; ?>
            <?php if (!empty($reg['case_title'])): ?>
                <div class="row">📁 <span><b>Processo:</b> <?= e($reg['case_title']) ?><?= $reg['case_number'] ? ' — ' . e($reg['case_number']) : '' ?></span></div>
            <?php endif; ?>
            <?php if ($audienciaFmt): ?>
                <div class="row">📅 <span><b>Audiência:</b> <?= e($audienciaFmt) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($mensagemOk): ?>
        <div class="msg msg-ok">✅ <?= e($mensagemOk) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="msg msg-err">⚠️ <?= e($mensagemErro) ?></div>
    <?php endif; ?>

    <?php if ($jaAssinado): ?>
        <!-- ═══════════════ CERTIFICADO DIPLOMA ═══════════════ -->
        <div class="cert-wrap">
            <div class="cert-content">

                <!-- Logo oficial do escritório -->
                <div class="cert-logo">
                    <img src="https://ferreiraesa.com.br/conecta/assets/img/logo.png" alt="Ferreira & Sá Advocacia Especializada">
                </div>

                <!-- Divisor ornamental -->
                <div class="cert-div"><span></span></div>

                <!-- Título -->
                <h1 class="cert-title">Certificado</h1>
                <div class="cert-sub">Treinamento Obrigatório de Audiência por Videoconferência</div>

                <!-- Preâmbulo -->
                <div class="cert-body">
                    <p>Certificamos, para os devidos fins de prova, que</p>
                </div>

                <!-- Nome do cliente -->
                <div class="cert-nome"><?= e($reg['aceite_nome']) ?></div>
                <div class="cert-nome-linha"></div>
                <?php if (!empty($reg['aceite_cpf'])): ?>
                    <div class="cert-cpf">CPF <?= e(treinamento_audiencia_cpf_mascarado($reg['aceite_cpf'])) ?></div>
                <?php endif; ?>

                <!-- Corpo do texto -->
                <div class="cert-body">
                    <p>
                        concluiu integralmente o <b>Treinamento Obrigatório</b> ministrado pelo escritório <b>FERREIRA &amp; SÁ ADVOCACIA ESPECIALIZADA</b>,
                        preparatório para a participação em audiência por meio de videoconferência<?= !empty($reg['case_title']) ? ', nos autos do processo <b>' . e($reg['case_title']) . '</b>' . (!empty($reg['case_number']) ? ' (nº ' . e($reg['case_number']) . ')' : '') : '' ?><?= $audienciaFmt ? ', com sessão designada para <b>' . e($audienciaFmt) . '</b>' : '' ?>.
                    </p>
                    <p>
                        Tendo, para tanto, procedido à leitura integral da cartilha institucional, realizado os testes prévios
                        de câmera, microfone e conexão de internet, e firmado, por meio de assinatura eletrônica, o competente
                        <b>Termo de Ciência e Responsabilidade</b>, encontra-se APTO(A) a participar da audiência remota
                        designada, assumindo as responsabilidades técnicas ali descritas.
                    </p>
                </div>

                <!-- Linha formal de registro (IP + hora) — destacada como parte do texto -->
                <div class="cert-registro">
                    Assinatura eletrônica registrada em <b><?= date('d/m/Y', strtotime($reg['aceite_em'])) ?> às <?= date('H:i:s', strtotime($reg['aceite_em'])) ?></b><?php if (!empty($reg['aceite_ip'])): ?>, a partir do endereço IP <b><?= e($reg['aceite_ip']) ?></b><?php endif; ?>, sob código único de verificação abaixo.
                </div>

                <!-- Rodapé: assinatura + selo + meta -->
                <div class="cert-foot">
                    <!-- Assinatura -->
                    <div class="cert-sig">
                        <div class="cert-sig-line">Amanda Guedes Ferreira</div>
                        <div class="cert-sig-nome">Amanda Guedes Ferreira</div>
                        <div class="cert-sig-role">Sócia — OAB/RS 005.987</div>
                    </div>

                    <!-- Selo com monograma oficial F&S -->
                    <div class="cert-selo" aria-hidden="true">
                        <img src="https://ferreiraesa.com.br/conecta/assets/img/site/monograma.png" alt="Monograma Ferreira & Sá">
                    </div>

                    <!-- Meta -->
                    <div class="cert-meta" style="text-align: right;">
                        <strong>Registro de assinatura</strong>
                        <?= date('d/m/Y', strtotime($reg['aceite_em'])) ?> às <?= date('H:i', strtotime($reg['aceite_em'])) ?><br>
                        <?php if (!empty($reg['aceite_ip'])): ?>
                            IP <?= e($reg['aceite_ip']) ?><br>
                        <?php endif; ?>
                        Versão do termo: <?= e($reg['aceite_termo_versao'] ?: '—') ?>
                    </div>
                </div>

                <!-- Hash de verificação -->
                <div class="cert-hash-box" title="Hash SHA-256 da assinatura eletrônica">
                    <strong style="color: var(--brown);">CÓDIGO DE VERIFICAÇÃO:</strong>
                    <?= e(substr($reg['aceite_checks_hash'] ?? '', 0, 32)) ?>
                </div>

                <!-- Institucional footer -->
                <div style="margin-top: 18px; font-size: 11px; color: var(--muted); font-family: system-ui, sans-serif; letter-spacing: .04em;">
                    FERREIRA &amp; SÁ ADVOCACIA ESPECIALIZADA • CNPJ 51.294.223/0001-40 • WhatsApp (24) 9.9205-0096 • www.ferreiraesa.com.br
                </div>
            </div>
        </div>

        <div style="text-align: center; margin: 24px 0;" class="no-print">

            <!-- Status do arquivamento no processo -->
            <div id="cert-status" style="max-width: 640px; margin: 0 auto 16px; padding: 12px 16px; border-radius: 12px; font-size: 14px; font-weight: 600;">
                <?php if (!empty($reg['certificado_url'])): ?>
                    <div style="background: #ecfdf5; border: 1px solid #86efac; color: #059669; padding: 10px 14px; border-radius: 10px;">
                        ✅ Cópia arquivada com sucesso no seu processo em <?= e(date('d/m/Y H:i', strtotime($reg['certificado_gerado_em'] ?: $reg['aceite_em']))) ?>.
                    </div>
                <?php else: ?>
                    <div id="cert-status-loading" style="background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 10px 14px; border-radius: 10px;">
                        ⏳ Arquivando uma cópia deste certificado no seu processo... <span id="cert-progress" style="font-size:12px;opacity:.8;"></span>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display:flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <button onclick="window.print()" class="btn-primary" style="max-width: 320px;">🖨️ Imprimir / Salvar como PDF</button>
                <a id="cert-drive-link" href="<?= e($reg['certificado_url'] ?? '') ?>" target="_blank" rel="noopener" class="link-externo btn-primary" style="max-width: 320px; background: linear-gradient(135deg, #10b981, #059669); box-shadow: 0 8px 20px rgba(16,185,129,.32); text-decoration:none; <?= empty($reg['certificado_url']) ? 'display:none;' : '' ?>">
                    📁 Ver cópia no processo ↗
                </a>
            </div>

            <p style="font-size: 12.5px; color: var(--muted); margin-top: 12px; max-width: 640px; margin-left: auto; margin-right: auto;">
                <b>Imprimir</b>: escolha “Salvar como PDF” no destino da impressão. Certificado em <b>A4 paisagem</b>.<br>
                <b>Cópia no processo</b>: uma cópia em PDF é anexada automaticamente à pasta do seu processo — não precisa fazer nada.
            </p>
        </div>

        <!-- ═══════════════ JS: gera PDF client-side e envia pro servidor ═══════════════ -->
        <?php if (empty($reg['certificado_url'])): ?>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" integrity="sha512-GsLlZN/3F2ErC5ifS5QtgpiJtWd43JWSuIgh7mbzZ8zBps+dvLusV+eNQATqgA/HdeKFVgA5v3S/cIrLF7QnIg==" crossorigin="anonymous"></script>
        <script>
        (function(){
            var statusBox = document.getElementById('cert-status-loading');
            var progress  = document.getElementById('cert-progress');
            var driveLink = document.getElementById('cert-drive-link');
            var certEl    = document.querySelector('.cert-wrap');
            if (!statusBox || !certEl || typeof html2pdf === 'undefined') {
                if (statusBox) {
                    statusBox.style.background = '#fef3c7';
                    statusBox.style.borderColor = '#fcd34d';
                    statusBox.style.color = '#92400e';
                    statusBox.innerHTML = '⚠️ Não foi possível arquivar a cópia automática. Use o botão “Imprimir / Salvar como PDF” pra guardar uma cópia.';
                }
                return;
            }

            function setErro(msg) {
                statusBox.style.background = '#fef3c7';
                statusBox.style.borderColor = '#fcd34d';
                statusBox.style.color = '#92400e';
                statusBox.innerHTML = '⚠️ ' + msg + ' Use o botão “Imprimir / Salvar como PDF” pra guardar uma cópia.';
            }

            function setSucesso(url) {
                statusBox.style.background = '#ecfdf5';
                statusBox.style.borderColor = '#86efac';
                statusBox.style.color = '#059669';
                statusBox.innerHTML = '✅ Cópia arquivada com sucesso no seu processo.';
                if (driveLink && url) {
                    driveLink.href = url;
                    driveLink.style.display = 'inline-flex';
                }
            }

            progress.textContent = ' gerando arquivo...';

            var opts = {
                margin: 0,
                filename: 'certificado_treinamento_audiencia.pdf',
                image: { type: 'jpeg', quality: 0.95 },
                html2canvas: { scale: 2, useCORS: true, backgroundColor: '#ffffff' },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'landscape' },
            };

            // Timeout de segurança
            var timeoutId = setTimeout(function(){ setErro('A geração demorou mais do que o esperado.'); }, 60000);

            html2pdf().set(opts).from(certEl).outputPdf('datauristring').then(function(dataUri){
                clearTimeout(timeoutId);
                progress.textContent = ' enviando ao processo...';
                // Remove "data:application/pdf;base64," do início
                var base64 = dataUri.replace(/^data:application\/pdf;base64,/, '');
                var url = window.location.pathname + '?t=' + encodeURIComponent(<?= json_encode($token) ?>) + '&acao=salvar_pdf';
                return fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pdf_base64: base64 }),
                });
            }).then(function(resp){ return resp.json(); }).then(function(data){
                if (data && data.ok) {
                    setSucesso(data.url);
                } else {
                    var msg = 'Não foi possível arquivar no processo automaticamente';
                    if (data && data.error === 'case_sem_pasta_drive') msg = 'Sua pasta no Drive ainda não foi criada pelo escritório.';
                    setErro(msg + '.');
                }
            }).catch(function(err){
                clearTimeout(timeoutId);
                setErro('Erro ao gerar/enviar o PDF.');
                console.error(err);
            });
        })();
        </script>
        <?php endif; ?>

    <?php else: ?>

        <!-- ═══════════════ ETAPAS DE TREINAMENTO ═══════════════ -->
        <div class="card">
            <h2>Como funciona</h2>
            <p>Este treinamento é obrigatório e tem como objetivo garantir que você esteja preparado(a) para participar da audiência por vídeo com tranquilidade. São 3 etapas simples:</p>

            <div class="steps">
                <div class="step">
                    <div class="step-num">1</div>
                    <div>
                        <b>Leia a cartilha completa</b>
                        <p>Ela explica passo a passo como acessar a audiência pelo Microsoft Teams, o que fazer no dia, o que levar em mãos e como se preparar.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">2</div>
                    <div>
                        <b>Faça os testes técnicos</b>
                        <p>Teste sua câmera, seu microfone e sua conexão de internet <b>com pelo menos 3 dias de antecedência</b>. Use a sala de simulação da cartilha.</p>
                    </div>
                </div>
                <div class="step">
                    <div class="step-num">3</div>
                    <div>
                        <b>Assine eletronicamente e emita seu certificado</b>
                        <p>Confirme que fez os testes, digite seu nome e CPF, aceite o termo. O certificado é gerado na hora e uma cópia é anexada ao seu processo.</p>
                    </div>
                </div>
            </div>

            <div class="cartilha-link">
                <div>
                    <b>📖 Cartilha completa de audiências</b>
                    <p>Abre em uma nova aba — você continua nesta página aberta pra assinar depois.</p>
                </div>
                <a class="link-externo" href="https://ferreiraesa.com.br/audiencias/" target="_blank" rel="noopener noreferrer external">Abrir cartilha ↗</a>
            </div>
        </div>

        <!-- ═══════════════ TERMO ═══════════════ -->
        <div class="card">
            <h2>Termo de Ciência e Responsabilidade</h2>
            <p style="color: var(--muted); font-size: 13.5px; margin-bottom: 8px;">
                Leia com atenção antes de aceitar. Este termo é o documento que será assinado eletronicamente por você e anexado ao seu processo.
            </p>

            <div class="termo-box">
                <h3><?= e($termo['titulo']) ?></h3>
                <p style="text-align: justify;"><?= e($termo['preambulo']) ?></p>
                <?php foreach ($termo['clausulas'] as $cl): ?>
                    <div class="clausula">
                        <b>Cláusula <?= e($cl['num']) ?> — <?= e($cl['titulo']) ?></b>
                        <p><?= e($cl['texto']) ?></p>
                    </div>
                <?php endforeach; ?>
                <p style="text-align: justify; margin-top: 14px; padding-top: 10px; border-top: 1px solid var(--border);">
                    <?= e($termo['aceite_final']) ?>
                </p>
                <p style="font-size: 12px; color: var(--muted); margin-top: 10px; text-align: right;">
                    Versão: <?= e($termo['versao']) ?>
                </p>
            </div>
        </div>

        <!-- ═══════════════ FORM DE ACEITE ═══════════════ -->
        <div class="card">
            <h2>Confirmação e assinatura eletrônica</h2>
            <p>Marque todas as confirmações abaixo e preencha seus dados:</p>

            <form method="POST" id="acForm" novalidate>
                <div class="checkbox-list">
                    <label>
                        <input type="checkbox" name="leu_cartilha" value="1" required>
                        <span>Li a <b>cartilha completa</b> sobre audiências e entendi como acessar o Microsoft Teams.</span>
                    </label>
                    <label>
                        <input type="checkbox" name="testou_camera" value="1" required>
                        <span>Testei minha <b>câmera</b> — ela funciona e a imagem aparece corretamente.</span>
                    </label>
                    <label>
                        <input type="checkbox" name="testou_mic" value="1" required>
                        <span>Testei meu <b>microfone</b> — o som sai com clareza.</span>
                    </label>
                    <label>
                        <input type="checkbox" name="testou_internet" value="1" required>
                        <span>Testei minha <b>conexão de internet</b> — ela está estável no local onde participarei da audiência.</span>
                    </label>
                    <label>
                        <input type="checkbox" name="fez_simulacao" value="1" required>
                        <span>Fiz a <b>simulação na sala de teste</b> e me senti à vontade com os controles de câmera, microfone e chat.</span>
                    </label>
                    <label>
                        <input type="checkbox" name="aceita_termo" value="1" required>
                        <span>Li e aceito integralmente o <b>Termo de Ciência e Responsabilidade</b> acima, assumindo as responsabilidades ali descritas.</span>
                    </label>
                </div>

                <div class="form-row dois">
                    <div>
                        <label for="nome">Nome completo</label>
                        <input type="text" id="nome" name="nome" required autocomplete="name" placeholder="Como consta no seu documento" value="<?= e($_POST['nome'] ?? $reg['client_name'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="cpf">CPF</label>
                        <input type="text" id="cpf" name="cpf" required inputmode="numeric" placeholder="000.000.000-00" maxlength="14" value="<?= e($_POST['cpf'] ?? '') ?>">
                    </div>
                </div>

                <button type="submit" class="btn-primary" id="btnAssinar" disabled>
                    ✍️ Assinar eletronicamente e emitir certificado
                </button>

                <p style="font-size: 12.5px; color: var(--muted); margin-top: 14px; text-align: center; line-height: 1.6;">
                    Ao clicar, você declara estar de acordo com o Termo acima. Seu IP, dispositivo e horário serão registrados
                    como prova da assinatura eletrônica, conforme legislação aplicável.
                </p>
            </form>
        </div>

    <?php endif; ?>

    <div class="footer">
        FERREIRA &amp; SÁ ADVOCACIA ESPECIALIZADA<br>
        CNPJ 51.294.223/0001-40 • WhatsApp (24) 9.9205-0096
    </div>
</div>

<!-- Fallback pros webviews (WhatsApp/IG in-app browser) que às vezes ignoram target=_blank -->
<script>
document.addEventListener('click', function(e){
    var a = e.target.closest('a.link-externo');
    if (!a) return;
    var opened = window.open(a.href, '_blank');
    if (opened) { e.preventDefault(); try { opened.opener = null; } catch(_){} }
});
</script>

<?php if (!$jaAssinado): ?>
<script>
(function(){
    var form = document.getElementById('acForm');
    if (!form) return;
    var btn = document.getElementById('btnAssinar');
    var cpf = document.getElementById('cpf');
    var checks = form.querySelectorAll('input[type=checkbox]');

    // Máscara CPF
    if (cpf) {
        cpf.addEventListener('input', function(){
            var v = cpf.value.replace(/\D/g, '').slice(0, 11);
            if (v.length > 9) v = v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6,9) + '-' + v.slice(9);
            else if (v.length > 6) v = v.slice(0,3) + '.' + v.slice(3,6) + '.' + v.slice(6);
            else if (v.length > 3) v = v.slice(0,3) + '.' + v.slice(3);
            cpf.value = v;
        });
    }

    // Estado visual do label + habilitação do botão
    function refresh() {
        var allChecked = true;
        checks.forEach(function(c){
            var label = c.closest('label');
            if (c.checked) label.classList.add('checked');
            else { label.classList.remove('checked'); allChecked = false; }
        });
        var nome = form.nome.value.trim();
        var cpfDig = form.cpf.value.replace(/\D/g,'');
        btn.disabled = !(allChecked && nome.length >= 5 && cpfDig.length === 11);
    }
    checks.forEach(function(c){ c.addEventListener('change', refresh); });
    form.nome.addEventListener('input', refresh);
    form.cpf.addEventListener('input', refresh);
    refresh();
})();
</script>
<?php endif; ?>
</body>
</html>
