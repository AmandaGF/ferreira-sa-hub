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

        /* Certificado */
        .cert{
            background: linear-gradient(135deg, #FBF6F1 0%, #F3E9DF 100%);
            border: 3px double var(--brown);
            border-radius: 18px;
            padding: 32px 26px;
            text-align: center;
            box-shadow: var(--shadow);
        }
        @media (min-width: 720px){ .cert{ padding: 46px 40px; } }
        .cert-brand{ color: var(--brown); letter-spacing: .18em; font-size: 12px; font-weight: 800; text-transform: uppercase; }
        .cert h2{ color: var(--petrol-900); margin: 12px 0; font-size: 22px; }
        .cert .nome{ color: var(--petrol-900); font-size: 24px; font-weight: 800; margin: 16px 0 4px; letter-spacing: -.01em; }
        .cert .cpf{ color: var(--muted); font-size: 14px; }
        .cert .decl{ margin: 22px auto; max-width: 560px; font-size: 15px; color: var(--text); line-height: 1.7; }
        .cert-hash{
            font-family: ui-monospace, "Courier New", monospace;
            font-size: 11px; color: var(--muted);
            background: rgba(255,255,255,.5); border-radius: 8px;
            padding: 8px 12px; margin-top: 20px;
            word-break: break-all;
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
        <!-- ═══════════════ CERTIFICADO ═══════════════ -->
        <div class="cert">
            <div class="cert-brand">Ferreira &amp; Sá Advocacia Especializada</div>
            <h2>Certificado de Conclusão</h2>
            <p style="color: var(--muted); font-size: 14px; margin: 0;">Treinamento Obrigatório — Audiência por Videoconferência</p>

            <div class="nome"><?= e($reg['aceite_nome']) ?></div>
            <?php if (!empty($reg['aceite_cpf'])): ?>
                <div class="cpf">CPF <?= e(treinamento_audiencia_cpf_mascarado($reg['aceite_cpf'])) ?></div>
            <?php endif; ?>

            <div class="decl">
                Concluiu o treinamento obrigatório sobre a participação em audiência por videoconferência,
                tendo lido a cartilha, realizado os testes técnicos prévios e assinado o Termo de Ciência
                e Responsabilidade correspondente<?= !empty($reg['case_title']) ? ', nos autos do processo <b>' . e($reg['case_title']) . '</b>' : '' ?>.
            </div>

            <div style="margin-top: 20px; font-size: 14px; color: var(--petrol-900);">
                <b>Assinado eletronicamente em</b><br>
                <?= date('d/m/Y \à\s H:i', strtotime($reg['aceite_em'])) ?>
                <?php if (!empty($reg['aceite_ip'])): ?>
                    <br><span style="font-size: 12px; color: var(--muted);">IP <?= e($reg['aceite_ip']) ?></span>
                <?php endif; ?>
            </div>

            <div class="cert-hash" title="Hash de integridade do aceite eletrônico">
                Código de verificação: <?= e(substr($reg['aceite_checks_hash'] ?? '', 0, 16)) ?>...
            </div>

            <div style="margin-top: 22px; padding-top: 20px; border-top: 1px solid var(--border); font-size: 12.5px; color: var(--muted); line-height: 1.6;">
                CNPJ 51.294.223/0001-40 • OAB/RS 005.987/2023<br>
                Contato: WhatsApp (24) 9.9205-0096 • www.ferreiraesa.com.br
            </div>
        </div>

        <div style="text-align: center; margin: 20px 0;" class="no-print">
            <button onclick="window.print()" class="btn-primary" style="max-width: 320px; margin: 0 auto;">🖨️ Imprimir / Salvar como PDF</button>
            <p style="font-size: 12.5px; color: var(--muted); margin-top: 12px;">
                Ao clicar em <b>Imprimir</b>, escolha “Salvar como PDF” no destino da impressão pra guardar uma cópia.
                <br>Uma cópia também é anexada automaticamente ao seu processo pelo escritório.
            </p>
        </div>

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
                    <p>Abre em uma nova aba. Leia inteira antes de continuar.</p>
                </div>
                <a href="https://ferreiraesa.com.br/audiencias/" target="_blank" rel="noopener">Abrir cartilha</a>
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
