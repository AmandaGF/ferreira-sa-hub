<?php
/**
 * Ferreira & Sá Hub — Dashboard WhatsApp CRM
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/whatsapp/'));
}

$pdo = db();
$pageTitle = 'Dashboard WhatsApp';
$canal = $_GET['canal'] ?? 'todos'; // 21 | 24 | todos
$hoje = date('Y-m-d');

// Filtro canal
$wCanal = '';
$wParams = array();
if ($canal === '21' || $canal === '24') { $wCanal = " AND co.canal = ? "; $wParams[] = $canal; }

// ── KPIs do topo ──
function q(PDO $pdo, $sql, $params = array()) {
    $s = $pdo->prepare($sql); $s->execute($params); return (int)$s->fetchColumn();
}

$kpiConvHoje = q($pdo, "SELECT COUNT(DISTINCT co.id) FROM zapi_conversas co
    JOIN zapi_mensagens m ON m.conversa_id = co.id
    WHERE DATE(m.created_at) = ? {$wCanal}", array_merge(array($hoje), $wParams));

$kpiAguard = q($pdo, "SELECT COUNT(*) FROM zapi_conversas co WHERE status = 'aguardando' {$wCanal}", $wParams);

$kpiBot = q($pdo, "SELECT COUNT(*) FROM zapi_conversas co WHERE bot_ativo = 1 {$wCanal}", $wParams);

$kpiResolvHoje = q($pdo, "SELECT COUNT(*) FROM zapi_conversas co WHERE status = 'resolvido' AND DATE(updated_at) = ? {$wCanal}",
    array_merge(array($hoje), $wParams));

$kpiMsgHoje = q($pdo, "SELECT COUNT(*) FROM zapi_mensagens m
    JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE DATE(m.created_at) = ? {$wCanal}", array_merge(array($hoje), $wParams));

$kpiRecebHoje = q($pdo, "SELECT COUNT(*) FROM zapi_mensagens m
    JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE DATE(m.created_at) = ? AND m.direcao = 'recebida' {$wCanal}", array_merge(array($hoje), $wParams));

$kpiEnvHoje = q($pdo, "SELECT COUNT(*) FROM zapi_mensagens m
    JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE DATE(m.created_at) = ? AND m.direcao = 'enviada' {$wCanal}", array_merge(array($hoje), $wParams));

// ── Mensagens por hora (hoje) ──
$sqlHora = "SELECT HOUR(m.created_at) AS h, SUM(CASE WHEN m.direcao='recebida' THEN 1 ELSE 0 END) AS recebidas,
            SUM(CASE WHEN m.direcao='enviada' THEN 1 ELSE 0 END) AS enviadas
            FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
            WHERE DATE(m.created_at) = ? {$wCanal}
            GROUP BY HOUR(m.created_at) ORDER BY h ASC";
$s = $pdo->prepare($sqlHora); $s->execute(array_merge(array($hoje), $wParams));
$porHora = array();
foreach ($s->fetchAll() as $r) $porHora[(int)$r['h']] = array('recebidas' => (int)$r['recebidas'], 'enviadas' => (int)$r['enviadas']);
$horaLabels = array(); $horaRec = array(); $horaEnv = array();
for ($h = 0; $h < 24; $h++) {
    $horaLabels[] = sprintf('%02d', $h) . 'h';
    $horaRec[] = $porHora[$h]['recebidas'] ?? 0;
    $horaEnv[] = $porHora[$h]['enviadas'] ?? 0;
}

// ── Conversas por atendente (últimos 7 dias) ──
$sqlAtend = "SELECT u.name, COUNT(DISTINCT co.id) as total,
             SUM(CASE WHEN co.status='resolvido' THEN 1 ELSE 0 END) as resolvidas
             FROM zapi_conversas co
             LEFT JOIN users u ON u.id = co.atendente_id
             WHERE co.atendente_id IS NOT NULL
               AND co.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               {$wCanal}
             GROUP BY co.atendente_id, u.name
             ORDER BY total DESC";
$s = $pdo->prepare($sqlAtend); $s->execute($wParams);
$porAtend = $s->fetchAll();

// ── Tempo médio de resposta (por atendente, últimos 7 dias) ──
// Calcula: tempo entre a última msg 'recebida' e a próxima 'enviada' do mesmo atendente na mesma conversa
$sqlTempo = "SELECT u.name,
             AVG(TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at)) AS avg_min,
             COUNT(*) as n_respostas
             FROM zapi_mensagens m1
             JOIN zapi_mensagens m2 ON m2.conversa_id = m1.conversa_id AND m2.id > m1.id AND m2.direcao = 'enviada' AND m2.enviado_por_bot = 0
             JOIN zapi_conversas co ON co.id = m1.conversa_id
             LEFT JOIN users u ON u.id = m2.enviado_por_id
             WHERE m1.direcao = 'recebida'
               AND m2.id = (SELECT MIN(m3.id) FROM zapi_mensagens m3 WHERE m3.conversa_id = m1.conversa_id AND m3.id > m1.id AND m3.direcao='enviada' AND m3.enviado_por_bot=0)
               AND m1.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND m2.enviado_por_id IS NOT NULL
               {$wCanal}
             GROUP BY m2.enviado_por_id, u.name
             HAVING n_respostas >= 3
             ORDER BY avg_min ASC";
$s = $pdo->prepare($sqlTempo); $s->execute($wParams);
$tempoResp = $s->fetchAll();

// ── Taxa bot → humano ──
$botAtendidas = q($pdo, "SELECT COUNT(DISTINCT conversa_id) FROM zapi_mensagens m
                         JOIN zapi_conversas co ON co.id = m.conversa_id
                         WHERE m.enviado_por_bot = 1 AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) {$wCanal}", $wParams);
$botTransferidas = q($pdo, "SELECT COUNT(DISTINCT co.id) FROM zapi_conversas co
                            WHERE co.atendente_id IS NOT NULL
                            AND EXISTS (SELECT 1 FROM zapi_mensagens m WHERE m.conversa_id = co.id AND m.enviado_por_bot = 1 AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
                            {$wCanal}", $wParams);
$pctTransfer = $botAtendidas > 0 ? round($botTransferidas / $botAtendidas * 100) : 0;

// ── Etiquetas mais usadas ──
$etiquetas = $pdo->query("SELECT e.nome, e.cor, COUNT(*) as qtd
                          FROM zapi_conversa_etiquetas ce
                          JOIN zapi_etiquetas e ON e.id = ce.etiqueta_id
                          GROUP BY e.id, e.nome, e.cor
                          ORDER BY qtd DESC LIMIT 10")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.wd-header { display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap; }
.wd-tabs { display:flex;gap:.4rem; }
.wd-tab { padding:6px 14px;border-radius:10px;background:#fff;border:1px solid var(--border);cursor:pointer;font-size:.82rem;text-decoration:none;color:var(--text); }
.wd-tab.active { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
.wd-grid { display:grid;grid-template-columns:repeat(auto-fit, minmax(160px,1fr));gap:.7rem;margin-bottom:1.2rem; }
.wd-card { background:#fff;border:1px solid var(--border);border-radius:12px;padding:1rem; }
.wd-num { font-size:2rem;font-weight:800;color:var(--petrol-900);line-height:1; }
.wd-label { font-size:.72rem;color:var(--text-muted);margin-top:4px;text-transform:uppercase;letter-spacing:.3px; }
.wd-sub { font-size:.7rem;color:var(--text-muted);margin-top:4px; }
.wd-row { display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem; }
.wd-panel { background:#fff;border:1px solid var(--border);border-radius:12px;padding:1rem; }
.wd-panel h3 { margin:0 0 .6rem;font-size:.95rem;color:var(--petrol-900); }
.wd-list { display:flex;flex-direction:column;gap:.3rem; }
.wd-list-item { display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;background:#f9fafb;border-radius:6px;font-size:.82rem; }
.wd-list-item strong { min-width:180px; }
.wd-bar-bg { flex:1;background:#e5e7eb;height:8px;border-radius:4px;overflow:hidden; }
.wd-bar-fill { height:100%;background:var(--rose);transition:width .3s; }
@media (max-width:900px) { .wd-row{grid-template-columns:1fr;} }
</style>

<div class="wd-header">
    <h1 style="margin:0;">📊 Dashboard WhatsApp</h1>
    <div class="wd-tabs" style="margin-left:1rem;">
        <a href="?canal=todos" class="wd-tab <?= $canal === 'todos' ? 'active' : '' ?>">Todos</a>
        <a href="?canal=21" class="wd-tab <?= $canal === '21' ? 'active' : '' ?>">DDD 21 (Comercial)</a>
        <a href="?canal=24" class="wd-tab <?= $canal === '24' ? 'active' : '' ?>">DDD 24 (CX)</a>
    </div>
    <div style="margin-left:auto;">
        <a href="<?= module_url('whatsapp', 'central.php') ?>" class="btn btn-outline btn-sm">← Voltar</a>
    </div>
</div>

<!-- KPIs -->
<div class="wd-grid">
    <div class="wd-card">
        <div class="wd-num" style="color:#3b82f6;"><?= $kpiConvHoje ?></div>
        <div class="wd-label">💬 Conversas hoje</div>
    </div>
    <div class="wd-card">
        <div class="wd-num" style="color:#f59e0b;"><?= $kpiAguard ?></div>
        <div class="wd-label">⏳ Aguardando</div>
        <div class="wd-sub">sem atendente atribuído</div>
    </div>
    <div class="wd-card">
        <div class="wd-num" style="color:#7c3aed;"><?= $kpiBot ?></div>
        <div class="wd-label">🤖 Bot ativo</div>
    </div>
    <div class="wd-card">
        <div class="wd-num" style="color:#22c55e;"><?= $kpiResolvHoje ?></div>
        <div class="wd-label">✅ Resolvidas hoje</div>
    </div>
    <div class="wd-card">
        <div class="wd-num"><?= $kpiMsgHoje ?></div>
        <div class="wd-label">📬 Mensagens hoje</div>
        <div class="wd-sub"><?= $kpiRecebHoje ?> recebidas · <?= $kpiEnvHoje ?> enviadas</div>
    </div>
    <div class="wd-card">
        <div class="wd-num" style="color:#ec4899;"><?= $pctTransfer ?>%</div>
        <div class="wd-label">🔄 Bot → Humano</div>
        <div class="wd-sub"><?= $botTransferidas ?> de <?= $botAtendidas ?> (30d)</div>
    </div>
</div>

<div class="wd-row">
    <!-- Mensagens por hora -->
    <div class="wd-panel">
        <h3>📈 Mensagens por hora (hoje)</h3>
        <div style="position:relative;height:260px;width:100%;">
            <canvas id="chartHora"></canvas>
        </div>
    </div>

    <!-- Conversas por atendente -->
    <div class="wd-panel">
        <h3>👤 Conversas por atendente (últimos 7 dias)</h3>
        <?php if (empty($porAtend)): ?>
            <p class="text-muted text-sm">Nenhum atendente com conversas nos últimos 7 dias.</p>
        <?php else:
            $maxAt = max(array_column($porAtend, 'total'));
        ?>
            <div class="wd-list">
                <?php foreach ($porAtend as $a):
                    $pct = $maxAt > 0 ? ($a['total'] / $maxAt) * 100 : 0;
                ?>
                    <div class="wd-list-item">
                        <strong><?= e($a['name'] ?: '(sem atendente)') ?></strong>
                        <div class="wd-bar-bg"><div class="wd-bar-fill" style="width:<?= $pct ?>%;"></div></div>
                        <span><?= $a['total'] ?> (<?= $a['resolvidas'] ?> resolvidas)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="wd-row">
    <!-- Tempo médio de resposta -->
    <div class="wd-panel">
        <h3>⏱ Tempo médio de resposta (últimos 7 dias)</h3>
        <p class="text-sm text-muted" style="margin:0 0 .5rem;">Tempo entre mensagem recebida e primeira resposta humana (mín. 3 respostas)</p>
        <?php if (empty($tempoResp)): ?>
            <p class="text-muted text-sm">Dados insuficientes ainda.</p>
        <?php else: ?>
            <div class="wd-list">
                <?php foreach ($tempoResp as $t):
                    $min = round($t['avg_min']);
                    $h = floor($min / 60); $m = $min % 60;
                    $tempoStr = $h > 0 ? "{$h}h{$m}min" : "{$m} min";
                    $cor = $min < 15 ? '#22c55e' : ($min < 60 ? '#f59e0b' : '#ef4444');
                ?>
                    <div class="wd-list-item">
                        <strong><?= e($t['name']) ?></strong>
                        <span style="font-weight:700;color:<?= $cor ?>;"><?= $tempoStr ?></span>
                        <span style="margin-left:auto;font-size:.72rem;color:var(--text-muted);"><?= $t['n_respostas'] ?> respostas</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Etiquetas mais usadas -->
    <div class="wd-panel">
        <h3>🏷 Etiquetas mais usadas</h3>
        <?php if (empty($etiquetas)): ?>
            <p class="text-muted text-sm">Nenhuma etiqueta aplicada ainda.</p>
        <?php else:
            $maxEt = max(array_column($etiquetas, 'qtd'));
        ?>
            <div style="display:flex;flex-wrap:wrap;gap:.4rem;">
                <?php foreach ($etiquetas as $e):
                    $pct = $maxEt > 0 ? ($e['qtd'] / $maxEt) * 100 : 0;
                ?>
                    <div style="background:<?= e($e['cor']) ?>;color:#fff;padding:4px 10px;border-radius:10px;font-size:.75rem;font-weight:600;">
                        <?= e($e['nome']) ?> <span style="opacity:.85;">(<?= $e['qtd'] ?>)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('chartHora').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($horaLabels) ?>,
        datasets: [
            { label: 'Recebidas', data: <?= json_encode($horaRec) ?>, backgroundColor: '#3b82f6', stack: 's' },
            { label: 'Enviadas',  data: <?= json_encode($horaEnv) ?>, backgroundColor: '#22c55e', stack: 's' }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } },
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
