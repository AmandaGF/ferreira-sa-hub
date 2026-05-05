<?php
/**
 * Ferreira & Sá Advocacia — Página pública de Boas-Vindas
 * Acesso: público (sem login), via token único + autenticação por
 * nome completo + data de nascimento (conforme cadastro do admin).
 */
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/onboarding_docs_schema.php';

@session_start();

$pdo = db();
$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$erro = '';
$reg = null;
$autenticado = false;

if (!$token || !preg_match('/^[a-f0-9]{16,48}$/', $token)) {
    http_response_code(404);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:3rem;">Link inválido ou expirado.</h1>';
    exit;
}

// Carrega cadastro pelo token
try {
    $st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE token = ? AND status != 'arquivado'");
    $st->execute(array($token));
    $reg = $st->fetch();
} catch (Exception $e) {
    $reg = null;
}

if (!$reg) {
    http_response_code(404);
    echo '<h1 style="font-family:sans-serif;text-align:center;padding:3rem;">Link inválido ou expirado.</h1>';
    exit;
}

// Sessão de auth: chave por token
$sessKey = 'onb_auth_' . $token;

// Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_login'])) {
    $nomeIn = trim(mb_strtolower($_POST['nome_login'] ?? '', 'UTF-8'));
    $dataIn = trim($_POST['data_login'] ?? '');
    $nomeOk = mb_strtolower($reg['nome_completo'], 'UTF-8');
    $nomeOkSemAcento = preg_replace('/[áàâãä]/u', 'a', $nomeOk);
    $nomeOkSemAcento = preg_replace('/[éèêë]/u', 'e', $nomeOkSemAcento);
    $nomeOkSemAcento = preg_replace('/[íìîï]/u', 'i', $nomeOkSemAcento);
    $nomeOkSemAcento = preg_replace('/[óòôõö]/u', 'o', $nomeOkSemAcento);
    $nomeOkSemAcento = preg_replace('/[úùûü]/u', 'u', $nomeOkSemAcento);
    $nomeOkSemAcento = preg_replace('/ç/u', 'c', $nomeOkSemAcento);
    $nomeInSemAcento = preg_replace('/[áàâãä]/u', 'a', $nomeIn);
    $nomeInSemAcento = preg_replace('/[éèêë]/u', 'e', $nomeInSemAcento);
    $nomeInSemAcento = preg_replace('/[íìîï]/u', 'i', $nomeInSemAcento);
    $nomeInSemAcento = preg_replace('/[óòôõö]/u', 'o', $nomeInSemAcento);
    $nomeInSemAcento = preg_replace('/[úùûü]/u', 'u', $nomeInSemAcento);
    $nomeInSemAcento = preg_replace('/ç/u', 'c', $nomeInSemAcento);

    if ($nomeInSemAcento === $nomeOkSemAcento && $dataIn === $reg['data_nascimento']) {
        $_SESSION[$sessKey] = true;
        try {
            $pdo->prepare("UPDATE colaboradores_onboarding SET ultimo_acesso_em = NOW(), status = IF(status='pendente','ativo',status) WHERE id = ?")
                ->execute(array($reg['id']));
        } catch (Exception $e) {}
        header('Location: ?token=' . urlencode($token));
        exit;
    } else {
        $erro = 'Nome ou data de nascimento incorretos. Confira com o RH.';
    }
}

// Aceite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_aceitar']) && !empty($_SESSION[$sessKey])) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare("UPDATE colaboradores_onboarding SET aceite_em = NOW(), aceite_ip = ?, status = 'aceito' WHERE id = ?")
            ->execute(array($ip, $reg['id']));
        $reg['aceite_em'] = date('Y-m-d H:i:s');
        $reg['aceite_ip'] = $ip;
        $reg['status'] = 'aceito';
    } catch (Exception $e) {}
}

// Salvar tamanho da camisa (action ajax dentro da propria pagina)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_tamanho_camisa']) && !empty($_SESSION[$sessKey])) {
    header('Content-Type: application/json; charset=utf-8');
    $tam = strtoupper(trim($_POST['tamanho'] ?? ''));
    if (!in_array($tam, array('P','M','G','GG'), true)) {
        echo json_encode(array('ok' => false, 'erro' => 'Tamanho inválido'));
        exit;
    }
    try {
        $pdo->prepare("UPDATE colaboradores_onboarding SET tamanho_camisa = ? WHERE id = ?")
            ->execute(array($tam, $reg['id']));
        // Notificar admins (Amanda/Luiz) que a colaboradora escolheu o tamanho
        try {
            require_once __DIR__ . '/../../core/functions_notify.php';
            if (function_exists('notify_admins')) {
                notify_admins(
                    '👕 Tamanho de camisa escolhido',
                    htmlspecialchars($reg['nome_completo']) . ' escolheu tamanho ' . $tam . ' para o kit.',
                    null
                );
            }
        } catch (Exception $e) {}
        echo json_encode(array('ok' => true, 'tamanho' => $tam));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'erro' => $e->getMessage()));
    }
    exit;
}

$autenticado = !empty($_SESSION[$sessKey]);

// Carrega documentos vinculados quando ja autenticado
$documentosVinculados = array();
if ($autenticado && $reg) {
    try {
        $stD = $pdo->prepare("SELECT id, tipo, status, dados_admin_json, dados_estagiario_json,
                                     assinatura_estagiario_em, pdf_drive_url
                              FROM colaboradores_documentos
                              WHERE colaborador_id = ?
                              ORDER BY id ASC");
        $stD->execute(array($reg['id']));
        $documentosVinculados = $stD->fetchAll();
    } catch (Exception $e) {
        $documentosVinculados = array();
    }
}

/**
 * Helper de gênero — retorna uma das 2 strings dependendo do gênero.
 * Ex: g('bem-vinda', 'bem-vindo', $reg['genero'])
 * Default (genero null) = feminino, pra evitar "(o)/(a)" feio na tela.
 */
function g($fem, $masc, $genero) {
    return ($genero === 'M') ? $masc : $fem;
}

function fmt_data_br($d) {
    if (!$d) return '';
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt ? $dt->format('d/m/Y') : $d;
}
function fmt_horario($h) {
    if (!$h) return '';
    return preg_replace('/:00$/', '', substr($h, 0, 5));
}
function fmt_moeda($v) {
    if ($v === null || $v === '') return '';
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bem-vinda(o) — Ferreira &amp; Sá Advocacia</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Open+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --petrol-900: #052228;
    --petrol-700: #173d46;
    --cobre: #6a3c2c;
    --cobre-light: #B87333;
    --nude: #d7ab90;
    --nude-light: #fff7ed;
    --bg: #f8f4ef;
    --card: #ffffff;
    --text: #1e1e1e;
    --muted: #6b6b6b;
    --rose: #ec4899;
    --shadow: 0 4px 24px rgba(5,34,40,.08);
    --radius: 18px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Open Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    min-height: 100vh;
}
h1, h2, h3, h4 { font-family: 'Playfair Display', serif; color: var(--petrol-900); }

/* ── HERO ───────────────────────────────────────────────── */
.hero {
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
    color: #fff;
    text-align: center;
    padding: 4rem 1.5rem 5rem;
    position: relative;
    overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background-image:
        radial-gradient(circle at 20% 30%, rgba(215,171,144,.18) 0%, transparent 40%),
        radial-gradient(circle at 80% 60%, rgba(184,115,51,.15) 0%, transparent 50%);
}
.hero-inner { position: relative; z-index: 1; max-width: 720px; margin: 0 auto; }
.hero-logo {
    background: rgba(255, 255, 255, .96);
    border-radius: 14px;
    padding: 14px 28px;
    display: inline-block;
    margin-bottom: .9rem;
    box-shadow: 0 6px 18px rgba(0, 0, 0, .12);
}
.hero-logo img { max-height: 60px; width: auto; display: block; }
.hero-logo-fallback { font-family: 'Playfair Display', serif; font-size: 1.6rem; letter-spacing: .15em; font-weight: 700; color: var(--petrol-900); }
.hero-subtitle { font-size: .72rem; letter-spacing: .35em; opacity: .65; text-transform: uppercase; margin-bottom: 2rem; color:#fff; }
.hero-emoji { font-size: 4rem; margin-bottom: 1rem; line-height: 1; }
.hero-foto-colab {
    width: 110px; height: 110px; margin: 0 auto 1rem;
    border-radius: 50%; overflow: hidden;
    border: 4px solid var(--nude);
    box-shadow: 0 6px 20px rgba(0,0,0,.25);
    background: rgba(255,255,255,.05);
}
.hero-foto-colab img { width: 100%; height: 100%; object-fit: cover; display: block; }
.hero h1 { color: #fff; font-size: 2.6rem; font-weight: 700; line-height: 1.15; margin-bottom: 1rem; }
.hero h1 .nome-destaque { color: var(--nude); }
.hero p { font-size: 1.05rem; opacity: .92; max-width: 520px; margin: 0 auto; }
.hero-emojis { font-size: 1.8rem; margin-top: 1.5rem; opacity: .9; letter-spacing: .5rem; }

/* ── CONTAINER ──────────────────────────────────────────── */
.container { max-width: 920px; margin: -3rem auto 3rem; padding: 0 1.2rem; position: relative; z-index: 2; }

/* ── CARD BASE ──────────────────────────────────────────── */
.card-block {
    background: var(--card);
    border-radius: var(--radius);
    box-shadow: var(--shadow);
    padding: 2rem 1.8rem;
    margin-bottom: 1.5rem;
}
.card-title-row { display: flex; align-items: center; gap: .7rem; margin-bottom: 1rem; }
.card-title-icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, var(--nude-light), var(--nude));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; flex-shrink: 0;
}
.card-block h2 { font-size: 1.5rem; }
.card-block p { color: var(--text); margin-bottom: .8rem; }

/* ── LOGIN ──────────────────────────────────────────────── */
.login-box {
    max-width: 460px;
    margin: -3rem auto 3rem;
    background: #fff;
    border-radius: var(--radius);
    padding: 2.5rem 2rem;
    box-shadow: var(--shadow);
    position: relative;
    z-index: 2;
}
.login-box h2 { font-size: 1.4rem; margin-bottom: .3rem; text-align: center; }
.login-box p.sub { color: var(--muted); font-size: .88rem; text-align: center; margin-bottom: 1.5rem; }
.login-box label { display: block; font-size: .78rem; font-weight: 700; color: var(--petrol-900); margin-bottom: .35rem; }
.login-box input {
    width: 100%; padding: .75rem .9rem; border: 1.5px solid #e5e7eb; border-radius: 10px;
    font-size: .95rem; font-family: inherit; margin-bottom: 1rem;
}
.login-box input:focus { outline: none; border-color: var(--cobre-light); box-shadow: 0 0 0 3px rgba(184,115,51,.15); }
.login-box button {
    width: 100%; padding: .85rem; background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
    color: #fff; border: 0; border-radius: 10px; font-size: .95rem; font-weight: 700; cursor: pointer;
    font-family: inherit; transition: transform .15s;
}
.login-box button:hover { transform: translateY(-1px); }
.login-erro {
    background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b;
    padding: .7rem 1rem; border-radius: 10px; font-size: .85rem; margin-bottom: 1rem;
}

/* ── MISSÃO/VISÃO/VALORES ───────────────────────────────── */
.mvv-grid { display: grid; gap: 1rem; }
@media (min-width: 720px) {
    .mvv-grid { grid-template-columns: 1fr 1fr; }
    .mvv-grid .mvv-valores { grid-column: 1/-1; }
}
.mvv-card {
    background: linear-gradient(135deg, #fff, var(--nude-light));
    border: 1px solid var(--nude);
    border-radius: var(--radius);
    padding: 1.6rem 1.4rem;
    position: relative;
    transition: transform .2s, box-shadow .2s;
}
.mvv-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(106,60,44,.12); }
.mvv-emoji { font-size: 2.4rem; line-height: 1; margin-bottom: .5rem; }
.mvv-card h3 {
    font-size: 1.1rem; letter-spacing: .15em; text-transform: uppercase;
    color: var(--petrol-900); margin-bottom: .8rem;
}
.mvv-card p { font-size: .9rem; color: var(--text); margin-bottom: .8rem; }
.mvv-card p:last-child { margin-bottom: 0; }
.mvv-card.mvv-valores ul { list-style: none; }
.mvv-card.mvv-valores li {
    padding: .65rem 0 .65rem 2rem;
    position: relative;
    font-size: .87rem;
    border-bottom: 1px dashed rgba(106,60,44,.18);
}
.mvv-card.mvv-valores li:last-child { border-bottom: 0; }
.mvv-card.mvv-valores li::before {
    content: '✦';
    position: absolute; left: 0; top: .65rem;
    color: var(--cobre-light); font-size: 1rem;
}
.mvv-card.mvv-valores strong { color: var(--cobre); }

/* ── DADOS ──────────────────────────────────────────────── */
.dados-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.dado-item {
    background: #fafafa; border: 1px solid #f0e9e0; border-left: 4px solid var(--cobre-light);
    border-radius: 0 10px 10px 0; padding: .8rem 1rem;
}
.dado-label { font-size: .68rem; font-weight: 700; color: var(--cobre); text-transform: uppercase; letter-spacing: .08em; margin-bottom: .15rem; }
.dado-valor { font-size: .95rem; font-weight: 700; color: var(--petrol-900); word-break: break-word; }

/* ── DESTAQUE SENHA / EMAIL ─────────────────────────────── */
.acesso-destaque {
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
    color: #fff;
    border-radius: var(--radius);
    padding: 1.5rem 1.4rem;
    margin-top: 1rem;
}
.acesso-destaque h4 { color: #fff; font-size: 1rem; margin-bottom: .8rem; letter-spacing: .1em; }
.acesso-destaque p, .acesso-destaque strong, .acesso-destaque span { color: #fff !important; }
.acesso-destaque .acesso-aviso strong { color: #fef3c7 !important; }
.acesso-item { background: rgba(255,255,255,.08); border: 1px solid rgba(215,171,144,.3); border-radius: 10px; padding: .7rem 1rem; margin-bottom: .5rem; display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
.acesso-item .lbl { font-size: .7rem; opacity: .75; letter-spacing: .08em; text-transform: uppercase; }
.acesso-item .val { font-family: 'Courier New', monospace; font-size: 1rem; font-weight: 700; color: var(--nude); user-select: all; }
.acesso-aviso { font-size: .78rem; opacity: .85; margin-top: .8rem; padding: .6rem .8rem; background: rgba(255,193,7,.15); border-left: 3px solid #fbbf24; border-radius: 0 6px 6px 0; }
.btn-config-email {
    display: inline-block; margin-top: .8rem;
    background: var(--nude); color: var(--petrol-900); padding: .6rem 1rem; border-radius: 8px;
    font-size: .82rem; font-weight: 700; text-decoration: none;
    transition: background .15s;
}
.btn-config-email:hover { background: #e8c2a5; }

/* ── KIT ────────────────────────────────────────────────── */
.kit-box {
    background: linear-gradient(135deg, #fdf2f8, #fce7f3);
    border: 1px solid #f9a8d4; border-radius: var(--radius);
    padding: 1.5rem; text-align: center;
}
.kit-emoji { font-size: 3rem; margin-bottom: .5rem; }
.kit-box p { color: #831843; font-size: .95rem; }

/* ── PRINCÍPIOS ─────────────────────────────────────────── */
.princ-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin-top: 1rem; }
.princ-card {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 1.1rem 1.2rem;
    border-top: 4px solid var(--cobre-light);
}
.princ-card .princ-emoji { font-size: 1.8rem; line-height: 1; margin-bottom: .4rem; }
.princ-card h4 { font-size: 1rem; margin-bottom: .35rem; color: var(--petrol-900); font-family: 'Open Sans'; font-weight: 700; }
.princ-card p { font-size: .82rem; color: var(--text); margin: 0; }

/* ── HUB / CONTRATO ─────────────────────────────────────── */
.btn-grande {
    display: inline-flex; align-items: center; gap: .5rem;
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
    color: #fff; padding: 1rem 1.8rem; border-radius: 12px;
    font-size: 1rem; font-weight: 700; text-decoration: none;
    box-shadow: 0 4px 16px rgba(5,34,40,.25);
    transition: transform .15s, box-shadow .15s;
}
.btn-grande:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(5,34,40,.3); }
.btn-grande.btn-cobre { background: linear-gradient(135deg, var(--cobre), var(--cobre-light)); }

/* ── ACEITE ─────────────────────────────────────────────── */
.aceite-box {
    background: linear-gradient(135deg, var(--nude-light), #fff);
    border: 2px solid var(--nude);
    border-radius: var(--radius);
    padding: 2rem 1.8rem;
    text-align: center;
    margin-top: 2rem;
}
.aceite-box.aceito { background: linear-gradient(135deg, #ecfdf5, #d1fae5); border-color: #34d399; }
.aceite-emoji { font-size: 3rem; margin-bottom: .5rem; }
.aceite-box h3 { font-size: 1.4rem; margin-bottom: .5rem; }
.aceite-box p { color: var(--muted); font-size: .9rem; margin-bottom: 1.2rem; }
.btn-aceite {
    background: linear-gradient(135deg, #059669, #047857);
    color: #fff; border: 0; padding: 1rem 2rem; border-radius: 12px;
    font-size: 1rem; font-weight: 700; cursor: pointer; font-family: inherit;
    box-shadow: 0 4px 16px rgba(5,150,105,.3);
}
.btn-aceite:hover { transform: translateY(-2px); }

/* ── FOOTER ─────────────────────────────────────────────── */
.footer-fsa {
    text-align: center; padding: 2rem 1rem; color: var(--muted); font-size: .8rem;
    border-top: 1px solid var(--nude);
    margin-top: 2rem;
}
.footer-fsa strong { color: var(--cobre); }

@media (max-width: 540px) {
    .hero h1 { font-size: 1.9rem; }
    .hero { padding: 3rem 1rem 4rem; }
    .card-block { padding: 1.4rem 1.2rem; }
    .container { padding: 0 .8rem; }
}
</style>
</head>
<body>

<?php if (!$autenticado): ?>
    <!-- ─── TELA DE LOGIN ──────────────────────────────────── -->
    <div class="hero">
        <div class="hero-inner">
            <div class="hero-logo">
                <img src="/conecta/assets/img/logo.png" alt="Ferreira &amp; Sá Advocacia"
                     onerror="this.onerror=null;this.src='../../assets/img/logo.png';">
            </div>
            <div class="hero-subtitle">Advocacia Especializada</div>
            <div class="hero-emoji">🔒</div>
            <h1>Acesso ao seu portal de boas-vindas</h1>
            <p>Para abrir a página, confirme seus dados.</p>
        </div>
    </div>

    <div class="login-box">
        <h2>Identifique-se</h2>
        <p class="sub">Digite o seu nome completo e data de nascimento.</p>
        <?php if ($erro): ?><div class="login-erro">⚠ <?= htmlspecialchars($erro) ?></div><?php endif; ?>
        <form method="POST">
            <input type="hidden" name="acao_login" value="1">
            <label>Nome completo</label>
            <input name="nome_login" required placeholder="Maria Silva Santos" autofocus>
            <label>Data de nascimento</label>
            <input name="data_login" type="date" required>
            <button type="submit">Acessar minha página</button>
        </form>
    </div>

<?php else:
    // ─── TELA AUTENTICADA — BOAS-VINDAS ───────────────────────
    $primeiroNome = explode(' ', $reg['nome_completo'])[0];
    $jaAceitou = !empty($reg['aceite_em']);
    $genero = isset($reg['genero']) ? $reg['genero'] : 'F'; // default feminino
    $fotoColab = !empty($reg['foto_path']) ? $reg['foto_path'] : '';
?>

    <!-- HERO -->
    <div class="hero">
        <div class="hero-inner">
            <div class="hero-logo">
                <img src="/conecta/assets/img/logo.png" alt="Ferreira &amp; Sá Advocacia"
                     onerror="this.onerror=null;this.src='../../assets/img/logo.png';">
            </div>
            <div class="hero-subtitle">Advocacia Especializada</div>
            <?php if ($fotoColab): ?>
                <div class="hero-foto-colab">
                    <img src="<?= htmlspecialchars($fotoColab) ?>" alt="<?= htmlspecialchars($primeiroNome) ?>" onerror="this.parentNode.style.display='none';">
                </div>
            <?php else: ?>
                <div class="hero-emoji">🎉</div>
            <?php endif; ?>
            <h1>Seja muito <?= g('bem-vinda', 'bem-vindo', $genero) ?>, <span class="nome-destaque"><?= htmlspecialchars($primeiroNome) ?></span>!</h1>
            <p>Estamos super <?= g('felizes', 'felizes', $genero) ?> de você estar começando essa jornada com a gente. Vamos <?= g('juntas', 'juntos', $genero) ?>! 💜</p>
            <div class="hero-emojis">💼 ⚖️ 💖</div>
        </div>
    </div>

    <div class="container">

        <!-- MENSAGEM PESSOAL (se houver) -->
        <?php if (!empty($reg['mensagem_pessoal'])): ?>
        <div class="card-block" style="background:linear-gradient(135deg,#fdf2f8,#fce7f3);border-left:6px solid var(--rose);">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#fbcfe8,#f9a8d4);">💌</div>
                <h2>Uma mensagem para você</h2>
            </div>
            <p style="font-size:1rem;line-height:1.7;color:#831843;font-style:italic;"><?= nl2br(htmlspecialchars($reg['mensagem_pessoal'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- MISSÃO / VISÃO / VALORES -->
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon">🌟</div>
                <h2>Quem somos</h2>
            </div>
            <p style="font-size:.95rem;color:var(--muted);">Antes de mais nada, queremos que você conheça o que nos move. Esses são os pilares do <strong>Ferreira &amp; Sá</strong>:</p>

            <div class="mvv-grid" style="margin-top:1.2rem;">
                <div class="mvv-card">
                    <div class="mvv-emoji">🎯</div>
                    <h3>Missão</h3>
                    <p>Com <strong>ética, profissionalismo e inovação</strong>, nossa missão é fornecer serviços de assessoria e consultoria jurídica de alta qualidade para clientes pessoa física e jurídica.</p>
                    <p>Buscamos sempre os melhores resultados, atendendo nossos clientes com <strong>rapidez e eficiência</strong> para estabelecer uma verdadeira relação de confiança.</p>
                    <p>Para melhorar ainda mais a experiência, incorporamos <strong>tecnologias inovadoras</strong> em nossos atendimentos personalizados, garantindo que cada consulta seja individualizada e atenda às necessidades específicas de cada cliente.</p>
                </div>

                <div class="mvv-card">
                    <div class="mvv-emoji">👁️</div>
                    <h3>Visão</h3>
                    <p>Ser reconhecido como um escritório de advocacia <strong>especializado em direito das famílias e sucessões</strong> que preza tanto pela <strong>qualidade técnica</strong> quanto pelo elevado nível de <strong>transparência</strong> em todas as relações.</p>
                </div>

                <div class="mvv-card mvv-valores">
                    <div class="mvv-emoji">🤝</div>
                    <h3>Valores</h3>
                    <ul>
                        <li><strong>Solução pacífica dos conflitos:</strong> resolver conflitos de maneira pacífica é nossa prioridade — evitamos litígios longos e desgastantes sempre que possível.</li>
                        <li><strong>Conhecimento técnico atualizado:</strong> a equipe se mantém constantemente atualizada nas mudanças da legislação e jurisprudências, oferecendo as soluções mais eficazes e adequadas.</li>
                        <li><strong>Lealdade, limites e transparência:</strong> agimos com integridade e ética, respeitando legalidade e imparcialidade. Somos transparentes sobre os limites e riscos de cada caso — nunca fazemos promessas irrealistas.</li>
                        <li><strong>Respeito mútuo e confiança:</strong> ouvimos as necessidades e preocupações dos clientes, buscando sempre que possível atender suas expectativas, com respeito mútuo.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ACESSOS INSTITUCIONAIS -->
        <?php if ($reg['email_institucional'] || $reg['senha_inicial']): ?>
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon">🔐</div>
                <h2>Seu acesso ao Hub Conecta</h2>
            </div>
            <p>Aqui estão suas credenciais. <strong>Por segurança, troque a senha no primeiro acesso.</strong></p>

            <div class="acesso-destaque">
                <h4>📧 E-MAIL INSTITUCIONAL</h4>
                <?php if ($reg['email_institucional']): ?>
                <div class="acesso-item">
                    <span class="lbl">E-mail:</span>
                    <span class="val"><?= htmlspecialchars($reg['email_institucional']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($reg['senha_inicial']): ?>
                <div class="acesso-item">
                    <span class="lbl">Senha inicial:</span>
                    <span class="val"><?= htmlspecialchars($reg['senha_inicial']) ?></span>
                </div>
                <div class="acesso-aviso">
                    ⚠ <strong>Atenção:</strong> essa é uma senha temporária. Recomendamos trocar por uma senha pessoal no primeiro acesso.
                </div>
                <?php endif; ?>
                <p style="font-size:.82rem;margin-top:.9rem;opacity:.92;">
                    O e-mail institucional pode ser configurado no <strong>Outlook</strong>, no <strong>app de e-mail do celular</strong>, ou acessado diretamente pelo webmail:
                </p>
                <a class="btn-config-email" href="https://br.obi6070.com.br:2096/" target="_blank" rel="noopener">🌐 Acessar Webmail</a>
            </div>

            <div class="acesso-destaque" style="background:linear-gradient(135deg, var(--cobre), var(--cobre-light));margin-top:1rem;">
                <h4>🔗 HUB CONECTA — sistema do escritório</h4>
                <p style="opacity:.95;font-size:.92rem;">É no Hub que você acessa processos, agenda, WhatsApp, documentos e tudo mais.</p>
                <a class="btn-config-email" href="https://ferreiraesa.com.br/conecta/" target="_blank" rel="noopener" style="background:#fff;">🚀 Acessar o Hub</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- DETALHES DA POSIÇÃO -->
        <?php if ($reg['cargo'] || $reg['setor'] || $reg['modalidade'] || $reg['dias_trabalho'] || $reg['horario_inicio']): ?>
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon">💼</div>
                <h2>Sua jornada conosco</h2>
            </div>
            <div class="dados-grid">
                <?php if ($reg['cargo']): ?>
                <div class="dado-item"><div class="dado-label">Cargo</div><div class="dado-valor"><?= htmlspecialchars($reg['cargo']) ?></div></div>
                <?php endif; ?>
                <?php if ($reg['setor']): ?>
                <div class="dado-item"><div class="dado-label">Área / Setor</div><div class="dado-valor"><?= htmlspecialchars($reg['setor']) ?></div></div>
                <?php endif; ?>
                <?php if ($reg['modalidade']): ?>
                <div class="dado-item"><div class="dado-label">Modalidade</div><div class="dado-valor"><?= htmlspecialchars($reg['modalidade']) ?></div></div>
                <?php endif; ?>
                <?php if (!empty($reg['local_presencial'])): ?>
                <div class="dado-item" style="grid-column:1/-1;"><div class="dado-label">📍 Local (quando presencial)</div><div class="dado-valor"><?= htmlspecialchars($reg['local_presencial']) ?></div></div>
                <?php endif; ?>
                <?php if ($reg['dias_trabalho']): ?>
                <div class="dado-item"><div class="dado-label">Dias de trabalho</div><div class="dado-valor"><?= htmlspecialchars($reg['dias_trabalho']) ?></div></div>
                <?php endif; ?>
                <?php if ($reg['horario_inicio'] && $reg['horario_fim']): ?>
                <div class="dado-item"><div class="dado-label">Horário</div><div class="dado-valor"><?= fmt_horario($reg['horario_inicio']) ?> às <?= fmt_horario($reg['horario_fim']) ?></div></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- REMUNERAÇÃO + BENEFÍCIOS -->
        <?php if ($reg['tipo_remuneracao'] || $reg['valor_remuneracao'] || $reg['data_pagamento'] || $reg['beneficios']): ?>
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon">💰</div>
                <h2>Remuneração e benefícios</h2>
            </div>
            <div class="dados-grid">
                <?php if ($reg['tipo_remuneracao']): ?>
                <div class="dado-item"><div class="dado-label">Tipo</div><div class="dado-valor"><?= htmlspecialchars($reg['tipo_remuneracao']) ?></div></div>
                <?php endif; ?>
                <?php if ($reg['valor_remuneracao']): ?>
                <div class="dado-item"><div class="dado-label">Valor</div><div class="dado-valor"><?= htmlspecialchars(fmt_moeda($reg['valor_remuneracao'])) ?></div></div>
                <?php endif; ?>
                <?php if ($reg['data_pagamento']): ?>
                <div class="dado-item"><div class="dado-label">Pagamento</div><div class="dado-valor"><?= htmlspecialchars($reg['data_pagamento']) ?></div></div>
                <?php endif; ?>
            </div>

            <?php if ($reg['beneficios']):
                $linhas = preg_split('/\r?\n/', trim($reg['beneficios']));
            ?>
            <h4 style="margin-top:1.3rem;font-family:'Open Sans';font-weight:700;color:var(--cobre);font-size:.85rem;letter-spacing:.05em;">✨ BENEFÍCIOS</h4>
            <ul style="list-style:none;margin-top:.6rem;">
                <?php foreach ($linhas as $b): if (!trim($b)) continue; ?>
                <li style="padding:.45rem 0 .45rem 2rem;position:relative;font-size:.92rem;border-bottom:1px dashed #f0e9e0;">
                    <span style="position:absolute;left:0;top:.45rem;font-size:1.1rem;">✓</span>
                    <?= htmlspecialchars(trim($b)) ?>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- KIT -->
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#fbcfe8,#f9a8d4);">🎁</div>
                <h2>Seu kit de boas-vindas</h2>
            </div>
            <div class="kit-box">
                <?php if ($reg['kit_descricao']): ?>
                    <div class="kit-emoji">🎁</div>
                    <p><?= nl2br(htmlspecialchars($reg['kit_descricao'])) ?></p>
                    <p style="margin-top:.8rem;font-size:.85rem;opacity:.85;">Será entregue em breve! 💜</p>
                <?php else: ?>
                    <div class="kit-emoji">📦</div>
                    <p>Seu kit está sendo preparado com muito carinho e será entregue em breve! 💜</p>
                <?php endif; ?>

                <!-- Tamanho da camisa -->
                <div style="margin-top:1.2rem;padding-top:1rem;border-top:1px dashed #f9a8d4;">
                    <p style="font-size:.92rem;color:#831843;font-weight:700;margin-bottom:.5rem;">👕 Qual o tamanho da sua camisa?</p>
                    <?php $tamAtual = isset($reg['tamanho_camisa']) ? $reg['tamanho_camisa'] : ''; ?>
                    <div id="tamanhoWrap" style="display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap;">
                        <?php foreach (array('P','M','G','GG') as $t): ?>
                            <button type="button" class="btn-tam <?= $tamAtual === $t ? 'sel' : '' ?>" data-tam="<?= $t ?>" onclick="escolherTamanho('<?= $t ?>', this)"><?= $t ?></button>
                        <?php endforeach; ?>
                    </div>
                    <p id="tamanhoMsg" style="font-size:.78rem;color:#9f1239;margin-top:.5rem;text-align:center;<?= $tamAtual ? '' : 'display:none;' ?>">
                        <?= $tamAtual ? '✓ Tamanho ' . htmlspecialchars($tamAtual) . ' registrado!' : '' ?>
                    </p>
                </div>
            </div>
        </div>

        <style>
            .btn-tam {
                background:#fff; border:1.5px solid #f9a8d4; color:#9f1239;
                padding:.55rem 1.1rem; border-radius:8px; font-size:.95rem;
                font-weight:700; cursor:pointer; min-width:54px; transition:all .15s;
                font-family:inherit;
            }
            .btn-tam:hover { background:#fdf2f8; transform:translateY(-1px); }
            .btn-tam.sel { background:linear-gradient(135deg,#db2777,#9f1239); color:#fff; border-color:#db2777; }
        </style>

        <script>
            function escolherTamanho(tam, btn) {
                if (!confirm('Confirmar tamanho ' + tam + ' para a camisa do kit?')) return;
                var fd = new FormData();
                fd.append('acao_tamanho_camisa', '1');
                fd.append('tamanho', tam);
                fetch(window.location.href, { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        if (j && j.ok) {
                            document.querySelectorAll('.btn-tam').forEach(function(b){ b.classList.remove('sel'); });
                            btn.classList.add('sel');
                            var msg = document.getElementById('tamanhoMsg');
                            msg.textContent = '✓ Tamanho ' + tam + ' registrado!';
                            msg.style.display = 'block';
                        } else {
                            alert('❌ ' + (j && j.erro ? j.erro : 'Erro ao salvar tamanho'));
                        }
                    })
                    .catch(function(err){ alert('❌ ' + err.message); });
            }
        </script>

        <!-- PRINCÍPIOS / POSTURA -->
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon">💎</div>
                <h2>Como atuamos por aqui</h2>
            </div>
            <p>Algumas premissas que fazem parte do nosso jeito de trabalhar — e do seu também, a partir de agora:</p>

            <div class="princ-grid">
                <div class="princ-card">
                    <div class="princ-emoji">🤐</div>
                    <h4>Confidencialidade</h4>
                    <p>Tudo o que envolve as demandas dos clientes é <strong>sigiloso</strong>. Não comente casos, nomes, valores ou estratégias com pessoas de fora do escritório — nem com colegas em locais públicos.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">💝</div>
                    <h4>Empatia no atendimento</h4>
                    <p>Quem nos procura está vivendo um momento delicado. Escute com atenção, valide o sentimento da pessoa e responda com <strong>cuidado e gentileza</strong> — sempre.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">👂</div>
                    <h4>Escuta ativa</h4>
                    <p>Antes de responder, entenda. Faça perguntas. Anote o que importa. Quanto melhor a escuta, mais assertiva fica a orientação técnica que damos depois.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">📱</div>
                    <h4>WhatsApp profissional</h4>
                    <p>Use sempre os canais oficiais do escritório (não o pessoal). Mensagens claras, sem áudios longos.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">⏱️</div>
                    <h4>Respeito ao prazo de resposta</h4>
                    <p>Mesmo sem ter a resposta final, <strong>retorne ao cliente</strong> avisando que está com ele. Silêncio gera ansiedade. Em até 24h úteis, alguém da equipe deu retorno.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🚫</div>
                    <h4>Sem promessas irrealistas</h4>
                    <p>Nunca prometa resultado de processo. Apresente cenários, riscos e prazos com transparência. <strong>Honestidade técnica é nossa marca.</strong></p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🤝</div>
                    <h4>Trabalho em equipe</h4>
                    <p>Aqui ninguém anda sozinho. Pergunte, peça ajuda, compartilhe o que aprendeu. A gente cresce junto.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">📚</div>
                    <h4>Atualização constante</h4>
                    <p>Direito muda toda hora. Reserve um tempo da semana para estudar, ler julgados e acompanhar as mudanças legislativas da sua área.</p>
                    <p style="margin-top:.5rem;font-style:italic;color:var(--cobre);">Não se esqueça: a Profª Amanda também está aqui e essa troca agrega para todo mundo, inclusive para ela! 😉</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🤖</div>
                    <h4>Tecnologia a seu favor</h4>
                    <p>Use a tecnologia a seu favor! <strong>Inteligência Artificial é muito bem-vinda</strong>, mas não deixe de <strong>conferir as informações</strong>, dar o seu toque pessoal e — especialmente — <strong>conferir os julgados e artigos</strong> que ela mencionar.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🎮</div>
                    <h4>Discord no expediente</h4>
                    <p>Estar online no <strong>Discord durante o horário de expediente</strong> é muito importante: facilita a comunicação, permite descontração em momentos adequados e diminui as barreiras do home office, aproximando a equipe.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🥂</div>
                    <h4>Encontros e confraternizações</h4>
                    <p>Tente participar dos <strong>encontros, confraternizações e eventos</strong> realizados pelo escritório. Preparamos tudo com muito carinho e ter você nesses momentos fará tudo muito mais especial.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">💗</div>
                    <h4>Empatia com quem nos procura</h4>
                    <p>Lembre-se de que trabalhamos com <strong>pessoas que têm necessidades e dores específicas</strong>. É muito importante compreender isso antes de a gente se estressar (rs).</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🚀</div>
                    <h4>Vamos crescer juntos</h4>
                    <p>Estamos aqui para <strong>crescer juntos</strong> e transformar o Ferreira &amp; Sá no <strong>maior escritório de advocacia da região</strong> — e para isso contamos muito com a sua ajuda.</p>
                    <p style="margin-top:.4rem;">Qualquer dúvida, dica, sugestão ou reclamação <strong>pode (e deve!)</strong> ser passada para a gente, para que possamos sempre melhorar. 💜</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🎓</div>
                    <h4>Treinamentos importam</h4>
                    <p>Realize os <strong>treinamentos disponibilizados</strong> com atenção. Tudo aqui é muito cíclico — o que você aprende no treinamento volta repetidamente no dia a dia, e dominar esses fluxos faz toda a diferença na sua entrega.</p>
                </div>
            </div>
        </div>

        <!-- PLANO DE INCENTIVO À SAÚDE -->
        <div class="card-block" style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1.5px solid #34d399;">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#86efac,#4ade80);">💪</div>
                <h2 style="color:#065f46;">Plano de Incentivo Ferreira &amp; Sá</h2>
            </div>
            <p>Nós também nos preocupamos com a <strong>saúde da equipe</strong>! Por isso, criamos o <strong>Plano de Incentivo Ferreira &amp; Sá</strong>: um adicional financeiro pra quem cuida do corpo durante o mês.</p>

            <div style="background:#fff;border:1.5px dashed #10b981;border-radius:12px;padding:1.2rem 1.4rem;margin-top:1rem;">
                <div style="text-align:center;font-size:.7rem;letter-spacing:3px;font-weight:700;color:#065f46;margin-bottom:.4rem;">RECEBA POR MÊS</div>
                <div style="text-align:center;font-size:2.4rem;font-weight:900;color:#047857;line-height:1;">R$ 100,00</div>
                <div style="text-align:center;font-size:.85rem;color:#065f46;margin-top:.5rem;">de incentivo por treinar</div>
            </div>

            <p style="margin-top:1rem;font-size:.92rem;"><strong>Como funciona:</strong> dentro de um mês (a contar do <strong>1º dia útil</strong>), quem treinar pelo menos <strong>3 dias na semana</strong>, com no mínimo <strong>30 minutos por treino</strong>, em qualquer modalidade (academia, corrida, pilates, dança, etc.), recebe os <strong>R$ 100,00</strong> como adicional do mês seguinte.</p>

            <p style="font-size:.82rem;color:#065f46;margin-top:.5rem;">💚 É o nosso jeitinho de te lembrar que cuidar de você também faz parte da jornada.</p>
        </div>

        <!-- CONTRATO PARA ASSINATURA -->
        <?php if (!empty($reg['link_contrato_url'])): ?>
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon">📝</div>
                <h2>Seu contrato</h2>
            </div>
            <p>Antes de tudo, vamos formalizar essa parceria. Acesse o link abaixo para ler com calma e assinar digitalmente.</p>
            <div style="text-align:center;margin-top:1.2rem;">
                <a class="btn-grande btn-cobre" href="<?= htmlspecialchars($reg['link_contrato_url']) ?>" target="_blank" rel="noopener">
                    📄 Acessar e assinar o contrato
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- DOCUMENTOS PARA PREENCHER E ASSINAR -->
        <?php if (!empty($documentosVinculados)): ?>
        <div class="card-block">
            <div class="card-title-row">
                <div class="card-title-icon">📄</div>
                <h2>Documentos para preencher e assinar</h2>
            </div>
            <p>Esses são os documentos da sua admissão. Preencha os seus dados, leia com calma e assine.</p>

            <div style="display:flex;flex-direction:column;gap:.75rem;margin-top:1rem;">
            <?php foreach ($documentosVinculados as $doc):
                $schema = onboarding_doc_schema($doc['tipo']);
                if (!$schema) continue;
                $statusLabel = '';
                $statusCor = '';
                $assinado = !empty($doc['assinatura_estagiario_em']);
                if ($assinado) {
                    $statusLabel = '✓ Assinado em ' . date('d/m/Y H:i', strtotime($doc['assinatura_estagiario_em']));
                    $statusCor = '#065f46';
                    $statusBg = '#d1fae5';
                } elseif ($doc['status'] === 'em_preenchimento') {
                    $statusLabel = '⏳ Em preenchimento';
                    $statusCor = '#9a3412';
                    $statusBg = '#fed7aa';
                } else {
                    $statusLabel = '📋 Pendente';
                    $statusCor = '#92400e';
                    $statusBg = '#fef3c7';
                }
            ?>
                <div style="background:#fff;border:1.5px solid <?= $assinado ? '#34d399' : '#e5e7eb' ?>;border-radius:12px;padding:1rem 1.2rem;">
                    <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                        <div style="font-size:1.8rem;line-height:1;"><?= htmlspecialchars($schema['icon']) ?></div>
                        <div style="flex:1;min-width:200px;">
                            <h3 style="font-size:1.05rem;margin:0 0 .15rem;color:var(--petrol-900);font-family:'Open Sans',sans-serif;font-weight:700;"><?= htmlspecialchars($schema['label']) ?></h3>
                            <p style="font-size:.78rem;color:var(--muted);margin:0;"><?= htmlspecialchars($schema['descricao']) ?></p>
                            <span style="display:inline-block;margin-top:.4rem;background:<?= $statusBg ?>;color:<?= $statusCor ?>;padding:.15rem .55rem;border-radius:10px;font-size:.7rem;font-weight:700;"><?= htmlspecialchars($statusLabel) ?></span>
                        </div>
                        <div>
                        <?php if ($assinado && !empty($doc['pdf_drive_url'])): ?>
                            <a href="<?= htmlspecialchars($doc['pdf_drive_url']) ?>" target="_blank" rel="noopener" style="background:#059669;color:#fff;padding:.55rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;">📎 Ver PDF</a>
                        <?php elseif ($assinado): ?>
                            <span style="background:#d1fae5;color:#065f46;padding:.55rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:700;">✓ Concluído</span>
                        <?php else: ?>
                            <a href="?token=<?= htmlspecialchars($token) ?>&doc=<?= (int)$doc['id'] ?>" style="background:linear-gradient(135deg,var(--cobre),var(--cobre-light));color:#fff;padding:.55rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;">
                                <?php if ($schema['fluxo'] === 'so_assina'): ?>✍️ Ler e assinar<?php else: ?>📝 Preencher e assinar<?php endif; ?>
                            </a>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <p style="font-size:.78rem;color:var(--muted);margin-top:1rem;text-align:center;">
                ⏳ A funcionalidade de preenchimento e assinatura estará disponível em breve.
                Por enquanto, fale com a Dra. Amanda para receber os documentos.
            </p>
        </div>
        <?php endif; ?>

        <!-- ACEITE -->
        <?php if ($jaAceitou): ?>
            <div class="aceite-box aceito">
                <div class="aceite-emoji">✅</div>
                <h3>Tudo certo, <?= htmlspecialchars($primeiroNome) ?>!</h3>
                <p>Você confirmou a leitura desta página em <strong><?= htmlspecialchars(date('d/m/Y \à\s H:i', strtotime($reg['aceite_em']))) ?></strong>.</p>
                <p style="margin-top:.5rem;">Estamos <?= g('prontas', 'prontos', $genero) ?> pra começar essa jornada <?= g('juntas', 'juntos', $genero) ?>. Qualquer dúvida, fale com a gente. 💜</p>
            </div>
        <?php else: ?>
            <div class="aceite-box">
                <div class="aceite-emoji">🌟</div>
                <h3><?= g('Pronta', 'Pronto', $genero) ?> pra começar?</h3>
                <p>Quando você confirmar abaixo, registramos que leu as informações desta página. Pode aceitar com tranquilidade — qualquer dúvida, é só chamar a equipe.</p>
                <form method="POST" onsubmit="return confirm('Confirmar leitura e aceite das informações desta página?');">
                    <input type="hidden" name="acao_aceitar" value="1">
                    <button type="submit" class="btn-aceite">✓ Li e estou <?= g('pronta', 'pronto', $genero) ?> pra começar!</button>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <div class="footer-fsa">
        <strong>FERREIRA &amp; SÁ</strong> — Advocacia Especializada<br>
        Estamos felizes por ter você na equipe 💜
    </div>

<?php endif; ?>

</body>
</html>
