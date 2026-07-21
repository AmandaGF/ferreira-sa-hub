<?php
/**
 * Ferreira & Sá Advocacia — Linha do Tempo do Cliente (página pública)
 *
 * Acesso: sem login, por token exclusivo + trava opcional por CPF.
 * Editor: modules/operacional/linha_tempo.php
 *
 * Nenhum dado do processo é escrito no HTML antes da autenticação — a tela
 * de entrada é renderizada e o script encerra ali.
 */

require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';
require_once __DIR__ . '/../../core/functions_linha_tempo.php';

header('X-Robots-Tag: noindex, nofollow', true);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Referrer-Policy: no-referrer');

function lth($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function lt_404() {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>Página não encontrada</title>'
       . '<div style="font:16px/1.6 system-ui,sans-serif;color:#33454a;text-align:center;padding:4rem 1.5rem;">'
       . '<p style="font-size:1.1rem;margin:0 0 .4rem;">Esta página não está disponível.</p>'
       . '<p style="color:#7d9295;margin:0;">Se você recebeu este link do escritório, fale com a gente pelo WhatsApp.</p>'
       . '</div>';
    exit;
}

$pdo   = db();
$token = isset($_GET['t']) ? trim((string)$_GET['t']) : '';
if (!preg_match('/^[a-f0-9]{32}$/', $token)) lt_404();

lt_self_heal($pdo);

$st = $pdo->prepare(
    "SELECT tl.*, c.title AS caso_titulo, c.case_number, c.case_type,
            cl.name AS cliente_nome
     FROM case_timeline tl
     JOIN cases c    ON c.id = tl.case_id
     LEFT JOIN clients cl ON cl.id = c.client_id
     WHERE tl.token = ?"
);
$st->execute(array($token));
$tl = $st->fetch();
if (!$tl) lt_404();

// Equipe logada no Hub enxerga o rascunho — é a pré-visualização.
$_equipe = isset($_SESSION['user']['id']);
if (!(int)$tl['publicado'] && !$_equipe) lt_404();

// ─────────────────────────────────────────────────────────────────
//  Trava de CPF
// ─────────────────────────────────────────────────────────────────
$sessKey   = 'lt_auth_' . $token;
$semTrava  = ($tl['gate'] === 'aberto') || !preg_match('/^\d{11}$/', (string)$tl['gate_cpf']);
$liberado  = $_equipe || $semTrava || !empty($_SESSION[$sessKey]);
$erroGate  = '';
$bloqueado = false;

if (!$liberado) {
    $ip        = lt_ip();
    $tentativas = lt_tentativas_recentes($pdo, $token, $ip);
    $bloqueado  = $tentativas >= 5;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {
        $digitado = preg_replace('/\D/', '', (string)($_POST['cpf'] ?? ''));
        if (strlen($digitado) !== 11) {
            $erroGate = 'Digite os 11 números do CPF.';
        } elseif (hash_equals((string)$tl['gate_cpf'], $digitado)) {
            lt_registrar_tentativa($pdo, $token, $ip, 1);
            $_SESSION[$sessKey] = time();
            // Post/Redirect/Get: recarregar não reenvia o CPF
            header('Location: ?t=' . urlencode($token), true, 303);
            exit;
        } else {
            lt_registrar_tentativa($pdo, $token, $ip, 0);
            $tentativas++;
            $bloqueado = $tentativas >= 5;
            $erroGate  = $bloqueado
                ? 'Muitas tentativas. Aguarde 15 minutos e tente de novo.'
                : 'CPF não confere. Confira os números e tente de novo.';
        }
    }
}

// ─────────────────────────────────────────────────────────────────
//  Tela de entrada — nada do processo é renderizado aqui
// ─────────────────────────────────────────────────────────────────
if (!$liberado) {
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Ferreira &amp; Sá Advocacia</title>
<style>
:root{
  --petroleo:#052228; --petroleo-2:#0B3138;
  --rose:#D7AB90; --rose-claro:#EBD3C2;
  --brasa:#E08D78;
  --mono:ui-monospace,"Cascadia Mono",Consolas,"SF Mono",Menlo,monospace;
  --serif:"Iowan Old Style","Palatino Linotype","Book Antiqua",Palatino,Georgia,serif;
  --sans:"Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0; background:var(--petroleo); color:#E7EFED;
  font-family:var(--sans); font-size:16px; line-height:1.6;
  -webkit-font-smoothing:antialiased;
  display:grid; place-items:center; padding:2rem 1.25rem;
  background-image:radial-gradient(110% 70% at 50% 0%, var(--petroleo-2), var(--petroleo) 65%);
}
.cx{width:100%; max-width:370px; text-align:center}
.selo{
  width:54px; height:54px; margin:0 auto 1.6rem; display:grid; place-items:center;
  border:1px solid rgba(215,171,144,.45); border-radius:50%; color:var(--rose);
}
h1{
  font-family:var(--serif); font-weight:400; font-size:1.5rem; line-height:1.3;
  letter-spacing:-.01em; margin:0 0 .6rem;
}
.sub{margin:0 0 2rem; font-size:.9rem; line-height:1.55; color:rgba(231,239,237,.62)}
form{display:flex; flex-direction:column; gap:.55rem; text-align:left}
label{
  font-size:.66rem; letter-spacing:.16em; text-transform:uppercase;
  color:rgba(215,171,144,.75); font-weight:700;
}
input{
  font-family:var(--mono); font-size:1.1rem; letter-spacing:.06em; text-align:center;
  padding:.85rem .7rem; border-radius:9px; width:100%;
  border:1px solid rgba(215,171,144,.3); background:rgba(255,255,255,.05); color:#fff;
  font-variant-numeric:tabular-nums;
}
input::placeholder{color:rgba(231,239,237,.28)}
input:focus{outline:none; border-color:var(--rose); box-shadow:0 0 0 4px rgba(215,171,144,.14)}
button{
  margin-top:.7rem; width:100%; padding:.85rem; border:0; border-radius:9px; cursor:pointer;
  background:var(--rose); color:var(--petroleo); font-family:var(--sans);
  font-size:.92rem; font-weight:700; letter-spacing:.01em;
  transition:filter .18s ease, transform .12s ease;
}
button:hover:not(:disabled){filter:brightness(1.07)}
button:active:not(:disabled){transform:translateY(1px)}
button:disabled{opacity:.4; cursor:not-allowed}
.erro{margin:.9rem 0 0; min-height:1.3em; font-size:.84rem; color:var(--brasa)}
.pe{
  margin-top:2.4rem; font-size:.7rem; line-height:1.7; color:rgba(231,239,237,.4);
  letter-spacing:.02em;
}
.pe strong{display:block; font-family:var(--serif); font-size:.86rem; letter-spacing:.22em;
  text-transform:uppercase; color:rgba(231,239,237,.62); font-weight:400; margin-bottom:.2rem}
:focus-visible{outline:2px solid var(--rose); outline-offset:3px}
.tremer{animation:tremer .4s}
@keyframes tremer{
  0%,100%{transform:translateX(0)} 20%{transform:translateX(-7px)}
  40%{transform:translateX(6px)} 60%{transform:translateX(-4px)} 80%{transform:translateX(3px)}
}
@media (prefers-reduced-motion:reduce){ .tremer{animation:none} button{transition:none} }
</style>
</head>
<body>
<main class="cx<?= $erroGate ? ' tremer' : '' ?>">
  <div class="selo" aria-hidden="true">
    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
      <rect x="4" y="10.5" width="16" height="10.5" rx="2.5"/>
      <path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>
    </svg>
  </div>

  <h1>A linha do tempo do seu processo</h1>
  <p class="sub">
    Esta página é reservada. Informe o CPF
    <?= trim((string)$tl['gate_label']) !== '' ? lth($tl['gate_label']) : 'cadastrado no processo' ?>
    para abrir.
  </p>

  <form method="post" autocomplete="off" novalidate>
    <label for="cpf">CPF</label>
    <input id="cpf" name="cpf" inputmode="numeric" maxlength="14"
           placeholder="000.000.000-00" autocomplete="off" spellcheck="false"
           <?= $bloqueado ? 'disabled' : 'autofocus' ?>>
    <button type="submit" <?= $bloqueado ? 'disabled' : '' ?>>Abrir minha linha do tempo</button>
  </form>

  <p class="erro" role="alert"><?= lth($erroGate) ?></p>

  <p class="pe">
    <strong>Ferreira &amp; Sá</strong>
    Advocacia Especializada<br>
    Conteúdo sigiloso — não encaminhe este link.
  </p>
</main>

<script>
(function(){
  var i = document.getElementById('cpf');
  if (!i) return;
  i.addEventListener('input', function(){
    var d = i.value.replace(/\D/g,'').slice(0,11), s = d;
    if (d.length > 9)      s = d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9);
    else if (d.length > 6) s = d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6);
    else if (d.length > 3) s = d.slice(0,3)+'.'+d.slice(3);
    i.value = s;
  });
})();
</script>
</body>
</html><?php
    exit;
}

// ─────────────────────────────────────────────────────────────────
//  Conteúdo — daqui pra baixo o visitante já está autenticado
// ─────────────────────────────────────────────────────────────────
$marcos = lt_marcos($pdo, (int)$tl['id'], true);
if (!$marcos && !$_equipe) lt_404();

// Uma visualização por sessão — recarregar não infla o contador.
if (empty($_SESSION['lt_visto_' . $token]) && !$_equipe) {
    $_SESSION['lt_visto_' . $token] = 1;
    try {
        $pdo->prepare("UPDATE case_timeline SET visualizacoes = visualizacoes + 1, ultima_visualizacao = NOW() WHERE id = ?")
            ->execute(array((int)$tl['id']));
    } catch (Throwable $e) { /* contador não pode derrubar a página */ }
}

$pedidos = lt_pedidos($pdo, $tl);
$passos  = lt_linhas($tl['proximos_passos']);

// Espinha dorsal: a distância entre dois marcos é o tempo real de espera.
$vaos = array();
$anterior = null;
foreach ($marcos as $i => $m) {
    $vaos[$i] = null;
    if ($i === 0) { $anterior = $m['data_evento']; continue; }
    if ($anterior && $m['data_evento']) {
        $d = lt_dias_entre($anterior, $m['data_evento']);
        $vaos[$i] = array('px' => lt_vao_px($d), 'dias' => $d);
    } else {
        $vaos[$i] = array('px' => 70, 'dias' => 0);
    }
    if ($m['data_evento']) $anterior = $m['data_evento'];
}

// Cabeçalho: a duração é o fato mais característico do processo.
$primeiraData = null;
foreach ($marcos as $m) { if ($m['data_evento']) { $primeiraData = $m['data_evento']; break; } }
$duracao = $primeiraData ? lt_intervalo_humano(lt_dias_entre($primeiraData, date('Y-m-d'))) : '';

$tituloPag = trim((string)$tl['titulo']);
if ($tituloPag === '') {
    $tituloPag = $tl['cliente_nome']
        ? 'A linha do tempo do processo de ' . trim((string)$tl['cliente_nome'])
        : 'A linha do tempo do seu processo';
}

/** Parágrafos a partir de um bloco de texto livre. */
function lt_p($txt, $classe = '') {
    $txt = trim((string)$txt);
    if ($txt === '') return '';
    $out = '';
    foreach (preg_split('/\R{2,}/', $txt) as $par) {
        $par = trim($par);
        if ($par === '') continue;
        $out .= '<p' . ($classe ? ' class="' . $classe . '"' : '') . '>'
              . nl2br(lth($par)) . '</p>';
    }
    return $out;
}

/** Bloco de vídeo/áudio: embute o que dá, e linka o resto. */
function lt_midia_html($url, $tipo) {
    $url = trim((string)$url);
    if ($url === '') return '';

    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([\w-]{11})~', $url, $mm)) {
        return '<div class="midia-quadro"><iframe src="https://www.youtube-nocookie.com/embed/' . lth($mm[1]) . '"'
             . ' title="Vídeo do escritório" loading="lazy" allowfullscreen'
             . ' allow="accelerometer; encrypted-media; picture-in-picture"></iframe></div>';
    }
    if (preg_match('~\.(mp3|m4a|aac|ogg|wav)(\?|$)~i', $url) || $tipo === 'audio') {
        return '<audio class="midia-audio" controls preload="none" src="' . lth($url) . '"></audio>';
    }
    if (preg_match('~\.(mp4|webm|mov)(\?|$)~i', $url)) {
        return '<div class="midia-quadro"><video controls preload="metadata" src="' . lth($url) . '"></video></div>';
    }
    return '<a class="midia-link" href="' . lth($url) . '" target="_blank" rel="noopener">Abrir o '
         . ($tipo === 'audio' ? 'áudio' : 'vídeo') . ' &rarr;</a>';
}

$marcasTipo = array(
    'nos'       => 'Nosso movimento',
    'decisao'   => 'Decisão da Justiça',
    'audiencia' => 'Audiência',
    'recurso'   => 'Recurso',
    'marco'     => 'Marco',
    'alerta'    => 'Atenção',
    'agora'     => 'Onde estamos hoje',
    'outro'     => '',
);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#052228">
<title><?= lth($tituloPag) ?> — Ferreira &amp; Sá</title>
<style>
/* ═══════════════════════════════════════════════════════════════
   Ferreira & Sá — Linha do Tempo do Cliente
   Petróleo é a tinta, não o fundo. O papel é claro, levemente
   esverdeado; a espinha da linha do tempo é proporcional ao tempo
   real de espera — é isso que o cliente de fato viveu.
   ═══════════════════════════════════════════════════════════════ */
:root{
  --papel:#EDF1F0;
  --superficie:#FFFFFF;
  --superficie-2:#E3EAE8;
  --tinta:#052228;
  --tinta-2:#2E4A4C;
  --apagado:#6B8285;
  --regua:#CBD9D6;

  --jade:#146B5E;      --jade-fundo:#DCEBE7;
  --rose:#D7AB90;      --rose-tinta:#8A5636;  --rose-fundo:#F5E7DC;
  --brasa:#A6402F;     --brasa-fundo:#F6DFD9;

  --sombra:0 1px 2px rgba(5,34,40,.05), 0 10px 30px -20px rgba(5,34,40,.45);
  --serif:"Iowan Old Style","Palatino Linotype","Book Antiqua",Palatino,Georgia,serif;
  --sans:"Segoe UI",system-ui,-apple-system,"Helvetica Neue",Arial,sans-serif;
  --mono:ui-monospace,"Cascadia Mono",Consolas,"SF Mono",Menlo,monospace;

  --railx:96px;
  color-scheme:light;
}
@media (prefers-color-scheme:dark){
  :root{
    --papel:#071C21; --superficie:#0E2A30; --superficie-2:#143840;
    --tinta:#E7EFED; --tinta-2:#BCCFCC; --apagado:#7E9799; --regua:#204048;
    --jade:#5FC6AE;  --jade-fundo:#123A35;
    --rose:#D7AB90;  --rose-tinta:#E0B79E; --rose-fundo:#3A2A21;
    --brasa:#E08D78; --brasa-fundo:#3D2019;
    --sombra:0 1px 2px rgba(0,0,0,.35), 0 12px 32px -22px rgba(0,0,0,.9);
    color-scheme:dark;
  }
}
:root[data-tema="dark"]{
  --papel:#071C21; --superficie:#0E2A30; --superficie-2:#143840;
  --tinta:#E7EFED; --tinta-2:#BCCFCC; --apagado:#7E9799; --regua:#204048;
  --jade:#5FC6AE;  --jade-fundo:#123A35;
  --rose:#D7AB90;  --rose-tinta:#E0B79E; --rose-fundo:#3A2A21;
  --brasa:#E08D78; --brasa-fundo:#3D2019;
  --sombra:0 1px 2px rgba(0,0,0,.35), 0 12px 32px -22px rgba(0,0,0,.9);
  color-scheme:dark;
}
:root[data-tema="light"]{
  --papel:#EDF1F0; --superficie:#FFFFFF; --superficie-2:#E3EAE8;
  --tinta:#052228; --tinta-2:#2E4A4C; --apagado:#6B8285; --regua:#CBD9D6;
  --jade:#146B5E;  --jade-fundo:#DCEBE7;
  --rose:#D7AB90;  --rose-tinta:#8A5636; --rose-fundo:#F5E7DC;
  --brasa:#A6402F; --brasa-fundo:#F6DFD9;
  --sombra:0 1px 2px rgba(5,34,40,.05), 0 10px 30px -20px rgba(5,34,40,.45);
  color-scheme:light;
}

*{box-sizing:border-box}
body{
  margin:0; background:var(--papel); color:var(--tinta);
  font-family:var(--sans); font-size:17px; line-height:1.65;
  -webkit-font-smoothing:antialiased;
}
:focus-visible{outline:2px solid var(--rose-tinta); outline-offset:3px; border-radius:3px}
.env{max-width:940px; margin:0 auto; padding:0 24px 96px}

/* ── Abertura ───────────────────────────────────────────────── */
.abre{padding:80px 0 52px}
.sobrancelha{
  font-family:var(--mono); font-size:.68rem; letter-spacing:.2em; text-transform:uppercase;
  color:var(--rose-tinta); font-weight:700; margin:0 0 26px;
}
.abre h1{
  font-family:var(--serif); font-weight:400; font-size:clamp(2rem,5.2vw,3.3rem);
  line-height:1.08; letter-spacing:-.022em; margin:0 0 24px; text-wrap:balance;
}
.lede{max-width:60ch; font-size:1.05rem; color:var(--tinta-2); margin:0 0 40px}
.lede p{margin:0 0 .8em} .lede p:last-child{margin-bottom:0}

/* O fato mais característico do processo é a duração. */
.medida{
  display:flex; gap:0; flex-wrap:wrap; border-top:1px solid var(--regua);
  padding-top:20px;
}
.medida div{padding-right:40px}
.medida dt{
  font-family:var(--mono); font-size:.63rem; letter-spacing:.16em; text-transform:uppercase;
  color:var(--apagado); font-weight:700; margin:0 0 5px;
}
.medida dd{
  margin:0; font-family:var(--serif); font-size:1.32rem; line-height:1.2; color:var(--tinta);
  font-variant-numeric:tabular-nums;
}
.medida dd small{display:block; font-family:var(--sans); font-size:.76rem; color:var(--apagado); margin-top:2px}

/* ── Onde estamos agora ─────────────────────────────────────── */
.secao{padding:54px 0 0}
.secao > h2{
  font-family:var(--serif); font-weight:400; font-size:1.7rem; letter-spacing:-.015em;
  margin:0 0 6px;
}
.secao > .cabecalho-sub{margin:0 0 28px; color:var(--apagado); font-size:.92rem; max-width:56ch}

.painel{display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px}
.cartao{
  background:var(--superficie); border-radius:2px 12px 12px 2px; padding:22px 22px 24px;
  box-shadow:var(--sombra); border-left:3px solid var(--regua);
}
.cartao h3{
  font-family:var(--mono); font-size:.66rem; letter-spacing:.15em; text-transform:uppercase;
  font-weight:700; margin:0 0 10px;
}
.cartao p{margin:0 0 .7em; font-size:.94rem; line-height:1.6; color:var(--tinta-2)}
.cartao p:last-child{margin-bottom:0}
.cartao.ok{border-left-color:var(--jade)}         .cartao.ok h3{color:var(--jade)}
.cartao.andando{border-left-color:var(--rose)}    .cartao.andando h3{color:var(--rose-tinta)}
.cartao.atencao{border-left-color:var(--brasa)}   .cartao.atencao h3{color:var(--brasa)}

/* ── Recado em vídeo/áudio ──────────────────────────────────── */
.midia{
  margin-top:16px; background:var(--superficie); border-radius:12px; padding:20px 22px 22px;
  box-shadow:var(--sombra);
}
.midia h3{font-family:var(--serif); font-weight:400; font-size:1.16rem; margin:0 0 14px}
.midia-quadro{position:relative; padding-top:56.25%; border-radius:8px; overflow:hidden; background:var(--superficie-2)}
.midia-quadro iframe, .midia-quadro video{position:absolute; inset:0; width:100%; height:100%; border:0}
.midia-audio{width:100%}
.midia-link{
  display:inline-block; background:var(--tinta); color:var(--papel); text-decoration:none;
  padding:.6rem 1.1rem; border-radius:8px; font-size:.9rem; font-weight:600;
}

/* ── A linha do tempo ───────────────────────────────────────── */
.linha{position:relative; padding-top:34px}
.trilho, .trilho-cheio{
  position:absolute; top:0; bottom:0; left:var(--railx);
  width:2px; margin-left:-1px; border-radius:2px;
}
.trilho{background:var(--regua)}
.trilho-cheio{
  background:linear-gradient(180deg, var(--rose), var(--rose-tinta));
  transform-origin:top; transform:scaleY(var(--p,0));
  transition:transform .12s linear;
}

.ev{display:grid; grid-template-columns:var(--railx) 1fr}
.quando{
  padding-right:24px; text-align:right;
  display:flex; flex-direction:column; align-items:flex-end; gap:4px; padding-top:1px;
}
.quando .ano{
  font-family:var(--mono); font-size:1.24rem; font-weight:600; color:var(--tinta);
  letter-spacing:-.04em; line-height:1; font-variant-numeric:tabular-nums;
}
.quando .diames{
  font-family:var(--mono); font-size:.68rem; color:var(--apagado);
  letter-spacing:.06em; text-transform:lowercase;
}
.quando .rotulo{
  font-size:.68rem; color:var(--rose-tinta); background:var(--rose-fundo);
  padding:2px 8px; border-radius:20px; white-space:nowrap; font-weight:600; line-height:1.4;
}

.corpo{position:relative; padding-left:32px}
.marca{
  position:absolute; left:0; top:7px; width:11px; height:11px; border-radius:50%;
  transform:translateX(-50%); background:var(--papel); border:2px solid var(--regua);
}
.ev[data-tipo="nos"]       .marca{background:var(--rose);  border-color:var(--rose)}
.ev[data-tipo="decisao"]   .marca{background:var(--jade);  border-color:var(--jade)}
.ev[data-tipo="audiencia"] .marca{background:var(--papel); border-color:var(--jade); border-width:3px}
.ev[data-tipo="recurso"]   .marca{background:var(--brasa); border-color:var(--brasa); border-radius:2px; transform:translateX(-50%) rotate(45deg)}
.ev[data-tipo="alerta"]    .marca{background:var(--brasa); border-color:var(--brasa)}
.ev[data-tipo="marco"]     .marca{background:var(--jade);  border-color:var(--jade); border-radius:2px}
.ev.destacado .marca{
  width:17px; height:17px; top:4px; background:var(--rose); border-color:var(--rose);
  box-shadow:0 0 0 6px var(--rose-fundo);
}
.ev[data-tipo="agora"] .marca{
  width:15px; height:15px; top:5px; background:var(--brasa); border-color:var(--brasa);
  box-shadow:0 0 0 6px var(--brasa-fundo);
}

.tipo{
  font-family:var(--mono); font-size:.62rem; letter-spacing:.15em; text-transform:uppercase;
  color:var(--apagado); font-weight:700; margin:0 0 6px;
}
.ev[data-tipo="decisao"] .tipo{color:var(--jade)}
.ev[data-tipo="agora"]   .tipo, .ev[data-tipo="alerta"] .tipo{color:var(--brasa)}
.ev[data-tipo="nos"]     .tipo{color:var(--rose-tinta)}

.corpo h3{
  font-family:var(--serif); font-weight:400; font-size:1.28rem; line-height:1.28;
  margin:0 0 10px; letter-spacing:-.012em; text-wrap:balance;
}
.ev.destacado .corpo h3{font-size:1.6rem; color:var(--rose-tinta)}
.ev[data-tipo="decisao"] .corpo h3{color:var(--jade)}
.ev[data-tipo="agora"]   .corpo h3{color:var(--brasa)}
.corpo p{margin:0 0 .7em; color:var(--tinta-2); font-size:.97rem; max-width:58ch}
.corpo p:last-child{margin-bottom:0}
.obs{
  font-size:.87rem; color:var(--apagado); border-left:2px solid var(--regua);
  padding-left:14px; margin-top:14px; max-width:56ch;
}
.ev[data-tipo="agora"] .corpo{
  background:var(--superficie); border-radius:0 12px 12px 0;
  padding:20px 24px 22px 32px; box-shadow:var(--sombra);
}
.ev[data-tipo="agora"] .marca{top:24px}

/* O vão entre marcos É o tempo de espera. */
.vao{position:relative; height:var(--v,70px)}
.vao-tag{
  position:absolute; left:var(--railx); top:50%;
  transform:translate(-50%,-50%);
  font-family:var(--mono); font-size:.63rem; letter-spacing:.06em;
  color:var(--apagado); background:var(--papel);
  padding:6px 2px; text-align:center; width:92px; line-height:1.35;
}

/* ── Precisamos de você ─────────────────────────────────────── */
.pedidos{
  background:var(--brasa-fundo); border-radius:12px; padding:24px 26px 26px;
  border:1px solid var(--brasa);
}
.pedidos h2{font-family:var(--serif); font-weight:400; font-size:1.5rem; margin:0 0 6px; color:var(--brasa)}
.pedidos p.intro{margin:0 0 16px; font-size:.93rem; color:var(--tinta-2)}
.pedidos ul{margin:0; padding:0; list-style:none}
.pedidos li{
  padding:10px 0 10px 30px; position:relative; font-size:.96rem; color:var(--tinta);
  border-bottom:1px solid rgba(166,64,47,.18);
}
.pedidos li:last-child{border-bottom:none; padding-bottom:0}
.pedidos li::before{
  content:""; position:absolute; left:2px; top:16px; width:11px; height:11px;
  border:1.5px solid var(--brasa); border-radius:3px;
}

/* ── O que vem pela frente ──────────────────────────────────── */
.passos{display:grid; grid-template-columns:repeat(auto-fit,minmax(270px,1fr)); gap:24px 38px}
.passo{position:relative; padding-left:44px; margin:0; color:var(--tinta-2); font-size:.96rem}
.passo .n{
  position:absolute; left:0; top:-1px; width:28px; height:28px; display:grid; place-items:center;
  border:1px solid var(--jade); border-radius:50%;
  font-family:var(--mono); font-size:.82rem; font-weight:600; color:var(--jade);
}

/* ── Fecho e rodapé ─────────────────────────────────────────── */
.fecho{
  margin-top:60px; padding:32px 0 0; border-top:1px solid var(--regua);
  font-family:var(--serif); font-size:1.12rem; line-height:1.65; color:var(--tinta-2);
  max-width:60ch;
}
.fecho p{margin:0 0 .7em}
.assina{
  margin-top:26px !important; font-family:var(--sans); font-size:.82rem;
  letter-spacing:.02em; color:var(--tinta); font-weight:600;
}
.rodape{
  margin-top:64px; padding-top:28px; border-top:1px solid var(--regua);
  color:var(--apagado); font-size:.8rem; line-height:1.7;
}
.rodape .marca-fs{
  font-family:var(--serif); font-size:1.1rem; color:var(--tinta);
  letter-spacing:.2em; text-transform:uppercase; margin:0 0 2px;
}
.rodape .marca-fs span{display:block; font-size:.55rem; letter-spacing:.34em; color:var(--apagado); margin-top:3px}
.rodape p{margin:.55rem 0 0}
.sigilo{color:var(--brasa); max-width:60ch}

.tema{
  position:fixed; right:16px; top:16px; z-index:10;
  width:36px; height:36px; border-radius:50%; cursor:pointer;
  background:var(--superficie); border:1px solid var(--regua); color:var(--apagado);
  display:grid; place-items:center; font-size:.9rem; box-shadow:var(--sombra);
}
.aviso-rascunho{
  background:var(--brasa); color:#fff; text-align:center; padding:.5rem 1rem;
  font-size:.78rem; font-weight:700; letter-spacing:.02em;
}

/* ── Movimento ──────────────────────────────────────────────── */
.surge{opacity:0; transform:translateY(18px)}
.surge.visivel{
  opacity:1; transform:none;
  transition:opacity .6s ease, transform .6s cubic-bezier(.22,1,.36,1);
}
.entra{opacity:0; transform:translateY(14px); animation:entra .7s cubic-bezier(.22,1,.36,1) forwards}
.entra:nth-child(1){animation-delay:.05s}
.entra:nth-child(2){animation-delay:.15s}
.entra:nth-child(3){animation-delay:.25s}
.entra:nth-child(4){animation-delay:.35s}
@keyframes entra{to{opacity:1; transform:none}}
.ev[data-tipo="agora"] .marca{animation:respira 3.4s ease-in-out infinite}
@keyframes respira{
  0%,100%{box-shadow:0 0 0 6px var(--brasa-fundo)}
  50%{box-shadow:0 0 0 12px transparent}
}

/* ── Celular ────────────────────────────────────────────────── */
@media (max-width:640px){
  body{font-size:16px}
  :root{--railx:11px}
  .env{padding:0 20px 72px}
  .abre{padding:52px 0 38px}
  .ev{grid-template-columns:1fr}
  /* Coluna única: a régua de datas vira uma linha acima do texto, e o corpo
     é deslocado até o trilho pra que a marca continue caindo em cima dele. */
  .quando{
    flex-direction:row; align-items:baseline; gap:10px;
    text-align:left; justify-content:flex-start;
    padding:0 0 6px calc(var(--railx) + 22px);
  }
  .quando .ano{font-size:1rem}
  .corpo{margin-left:var(--railx); padding-left:22px}
  .ev[data-tipo="agora"] .corpo{padding:18px 20px 20px 22px; border-radius:12px}
  .vao{height:calc(var(--v,70px) * .62)}
  .vao-tag{
    left:0; transform:translateY(-50%); text-align:left;
    width:auto; padding:4px 0 4px calc(var(--railx) + 22px); background:none;
  }
  .medida div{padding-right:28px}
  .fecho{font-size:1.05rem}
}

@media (prefers-reduced-motion:reduce){
  .surge{opacity:1; transform:none; transition:none}
  .entra{opacity:1; transform:none; animation:none}
  .trilho-cheio{transition:none}
  .ev[data-tipo="agora"] .marca{animation:none}
}
@media print{ .tema{display:none} .surge,.entra{opacity:1; transform:none; animation:none} }
</style>
<noscript><style>.surge{opacity:1; transform:none} .tema{display:none}</style></noscript>
</head>
<body>

<?php if ($_equipe && !(int)$tl['publicado']): ?>
<div class="aviso-rascunho">Pré-visualização da equipe — esta linha do tempo ainda não foi publicada. O cliente vê "página não encontrada".</div>
<?php endif; ?>

<button class="tema" id="btnTema" type="button" aria-label="Alternar tema claro e escuro" title="Alternar tema">◐</button>

<div class="env">

  <!-- ── Abertura ────────────────────────────────────────────── -->
  <header class="abre">
    <p class="sobrancelha entra">Acompanhamento do seu processo</p>
    <h1 class="entra"><?= lth($tituloPag) ?></h1>
    <?php if (trim((string)$tl['lede']) !== ''): ?>
      <div class="lede entra"><?= lt_p($tl['lede']) ?></div>
    <?php endif; ?>

    <dl class="medida entra">
      <?php if ($duracao !== ''): ?>
      <div>
        <dt>Caminhando há</dt>
        <dd><?= lth($duracao) ?><small>desde <?= lth(lt_data_extenso($primeiraData)) ?></small></dd>
      </div>
      <?php endif; ?>
      <div>
        <dt>Passos até aqui</dt>
        <dd><?= count($marcos) ?><small><?= count($marcos) === 1 ? 'momento registrado' : 'momentos registrados' ?></small></dd>
      </div>
      <?php if (!empty($tl['case_type'])): ?>
      <div>
        <dt>Assunto</dt>
        <dd style="font-size:1.05rem;line-height:1.35;"><?= lth($tl['case_type']) ?></dd>
      </div>
      <?php endif; ?>
    </dl>
  </header>

  <!-- ── Onde estamos agora ──────────────────────────────────── -->
  <?php
  $temPainel = trim((string)$tl['painel_ok']) !== ''
            || trim((string)$tl['painel_atencao']) !== ''
            || trim((string)$tl['painel_acao']) !== '';
  if ($temPainel): ?>
  <section class="secao">
    <h2>Onde estamos agora</h2>
    <p class="cabecalho-sub">O resumo de hoje, antes da história completa.</p>
    <div class="painel">
      <?php if (trim((string)$tl['painel_ok']) !== ''): ?>
      <article class="cartao ok surge"><h3>Já conquistamos</h3><?= lt_p($tl['painel_ok']) ?></article>
      <?php endif; ?>
      <?php if (trim((string)$tl['painel_atencao']) !== ''): ?>
      <article class="cartao andando surge"><h3>Em andamento</h3><?= lt_p($tl['painel_atencao']) ?></article>
      <?php endif; ?>
      <?php if (trim((string)$tl['painel_acao']) !== ''): ?>
      <article class="cartao atencao surge"><h3>Exige atenção</h3><?= lt_p($tl['painel_acao']) ?></article>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── Recado em vídeo/áudio ───────────────────────────────── -->
  <?php $midia = lt_midia_html($tl['midia_url'], $tl['midia_tipo']); ?>
  <?php if ($midia !== ''): ?>
  <section class="secao">
    <div class="midia surge">
      <h3><?= lth(trim((string)$tl['midia_titulo']) !== '' ? $tl['midia_titulo'] : 'Um recado para você') ?></h3>
      <?= $midia ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── A linha do tempo ────────────────────────────────────── -->
  <section class="secao">
    <h2>A história do seu caso</h2>
    <p class="cabecalho-sub">
      O espaço entre um momento e outro mostra o tempo real que passou — inclusive as esperas.
    </p>

    <div class="linha" id="linha">
      <div class="trilho" aria-hidden="true"></div>
      <div class="trilho-cheio" id="trilhoCheio" aria-hidden="true"></div>

      <?php foreach ($marcos as $i => $m):
        $vao = $vaos[$i];
        if ($vao !== null): ?>
          <div class="vao" style="--v:<?= (int)$vao['px'] ?>px" aria-hidden="true">
            <?php if ($vao['dias'] >= 45): ?>
              <span class="vao-tag"><?= lth(lt_intervalo_humano($vao['dias'])) ?><br>de espera</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <article class="ev surge<?= (int)$m['destaque'] ? ' destacado' : '' ?>" data-tipo="<?= lth($m['tipo']) ?>">
          <div class="quando">
            <?php if (trim((string)$m['data_label']) !== ''): ?>
              <span class="rotulo"><?= lth($m['data_label']) ?></span>
            <?php elseif (!empty($m['data_evento'])):
              $ts = strtotime($m['data_evento']); ?>
              <span class="ano"><?= date('Y', $ts) ?></span>
              <span class="diames"><?= date('d/m', $ts) ?></span>
            <?php endif; ?>
          </div>

          <div class="corpo">
            <span class="marca" aria-hidden="true"></span>
            <?php if (!empty($marcasTipo[$m['tipo']])): ?>
              <p class="tipo"><?= lth($marcasTipo[$m['tipo']]) ?></p>
            <?php endif; ?>
            <h3><?= lth($m['titulo']) ?></h3>
            <?= lt_p($m['texto']) ?>
            <?php if (trim((string)$m['nota']) !== ''): ?>
              <p class="obs"><?= nl2br(lth($m['nota'])) ?></p>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- ── O que precisamos de você ────────────────────────────── -->
  <?php if ($pedidos): ?>
  <section class="secao">
    <div class="pedidos surge">
      <h2>O que precisamos de você</h2>
      <p class="intro">Assim que chegarem, seguimos com o próximo passo. Pode mandar pelo WhatsApp do escritório.</p>
      <ul>
        <?php foreach ($pedidos as $p): ?>
          <li><?= lth($p) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── O que vem pela frente ───────────────────────────────── -->
  <?php if ($passos): ?>
  <section class="secao">
    <h2>O que vem pela frente</h2>
    <p class="cabecalho-sub">Os próximos movimentos previstos. A Justiça tem seu tempo, e a gente acompanha cada etapa.</p>
    <div class="passos">
      <?php foreach ($passos as $i => $p): ?>
        <p class="passo surge"><span class="n"><?= $i + 1 ?></span><?= lth($p) ?></p>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ── Fecho ───────────────────────────────────────────────── -->
  <?php if (trim((string)$tl['fecho']) !== ''): ?>
  <div class="fecho">
    <?= lt_p($tl['fecho']) ?>
    <p class="assina">Equipe Ferreira &amp; Sá Advocacia</p>
  </div>
  <?php endif; ?>

  <!-- ── Rodapé ──────────────────────────────────────────────── -->
  <footer class="rodape">
    <p class="marca-fs">Ferreira &amp; Sá<span>Advocacia Especializada</span></p>
    <p>Página gerada para você e atualizada pela sua equipe. Ela reflete o processo até
       <?= lth(lt_data_extenso(date('Y-m-d'))) ?>.</p>
    <p class="sigilo">Conteúdo sigiloso. Este link é exclusivo seu — não encaminhe nem publique em redes sociais.</p>
  </footer>

</div>

<script>
(function(){
  var reduz = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ── Tema: segue o aparelho até o visitante escolher ──────────
  var btn = document.getElementById('btnTema');
  try {
    var salvo = localStorage.getItem('fs_lt_tema');
    if (salvo) document.documentElement.setAttribute('data-tema', salvo);
  } catch (e) {}
  btn.addEventListener('click', function(){
    var atual = document.documentElement.getAttribute('data-tema');
    if (!atual) {
      atual = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    var novo = atual === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-tema', novo);
    try { localStorage.setItem('fs_lt_tema', novo); } catch (e) {}
  });

  if (reduz || !('IntersectionObserver' in window)) {
    document.querySelectorAll('.surge').forEach(function(el){ el.classList.add('visivel'); });
    return;
  }

  // ── Cada momento surge quando chega perto ────────────────────
  var io = new IntersectionObserver(function(itens){
    itens.forEach(function(it){
      if (!it.isIntersecting) return;
      it.target.classList.add('visivel');
      io.unobserve(it.target);
    });
  }, {rootMargin: '0px 0px -8% 0px', threshold: 0.05});
  document.querySelectorAll('.surge').forEach(function(el){ io.observe(el); });

  // ── O trilho se preenche conforme a leitura avança ───────────
  var linha  = document.getElementById('linha');
  var cheio  = document.getElementById('trilhoCheio');
  var pedido = false;

  function pintar(){
    pedido = false;
    var r = linha.getBoundingClientRect();
    var marca = window.innerHeight * 0.55;
    var p = (marca - r.top) / r.height;
    cheio.style.setProperty('--p', Math.max(0, Math.min(1, p)).toFixed(4));
  }
  function agendar(){
    if (pedido) return;
    pedido = true;
    requestAnimationFrame(pintar);
  }
  window.addEventListener('scroll', agendar, {passive:true});
  window.addEventListener('resize', agendar);
  pintar();
})();
</script>
</body>
</html>
