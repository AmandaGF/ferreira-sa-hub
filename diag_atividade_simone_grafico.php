<?php
/**
 * Relatório VISUAL (HTML+Chart.js) da atividade da Simone.
 * Mesma lógica de extração do diag_atividade_simone.php, mas renderiza
 * gráficos pra Amanda compartilhar/imprimir.
 *
 * Uso: abrir no browser
 *   https://ferreiraesa.com.br/conecta/diag_atividade_simone_grafico.php?key=fsa-hub-deploy-2026
 *   adiciona &dias=N pra mudar período (default 30)
 */
require_once __DIR__ . '/core/middleware.php';
require_login();
require_role('admin'); // Só admin (Amanda / Luiz) — relatório sensível de monitoramento

$pdo = db();

$st = $pdo->query("SELECT id, name, email, role, is_active, last_login_at, created_at
                   FROM users WHERE id = 5 OR LOWER(name) LIKE '%simone%'
                   ORDER BY id LIMIT 1");
$simone = $st->fetch();
if (!$simone) { http_response_code(404); exit('Usuária não encontrada.'); }

$uid = (int)$simone['id'];
$diasPeriodo = max(1, min(365, (int)($_GET['dias'] ?? 30)));
$dataInicio = date('Y-m-d', strtotime("-{$diasPeriodo} days"));

// Total
$st = $pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE user_id = ? AND created_at >= ?");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$totalAcoes = (int)$st->fetchColumn();

// Sessões
$st = $pdo->prepare("SELECT created_at FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     ORDER BY created_at ASC");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$timestamps = $st->fetchAll(PDO::FETCH_COLUMN);

$sessoes = array();
$sesInicio = null; $sesFim = null;
$gap = 30 * 60;
foreach ($timestamps as $ts) {
    $t = strtotime($ts);
    if ($sesInicio === null) { $sesInicio = $t; $sesFim = $t; }
    elseif (($t - $sesFim) > $gap) {
        $sessoes[] = array('inicio' => $sesInicio, 'fim' => $sesFim, 'duracao' => $sesFim - $sesInicio);
        $sesInicio = $t; $sesFim = $t;
    } else $sesFim = $t;
}
if ($sesInicio !== null) $sessoes[] = array('inicio' => $sesInicio, 'fim' => $sesFim, 'duracao' => $sesFim - $sesInicio);

$totalSegundos = 0; $sesMaisLonga = 0;
foreach ($sessoes as $s) { $totalSegundos += $s['duracao']; if ($s['duracao'] > $sesMaisLonga) $sesMaisLonga = $s['duracao']; }
$sesMedia = count($sessoes) ? $totalSegundos / count($sessoes) : 0;

function fmt_dur($seg) {
    if ($seg < 60) return $seg . 's';
    if ($seg < 3600) return floor($seg / 60) . 'min';
    $h = floor($seg / 3600); $m = floor(($seg % 3600) / 60);
    return $h . 'h' . ($m ? ' ' . $m . 'min' : '');
}

// Atividade por dia (preenche dias sem atividade com 0)
$st = $pdo->prepare("SELECT DATE(created_at) AS dia, COUNT(*) AS qt
                     FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     GROUP BY DATE(created_at)");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$diasMap = array();
foreach ($st->fetchAll() as $r) $diasMap[$r['dia']] = (int)$r['qt'];

$labelsDias = array(); $dadosDias = array(); $tempoDias = array();
for ($i = $diasPeriodo - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $labelsDias[] = date('d/m', strtotime($d));
    $dadosDias[] = $diasMap[$d] ?? 0;
    // tempo daquele dia
    $segDia = 0;
    foreach ($sessoes as $s) if (date('Y-m-d', $s['inicio']) === $d) $segDia += $s['duracao'];
    $tempoDias[] = round($segDia / 60, 1); // em minutos
}

// Top ações
$st = $pdo->prepare("SELECT action, COUNT(*) AS qt FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     GROUP BY action ORDER BY qt DESC LIMIT 10");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$topAcoes = $st->fetchAll();

// Distribuição por hora
$st = $pdo->prepare("SELECT HOUR(created_at) AS h, COUNT(*) AS qt
                     FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     GROUP BY HOUR(created_at)");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$horaMap = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$labelsHora = array(); $dadosHora = array();
for ($h = 0; $h < 24; $h++) {
    $labelsHora[] = sprintf('%02d:00', $h);
    $dadosHora[] = (int)($horaMap[$h] ?? 0);
}

// Distribuição por dia da semana (último dia em cima)
$st = $pdo->prepare("SELECT DAYOFWEEK(created_at) AS dow, COUNT(*) AS qt
                     FROM audit_log
                     WHERE user_id = ? AND created_at >= ?
                     GROUP BY DAYOFWEEK(created_at)");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$dowMap = $st->fetchAll(PDO::FETCH_KEY_PAIR);
// MySQL DAYOFWEEK: 1=Sun, 2=Mon, ..., 7=Sat
$nomesDow = array(1=>'Domingo',2=>'Segunda',3=>'Terça',4=>'Quarta',5=>'Quinta',6=>'Sexta',7=>'Sábado');
$labelsDow = array(); $dadosDow = array();
foreach (array(2,3,4,5,6,7,1) as $d) { // segunda primeiro
    $labelsDow[] = $nomesDow[$d];
    $dadosDow[] = (int)($dowMap[$d] ?? 0);
}

// Cases tocados
$st = $pdo->prepare("SELECT entity_id, COUNT(*) AS qt FROM audit_log
                     WHERE user_id = ? AND created_at >= ? AND entity_type = 'case'
                     GROUP BY entity_id ORDER BY qt DESC LIMIT 10");
$st->execute(array($uid, $dataInicio . ' 00:00:00'));
$casesTocados = $st->fetchAll();

$diasAcesso = 0;
foreach ($dadosDias as $v) if ($v > 0) $diasAcesso++;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Relatório de Atividade — <?= htmlspecialchars($simone['name']) ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:-apple-system,'Segoe UI',Roboto,sans-serif; background:#f8f4ef; color:#1a1a1a; line-height:1.5; padding:1.5rem; }
.container { max-width:1100px; margin:0 auto; }
h1 { color:#052228; font-size:1.6rem; margin-bottom:.3rem; font-family:'Playfair Display',Georgia,serif; }
.sub { color:#6b7280; font-size:.88rem; margin-bottom:1.5rem; }
.stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1rem; margin-bottom:1.5rem; }
.stat-card { background:#fff; border-radius:12px; padding:1.1rem 1.2rem; box-shadow:0 2px 8px rgba(0,0,0,.05); border-top:4px solid #B87333; }
.stat-card.azul { border-top-color:#3b82f6; }
.stat-card.verde { border-top-color:#10b981; }
.stat-card.laranja { border-top-color:#f59e0b; }
.stat-card.lbl { font-size:.7rem; letter-spacing:1.5px; font-weight:700; color:#6a3c2c; text-transform:uppercase; }
.stat-card .lbl { font-size:.7rem; letter-spacing:1.5px; font-weight:700; color:#6a3c2c; text-transform:uppercase; }
.stat-card .val { font-size:2rem; font-weight:900; color:#052228; margin-top:.3rem; line-height:1; }
.stat-card .sub { font-size:.78rem; color:#6b7280; margin:.2rem 0 0; }
.chart-card { background:#fff; border-radius:12px; padding:1.3rem 1.4rem; box-shadow:0 2px 8px rgba(0,0,0,.05); margin-bottom:1.2rem; }
.chart-card h3 { font-size:1rem; color:#052228; margin-bottom:.4rem; font-family:'Playfair Display',Georgia,serif; }
.chart-card p { font-size:.8rem; color:#6b7280; margin-bottom:1rem; }
.chart-wrap { position:relative; height:280px; }
.chart-wrap-tall { position:relative; height:380px; }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.2rem; }
@media (max-width:760px){ .grid-2 { grid-template-columns:1fr; } }
table { width:100%; border-collapse:collapse; margin-top:.5rem; font-size:.85rem; }
th, td { padding:.45rem .6rem; text-align:left; border-bottom:1px solid #f0f0f0; }
th { background:#fafafa; font-weight:700; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; color:#052228; }
.btns-print { text-align:right; margin-bottom:1rem; }
.btns-print button { background:#052228; color:#fff; border:0; padding:.5rem 1.1rem; border-radius:8px; font-weight:700; cursor:pointer; font-size:.85rem; }
@media print {
    body { background:#fff; padding:0; }
    .btns-print { display:none; }
    .chart-card, .stat-card { box-shadow:none; border:1px solid #e5e7eb; break-inside:avoid; }
}
</style>
</head>
<body>

<div class="container">
    <div class="btns-print">
        <button onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    </div>

    <h1>📊 Relatório de Atividade — <?= htmlspecialchars($simone['name']) ?></h1>
    <div class="sub">
        ID #<?= $simone['id'] ?> · <?= htmlspecialchars($simone['role']) ?> ·
        Período: últimos <?= $diasPeriodo ?> dias (desde <?= date('d/m/Y', strtotime($dataInicio)) ?>) ·
        Último login: <?= $simone['last_login_at'] ? date('d/m/Y H:i', strtotime($simone['last_login_at'])) : 'Nunca' ?> ·
        Gerado em <?= date('d/m/Y H:i') ?>
    </div>

    <!-- CARDS DE STATS -->
    <div class="stats">
        <div class="stat-card azul">
            <div class="lbl">Total de ações</div>
            <div class="val"><?= $totalAcoes ?></div>
            <div class="sub">no período</div>
        </div>
        <div class="stat-card verde">
            <div class="lbl">Tempo ativo estimado</div>
            <div class="val"><?= htmlspecialchars(fmt_dur($totalSegundos)) ?></div>
            <div class="sub"><?= count($sessoes) ?> sessões</div>
        </div>
        <div class="stat-card">
            <div class="lbl">Dias com acesso</div>
            <div class="val"><?= $diasAcesso ?>/<?= $diasPeriodo ?></div>
            <div class="sub"><?= round($diasAcesso / $diasPeriodo * 100) ?>% dos dias</div>
        </div>
        <div class="stat-card laranja">
            <div class="lbl">Sessão mais longa</div>
            <div class="val"><?= htmlspecialchars(fmt_dur($sesMaisLonga)) ?></div>
            <div class="sub">média: <?= htmlspecialchars(fmt_dur($sesMedia)) ?></div>
        </div>
    </div>

    <!-- GRÁFICO 1: Atividade por dia -->
    <div class="chart-card">
        <h3>📅 Atividade por dia (último <?= $diasPeriodo ?> dias)</h3>
        <p>Quantidade de ações registradas em cada dia. Dias sem barra = sem atividade.</p>
        <div class="chart-wrap-tall"><canvas id="grafDias"></canvas></div>
    </div>

    <div class="grid-2">
        <!-- GRÁFICO 2: Top ações (donut) -->
        <div class="chart-card">
            <h3>🎯 Tipos de ação</h3>
            <p>O que ela faz quando entra no sistema.</p>
            <div class="chart-wrap"><canvas id="grafAcoes"></canvas></div>
        </div>

        <!-- GRÁFICO 3: Por dia da semana -->
        <div class="chart-card">
            <h3>📆 Por dia da semana</h3>
            <p>Total de ações em cada dia da semana (somando todos os dias do período).</p>
            <div class="chart-wrap"><canvas id="grafDow"></canvas></div>
        </div>
    </div>

    <!-- GRÁFICO 4: Por hora do dia -->
    <div class="chart-card">
        <h3>🕐 Por hora do dia</h3>
        <p>Distribuição de ações ao longo do dia (somando todos os dias do período).</p>
        <div class="chart-wrap"><canvas id="grafHora"></canvas></div>
    </div>

    <!-- TABELA: Top ações detalhada -->
    <div class="chart-card">
        <h3>📋 Detalhe das ações</h3>
        <table>
            <thead><tr><th>Ação</th><th style="text-align:right;width:100px;">Quantidade</th></tr></thead>
            <tbody>
                <?php foreach ($topAcoes as $a): ?>
                <tr><td><?= htmlspecialchars($a['action']) ?></td><td style="text-align:right;font-weight:700;"><?= $a['qt'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($casesTocados)): ?>
    <div class="chart-card">
        <h3>📁 Casos mais tocados</h3>
        <p>Top 10 casos que ela mais movimentou no período.</p>
        <table>
            <thead><tr><th>Caso</th><th style="text-align:right;width:100px;">Ações</th></tr></thead>
            <tbody>
                <?php foreach ($casesTocados as $c): ?>
                <tr><td>case #<?= (int)$c['entity_id'] ?></td><td style="text-align:right;font-weight:700;"><?= $c['qt'] ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div style="margin-top:2rem;padding:1rem 1.2rem;background:#fff7ed;border:1px solid #fbcfe8;border-radius:10px;font-size:.82rem;color:#6a3c2c;">
        ⚠️ <strong>Importante:</strong> "Tempo ativo" é uma <strong>estimativa</strong> baseada nos cliques que disparam audit_log
        (mover Kanban, editar caso, etc.). Tempo só lendo (sem clicar) não é medido. O tempo real é provavelmente
        maior, mas a ordem de magnitude é informativa.
    </div>
</div>

<script>
const dadosDias = <?= json_encode($dadosDias) ?>;
const labelsDias = <?= json_encode($labelsDias) ?>;
const tempoDias = <?= json_encode($tempoDias) ?>;
const topAcoesData = <?= json_encode(array('labels' => array_column($topAcoes, 'action'), 'qts' => array_map('intval', array_column($topAcoes, 'qt')))) ?>;
const labelsHora = <?= json_encode($labelsHora) ?>;
const dadosHora = <?= json_encode($dadosHora) ?>;
const labelsDow = <?= json_encode($labelsDow) ?>;
const dadosDow = <?= json_encode($dadosDow) ?>;

// Gráfico 1: Atividade por dia (barras + linha de tempo)
new Chart(document.getElementById('grafDias'), {
    type: 'bar',
    data: {
        labels: labelsDias,
        datasets: [
            { type:'bar', label:'Ações', data:dadosDias, backgroundColor:'#3b82f6', yAxisID:'y' },
            { type:'line', label:'Minutos ativos', data:tempoDias, borderColor:'#10b981', backgroundColor:'rgba(16,185,129,.15)', yAxisID:'y1', tension:0.3, pointRadius:3 }
        ]
    },
    options: {
        responsive:true, maintainAspectRatio:false,
        scales: {
            y:  { beginAtZero:true, position:'left',  title:{display:true,text:'Ações'} },
            y1: { beginAtZero:true, position:'right', title:{display:true,text:'Minutos'}, grid:{drawOnChartArea:false} }
        },
        plugins: { legend: { position:'bottom' } }
    }
});

// Gráfico 2: Top ações (donut)
const cores = ['#B87333','#3b82f6','#10b981','#f59e0b','#dc2626','#8b5cf6','#06b6d4','#ec4899','#84cc16','#6366f1'];
new Chart(document.getElementById('grafAcoes'), {
    type: 'doughnut',
    data: {
        labels: topAcoesData.labels,
        datasets: [{ data:topAcoesData.qts, backgroundColor:cores }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{position:'right',labels:{font:{size:11}}} } }
});

// Gráfico 3: Por dia da semana
new Chart(document.getElementById('grafDow'), {
    type: 'bar',
    data: {
        labels: labelsDow,
        datasets: [{ label:'Ações', data:dadosDow, backgroundColor:'#B87333' }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});

// Gráfico 4: Por hora
new Chart(document.getElementById('grafHora'), {
    type: 'bar',
    data: {
        labels: labelsHora,
        datasets: [{ label:'Ações', data:dadosHora, backgroundColor:'#052228' }]
    },
    options: { responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});
</script>

</body>
</html>
