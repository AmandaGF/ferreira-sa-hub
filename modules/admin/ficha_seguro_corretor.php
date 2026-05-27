<?php
/**
 * Admin — Ficha do Colaborador pro Corretor de Seguro de Vida.
 *
 * Renderiza HTML formatado (identidade visual do escritório) com os dados
 * solicitados pela corretora: nome, endereço, CPF, data de nascimento,
 * e-mail, celular, cargo + valor da cobertura.
 *
 * Botão "Imprimir / Salvar PDF" usa window.print().
 *
 * URL: /modules/admin/ficha_seguro_corretor.php?colab_id=X&cobertura=100000
 *
 * Acesso: SOMENTE admin.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/onboarding_docs_templates.php';
require_login();
require_role('admin');

$pdo = db();
$colabId = (int)($_GET['colab_id'] ?? 0);
$cobertura = (int)($_GET['cobertura'] ?? 100000);

$coberturasValidas = array(30000, 50000, 100000, 250000, 500000);
if (!in_array($cobertura, $coberturasValidas, true)) { $cobertura = 100000; }

if (!$colabId) {
    flash_set('error', 'Colaborador não informado.');
    redirect(module_url('admin', 'onboarding.php'));
}

$st = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE id = ?");
$st->execute(array($colabId));
$c = $st->fetch();
if (!$c) {
    flash_set('error', 'Colaborador não encontrado.');
    redirect(module_url('admin', 'onboarding.php'));
}

// Helpers
function _ficha_cpf($cpf) {
    $d = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($d) !== 11) return $cpf ?: '—';
    return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
}
function _ficha_fone($p) {
    $d = preg_replace('/\D/', '', (string)$p);
    if (strlen($d) === 11) return '(' . substr($d,0,2) . ') ' . substr($d,2,5) . '-' . substr($d,7,4);
    if (strlen($d) === 10) return '(' . substr($d,0,2) . ') ' . substr($d,2,4) . '-' . substr($d,6,4);
    return $p ?: '—';
}
function _ficha_dt($d) { if (!$d) return '—'; $ts = strtotime($d); return $ts ? date('d/m/Y', $ts) : $d; }
function _ficha_cobertura_label($v) {
    return 'R$ ' . number_format($v, 0, ',', '.') . ',00';
}
function _ficha_cobertura_extenso($v) {
    $mapa = array(
        30000  => 'trinta mil reais',
        50000  => 'cinquenta mil reais',
        100000 => 'cem mil reais',
        250000 => 'duzentos e cinquenta mil reais',
        500000 => 'quinhentos mil reais',
    );
    return isset($mapa[$v]) ? $mapa[$v] : '';
}
function _ficha_data_pt($iso) {
    $meses = array(1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro');
    $ts = strtotime($iso);
    if (!$ts) return $iso;
    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

// Cargo amigável
$cargoLivre   = trim((string)($c['cargo'] ?? ''));
$perfilCargo  = (string)($c['perfil_cargo'] ?? '');
$perfilLabel  = array(
    'estagiario'         => 'Estagiária(o) de Direito',
    'advogado_associado' => 'Advogada(o) Associada(o)',
    'clt'                => 'Colaborador(a) CLT',
    'sociedade'          => 'Sócia(o)',
    'prestador_pj'       => 'Prestadora de Serviços — PJ',
    'prestador_mei'      => 'Prestadora de Serviços — MEI',
    'prestador_autonomo' => 'Prestadora de Serviços — Autônoma',
    'outro'              => 'Colaboradora(or)',
);
$perfilFmt = isset($perfilLabel[$perfilCargo]) ? $perfilLabel[$perfilCargo] : '—';

// Endereço completo
$endereco = '—';
if (!empty($c['endereco_logradouro']) || !empty($c['cep']) || !empty($c['endereco_completo'])) {
    if (!empty($c['endereco_logradouro'])) {
        $rua = $c['endereco_logradouro'];
        if (!empty($c['endereco_numero'])) $rua .= ', n° ' . $c['endereco_numero'];
        $partes = array($rua);
        if (!empty($c['endereco_complemento'])) $partes[] = $c['endereco_complemento'];
        if (!empty($c['endereco_bairro'])) $partes[] = $c['endereco_bairro'];
        if (!empty($c['endereco_cidade']) && !empty($c['endereco_uf'])) {
            $partes[] = $c['endereco_cidade'] . '/' . $c['endereco_uf'];
        } elseif (!empty($c['endereco_cidade'])) {
            $partes[] = $c['endereco_cidade'];
        }
        if (!empty($c['cep'])) $partes[] = 'CEP ' . $c['cep'];
        $endereco = implode(', ', $partes);
    } else {
        $endereco = $c['endereco_completo'] ?: '—';
    }
}

try { audit_log('ficha_seguro_corretor_view', 'colaboradores_onboarding', $colabId, 'cobertura=R$' . number_format($cobertura,0,',','.')); } catch (Throwable $e) {}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🛡️ Ficha Seguro — <?= htmlspecialchars($c['nome_completo']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root { --petrol-900:#052228; --petrol-700:#173d46; --cobre:#6a3c2c; --nude:#d7ab90; }
body { font-family:'Open Sans',Arial,sans-serif; background:#f8f4ef; margin:0; }
.toolbar { background:linear-gradient(135deg,var(--petrol-900),var(--petrol-700)); color:#fff; padding:1rem 1.5rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; justify-content:space-between; position:sticky; top:0; z-index:100; box-shadow:0 4px 14px rgba(0,0,0,.15); }
.toolbar h1 { color:#fff; font-size:1.05rem; margin:0; }
.toolbar a, .toolbar button, .toolbar select { background:rgba(255,255,255,.15); color:#fff; padding:.5rem 1rem; border-radius:8px; text-decoration:none; font-size:.85rem; font-weight:600; border:0; cursor:pointer; font-family:inherit; }
.toolbar select { background:#fff; color:var(--petrol-900); padding:.45rem .8rem; }
.toolbar a:hover, .toolbar button:hover { background:rgba(255,255,255,.25); }
.toolbar .btn-print { background:var(--nude); color:var(--petrol-900); }
.toolbar .btn-print:hover { background:#e8c2a5; }

.doc-wrap { background:#f3f4f6; padding:2rem 1rem; }

.ficha-page { max-width:780px; margin:0 auto; background:#fff; padding:38px 46px; font-size:11pt; color:#1a1a1a; line-height:1.55; }
.ficha-logo { text-align:center; margin-bottom:1rem; }
.ficha-logo img { max-height:62px; }
.ficha-titulo-banner { background:#fff7ed; border-top:3px solid var(--nude); border-bottom:3px solid var(--nude); padding:18px 22px; text-align:center; margin:1rem 0 1.6rem; }
.ficha-titulo-banner h1 { font-size:13pt; letter-spacing:.18em; color:var(--petrol-900); font-weight:700; margin:0; line-height:1.4; text-transform:uppercase; }
.ficha-titulo-banner .sub { font-size:9pt; color:var(--cobre); letter-spacing:.1em; margin-top:6px; text-transform:uppercase; }
.ficha-intro { background:#fafafa; border-left:4px solid var(--petrol-900); padding:.85rem 1rem; margin-bottom:1.4rem; font-size:10.5pt; }
.ficha-section { background:#f3f4f6; border-left:5px solid var(--petrol-900); padding:.55rem .9rem; margin:1.4rem 0 .8rem; font-weight:700; font-size:11pt; color:var(--petrol-900); letter-spacing:.05em; text-transform:uppercase; }
.ficha-grid { display:grid; grid-template-columns:1fr 1fr; gap:.85rem 1.6rem; margin:.6rem 0 1rem; }
.ficha-grid.full > div { grid-column:1/-1; }
.ficha-grid .full-row { grid-column:1/-1; }
.ficha-row .label { font-size:9pt; color:#6b7280; text-transform:uppercase; letter-spacing:.5px; font-weight:600; }
.ficha-row .valor { font-size:11pt; color:var(--petrol-900); font-weight:600; padding-top:2px; }
.ficha-cobertura { background:linear-gradient(135deg,#fff7ed,#fde6d0); border:2px solid var(--nude); padding:1.2rem 1.3rem; border-radius:6px; margin:1rem 0 1.4rem; text-align:center; }
.ficha-cobertura .l { font-size:9.5pt; color:var(--cobre); text-transform:uppercase; letter-spacing:.5px; font-weight:600; }
.ficha-cobertura .v { font-size:22pt; color:var(--petrol-900); font-weight:800; margin-top:6px; font-family:'Playfair Display',serif; }
.ficha-cobertura .x { font-size:10pt; color:#6b7280; margin-top:4px; font-style:italic; }
.ficha-rodape { margin-top:2.5rem; padding-top:1rem; border-top:1px solid #e5e7eb; font-size:9pt; color:#6b7280; text-align:center; }
.ficha-rodape strong { color:var(--petrol-900); }
.ficha-cidade { margin:1.6rem 0 .4rem; font-size:11pt; text-align:right; }
.ficha-assinatura { margin-top:2rem; text-align:center; }
.ficha-assinatura .linha { border-top:1px solid #1a1a1a; width:60%; margin:0 auto .4rem; }
.ficha-assinatura .nome { font-weight:700; font-size:10.5pt; color:var(--petrol-900); }
.ficha-assinatura .sub { font-size:9pt; color:#444; margin-top:2px; }

@media print {
    body { background:#fff; margin:0; padding:0; }
    .toolbar, .no-print { display:none !important; }
    .doc-wrap { background:#fff; padding:0; }
    .ficha-page { box-shadow:none; max-width:100%; padding:24mm 18mm; }
    @page { size:A4; margin:0; }
}
</style>
</head>
<body>

<div class="toolbar no-print">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <a href="<?= module_url('admin', 'onboarding.php?id=' . $colabId) ?>">← Voltar</a>
        <h1>🛡️ Ficha para o Corretor de Seguro</h1>
    </div>
    <form method="GET" style="display:flex;gap:.5rem;align-items:center;margin:0;">
        <input type="hidden" name="colab_id" value="<?= $colabId ?>">
        <label style="font-size:.85rem;font-weight:600;">Cobertura:</label>
        <select name="cobertura" onchange="this.form.submit()">
            <?php foreach ($coberturasValidas as $v): ?>
                <option value="<?= $v ?>" <?= $v === $cobertura ? 'selected' : '' ?>><?= _ficha_cobertura_label($v) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn-print" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    </form>
</div>

<div class="doc-wrap">
<div class="ficha-page">
    <div class="ficha-logo">
        <?php
        $logoUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                 . '://' . $_SERVER['HTTP_HOST'] . '/conecta/assets/img/logo.png';
        ?>
        <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Ferreira & Sá Advocacia">
    </div>

    <div class="ficha-titulo-banner">
        <h1>Ficha do Colaborador</h1>
        <div class="sub">Contratação de Seguro de Vida e Acidentes Pessoais</div>
    </div>

    <div class="ficha-intro">
        Prezada corretora, encaminhamos abaixo os dados do(a) colaborador(a) abaixo qualificado(a) para fins de elaboração de proposta de seguro de vida e acidentes pessoais, com o valor de cobertura indicado no quadro destacado.
    </div>

    <div class="ficha-section">1. Identificação do Colaborador</div>
    <div class="ficha-grid">
        <div class="ficha-row full-row">
            <div class="label">Nome completo</div>
            <div class="valor"><?= htmlspecialchars($c['nome_completo'] ?: '—') ?></div>
        </div>
        <div class="ficha-row">
            <div class="label">CPF</div>
            <div class="valor"><?= htmlspecialchars(_ficha_cpf($c['cpf'])) ?></div>
        </div>
        <div class="ficha-row">
            <div class="label">Data de nascimento</div>
            <div class="valor"><?= htmlspecialchars(_ficha_dt($c['data_nascimento'])) ?></div>
        </div>
        <div class="ficha-row">
            <div class="label">E-mail</div>
            <div class="valor"><?= htmlspecialchars($c['email_institucional'] ?: ($c['email_pessoal'] ?? '—')) ?></div>
        </div>
        <div class="ficha-row">
            <div class="label">Celular</div>
            <div class="valor"><?= htmlspecialchars(_ficha_fone($c['telefone_whatsapp'])) ?></div>
        </div>
        <div class="ficha-row full-row">
            <div class="label">Endereço completo</div>
            <div class="valor"><?= htmlspecialchars($endereco) ?></div>
        </div>
    </div>

    <div class="ficha-section">2. Vínculo com o Escritório</div>
    <div class="ficha-grid">
        <div class="ficha-row">
            <div class="label">Perfil contratual</div>
            <div class="valor"><?= htmlspecialchars($perfilFmt) ?></div>
        </div>
        <div class="ficha-row">
            <div class="label">Cargo / função</div>
            <div class="valor"><?= htmlspecialchars($cargoLivre ?: '—') ?></div>
        </div>
        <?php if (!empty($c['setor'])): ?>
        <div class="ficha-row full-row">
            <div class="label">Setor / área de atuação</div>
            <div class="valor"><?= htmlspecialchars($c['setor']) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="ficha-section">3. Cobertura Solicitada</div>
    <div class="ficha-cobertura">
        <div class="l">Capital segurado — Morte e Acidentes Pessoais</div>
        <div class="v"><?= _ficha_cobertura_label($cobertura) ?></div>
        <?php $ext = _ficha_cobertura_extenso($cobertura); ?>
        <?php if ($ext): ?><div class="x">(<?= htmlspecialchars($ext) ?>)</div><?php endif; ?>
    </div>

    <div class="ficha-cidade">Barra Mansa/RJ, <?= htmlspecialchars(_ficha_data_pt(date('Y-m-d'))) ?>.</div>

    <div class="ficha-assinatura">
        <div class="linha"></div>
        <div class="nome">Dra. Amanda Guedes Ferreira</div>
        <div class="sub">OAB/RJ 163.260 — Sócia Administradora</div>
        <div class="sub">Ferreira &amp; Sá Advocacia Especializada</div>
    </div>

    <div class="ficha-rodape">
        <strong>Ferreira &amp; Sá Advocacia Especializada</strong> · CNPJ 51.294.223/0001-40 · OAB-RJ 005.987/2023<br>
        Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom — Barra Mansa/RJ
    </div>
</div>
</div>

</body>
</html>
