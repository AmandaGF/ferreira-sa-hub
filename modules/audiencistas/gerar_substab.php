<?php
/**
 * Gera substabelecimento automático a partir dos dados de uma audiência.
 *
 * Fluxo:
 *  /gerar_substab.php?audiencia_id=X         → tela de escolha (Amanda/Luiz + com/sem reservas)
 *  …&modo=direto&substabelecente=…&reservas=…   → preview HTML pra imprimir/save as PDF
 *  …&modo=editar&substabelecente=…&reservas=…   → auto-POST pra /documentos/gerar.php (revisar antes)
 *
 * Reusa template_substabelecimento() do módulo /documentos.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('audiencistas');

require_once __DIR__ . '/../documentos/templates.php'; // template_substabelecimento + escritorioData

$pdo = db();
$audId = (int)($_GET['audiencia_id'] ?? 0);
if (!$audId) { http_response_code(400); die('Audiência não informada.'); }

$st = $pdo->prepare("SELECT au.*, ad.nome AS aud_nome, ad.oab AS aud_oab, ad.email AS aud_email,
                            cl.name AS client_name, cl.cpf AS client_cpf,
                            c.case_number AS case_cnj, c.title AS case_title, c.court AS case_court, c.comarca AS case_comarca, c.case_type AS case_action
                     FROM audiencias au
                     LEFT JOIN audiencistas ad ON ad.id = au.audiencista_id
                     LEFT JOIN clients cl ON cl.id = au.client_id
                     LEFT JOIN cases c ON c.id = au.case_id
                     WHERE au.id = ?");
$st->execute(array($audId));
$a = $st->fetch();
if (!$a) { http_response_code(404); die('Audiência não encontrada.'); }
if (empty($a['audiencista_id'])) { die('Designe uma audiencista antes de gerar o substabelecimento.'); }

$substabelecente = $_GET['substabelecente'] ?? '';
$reservas        = $_GET['reservas'] ?? 'com';
$modo            = $_GET['modo'] ?? '';

// Helper: extrai seccional (RJ/SP/MG...) + número do OAB digitado livre.
function parse_oab($txt) {
    $txt = (string)$txt;
    $sec = 'RJ'; $num = '';
    if (preg_match('/([A-Za-z]{2})/u', $txt, $m)) $sec = strtoupper($m[1]);
    if (preg_match('/([\d.\s\-]+)/u', $txt, $m)) {
        $num = preg_replace('/[^\d.]/', '', $m[1]);
    }
    return array($sec, $num);
}

// ─── 1) Tela de escolha (sem params decididos) ───────────────
if (!$substabelecente || !$modo) {
    $pageTitle = 'Substab automático';
    list($oabSec, $oabNum) = parse_oab($a['aud_oab']);
    require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.gs-card { background:#fff; border-radius:12px; padding:22px 24px; box-shadow:0 1px 3px rgba(0,0,0,.06); max-width:680px; margin:0 auto; }
.gs-card h2 { margin:0 0 4px; color:#0f3d3e; font-size:1.2rem; }
.gs-card .sub { color:#777; font-size:.85rem; margin-bottom:18px; }
.gs-box { background:#f8fafc; border-radius:10px; padding:13px 16px; margin:10px 0; font-size:.86rem; }
.gs-box b { color:#0f3d3e; }
.gs-radio { display:flex; gap:12px; flex-wrap:wrap; margin-top:6px; }
.gs-radio label { background:#fff; border:1.5px solid #e2e8f0; border-radius:8px; padding:8px 14px; cursor:pointer; font-size:.86rem; font-weight:600; display:flex; align-items:center; gap:6px; }
.gs-radio input { margin:0; }
.gs-radio label:has(input:checked) { border-color:#0f3d3e; background:#e8f3f1; color:#0f3d3e; }
.gs-section { margin-top:16px; }
.gs-section h4 { margin:0 0 6px; font-size:.78rem; color:#475569; text-transform:uppercase; letter-spacing:.3px; }
.gs-actions { display:flex; gap:10px; margin-top:24px; flex-wrap:wrap; }
.gs-btn { background:#0f3d3e; color:#fff; border:none; border-radius:10px; padding:12px 22px; font-weight:700; cursor:pointer; font-size:.92rem; text-decoration:none; display:inline-flex; align-items:center; gap:8px; }
.gs-btn.ghost { background:#fff; color:#0f3d3e; border:2px solid #0f3d3e; }
.gs-btn:hover { opacity:.92; }
</style>

<div style="margin-bottom:10px;"><a href="<?= module_url('audiencistas') ?>" style="color:#0f3d3e;text-decoration:none;font-weight:600;">← Voltar</a></div>

<div class="gs-card">
  <h2>📜 Gerar substabelecimento automático</h2>
  <div class="sub">Os dados da audiência e da audiencista vão ser usados no substab.</div>

  <div class="gs-box">
    <div>👩‍⚖️ <b><?= e($a['aud_nome']) ?></b><?= !empty($a['aud_oab']) ? ' — OAB ' . e($a['aud_oab']) : ' <span style="color:#b91c1c;">⚠️ sem OAB cadastrada</span>' ?></div>
    <div style="margin-top:5px;">📋 <?= e($a['tipo']) ?><?= $a['data_hora'] ? ' em ' . date('d/m/Y H:i', strtotime($a['data_hora'])) : '' ?><?= $a['comarca'] ? ' — ' . e($a['comarca']) : '' ?></div>
    <?php if ($a['client_name']): ?><div style="margin-top:3px;">👤 Cliente: <b><?= e($a['client_name']) ?></b><?= $a['client_cpf'] ? ' (CPF ' . e($a['client_cpf']) . ')' : '' ?></div><?php endif; ?>
    <?php $proc = $a['case_cnj'] ?: $a['processo_numero']; if ($proc): ?><div style="margin-top:3px;">📄 Processo: <?= e($proc) ?></div><?php endif; ?>
    <?php if ($a['case_court']): ?><div style="margin-top:3px;">🏛️ Vara: <?= e($a['case_court']) ?></div><?php endif; ?>
  </div>

  <form method="get" action="<?= module_url('audiencistas', 'gerar_substab.php') ?>">
    <input type="hidden" name="audiencia_id" value="<?= $audId ?>">

    <div class="gs-section">
      <h4>👤 Quem assina (substabelecente)?</h4>
      <div class="gs-radio">
        <label><input type="radio" name="substabelecente" value="amanda_para_outro" checked> Dra. Amanda</label>
        <label><input type="radio" name="substabelecente" value="luiz_para_outro"> Dr. Luiz Eduardo</label>
      </div>
    </div>

    <div class="gs-section">
      <h4>📜 Reserva de poderes?</h4>
      <div class="gs-radio">
        <label><input type="radio" name="reservas" value="com" checked> Com reservas (padrão)</label>
        <label><input type="radio" name="reservas" value="sem"> Sem reservas</label>
      </div>
    </div>

    <div class="gs-actions">
      <button type="submit" name="modo" value="editar" class="gs-btn ghost">✏️ Editar antes (abre Documentos)</button>
      <button type="submit" name="modo" value="direto" class="gs-btn">⚡ Gerar direto (preview)</button>
    </div>
  </form>
</div>
<?php
    require_once APP_ROOT . '/templates/layout_end.php';
    exit;
}

// ─── 2) Modos com dados decididos ────────────────────────────
list($oabSec, $oabNum) = parse_oab($a['aud_oab']);
$proc = $a['case_cnj'] ?: $a['processo_numero'];
$vara = $a['case_court'];
$cidadeData = ($a['comarca'] ? $a['comarca'] : ($a['case_comarca'] ?: 'Barra Mansa/RJ')) . ', ' . date('d \d\e F \d\e Y');

$dados = array(
    'substabelecente'        => $substabelecente,
    'sem_reserva'            => ($reservas === 'sem'),
    'subst_adv_nome'         => mb_strtoupper($a['aud_nome'], 'UTF-8'),
    'subst_adv_oab'          => $oabNum,
    'subst_adv_seccional'    => $oabSec,
    'subst_adv_email'        => $a['aud_email'],
    'subst_adv_endereco'     => '', // não temos endereço da audiencista cadastrado — fica em branco
    'subst_adv_nacionalidade'=> 'brasileira',
    'nome'                   => mb_strtoupper((string)$a['client_name'], 'UTF-8'),
    'cpf'                    => $a['client_cpf'],
    'numero_processo'        => $proc,
    'vara_juizo'             => $vara,
    'acao_texto'             => $a['case_action'] ?: $a['tipo'],
    'cidade_data'            => $cidadeData,
);

// ─── 2a) MODO 'editar' → auto-POST pro /documentos/gerar.php ──
if ($modo === 'editar') {
    $url = url('modules/documentos/gerar.php') . '?tipo=substabelecimento'
         . ($a['client_id'] ? '&client_id=' . (int)$a['client_id'] : '')
         . ($a['case_id'] ? '&case_id=' . (int)$a['case_id'] : '');
?>
<!doctype html>
<html><head><meta charset="utf-8"><title>Abrindo editor…</title></head>
<body style="font-family:Arial;text-align:center;padding:80px 20px;color:#555;">
<p>Abrindo o editor de Documentos pra você revisar o substab antes de gerar…</p>
<form id="postf" method="post" action="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>">
<?php foreach ($dados as $k => $v): ?>
  <input type="hidden" name="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
</form>
<script>document.getElementById('postf').submit();</script>
</body></html>
<?php
    exit;
}

// ─── 2b) MODO 'direto' → renderiza HTML pra imprimir ────────
$html = template_substabelecimento($dados);
$pageTitle = 'Substab — ' . $a['aud_nome'];
?>
<!doctype html>
<html lang="pt-br"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Open Sans',serif; color:#1a1a1a; background:#e5e7eb; }
.toolbar { background:#052228; color:#fff; padding:.6rem 1.5rem; display:flex; align-items:center; justify-content:space-between; gap:.5rem; position:sticky; top:0; z-index:100; flex-wrap:wrap; }
.toolbar a, .toolbar button { color:#fff; background:rgba(255,255,255,.15); border:none; padding:.45rem .85rem; border-radius:8px; cursor:pointer; font-family:inherit; font-size:.78rem; font-weight:600; text-decoration:none; display:inline-flex; align-items:center; gap:.3rem; }
.toolbar a:hover, .toolbar button:hover { background:rgba(255,255,255,.25); }
.toolbar .imp { background:#15803d; }
.page { max-width:210mm; margin:2rem auto; background:#fff; padding:50px 65px; box-shadow:0 4px 20px rgba(0,0,0,.15); line-height:1.7; font-size:12.5px; }
.doc-title { text-align:center; font-size:14px; font-weight:800; color:#052228; text-decoration:underline; margin-bottom:1.5rem; letter-spacing:1px; }
.doc-body { text-align:justify; }
.doc-body p { margin-bottom:.7rem; }
.doc-body strong { color:#052228; }
.doc-body .no-indent { text-indent:0; }
.local-data { text-align:center; margin-top:2rem; font-size:12px; }
.assinatura { text-align:center; margin-top:3rem; }
.assinatura .linha { border-top:1px solid #1a1a1a; width:320px; margin:0 auto .4rem; }
.assinatura .nome-ass { font-weight:700; font-size:12px; }
@page { size:A4; margin:1.5cm 2cm 1.5cm 2cm; }
@media print { body{background:#fff;} .toolbar{display:none !important;} .page{box-shadow:none;margin:0;padding:40px 55px;} }
</style>
</head>
<body>
<div class="toolbar">
  <a href="<?= module_url('audiencistas') ?>">← Voltar</a>
  <div style="display:flex;gap:8px;">
    <a href="<?= module_url('audiencistas', 'gerar_substab.php?audiencia_id=' . $audId) ?>">⚙️ Refazer com outras opções</a>
    <button onclick="window.print()" class="imp">🖨️ Imprimir / Salvar como PDF</button>
  </div>
</div>
<div class="page">
  <div class="doc-body">
    <?= $html ?>
  </div>
</div>
</body></html>
