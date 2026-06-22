<?php
/**
 * Conversas Novas (WhatsApp) — quantas conversas foram ABERTAS pela 1ª vez
 * por período, separadas por canal (21 Comercial / 24 CX/Operacional).
 * Base: zapi_conversas.created_at (data de cadastro do registro).
 * Granularidade: diário / semanal / mensal, com intervalo configurável.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) { flash_set('error', 'Acesso restrito.'); redirect(url('modules/whatsapp/')); }

$pdo = db();
$pageTitle = 'Conversas Novas — WhatsApp';

$gran = $_GET['gran'] ?? 'dia';
if (!in_array($gran, array('dia', 'semana', 'mes'), true)) $gran = 'dia';
$incluirGrupos = !empty($_GET['grupos']);

// ── Intervalo ────────────────────────────────────────────
$hojeD = new DateTime('today');
$fim   = clone $hojeD;
if ($gran === 'dia')        $iniDefault = (clone $hojeD)->modify('-29 days');
elseif ($gran === 'semana') $iniDefault = (clone $hojeD)->modify('-11 weeks');
else                        $iniDefault = (clone $hojeD)->modify('-11 months');

$de  = trim($_GET['de'] ?? '');
$ate = trim($_GET['ate'] ?? '');
$ini = ($de && preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) ? new DateTime($de) : $iniDefault;
if ($ate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $fim = new DateTime($ate);
if ($ini > $fim) { $tmp = $ini; $ini = $fim; $fim = $tmp; }

// Normaliza o início conforme a granularidade (semana=segunda, mês=dia 1)
if ($gran === 'semana') $ini->modify('-' . ((int)$ini->format('N') - 1) . ' days');
if ($gran === 'mes')    $ini->modify('first day of this month');

$iniStr = $ini->format('Y-m-d 00:00:00');
$fimStr = (clone $fim)->modify('+1 day')->format('Y-m-d 00:00:00'); // exclusivo

// Expressão da chave do período no SQL
if ($gran === 'dia')         $keyExpr = "DATE(created_at)";
elseif ($gran === 'semana')  $keyExpr = "DATE(DATE_SUB(created_at, INTERVAL WEEKDAY(created_at) DAY))"; // segunda da semana
else                         $keyExpr = "DATE_FORMAT(created_at, '%Y-%m')";

$where  = "created_at >= ? AND created_at < ?";
$params = array($iniStr, $fimStr);
if (!$incluirGrupos) $where .= " AND COALESCE(eh_grupo,0) = 0";

$rows = array();
try {
    $st = $pdo->prepare("SELECT $keyExpr k, canal, COUNT(*) c FROM zapi_conversas WHERE $where GROUP BY k, canal ORDER BY k");
    $st->execute($params);
    foreach ($st->fetchAll() as $r) { $rows[$r['k']][$r['canal']] = (int)$r['c']; }
} catch (Exception $e) {}

// ── Buckets ordenados (preenche zeros nos períodos sem conversa) ──
$mesesAbr = array(1=>'jan',2=>'fev',3=>'mar',4=>'abr',5=>'mai',6=>'jun',7=>'jul',8=>'ago',9=>'set',10=>'out',11=>'nov',12=>'dez');
$buckets = array();
$cur = clone $ini;
if ($gran === 'dia') {
    while ($cur <= $fim) { $buckets[$cur->format('Y-m-d')] = $cur->format('d/m'); $cur->modify('+1 day'); }
} elseif ($gran === 'semana') {
    while ($cur <= $fim) { $buckets[$cur->format('Y-m-d')] = $cur->format('d/m'); $cur->modify('+7 days'); }
} else {
    while ($cur <= $fim) { $buckets[$cur->format('Y-m')] = $mesesAbr[(int)$cur->format('n')] . '/' . $cur->format('y'); $cur->modify('first day of next month'); }
}

$labels = array(); $d21 = array(); $d24 = array();
$tot21 = 0; $tot24 = 0; $best = array('lbl' => '—', 'v' => 0);
foreach ($buckets as $k => $lbl) {
    $v21 = isset($rows[$k]['21']) ? $rows[$k]['21'] : 0;
    $v24 = isset($rows[$k]['24']) ? $rows[$k]['24'] : 0;
    $labels[] = $lbl; $d21[] = $v21; $d24[] = $v24;
    $tot21 += $v21; $tot24 += $v24;
    if (($v21 + $v24) > $best['v']) $best = array('lbl' => $lbl, 'v' => $v21 + $v24);
}
$totGeral  = $tot21 + $tot24;
$nPeriodos = max(1, count($buckets));
$media     = round($totGeral / $nPeriodos, 1);
$granLabel = array('dia' => 'dia', 'semana' => 'semana', 'mes' => 'mês');

require_once APP_ROOT . '/templates/layout_start.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.cn-head{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem;}
.cn-tabs{display:flex;gap:.3rem;}
.cn-tab{padding:.45rem 1rem;border-radius:8px;border:1.5px solid var(--border);background:#fff;text-decoration:none;color:#052228;font-weight:700;font-size:.85rem;}
.cn-tab.on{background:#052228;color:#fff;border-color:#052228;}
.cn-filtros{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;display:flex;gap:.8rem;align-items:flex-end;flex-wrap:wrap;}
.cn-filtros label{display:flex;flex-direction:column;gap:2px;font-size:.7rem;color:var(--text-muted);font-weight:700;text-transform:uppercase;}
.cn-filtros input[type=date]{padding:5px 8px;border:1.5px solid var(--border);border-radius:6px;font-size:.82rem;}
.cn-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.8rem;margin-bottom:1rem;}
.cn-card{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:1rem;text-align:center;}
.cn-card .num{font-size:1.8rem;font-weight:900;color:var(--petrol-900);line-height:1;}
.cn-card .lbl{font-size:.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;margin-top:.3rem;font-weight:700;}
.cn-card.c21{border-top:4px solid #0d9488;}
.cn-card.c24{border-top:4px solid #B87333;}
.cn-chartbox{background:var(--bg-card);border:1px solid var(--border);border-radius:12px;padding:1rem 1.2rem;margin-bottom:1rem;}
.cn-table{width:100%;border-collapse:collapse;font-size:.82rem;background:var(--bg-card);border-radius:10px;overflow:hidden;}
.cn-table th{background:var(--petrol-900);color:#fff;padding:.5rem .7rem;text-align:right;font-size:.68rem;text-transform:uppercase;}
.cn-table th:first-child{text-align:left;}
.cn-table td{padding:.45rem .7rem;border-bottom:1px solid var(--border);text-align:right;}
.cn-table td:first-child{text-align:left;font-weight:600;}
.cn-table tr:hover td{background:rgba(184,115,51,.06);}
.cn-table .tot td{background:#052228;color:#fff;font-weight:800;border:none;}
</style>

<a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao WhatsApp</a>
<h1 style="margin:.2rem 0 1rem;">📈 Conversas Novas (WhatsApp)</h1>
<p class="text-sm text-muted" style="margin-top:-.6rem;margin-bottom:1rem;">Conversas abertas pela 1ª vez (data de cadastro), por canal. Granularidade: <strong><?= $granLabel[$gran] ?></strong>.</p>

<div class="cn-head">
    <div class="cn-tabs">
        <?php foreach (array('dia'=>'Diário','semana'=>'Semanal','mes'=>'Mensal') as $g=>$lbl):
            $qs = $_GET; $qs['gran']=$g; unset($qs['de']); unset($qs['ate']); // troca de granularidade reseta o range pro default
        ?>
            <a href="?<?= http_build_query($qs) ?>" class="cn-tab <?= $gran===$g?'on':'' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
</div>

<form method="GET" class="cn-filtros">
    <input type="hidden" name="gran" value="<?= e($gran) ?>">
    <label>De <input type="date" name="de" value="<?= e($ini->format('Y-m-d')) ?>"></label>
    <label>Até <input type="date" name="ate" value="<?= e($fim->format('Y-m-d')) ?>"></label>
    <label style="flex-direction:row;align-items:center;gap:6px;text-transform:none;font-size:.8rem;color:var(--text);">
        <input type="checkbox" name="grupos" value="1" <?= $incluirGrupos?'checked':'' ?>> Incluir grupos
    </label>
    <button type="submit" class="btn btn-primary btn-sm">🔍 Aplicar</button>
    <a href="?gran=<?= e($gran) ?>" class="btn btn-outline btn-sm">Limpar</a>
</form>

<div class="cn-cards">
    <div class="cn-card"><div class="num"><?= $totGeral ?></div><div class="lbl">Total no período</div></div>
    <div class="cn-card c21"><div class="num"><?= $tot21 ?></div><div class="lbl">💬 Comercial (21)</div></div>
    <div class="cn-card c24"><div class="num"><?= $tot24 ?></div><div class="lbl">💬 CX/Operac. (24)</div></div>
    <div class="cn-card"><div class="num"><?= $media ?></div><div class="lbl">Média / <?= $granLabel[$gran] ?></div></div>
    <div class="cn-card"><div class="num"><?= (int)$best['v'] ?></div><div class="lbl">Pico (<?= e($best['lbl']) ?>)</div></div>
</div>

<div class="cn-chartbox">
    <canvas id="cnChart" height="100"></canvas>
</div>

<div style="overflow-x:auto;">
<table class="cn-table">
    <thead><tr><th>Período</th><th>Comercial (21)</th><th>CX/Operac. (24)</th><th>Total</th></tr></thead>
    <tbody>
        <?php for ($i = count($labels)-1; $i >= 0; $i--): // mais recente primeiro ?>
            <tr><td><?= e($labels[$i]) ?></td><td><?= $d21[$i] ?></td><td><?= $d24[$i] ?></td><td><strong><?= $d21[$i]+$d24[$i] ?></strong></td></tr>
        <?php endfor; ?>
        <tr class="tot"><td>TOTAL</td><td><?= $tot21 ?></td><td><?= $tot24 ?></td><td><?= $totGeral ?></td></tr>
    </tbody>
</table>
</div>

<script>
new Chart(document.getElementById('cnChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($labels) ?>,
        datasets: [
            { label: 'Comercial (21)', data: <?= json_encode($d21) ?>, backgroundColor: '#0d9488', borderRadius: 4 },
            { label: 'CX/Operac. (24)', data: <?= json_encode($d24) ?>, backgroundColor: '#B87333', borderRadius: 4 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index', intersect: false,
                callbacks: { footer: function(items){ var t=0; items.forEach(function(i){t+=i.parsed.y;}); return 'Total: '+t; } } }
        },
        scales: { x: { stacked: false, grid:{display:false} }, y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
