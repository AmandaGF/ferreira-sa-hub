<?php
/**
 * Ferreira e Sá Advocacia — Página pública de Boas-Vindas
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

// Salvar preferências do kit (handler unico — salva 1 campo por vez via AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao_kit_pref']) && !empty($_SESSION[$sessKey])) {
    header('Content-Type: application/json; charset=utf-8');
    $campo = trim($_POST['campo'] ?? '');
    $valor = trim($_POST['valor'] ?? '');
    $detalhes = trim($_POST['detalhes'] ?? '');

    $coresValidas = array('azul','preta','branca','rosa','vermelha','verde');
    $tamanhosValidos = array('P','M','G','GG');

    $sql = null;
    $params = array();

    if ($campo === 'tamanho') {
        $valor = strtoupper($valor);
        if (!in_array($valor, $tamanhosValidos, true)) { echo json_encode(array('ok'=>false,'erro'=>'Tamanho inválido')); exit; }
        $sql = "UPDATE colaboradores_onboarding SET tamanho_camisa = ? WHERE id = ?";
        $params = array($valor, $reg['id']);
    } elseif ($campo === 'cor') {
        $valor = strtolower($valor);
        if (!in_array($valor, $coresValidas, true)) { echo json_encode(array('ok'=>false,'erro'=>'Cor inválida')); exit; }
        $sql = "UPDATE colaboradores_onboarding SET kit_cor_favorita = ? WHERE id = ?";
        $params = array($valor, $reg['id']);
    } elseif ($campo === 'alergia') {
        $bool = ($valor === 'sim') ? 1 : 0;
        $sql = "UPDATE colaboradores_onboarding SET kit_alergia = ?, kit_alergia_detalhes = ? WHERE id = ?";
        $params = array($bool, $bool ? $detalhes : null, $reg['id']);
    } elseif ($campo === 'alcool') {
        $bool = ($valor === 'sim') ? 1 : 0;
        $sql = "UPDATE colaboradores_onboarding SET kit_consome_alcool = ? WHERE id = ?";
        $params = array($bool, $reg['id']);
    } else {
        echo json_encode(array('ok'=>false,'erro'=>'Campo desconhecido'));
        exit;
    }

    try {
        $pdo->prepare($sql)->execute($params);
        // Notificar admins na primeira escolha de cada campo
        try {
            require_once __DIR__ . '/../../core/functions_notify.php';
            if (function_exists('notify_admins')) {
                $titulo = '🎁 Preferência do kit registrada';
                $msg = htmlspecialchars($reg['nome_completo']) . ' respondeu: ' .
                       ($campo === 'tamanho' ? 'tamanho ' . $valor :
                       ($campo === 'cor' ? 'cor favorita ' . $valor :
                       ($campo === 'alergia' ? 'alergia ' . ($bool ? 'SIM (' . htmlspecialchars($detalhes) . ')' : 'não') :
                       ($campo === 'alcool' ? 'álcool ' . ($bool ? 'sim' : 'não') : ''))));
                notify_admins($titulo, $msg, null);
            }
        } catch (Exception $e) {}
        echo json_encode(array('ok' => true, 'campo' => $campo, 'valor' => $valor));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'erro' => $e->getMessage()));
    }
    exit;
}

$autenticado = !empty($_SESSION[$sessKey]);

// Carrega avisos do mural (apenas se autenticado)
$avisos = array();
if (!empty($_SESSION[$sessKey]) && $reg) {
    // Self-heal da tabela (admin pode nao ter acessado ainda)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_avisos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            colaborador_id INT NULL,
            tipo VARCHAR(20) NOT NULL DEFAULT 'aviso',
            titulo VARCHAR(200) NOT NULL,
            mensagem TEXT NOT NULL,
            icone VARCHAR(8) NULL,
            cor VARCHAR(20) NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_por INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_col (colaborador_id),
            INDEX idx_ativo (ativo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    try {
        $stA = $pdo->prepare("SELECT id, tipo, titulo, mensagem, icone, cor, ativo, created_at
                              FROM colaboradores_avisos
                              WHERE (colaborador_id = ? OR colaborador_id IS NULL)
                              ORDER BY created_at DESC LIMIT 60");
        $stA->execute(array($reg['id']));
        $todosAvisos = $stA->fetchAll();
    } catch (Exception $e) { $todosAvisos = array(); }
    // Separa em ativos (mostrados em destaque) e arquivados (em <details>)
    $avisos = array();
    $avisosArquivados = array();
    foreach ($todosAvisos as $a) {
        if ((int)$a['ativo'] === 1) $avisos[] = $a;
        else $avisosArquivados[] = $a;
    }
}

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
<title>Bem-vinda(o) — Ferreira e Sá Advocacia</title>
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

/* ── NAV STICKY discreta (dropdown elegante) ───────────── */
html { scroll-behavior: smooth; }
.card-block, .aceite-box { scroll-margin-top: 70px; }
.onb-nav {
    position: sticky;
    top: 14px;
    z-index: 50;
    margin: 0 0 1.4rem;
    list-style: none;
}
.onb-nav summary {
    background: #fff;
    border: 1px solid var(--nude);
    border-radius: 999px;
    padding: .5rem 1.1rem;
    font-size: .8rem;
    font-weight: 700;
    color: var(--cobre);
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(106,60,44,.12);
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    transition: all .15s;
    list-style: none;
    user-select: none;
}
.onb-nav summary::-webkit-details-marker { display: none; }
.onb-nav summary::marker { content: ''; }
.onb-nav summary:hover { background: var(--nude-light); border-color: var(--cobre-light); }
.onb-nav summary .seta { transition: transform .2s; font-size: .7rem; opacity: .6; }
.onb-nav[open] summary { background: var(--petrol-900); color: #fff; border-color: var(--petrol-900); }
.onb-nav[open] summary .seta { transform: rotate(180deg); opacity: 1; }
.onb-nav-grid {
    background: rgba(255,255,255,.98);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1px solid var(--nude);
    border-radius: 14px;
    padding: .8rem;
    margin-top: .5rem;
    box-shadow: 0 8px 24px rgba(0,0,0,.1);
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: .35rem;
    max-width: 560px;
}
.onb-nav-grid a {
    background: transparent;
    color: var(--petrol-900);
    padding: .5rem .7rem;
    border-radius: 8px;
    font-size: .8rem;
    font-weight: 600;
    text-decoration: none;
    transition: all .12s;
    border: 0;
    display: block;
}
.onb-nav-grid a:hover {
    background: var(--nude-light);
    color: var(--cobre);
}

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
                <img src="/conecta/assets/img/logo.png" alt="Ferreira e Sá Advocacia"
                     onerror="this.onerror=null;this.src='../../assets/img/logo.png';">
            </div>
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
            <input name="nome_login" required placeholder="Ex: Ana Beatriz Ferreira de Sá" autofocus>
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
                <img src="/conecta/assets/img/logo.png" alt="Ferreira e Sá Advocacia"
                     onerror="this.onerror=null;this.src='../../assets/img/logo.png';">
            </div>
            <?php if ($fotoColab): ?>
                <div class="hero-foto-colab">
                    <img src="<?= htmlspecialchars($fotoColab) ?>" alt="<?= htmlspecialchars($primeiroNome) ?>" onerror="this.parentNode.style.display='none';">
                </div>
            <?php else: ?>
                <div class="hero-emoji">🎉</div>
            <?php endif; ?>
            <h1>Seja muito <?= g('bem-vinda', 'bem-vindo', $genero) ?>, <span class="nome-destaque"><?= htmlspecialchars($primeiroNome) ?></span>!</h1>
            <p>Estamos super <?= g('felizes', 'felizes', $genero) ?> de você estar começando essa jornada com a gente. Vamos juntos! 💜</p>
            <div class="hero-emojis">💼 ⚖️ 💖</div>
        </div>
    </div>

    <div class="container">

        <!-- MENU DE NAVEGAÇÃO RÁPIDA (dropdown discreto e sticky) -->
        <details class="onb-nav" id="onbNav">
            <summary>
                <span>📑</span>
                <span>Ir para uma seção</span>
                <span class="seta">▾</span>
            </summary>
            <div class="onb-nav-grid">
                <a href="#sec-portal">🎯 Seu Portal</a>
                <?php if (!empty($avisos)): ?><a href="#sec-mural">📰 Mural</a><?php endif; ?>
                <?php if (!empty($reg['mensagem_pessoal'])): ?><a href="#sec-mensagem">💌 Mensagem</a><?php endif; ?>
                <a href="#sec-quem-somos">🌟 Quem somos</a>
                <?php if ($reg['email_institucional'] || $reg['senha_inicial']): ?><a href="#sec-acessos">🔐 Acessos</a><?php endif; ?>
                <?php if ($reg['cargo'] || $reg['setor'] || $reg['modalidade']): ?><a href="#sec-jornada">💼 Jornada</a><?php endif; ?>
                <?php if ($reg['tipo_remuneracao'] || $reg['valor_remuneracao'] || $reg['beneficios']): ?><a href="#sec-remuneracao">💰 Remuneração</a><?php endif; ?>
                <a href="#sec-kit">🎁 Kit</a>
                <a href="#sec-principios">💎 Princípios</a>
                <a href="#sec-fit">💪 F&S FIT</a>
                <a href="#sec-seguro">🛡️ Seguro</a>
                <?php if (!empty($documentosVinculados)): ?><a href="#sec-documentos">📄 Documentos</a><?php endif; ?>
                <a href="#sec-story">📸 Story</a>
                <a href="#sec-aceite">✅ Aceitar</a>
            </div>
        </details>

        <script>
            // Fecha dropdown ao clicar num link (depois de navegar)
            document.querySelectorAll('.onb-nav-grid a').forEach(function(a){
                a.addEventListener('click', function(){
                    var d = document.getElementById('onbNav');
                    if (d) d.open = false;
                });
            });
            // Fecha tambem ao clicar fora
            document.addEventListener('click', function(e){
                var d = document.getElementById('onbNav');
                if (d && d.open && !d.contains(e.target)) d.open = false;
            });
        </script>

        <!-- ATALHOS DO PORTAL (4 áreas exclusivas da colaboradora) -->
        <div class="card-block" id="sec-portal" style="background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700));color:#fff;">
            <h2 style="color:#fff;margin-bottom:.4rem;">🎯 Seu Portal</h2>
            <p style="color:rgba(255,255,255,.85);font-size:.92rem;margin-bottom:1rem;">Áreas exclusivas pra você se organizar, pedir o que precisa e acompanhar suas indicações.</p>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.6rem;">
                <a href="solicitacoes.php?token=<?= htmlspecialchars($token) ?>" style="background:rgba(255,255,255,.12);border:1px solid rgba(215,171,144,.4);border-radius:12px;padding:1rem .85rem;text-decoration:none;color:#fff;text-align:center;transition:all .15s;display:block;">
                    <div style="font-size:2rem;line-height:1;margin-bottom:.3rem;">📩</div>
                    <div style="font-weight:700;font-size:.92rem;">Solicitações</div>
                    <div style="font-size:.72rem;opacity:.75;margin-top:.2rem;">Folga, material, doença...</div>
                </a>
                <a href="indicacoes.php?token=<?= htmlspecialchars($token) ?>" style="background:rgba(255,255,255,.12);border:1px solid rgba(215,171,144,.4);border-radius:12px;padding:1rem .85rem;text-decoration:none;color:#fff;text-align:center;transition:all .15s;display:block;">
                    <div style="font-size:2rem;line-height:1;margin-bottom:.3rem;">💸</div>
                    <div style="font-weight:700;font-size:.92rem;">Indicações</div>
                    <div style="font-size:.72rem;opacity:.75;margin-top:.2rem;">Indique e ganhe %</div>
                </a>
                <a href="daily.php?token=<?= htmlspecialchars($token) ?>" style="background:rgba(255,255,255,.12);border:1px solid rgba(215,171,144,.4);border-radius:12px;padding:1rem .85rem;text-decoration:none;color:#fff;text-align:center;transition:all .15s;display:block;">
                    <div style="font-size:2rem;line-height:1;margin-bottom:.3rem;">📓</div>
                    <div style="font-weight:700;font-size:.92rem;">Daily Planner</div>
                    <div style="font-size:.72rem;opacity:.75;margin-top:.2rem;">Foco, tarefas, reflexão</div>
                </a>
                <a href="reunioes.php?token=<?= htmlspecialchars($token) ?>" style="background:rgba(255,255,255,.12);border:1px solid rgba(215,171,144,.4);border-radius:12px;padding:1rem .85rem;text-decoration:none;color:#fff;text-align:center;transition:all .15s;display:block;opacity:.6;">
                    <div style="font-size:2rem;line-height:1;margin-bottom:.3rem;">📅</div>
                    <div style="font-weight:700;font-size:.92rem;">Reuniões</div>
                    <div style="font-size:.72rem;opacity:.75;margin-top:.2rem;">Em breve…</div>
                </a>
            </div>
        </div>

        <!-- MURAL DE AVISOS (se houver) -->
        <?php if (!empty($avisos)): ?>
        <div class="card-block" id="sec-mural">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#fbcfe8,#f9a8d4);">📰</div>
                <h2>Mural — Recados pra você</h2>
            </div>
            <div style="display:flex;flex-direction:column;gap:.7rem;margin-top:.5rem;">
            <?php
            $coresMural = array(
                'verde'   => array('bg'=>'#ecfdf5','border'=>'#34d399','txt'=>'#065f46'),
                'azul'    => array('bg'=>'#eff6ff','border'=>'#60a5fa','txt'=>'#1e40af'),
                'cobre'   => array('bg'=>'#fff7ed','border'=>'#d7ab90','txt'=>'#6a3c2c'),
                'dourado' => array('bg'=>'#fefce8','border'=>'#facc15','txt'=>'#854d0e'),
                'rosa'    => array('bg'=>'#fdf2f8','border'=>'#f9a8d4','txt'=>'#9f1239'),
                'roxo'    => array('bg'=>'#faf5ff','border'=>'#c084fc','txt'=>'#6b21a8'),
            );
            foreach ($avisos as $av):
                $c = isset($coresMural[$av['cor']]) ? $coresMural[$av['cor']] : $coresMural['azul'];
            ?>
                <div style="background:<?= $c['bg'] ?>;border-left:4px solid <?= $c['border'] ?>;border-radius:0 10px 10px 0;padding:.85rem 1.1rem;display:flex;gap:.7rem;align-items:flex-start;">
                    <div style="font-size:1.6rem;line-height:1;flex-shrink:0;"><?= htmlspecialchars($av['icone'] ?: '📋') ?></div>
                    <div style="flex:1;">
                        <h3 style="font-family:'Open Sans',sans-serif;font-size:.98rem;font-weight:700;color:<?= $c['txt'] ?>;margin-bottom:.2rem;"><?= htmlspecialchars($av['titulo']) ?></h3>
                        <p style="font-size:.88rem;color:#374151;margin:0;line-height:1.55;"><?= nl2br(htmlspecialchars($av['mensagem'])) ?></p>
                        <p style="font-size:.7rem;color:#6b7280;margin-top:.4rem;"><?= htmlspecialchars(date('d/m/Y', strtotime($av['created_at']))) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <?php if (!empty($avisosArquivados)): ?>
            <details style="margin-top:1rem;border-top:1px dashed #e5e7eb;padding-top:.8rem;">
                <summary style="cursor:pointer;font-size:.82rem;font-weight:700;color:var(--cobre);user-select:none;list-style:none;display:flex;align-items:center;gap:.4rem;">
                    📜 Recados anteriores (<?= count($avisosArquivados) ?>) ▾
                </summary>
                <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.7rem;">
                <?php foreach ($avisosArquivados as $av):
                    $c = isset($coresMural[$av['cor']]) ? $coresMural[$av['cor']] : $coresMural['azul'];
                ?>
                    <div style="background:#fafafa;border-left:3px solid #d1d5db;border-radius:0 8px 8px 0;padding:.65rem .9rem;display:flex;gap:.6rem;align-items:flex-start;opacity:.75;">
                        <div style="font-size:1.3rem;line-height:1;flex-shrink:0;"><?= htmlspecialchars($av['icone'] ?: '📋') ?></div>
                        <div style="flex:1;">
                            <h4 style="font-family:'Open Sans',sans-serif;font-size:.88rem;font-weight:700;color:#374151;margin-bottom:.15rem;"><?= htmlspecialchars($av['titulo']) ?></h4>
                            <p style="font-size:.82rem;color:#6b7280;margin:0;line-height:1.5;"><?= nl2br(htmlspecialchars($av['mensagem'])) ?></p>
                            <p style="font-size:.68rem;color:#9ca3af;margin-top:.3rem;"><?= htmlspecialchars(date('d/m/Y', strtotime($av['created_at']))) ?> &middot; arquivado</p>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- MENSAGEM PESSOAL (se houver) -->
        <?php if (!empty($reg['mensagem_pessoal'])): ?>
        <div class="card-block" id="sec-mensagem" style="background:linear-gradient(135deg,#fdf2f8,#fce7f3);border-left:6px solid var(--rose);">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#fbcfe8,#f9a8d4);">💌</div>
                <h2>Uma mensagem para você</h2>
            </div>
            <p style="font-size:1rem;line-height:1.7;color:#831843;font-style:italic;"><?= nl2br(htmlspecialchars($reg['mensagem_pessoal'])) ?></p>
        </div>
        <?php endif; ?>

        <!-- MISSÃO / VISÃO / VALORES -->
        <div class="card-block" id="sec-quem-somos">
            <div class="card-title-row">
                <div class="card-title-icon">🌟</div>
                <h2>Quem somos</h2>
            </div>
            <p style="font-size:.95rem;color:var(--muted);">Antes de mais nada, queremos que você conheça o que nos move. Esses são os pilares do <strong>Ferreira e Sá</strong>:</p>

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
                        <li><strong>Solução pacífica dos conflitos:</strong> resolver conflitos de maneira pacífica é nossa prioridade, evitando litígios longos e desgastantes sempre que possível.</li>
                        <li><strong>Conhecimento técnico atualizado:</strong> a equipe se mantém constantemente atualizada nas mudanças da legislação e jurisprudências, oferecendo as soluções mais eficazes e adequadas.</li>
                        <li><strong>Lealdade, limites e transparência:</strong> agimos com integridade e ética, respeitando legalidade e imparcialidade. Somos transparentes sobre os limites e riscos de cada caso, e nunca fazemos promessas irrealistas.</li>
                        <li><strong>Respeito mútuo e confiança:</strong> ouvimos as necessidades e preocupações dos clientes, buscando sempre que possível atender suas expectativas, com respeito mútuo.</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- ACESSOS INSTITUCIONAIS -->
        <?php if ($reg['email_institucional'] || $reg['senha_inicial']): ?>
        <div class="card-block" id="sec-acessos">
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

            <div class="acesso-destaque" style="background:linear-gradient(135deg, #1a73e8, #4285f4);margin-top:1rem;">
                <h4>📁 GOOGLE DRIVE — documentos e arquivos do escritório</h4>
                <p style="opacity:.95;font-size:.92rem;">Todos os arquivos do escritório (processos, modelos, mídias, planilhas) ficam organizados no Drive do Google. Use o <strong>mesmo e-mail e senha</strong> do seu acesso institucional.</p>
                <?php if ($reg['email_institucional']): ?>
                <div class="acesso-item" style="margin-top:.6rem;">
                    <span class="lbl">Login:</span>
                    <span class="val"><?= htmlspecialchars($reg['email_institucional']) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($reg['senha_inicial']): ?>
                <div class="acesso-item">
                    <span class="lbl">Senha:</span>
                    <span class="val"><?= htmlspecialchars($reg['senha_inicial']) ?></span>
                </div>
                <?php endif; ?>
                <a class="btn-config-email" href="https://drive.google.com/" target="_blank" rel="noopener" style="background:#fff;">📂 Acessar o Drive</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- DETALHES DA POSIÇÃO -->
        <?php if ($reg['cargo'] || $reg['setor'] || $reg['modalidade'] || $reg['dias_trabalho'] || $reg['horario_inicio']): ?>
        <div class="card-block" id="sec-jornada">
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
        <div class="card-block" id="sec-remuneracao">
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

        <!-- KIT — perguntas pra surpresa -->
        <?php
        $tamAtual = isset($reg['tamanho_camisa']) ? $reg['tamanho_camisa'] : '';
        $corAtual = isset($reg['kit_cor_favorita']) ? $reg['kit_cor_favorita'] : '';
        $alergAtual = $reg['kit_alergia'] ?? null; // null = não respondeu, 0 = não, 1 = sim
        $alergDet = $reg['kit_alergia_detalhes'] ?? '';
        $alcoolAtual = $reg['kit_consome_alcool'] ?? null;
        $cores = array(
            'azul'     => '#3b82f6',
            'preta'    => '#1f2937',
            'branca'   => '#ffffff',
            'rosa'     => '#ec4899',
            'vermelha' => '#dc2626',
            'verde'    => '#10b981',
        );
        ?>
        <div class="card-block" id="sec-kit">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#fbcfe8,#f9a8d4);">🎁</div>
                <h2>Suas preferências pro kit (vai ser surpresa! ✨)</h2>
            </div>
            <p style="color:#6b7280;font-size:.9rem;margin-bottom:1.2rem;">
                Não vamos contar o que tem no kit pra você ficar com a expectativa do jeito certo 😄
                Mas precisamos de 4 informações pra garantir que vai ser do seu jeito:
            </p>

            <!-- 1. Cor favorita -->
            <div class="pref-bloco">
                <p class="pref-titulo">🎨 Qual sua cor favorita?</p>
                <div class="cor-wrap">
                    <?php foreach ($cores as $nomeCor => $hex): ?>
                        <button type="button"
                                class="btn-cor <?= $corAtual === $nomeCor ? 'sel' : '' ?>"
                                data-cor="<?= $nomeCor ?>"
                                onclick="escolherKit('cor', '<?= $nomeCor ?>', this)"
                                style="background:<?= $hex ?>;<?= $nomeCor === 'branca' ? 'border:2px solid #d1d5db;color:#111;' : 'color:#fff;' ?>">
                            <?= ucfirst($nomeCor) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                <p class="pref-msg" id="msgCor" style="<?= $corAtual ? '' : 'display:none;' ?>">
                    <?= $corAtual ? '✓ Cor ' . htmlspecialchars($corAtual) . ' registrada!' : '' ?>
                </p>
            </div>

            <!-- 2. Tamanho da camisa -->
            <div class="pref-bloco">
                <p class="pref-titulo">👕 Qual o tamanho da sua camisa?</p>
                <div class="cor-wrap">
                    <?php foreach (array('P','M','G','GG') as $t): ?>
                        <button type="button" class="btn-tam <?= $tamAtual === $t ? 'sel' : '' ?>" data-tam="<?= $t ?>" onclick="escolherKit('tamanho', '<?= $t ?>', this)"><?= $t ?></button>
                    <?php endforeach; ?>
                </div>
                <p class="pref-msg" id="msgTam" style="<?= $tamAtual ? '' : 'display:none;' ?>">
                    <?= $tamAtual ? '✓ Tamanho ' . htmlspecialchars($tamAtual) . ' registrado!' : '' ?>
                </p>
            </div>

            <!-- 3. Alergia -->
            <div class="pref-bloco">
                <p class="pref-titulo">🤧 Tem alguma alergia?</p>
                <div class="cor-wrap">
                    <button type="button" class="btn-simnao <?= $alergAtual === 1 ? 'sel-sim' : '' ?>" data-v="sim" onclick="abrirAlergia(true)">Sim</button>
                    <button type="button" class="btn-simnao <?= $alergAtual === 0 ? 'sel-nao' : '' ?>" data-v="nao" onclick="escolherKit('alergia', 'nao', this)">Não</button>
                </div>
                <div id="alergiaDetWrap" style="margin-top:.6rem;<?= $alergAtual === 1 ? '' : 'display:none;' ?>">
                    <input type="text" id="alergiaDet" value="<?= htmlspecialchars($alergDet) ?>" placeholder="Conta pra gente: o que você tem alergia?" style="width:100%;padding:.6rem .9rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;font-size:.9rem;">
                    <button type="button" class="btn-confirmar" onclick="confirmarAlergia()">✓ Confirmar alergia</button>
                </div>
                <p class="pref-msg" id="msgAlergia" style="<?= $alergAtual !== null ? '' : 'display:none;' ?>">
                    <?= $alergAtual === 1 ? '✓ Anotamos: ' . htmlspecialchars($alergDet) : ($alergAtual === 0 ? '✓ Sem alergias, ótimo!' : '') ?>
                </p>
            </div>

            <!-- 4. Bebida -->
            <div class="pref-bloco">
                <p class="pref-titulo">🍷 Consome bebida alcoólica, ainda que socialmente / esporadicamente?</p>
                <div class="cor-wrap">
                    <button type="button" class="btn-simnao <?= $alcoolAtual === 1 ? 'sel-sim' : '' ?>" data-v="sim" onclick="escolherKit('alcool', 'sim', this)">Sim</button>
                    <button type="button" class="btn-simnao <?= $alcoolAtual === 0 ? 'sel-nao' : '' ?>" data-v="nao" onclick="escolherKit('alcool', 'nao', this)">Não</button>
                </div>
                <p class="pref-msg" id="msgAlcool" style="<?= $alcoolAtual !== null ? '' : 'display:none;' ?>">
                    <?= $alcoolAtual !== null ? '✓ Resposta registrada!' : '' ?>
                </p>
            </div>

            <p style="font-size:.82rem;color:#831843;margin-top:1rem;text-align:center;font-weight:700;">
                💜 O kit vai ser entregue com muito carinho!
            </p>
        </div>

        <style>
            .pref-bloco { background:#fdf2f8; border:1.5px solid #fbcfe8; border-radius:12px; padding:1rem 1.1rem; margin-bottom:.85rem; }
            .pref-titulo { font-size:.92rem; color:#831843; font-weight:700; margin-bottom:.6rem; }
            .pref-msg { font-size:.78rem; color:#9f1239; margin-top:.55rem; text-align:center; font-weight:600; }
            .cor-wrap { display:flex; gap:.5rem; justify-content:center; flex-wrap:wrap; }
            .btn-cor { padding:.55rem 1.1rem; border-radius:8px; font-size:.85rem; font-weight:700; cursor:pointer; transition:all .15s; font-family:inherit; border:0; min-width:90px; }
            .btn-cor:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,.18); }
            .btn-cor.sel { transform:scale(1.06); box-shadow:0 0 0 3px #831843, 0 4px 12px rgba(0,0,0,.2); }
            .btn-tam { background:#fff; border:1.5px solid #f9a8d4; color:#9f1239; padding:.55rem 1.1rem; border-radius:8px; font-size:.95rem; font-weight:700; cursor:pointer; min-width:54px; transition:all .15s; font-family:inherit; }
            .btn-tam:hover { background:#fdf2f8; transform:translateY(-1px); }
            .btn-tam.sel { background:linear-gradient(135deg,#db2777,#9f1239); color:#fff; border-color:#db2777; }
            .btn-simnao { background:#fff; border:1.5px solid #f9a8d4; color:#9f1239; padding:.55rem 1.6rem; border-radius:8px; font-size:.9rem; font-weight:700; cursor:pointer; transition:all .15s; font-family:inherit; min-width:90px; }
            .btn-simnao:hover { background:#fdf2f8; transform:translateY(-1px); }
            .btn-simnao.sel-sim { background:#dc2626; color:#fff; border-color:#dc2626; }
            .btn-simnao.sel-nao { background:#059669; color:#fff; border-color:#059669; }
            .btn-confirmar { margin-top:.5rem; background:#9f1239; color:#fff; border:0; padding:.5rem 1.2rem; border-radius:8px; font-weight:700; cursor:pointer; font-family:inherit; font-size:.82rem; }
        </style>

        <script>
            function escolherKit(campo, valor, btn) {
                var fd = new FormData();
                fd.append('acao_kit_pref', '1');
                fd.append('campo', campo);
                fd.append('valor', valor);
                fetch(window.location.href, { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        if (j && j.ok) {
                            // Atualiza UI: limpa botões do mesmo grupo e marca o atual
                            if (campo === 'cor') {
                                document.querySelectorAll('.btn-cor').forEach(function(b){ b.classList.remove('sel'); });
                                if (btn) btn.classList.add('sel');
                                document.getElementById('msgCor').textContent = '✓ Cor ' + valor + ' registrada!';
                                document.getElementById('msgCor').style.display = 'block';
                            } else if (campo === 'tamanho') {
                                document.querySelectorAll('.btn-tam').forEach(function(b){ b.classList.remove('sel'); });
                                if (btn) btn.classList.add('sel');
                                document.getElementById('msgTam').textContent = '✓ Tamanho ' + valor + ' registrado!';
                                document.getElementById('msgTam').style.display = 'block';
                            } else if (campo === 'alergia') {
                                document.querySelectorAll('#sec-kit .pref-bloco').forEach(function(blk) {
                                    if (blk.querySelector('p').textContent.indexOf('alergia') !== -1) {
                                        blk.querySelectorAll('.btn-simnao').forEach(function(b){ b.classList.remove('sel-sim','sel-nao'); });
                                    }
                                });
                                if (valor === 'sim') { if (btn) btn.classList.add('sel-sim'); }
                                else { if (btn) btn.classList.add('sel-nao'); }
                                document.getElementById('alergiaDetWrap').style.display = (valor === 'sim') ? '' : 'none';
                                document.getElementById('msgAlergia').textContent = (valor === 'sim') ? '✓ Anotamos sua alergia!' : '✓ Sem alergias, ótimo!';
                                document.getElementById('msgAlergia').style.display = 'block';
                            } else if (campo === 'alcool') {
                                document.querySelectorAll('#sec-kit .pref-bloco').forEach(function(blk) {
                                    if (blk.querySelector('p').textContent.indexOf('alcoólica') !== -1) {
                                        blk.querySelectorAll('.btn-simnao').forEach(function(b){ b.classList.remove('sel-sim','sel-nao'); });
                                    }
                                });
                                if (valor === 'sim') { if (btn) btn.classList.add('sel-sim'); }
                                else { if (btn) btn.classList.add('sel-nao'); }
                                document.getElementById('msgAlcool').textContent = '✓ Resposta registrada!';
                                document.getElementById('msgAlcool').style.display = 'block';
                            }
                        } else {
                            alert('❌ ' + (j && j.erro ? j.erro : 'Erro ao salvar'));
                        }
                    })
                    .catch(function(err){ alert('❌ ' + err.message); });
            }

            function abrirAlergia(abrir) {
                document.getElementById('alergiaDetWrap').style.display = abrir ? '' : 'none';
                if (abrir) document.getElementById('alergiaDet').focus();
            }

            function confirmarAlergia() {
                var det = document.getElementById('alergiaDet').value.trim();
                if (!det) {
                    alert('Conta pra gente o que você tem alergia, por favor 😊');
                    return;
                }
                var fd = new FormData();
                fd.append('acao_kit_pref', '1');
                fd.append('campo', 'alergia');
                fd.append('valor', 'sim');
                fd.append('detalhes', det);
                fetch(window.location.href, { method:'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        if (j && j.ok) {
                            document.querySelectorAll('#sec-kit .pref-bloco').forEach(function(blk) {
                                if (blk.querySelector('p').textContent.indexOf('alergia') !== -1) {
                                    blk.querySelectorAll('.btn-simnao').forEach(function(b){ b.classList.remove('sel-sim','sel-nao'); });
                                    var btnSim = blk.querySelector('.btn-simnao[data-v="sim"]');
                                    if (btnSim) btnSim.classList.add('sel-sim');
                                }
                            });
                            document.getElementById('msgAlergia').textContent = '✓ Anotamos: ' + det;
                            document.getElementById('msgAlergia').style.display = 'block';
                        } else {
                            alert('❌ ' + (j && j.erro ? j.erro : 'Erro ao salvar'));
                        }
                    });
            }
        </script>

        <!-- PRINCÍPIOS / POSTURA -->
        <div class="card-block" id="sec-principios">
            <div class="card-title-row">
                <div class="card-title-icon">💎</div>
                <h2>Como atuamos por aqui</h2>
            </div>
            <p>Algumas premissas que fazem parte do nosso jeito de trabalhar, e do seu também, a partir de agora:</p>

            <div class="princ-grid">
                <div class="princ-card">
                    <div class="princ-emoji">🤐</div>
                    <h4>Confidencialidade</h4>
                    <p>Tudo o que envolve as demandas dos clientes é <strong>sigiloso</strong>. Não comente casos, nomes, valores ou estratégias com pessoas de fora do escritório, nem com colegas em locais públicos.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">💝</div>
                    <h4>Empatia no atendimento</h4>
                    <p>Quem nos procura está vivendo um momento delicado. Escute com atenção, valide o sentimento da pessoa e responda com <strong>cuidado e gentileza</strong>, sempre.</p>
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
                    <p>Use a tecnologia a seu favor! <strong>Inteligência Artificial é muito bem-vinda</strong>, mas não deixe de <strong>conferir as informações</strong>, dar o seu toque pessoal e, especialmente, <strong>conferir os julgados e artigos</strong> que ela mencionar.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🎮</div>
                    <h4>Discord no expediente</h4>
                    <p>Estar online no <strong>Discord durante o horário de expediente</strong>, e <strong>sem o áudio mutado</strong> (salvo nos momentos em que precisar sair ou não puder ouvir), é muito importante: facilita a comunicação, permite descontração em momentos adequados e diminui as barreiras do home office, aproximando a equipe.</p>
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
                    <p>Estamos aqui para <strong>crescer juntos</strong> e transformar o Ferreira e Sá no <strong>maior escritório de advocacia da região</strong>, e para isso contamos muito com a sua ajuda.</p>
                    <p style="margin-top:.4rem;">Qualquer dúvida, dica, sugestão ou reclamação <strong>pode (e deve!)</strong> ser passada para a gente, para que possamos sempre melhorar. 💜</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🎓</div>
                    <h4>Treinamentos importam</h4>
                    <p>Realize os <strong>treinamentos disponibilizados</strong> com atenção. Tudo aqui é muito cíclico: o que você aprende no treinamento volta repetidamente no dia a dia, e dominar esses fluxos faz toda a diferença na sua entrega.</p>
                </div>
                <div class="princ-card" style="border-top-color:#dc2626;background:#fef2f2;">
                    <div class="princ-emoji">⚠️</div>
                    <h4 style="color:#7f1d1d;">Atenção com arquivos em nuvem</h4>
                    <p><strong>Todos os nossos arquivos ficam em nuvem.</strong> Só altere ou apague um documento quando tiver <strong>certeza</strong> de que deve fazê-lo.</p>
                    <p style="margin-top:.4rem;">Alterar um documento para uma pessoa <strong>altera para todo mundo</strong> e pode causar danos à equipe inteira. Na dúvida, pergunte antes! 🙏</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🖥️</div>
                    <h4>Precisou de equipamento? Fala!</h4>
                    <p>Se precisar de algum <strong>equipamento específico</strong> para trabalhar bem (cadeira, monitor extra, headset, suporte de notebook, etc.), <strong>não hesite em nos comunicar</strong>. A gente conversa.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">⏱️</div>
                    <h4>Regra dos 3 minutos</h4>
                    <p>Se uma tarefa pode ser feita em <strong>até 3 minutos</strong>, faça <em>na hora</em>. Evita acúmulo, procrastinação e aquela sensação ruim de pendência. Pequenas ações resolvidas no momento poupam horas depois.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">📝</div>
                    <h4>Anote tudo (lembretes)</h4>
                    <p>Use o <strong>campo de lembretes</strong> aqui no Hub — temos um espaço próprio pra isso. São muitos atendimentos por dia: <strong>sem rotina de anotar, com certeza absoluta vamos esquecer</strong> alguma coisa. Anote sempre.</p>
                </div>
                <div class="princ-card">
                    <div class="princ-emoji">🗂️</div>
                    <h4>Anote nos andamentos</h4>
                    <p>Quando fizer algo num processo ou conversar com um cliente, <strong>registre nos andamentos</strong> do processo. Isso facilita o trabalho de quem pega o caso depois e evita aquele ping-pong de "o que foi feito aí?" toda hora. <strong>Rotina simples, dia leve.</strong></p>
                </div>
                <div class="princ-card" style="border-top-color:#059669;background:#ecfdf5;">
                    <div class="princ-emoji">💸</div>
                    <h4 style="color:#065f46;">Indicações rendem!</h4>
                    <p>Conhece alguém que precisa? <strong>Indique pra gente!</strong> Indicações que viram contrato fechado <strong>rendem percentual</strong> pra quem trouxe. Traga pra casa! 🏠💚</p>
                </div>
                <div class="princ-card" style="border-top-color:#dc2626;background:#fef2f2;">
                    <div class="princ-emoji">🚫</div>
                    <h4 style="color:#7f1d1d;">Brincadeiras: ok, mas com limite</h4>
                    <p>Brincadeiras são bem-vindas! Mas, pra evitar problemas (cada um é cada um), <strong>alguns assuntos não podem ser pauta</strong>:</p>
                    <ul style="margin:.4rem 0 .4rem 1.1rem;padding:0;font-size:.82rem;line-height:1.6;">
                        <li><strong>Política</strong></li>
                        <li><strong>Assuntos discriminatórios</strong></li>
                        <li>Qualquer fala que <strong>desrespeite outras pessoas</strong> — ainda que em tom de "brincadeira"</li>
                    </ul>
                    <p style="font-size:.78rem;color:#7f1d1d;margin-top:.4rem;"><strong>Não serão tolerados</strong> e justificarão o término imediato das atividades.</p>
                </div>
            </div>
        </div>

        <!-- PLANO DE INCENTIVO À SAÚDE -->
        <div class="card-block" id="sec-fit" style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1.5px solid #34d399;">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#86efac,#4ade80);">💪</div>
                <h2 style="color:#065f46;">Ferreira e Sá <span style="font-weight:900;letter-spacing:.05em;">FIT</span> 💪</h2>
            </div>
            <p>Nós também nos preocupamos com a <strong>saúde da equipe</strong>! Por isso, criamos o <strong>Ferreira e Sá FIT</strong>: um adicional financeiro pra quem cuida do corpo durante o mês.</p>

            <div style="background:#fff;border:1.5px dashed #10b981;border-radius:12px;padding:1.2rem 1.4rem;margin-top:1rem;">
                <div style="text-align:center;font-size:.7rem;letter-spacing:3px;font-weight:700;color:#065f46;margin-bottom:.4rem;">RECEBA POR MÊS</div>
                <div style="text-align:center;font-size:2.4rem;font-weight:900;color:#047857;line-height:1;">R$ 100,00</div>
                <div style="text-align:center;font-size:.85rem;color:#065f46;margin-top:.5rem;">de incentivo por treinar</div>
            </div>

            <p style="margin-top:1rem;font-size:.92rem;"><strong>Como funciona:</strong> dentro de um mês (a contar do <strong>1º dia útil</strong>), quem treinar pelo menos <strong>3 dias na semana</strong>, com no mínimo <strong>30 minutos por treino</strong>, em qualquer modalidade (academia, corrida, pilates, dança, etc.), recebe os <strong>R$ 100,00</strong> como adicional do mês seguinte.</p>
            <div style="background:#fff;border:1.5px solid #34d399;border-radius:10px;padding:.85rem 1rem;margin-top:.85rem;font-size:.9rem;color:#065f46;">
                📲 <strong>Para participar:</strong> você precisa estar cadastrada(o) no app <strong>Gymrats</strong> e fazer o <strong>check-in</strong> lá a cada treino. É só pedir no grupo da equipe pra te adicionar! 💚
            </div>

            <p style="font-size:.82rem;color:#065f46;margin-top:.5rem;">💚 É o nosso jeitinho de te lembrar que cuidar de você também faz parte da jornada.</p>
        </div>

        <!-- SEGURO CONTRA ACIDENTES PESSOAIS -->
        <div class="card-block" id="sec-seguro" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #60a5fa;">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#bfdbfe,#93c5fd);">🛡️</div>
                <h2 style="color:#1e40af;">Você está protegida(o)</h2>
            </div>
            <p>Durante todo o período do seu estágio, você está coberta(o) por um <strong>seguro contra acidentes pessoais</strong>, contratado pelo escritório conforme exige o <strong>art. 9º, IV, da Lei 11.788/2008</strong>.</p>
            <p style="margin-top:.5rem;">A apólice cobre lesões e demais sinistros decorrentes do exercício das atividades de estágio. Os <strong>dados da apólice</strong> (número e seguradora) demoram um pouquinho para serem fornecidos pela seguradora; assim que chegarem, em até <strong>2 (duas) semanas</strong>, atualizaremos seu Termo de Compromisso e você receberá uma cópia do comprovante.</p>
            <p style="font-size:.82rem;color:#1e40af;margin-top:.6rem;">🔵 Se algo acontecer, fale com a gente o quanto antes, a gente cuida de tudo junto.</p>
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
        <div class="card-block" id="sec-documentos">
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
                            <a href="documento.php?token=<?= htmlspecialchars($token) ?>&doc=<?= (int)$doc['id'] ?>" style="background:linear-gradient(135deg,var(--cobre),var(--cobre-light));color:#fff;padding:.55rem 1.1rem;border-radius:8px;font-size:.82rem;font-weight:700;text-decoration:none;">
                                <?php if ($schema['fluxo'] === 'so_assina'): ?>✍️ Ler e assinar<?php elseif ($schema['fluxo'] === 'admin_marca_e_ambos_assinam'): ?>👁 Acompanhar<?php else: ?>📝 Preencher e assinar<?php endif; ?>
                            </a>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <p style="font-size:.78rem;color:var(--muted);margin-top:1rem;text-align:center;">
                💡 Após assinar, você pode imprimir ou salvar em PDF a qualquer momento.
            </p>
        </div>
        <?php endif; ?>

        <!-- COMPARTILHAR NO INSTAGRAM -->
        <div class="card-block" id="sec-story" style="background:linear-gradient(135deg,#fdf2f8,#fce7f3);border:1.5px solid #f9a8d4;">
            <div class="card-title-row">
                <div class="card-title-icon" style="background:linear-gradient(135deg,#f9a8d4,#ec4899);">📸</div>
                <h2 style="color:#831843;">Compartilhe esse momento!</h2>
            </div>
            <p>Que tal contar pra galera que você está começando essa jornada com a gente? 💜<br>
            Geramos uma imagem linda pra você postar nos seus stories: é só baixar ou compartilhar direto.</p>
            <p style="font-size:.82rem;color:#9f1239;margin-top:.4rem;">
                ✨ <strong>Não esqueça de marcar <a href="https://instagram.com/advocaciaferreiraesa" target="_blank" style="color:#831843;">@advocaciaferreiraesa</a></strong> no seu story!
            </p>
            <div style="text-align:center;margin-top:1.2rem;">
                <button type="button" onclick="abrirGeradorStory()" style="background:linear-gradient(135deg,#db2777,#9f1239);color:#fff;border:0;padding:.85rem 1.8rem;border-radius:12px;font-size:1rem;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(219,39,119,.3);font-family:inherit;">
                    📸 Criar minha imagem pro story
                </button>
            </div>
        </div>

        <!-- FECHAMENTO EMOCIONAL -->
        <div style="background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700));color:#fff;border-radius:var(--radius);padding:2.2rem 1.8rem;text-align:center;margin-top:2rem;position:relative;overflow:hidden;">
            <div style="position:absolute;inset:0;background-image:radial-gradient(circle at 30% 30%,rgba(215,171,144,.18) 0%,transparent 50%),radial-gradient(circle at 70% 70%,rgba(184,115,51,.15) 0%,transparent 50%);pointer-events:none;"></div>
            <div style="position:relative;z-index:1;">
                <div style="font-size:2.4rem;margin-bottom:.4rem;">💜</div>
                <h2 style="color:#fff;font-size:1.5rem;margin-bottom:.6rem;line-height:1.3;">
                    Queremos que você tenha <span style="color:var(--nude);">orgulho</span><br>
                    de falar que faz parte da <span style="color:var(--nude);">Família Ferreira e Sá</span>!
                </h2>
                <p style="font-size:.95rem;opacity:.9;margin-top:.8rem;max-width:520px;margin-left:auto;margin-right:auto;">
                    Vamos, juntos, ajudar a melhorar a vida de outras famílias e construir uma história que vale a pena ser contada. ✨
                </p>
            </div>
        </div>

        <!-- ACEITE -->
        <?php if ($jaAceitou): ?>
            <div class="aceite-box aceito" id="sec-aceite">
                <div class="aceite-emoji">✅</div>
                <h3>Tudo certo, <?= htmlspecialchars($primeiroNome) ?>!</h3>
                <p>Você confirmou a leitura desta página em <strong><?= htmlspecialchars(date('d/m/Y \à\s H:i', strtotime($reg['aceite_em']))) ?></strong>.</p>
                <p style="margin-top:.5rem;">Estamos prontos pra começar essa jornada juntos. Qualquer dúvida, fale com a gente. 💜</p>
            </div>
        <?php else: ?>
            <div class="aceite-box" id="sec-aceite">
                <div class="aceite-emoji">🌟</div>
                <h3><?= g('Pronta', 'Pronto', $genero) ?> pra começar?</h3>
                <p>Quando você confirmar abaixo, registramos que leu as informações desta página. Pode aceitar com tranquilidade. Qualquer dúvida, é só chamar a equipe.</p>
                <form method="POST" onsubmit="return confirm('Confirmar leitura e aceite das informações desta página?');">
                    <input type="hidden" name="acao_aceitar" value="1">
                    <button type="submit" class="btn-aceite">✓ Li e estou <?= g('pronta', 'pronto', $genero) ?> pra começar!</button>
                </form>
            </div>
        <?php endif; ?>

    </div>

    <div class="footer-fsa">
        <strong>FERREIRA E SÁ</strong> — Advocacia Especializada<br>
        Estamos felizes por ter você na equipe 💜
    </div>

<?php endif; ?>

<?php if ($autenticado): ?>
<!-- ─── MODAL: GERADOR DE STORY ────────────────────────── -->
<div id="storyOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center;overflow-y:auto;padding:1rem;">
    <div style="background:#fff;border-radius:16px;max-width:540px;width:100%;padding:1.5rem;max-height:95vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.4);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
            <h3 style="margin:0;color:#831843;font-size:1.15rem;">📸 Sua imagem pro story</h3>
            <button onclick="fecharGeradorStory()" style="background:none;border:0;font-size:1.4rem;cursor:pointer;color:#9f1239;">✕</button>
        </div>

        <!-- Preview -->
        <div style="text-align:center;background:#f3f4f6;border-radius:12px;padding:.8rem;margin-bottom:1rem;">
            <img id="storyPreview" src="" alt="" style="max-width:100%;max-height:60vh;border-radius:8px;display:none;">
            <div id="storyLoading" style="padding:3rem 1rem;color:#6b7280;font-size:.9rem;">⏳ Gerando sua imagem…</div>
        </div>

        <!-- Trocar foto -->
        <div style="margin-bottom:1rem;">
            <label style="font-size:.78rem;font-weight:700;color:#831843;display:block;margin-bottom:.3rem;">📷 Trocar a foto (opcional)</label>
            <input type="file" accept="image/*" id="storyFotoInput" onchange="trocarFotoStory(this)" style="font-size:.85rem;width:100%;">
            <p style="font-size:.7rem;color:#6b7280;margin-top:.3rem;">A foto do seu WhatsApp já vem por padrão. Se quiser usar outra, suba aqui.</p>
        </div>

        <!-- Seletor de modelo -->
        <div style="margin-bottom:1rem;">
            <label style="font-size:.78rem;font-weight:700;color:#831843;display:block;margin-bottom:.3rem;">🎨 Escolha o estilo</label>
            <div id="storyTemplates" style="display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem;">
                <button type="button" class="story-tpl-btn ativo" data-tpl="1" onclick="trocarTemplateStory(1)">✨ Clássico</button>
                <button type="button" class="story-tpl-btn" data-tpl="2" onclick="trocarTemplateStory(2)">🎉 Divertido</button>
                <button type="button" class="story-tpl-btn" data-tpl="3" onclick="trocarTemplateStory(3)">🤍 Minimalista</button>
                <button type="button" class="story-tpl-btn" data-tpl="4" onclick="trocarTemplateStory(4)">👑 Elegante</button>
            </div>
        </div>
        <style>
            .story-tpl-btn { background:#fff; border:1.5px solid #f9a8d4; color:#9f1239; padding:.5rem .7rem; border-radius:8px; font-size:.82rem; font-weight:700; cursor:pointer; font-family:inherit; transition:all .15s; }
            .story-tpl-btn:hover { background:#fdf2f8; }
            .story-tpl-btn.ativo { background:linear-gradient(135deg,#db2777,#9f1239); color:#fff; border-color:#db2777; }
        </style>

        <!-- Lembrete da menção -->
        <div style="background:#fdf2f8;border-left:3px solid #db2777;padding:.6rem .9rem;border-radius:0 6px 6px 0;font-size:.78rem;color:#9f1239;margin-bottom:1rem;">
            ✨ <strong>Não esqueça:</strong> ao postar o story, marque <strong>@advocaciaferreiraesa</strong>!
        </div>

        <!-- Botões -->
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
            <button type="button" onclick="baixarStory()" style="flex:1;min-width:140px;background:#fff;border:2px solid #db2777;color:#9f1239;padding:.7rem;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.92rem;">
                📥 Baixar imagem
            </button>
            <button type="button" onclick="compartilharStory()" id="btnCompartilharStory" style="flex:1;min-width:140px;background:linear-gradient(135deg,#db2777,#9f1239);color:#fff;border:0;padding:.7rem;border-radius:10px;font-weight:700;cursor:pointer;font-family:inherit;font-size:.92rem;">
                📲 Compartilhar
            </button>
        </div>

        <p style="font-size:.7rem;color:#6b7280;text-align:center;margin-top:.8rem;">
            No celular, "Compartilhar" abre o seletor com Instagram, WhatsApp e outros apps. No PC, baixa o PNG.
        </p>
    </div>
</div>

<!-- Canvas escondido onde a imagem é gerada -->
<canvas id="storyCanvas" width="1080" height="1920" style="display:none;"></canvas>

<script>
(function(){
    var primeiroNome = <?= json_encode(htmlspecialchars($primeiroNome)) ?>;
    var generoStr = <?= json_encode($genero) ?>;
    var fotoUrlServidor = <?= json_encode($fotoColab ? $fotoColab : '') ?>;
    var logoUrl = '/conecta/assets/img/logo.png';

    var imagensCarregadas = false;
    var logoImg = new Image();
    var fotoImg = new Image();
    fotoImg.crossOrigin = 'anonymous';

    function carregarImagens(callback) {
        var carregadas = 0, total = fotoUrlServidor ? 2 : 1;
        var done = function() {
            carregadas++;
            if (carregadas === total) callback();
        };
        logoImg.onload = done;
        logoImg.onerror = done;
        logoImg.src = logoUrl;
        if (fotoUrlServidor) {
            fotoImg.onload = done;
            fotoImg.onerror = function(){ fotoImg = null; done(); };
            fotoImg.src = fotoUrlServidor;
        }
    }

    window.abrirGeradorStory = function() {
        document.getElementById('storyOverlay').style.display = 'flex';
        document.getElementById('storyPreview').style.display = 'none';
        document.getElementById('storyLoading').style.display = 'block';
        if (!imagensCarregadas) {
            carregarImagens(function(){
                imagensCarregadas = true;
                desenharStory();
            });
        } else {
            desenharStory();
        }
    };

    window.fecharGeradorStory = function() {
        document.getElementById('storyOverlay').style.display = 'none';
    };

    window.trocarFotoStory = function(input) {
        if (!input.files || !input.files[0]) return;
        var fr = new FileReader();
        fr.onload = function(e) {
            var nova = new Image();
            nova.onload = function() {
                fotoImg = nova;
                desenharStory();
            };
            nova.src = e.target.result;
        };
        fr.readAsDataURL(input.files[0]);
    };

    var templateAtivo = 1;

    window.trocarTemplateStory = function(n) {
        templateAtivo = n;
        document.querySelectorAll('.story-tpl-btn').forEach(function(b){
            b.classList.toggle('ativo', parseInt(b.dataset.tpl, 10) === n);
        });
        desenharStory();
    };

    function desenharStory() {
        if (templateAtivo === 2) return desenharDivertido();
        if (templateAtivo === 3) return desenharMinimalista();
        if (templateAtivo === 4) return desenharElegante();
        return desenharClassico();
    }

    // Helper: desenha logo dentro de um cartão branco arredondado.
    function drawLogoBox(ctx, W, x, y, w, h, radius, bgColor) {
        ctx.fillStyle = bgColor || 'rgba(255,255,255,0.96)';
        roundRect(ctx, x, y, w, h, radius || 24);
        ctx.fill();
        if (logoImg.complete && logoImg.naturalWidth) {
            var iw = logoImg.naturalWidth, ih = logoImg.naturalHeight;
            var scale = Math.min((w - 60) / iw, (h - 40) / ih);
            var dw = iw * scale, dh = ih * scale;
            var dx = x + (w - dw) / 2, dy = y + (h - dh) / 2;
            ctx.drawImage(logoImg, dx, dy, dw, dh);
        }
    }

    // Helper: desenha foto circular (com fallback emoji).
    function drawFotoCircular(ctx, cx, cy, r, anelCor1, anelCor2, fallbackBg, fallbackEmoji, fallbackEmojiCor) {
        if (anelCor1) {
            ctx.strokeStyle = anelCor1; ctx.lineWidth = 16;
            ctx.beginPath(); ctx.arc(cx, cy, r + 12, 0, Math.PI * 2); ctx.stroke();
        }
        if (anelCor2) {
            ctx.strokeStyle = anelCor2; ctx.lineWidth = 4;
            ctx.beginPath(); ctx.arc(cx, cy, r + 22, 0, Math.PI * 2); ctx.stroke();
        }
        if (fotoImg && fotoImg.complete && fotoImg.naturalWidth) {
            ctx.save();
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.clip();
            var iw = fotoImg.naturalWidth, ih = fotoImg.naturalHeight;
            var s = Math.max((r * 2) / iw, (r * 2) / ih);
            var dw = iw * s, dh = ih * s;
            ctx.drawImage(fotoImg, cx - dw/2, cy - dh/2, dw, dh);
            ctx.restore();
        } else {
            ctx.fillStyle = fallbackBg || 'rgba(255,255,255,0.1)';
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2); ctx.fill();
            ctx.font = '180px serif';
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillStyle = fallbackEmojiCor || '#d7ab90';
            ctx.fillText(fallbackEmoji || '💜', cx, cy);
        }
    }

    // Helper: ajusta tamanho de fonte pra caber em maxW
    function fontSizeFit(ctx, texto, maxW, baseFont, sizeIni, sizeMin) {
        var s = sizeIni;
        do {
            ctx.font = baseFont.replace('{S}', s);
            if (ctx.measureText(texto).width <= maxW) break;
            s -= 6;
        } while (s > sizeMin);
        return s;
    }

    function finalizarPreview() {
        var canvas = document.getElementById('storyCanvas');
        var dataUrl = canvas.toDataURL('image/png');
        var prev = document.getElementById('storyPreview');
        prev.src = dataUrl;
        prev.style.display = 'block';
        document.getElementById('storyLoading').style.display = 'none';
    }

    // ─── TEMPLATE 1 — CLÁSSICO (petrol + cobre) ────────────
    function desenharClassico() {
        var canvas = document.getElementById('storyCanvas');
        var ctx = canvas.getContext('2d');
        var W = 1080, H = 1920;
        var grad = ctx.createLinearGradient(0, 0, 0, H);
        grad.addColorStop(0, '#052228'); grad.addColorStop(0.6, '#0e3d44'); grad.addColorStop(1, '#173d46');
        ctx.fillStyle = grad; ctx.fillRect(0, 0, W, H);
        // círculos decorativos
        ctx.fillStyle = 'rgba(184, 115, 51, 0.18)';
        ctx.beginPath(); ctx.arc(900, 200, 280, 0, Math.PI * 2); ctx.fill();
        ctx.beginPath(); ctx.arc(150, H - 300, 240, 0, Math.PI * 2); ctx.fill();
        ctx.fillStyle = 'rgba(215, 171, 144, 0.12)';
        ctx.beginPath(); ctx.arc(W - 100, H - 600, 180, 0, Math.PI * 2); ctx.fill();
        // logo
        drawLogoBox(ctx, W, (W - 700) / 2, 110, 700, 180);
        // foto
        drawFotoCircular(ctx, W / 2, 720, 240, '#d7ab90', '#6a3c2c');
        // textos
        ctx.fillStyle = '#d7ab90'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
        ctx.font = '700 italic 56px "Playfair Display", serif';
        ctx.fillText(generoStr === 'M' ? 'Seja Bem-Vindo,' : 'Seja Bem-Vinda,', W/2, 1140);
        ctx.fillStyle = '#fff';
        var nomeTxt = primeiroNome + '! ✨';
        fontSizeFit(ctx, nomeTxt, W - 100, '800 {S}px "Playfair Display", serif', 110, 60);
        ctx.fillText(nomeTxt, W/2, 1260);
        ctx.font = '500 42px "Open Sans", sans-serif';
        ctx.fillText('Começa hoje uma nova jornada', W/2, 1380);
        ctx.fillStyle = '#d7ab90';
        ctx.font = '700 48px "Open Sans", sans-serif';
        ctx.fillText('na Família Ferreira e Sá', W/2, 1450);
        ctx.fillStyle = '#fff'; ctx.font = '500 42px "Open Sans", sans-serif';
        ctx.fillText('💜', W/2, 1530);
        // mention
        ctx.fillStyle = 'rgba(215, 171, 144, 0.95)'; ctx.font = '600 38px "Open Sans", sans-serif';
        ctx.fillText('@advocaciaferreiraesa', W/2, 1780);
        ctx.fillStyle = 'rgba(255,255,255,0.6)'; ctx.font = '500 28px "Open Sans", sans-serif';
        ctx.fillText('me marca no story! 💜', W/2, 1830);
        finalizarPreview();
    }

    // ─── TEMPLATE 2 — DIVERTIDO (cores vibrantes) ──────────
    function desenharDivertido() {
        var canvas = document.getElementById('storyCanvas');
        var ctx = canvas.getContext('2d');
        var W = 1080, H = 1920;
        // gradient rosa → laranja → cobre
        var grad = ctx.createLinearGradient(0, 0, W, H);
        grad.addColorStop(0, '#fb7185'); grad.addColorStop(0.5, '#f59e0b'); grad.addColorStop(1, '#ec4899');
        ctx.fillStyle = grad; ctx.fillRect(0, 0, W, H);
        // bolhas coloridas espalhadas
        var bolhas = [
            {x:100, y:400, r:80, c:'rgba(255,255,255,.25)'},
            {x:980, y:550, r:120, c:'rgba(255,255,255,.18)'},
            {x:200, y:1500, r:100, c:'rgba(255,255,255,.22)'},
            {x:900, y:1700, r:60, c:'rgba(255,255,255,.3)'},
            {x:540, y:1820, r:50, c:'rgba(255,255,255,.25)'},
            {x:80, y:900, r:50, c:'rgba(255,255,255,.3)'}
        ];
        bolhas.forEach(function(b){ ctx.fillStyle=b.c; ctx.beginPath(); ctx.arc(b.x,b.y,b.r,0,Math.PI*2); ctx.fill(); });
        // logo
        drawLogoBox(ctx, W, (W - 700) / 2, 110, 700, 180);
        // foto com anel branco vibrante
        drawFotoCircular(ctx, W / 2, 720, 240, '#fff', '#fbbf24', 'rgba(255,255,255,.3)', '🎉', '#9f1239');
        // textos
        ctx.fillStyle = '#fff'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
        ctx.font = '700 italic 60px "Playfair Display", serif';
        ctx.fillText('Olha quem chegou! 👀', W/2, 1130);
        var nomeTxt = primeiroNome + '! 🎉';
        fontSizeFit(ctx, nomeTxt, W - 100, '900 {S}px "Playfair Display", serif', 130, 70);
        // sombra no nome pra dar destaque
        ctx.shadowColor = 'rgba(0,0,0,.2)'; ctx.shadowBlur = 12; ctx.shadowOffsetY = 4;
        ctx.fillText(nomeTxt, W/2, 1280);
        ctx.shadowColor = 'transparent'; ctx.shadowBlur = 0; ctx.shadowOffsetY = 0;
        ctx.font = '600 44px "Open Sans", sans-serif';
        ctx.fillText('Bora viver coisas novas', W/2, 1410);
        ctx.fillText('na Família Ferreira e Sá!', W/2, 1480);
        ctx.font = '500 56px "Open Sans", sans-serif';
        ctx.fillText('🚀✨💜', W/2, 1570);
        // mention
        ctx.fillStyle = '#fff'; ctx.font = '700 42px "Open Sans", sans-serif';
        ctx.fillText('@advocaciaferreiraesa', W/2, 1780);
        ctx.fillStyle = 'rgba(255,255,255,.85)'; ctx.font = '500 30px "Open Sans", sans-serif';
        ctx.fillText('me marca aí no story! 💛', W/2, 1830);
        finalizarPreview();
    }

    // ─── TEMPLATE 3 — MINIMALISTA (clean) ──────────────────
    function desenharMinimalista() {
        var canvas = document.getElementById('storyCanvas');
        var ctx = canvas.getContext('2d');
        var W = 1080, H = 1920;
        // fundo nude bem clarinho
        var grad = ctx.createLinearGradient(0, 0, 0, H);
        grad.addColorStop(0, '#fff7ed'); grad.addColorStop(1, '#fdf2f8');
        ctx.fillStyle = grad; ctx.fillRect(0, 0, W, H);
        // borda fina cobre
        ctx.strokeStyle = '#d7ab90'; ctx.lineWidth = 4;
        ctx.strokeRect(60, 60, W - 120, H - 120);
        // logo (sem cartão de fundo)
        if (logoImg.complete && logoImg.naturalWidth) {
            var iw = logoImg.naturalWidth, ih = logoImg.naturalHeight;
            var maxW = 600; var scale = Math.min(maxW / iw, 160 / ih);
            var dw = iw * scale, dh = ih * scale;
            ctx.drawImage(logoImg, (W - dw) / 2, 180, dw, dh);
        }
        // linha divisória cobre
        ctx.strokeStyle = '#d7ab90'; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(W/2 - 80, 410); ctx.lineTo(W/2 + 80, 410); ctx.stroke();
        // foto
        drawFotoCircular(ctx, W / 2, 760, 240, '#d7ab90', null, '#fff', '🌿', '#6a3c2c');
        // textos
        ctx.fillStyle = '#6a3c2c'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
        ctx.font = '400 italic 52px "Playfair Display", serif';
        ctx.fillText(generoStr === 'M' ? 'bem-vindo' : 'bem-vinda', W/2, 1170);
        ctx.fillStyle = '#052228';
        var nomeTxt = primeiroNome + '.';
        fontSizeFit(ctx, nomeTxt, W - 200, '800 {S}px "Playfair Display", serif', 130, 70);
        ctx.fillText(nomeTxt, W/2, 1310);
        // linha
        ctx.strokeStyle = '#d7ab90'; ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.moveTo(W/2 - 60, 1370); ctx.lineTo(W/2 + 60, 1370); ctx.stroke();
        ctx.fillStyle = '#6a3c2c'; ctx.font = '400 36px "Open Sans", sans-serif';
        ctx.fillText('início de uma nova jornada', W/2, 1440);
        ctx.fillStyle = '#052228'; ctx.font = '600 38px "Open Sans", sans-serif';
        ctx.fillText('na Família Ferreira e Sá', W/2, 1500);
        // mention
        ctx.fillStyle = '#6a3c2c'; ctx.font = '600 36px "Open Sans", sans-serif';
        ctx.fillText('@advocaciaferreiraesa', W/2, 1760);
        ctx.fillStyle = 'rgba(106,60,44,.6)'; ctx.font = '400 italic 26px "Playfair Display", serif';
        ctx.fillText('marque-nos no seu story', W/2, 1810);
        finalizarPreview();
    }

    // ─── TEMPLATE 4 — ELEGANTE (formal/profissional) ──────
    function desenharElegante() {
        var canvas = document.getElementById('storyCanvas');
        var ctx = canvas.getContext('2d');
        var W = 1080, H = 1920;
        // fundo petrol mais escuro com vinheta
        ctx.fillStyle = '#021317'; ctx.fillRect(0, 0, W, H);
        var rgrad = ctx.createRadialGradient(W/2, H/2, 200, W/2, H/2, 1200);
        rgrad.addColorStop(0, 'rgba(15, 60, 70, .8)'); rgrad.addColorStop(1, 'rgba(2, 19, 23, 1)');
        ctx.fillStyle = rgrad; ctx.fillRect(0, 0, W, H);
        // moldura dourada dupla
        ctx.strokeStyle = '#c9a26b'; ctx.lineWidth = 3;
        ctx.strokeRect(60, 60, W - 120, H - 120);
        ctx.lineWidth = 1.5;
        ctx.strokeRect(80, 80, W - 160, H - 160);
        // linhas decorativas no topo e fundo
        ctx.strokeStyle = '#c9a26b'; ctx.lineWidth = 1.5;
        ctx.beginPath(); ctx.moveTo(W/2 - 200, 130); ctx.lineTo(W/2 + 200, 130); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(W/2 - 200, H - 130); ctx.lineTo(W/2 + 200, H - 130); ctx.stroke();
        // logo (em cartão branco discreto)
        drawLogoBox(ctx, W, (W - 620) / 2, 200, 620, 160, 12, '#f8f5f0');
        // foto com anéis dourados
        drawFotoCircular(ctx, W / 2, 770, 240, '#c9a26b', '#8a6d3b', 'rgba(201,162,107,.15)', '✦', '#c9a26b');
        // textos
        ctx.fillStyle = '#c9a26b'; ctx.textAlign = 'center'; ctx.textBaseline = 'alphabetic';
        ctx.font = '400 italic 48px "Playfair Display", serif';
        ctx.fillText('Tenho a honra de iniciar', W/2, 1160);
        ctx.font = '400 italic 48px "Playfair Display", serif';
        ctx.fillText('minha jornada profissional', W/2, 1230);
        // nome
        ctx.fillStyle = '#fff';
        var nomeTxt = primeiroNome.toUpperCase();
        fontSizeFit(ctx, nomeTxt, W - 200, '700 {S}px "Playfair Display", serif', 110, 60);
        ctx.fillText(nomeTxt, W/2, 1380);
        // separador ✦
        ctx.fillStyle = '#c9a26b'; ctx.font = '500 36px "Playfair Display", serif';
        ctx.fillText('✦', W/2, 1440);
        // família
        ctx.fillStyle = '#c9a26b'; ctx.font = '700 italic 50px "Playfair Display", serif';
        ctx.fillText('na Família Ferreira e Sá', W/2, 1530);
        ctx.fillStyle = 'rgba(255,255,255,.7)'; ctx.font = '400 italic 32px "Playfair Display", serif';
        ctx.fillText('Advocacia Especializada', W/2, 1590);
        // mention
        ctx.fillStyle = '#c9a26b'; ctx.font = '500 italic 36px "Playfair Display", serif';
        ctx.fillText('@advocaciaferreiraesa', W/2, 1780);
        ctx.fillStyle = 'rgba(201,162,107,.6)'; ctx.font = '400 italic 24px "Playfair Display", serif';
        ctx.fillText('Marque o escritório em seu story', W/2, 1820);
        finalizarPreview();
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    window.baixarStory = function() {
        var canvas = document.getElementById('storyCanvas');
        var link = document.createElement('a');
        link.download = 'story-fs-' + primeiroNome.toLowerCase().replace(/[^a-z0-9]/g, '') + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    };

    window.compartilharStory = function() {
        var canvas = document.getElementById('storyCanvas');
        if (!navigator.canShare || !navigator.share) {
            // Fallback: download
            window.baixarStory();
            alert('Imagem baixada! 💜\n\nAgora é só abrir o Instagram, criar um story, escolher essa imagem e marcar @advocaciaferreiraesa');
            return;
        }
        canvas.toBlob(function(blob) {
            var file = new File([blob], 'story-ferreirasa.png', {type: 'image/png'});
            if (navigator.canShare({files: [file]})) {
                navigator.share({
                    files: [file],
                    title: 'Família Ferreira e Sá',
                    text: 'Nova jornada começando! 💜 (não esquece de marcar @advocaciaferreiraesa)'
                }).catch(function(err){
                    if (err && err.name !== 'AbortError') alert('Erro: ' + err.message);
                });
            } else {
                window.baixarStory();
                alert('Imagem baixada! 💜\n\nAgora é só abrir o Instagram, criar um story, escolher essa imagem e marcar @advocaciaferreiraesa');
            }
        }, 'image/png');
    };

    // Fecha modal ao clicar fora
    document.getElementById('storyOverlay').addEventListener('click', function(e){
        if (e.target === this) fecharGeradorStory();
    });
})();
</script>
<?php endif; ?>

</body>
</html>
