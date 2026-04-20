<?php
/**
 * Ferreira & Sá Hub — Relatórios do WhatsApp CRM
 * Filtros por período, canal e atendente + métricas detalhadas.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/whatsapp/'));
}

$pdo = db();
$pageTitle = 'Relatórios WhatsApp';

// ── Filtros ─────────────────────────────────────────────────────
$canal      = $_GET['canal']       ?? 'todos'; // 21 | 24 | todos
$atendFiltro= (int)($_GET['atendente'] ?? 0);
$dtIni      = $_GET['dt_ini']      ?? date('Y-m-01');  // padrão: começo do mês
$dtFim      = $_GET['dt_fim']      ?? date('Y-m-d');   // padrão: hoje
$preset     = $_GET['preset']      ?? '';

// Presets
if ($preset === 'hoje')       { $dtIni = $dtFim = date('Y-m-d'); }
elseif ($preset === 'ontem')  { $dtIni = $dtFim = date('Y-m-d', strtotime('-1 day')); }
elseif ($preset === '7d')     { $dtIni = date('Y-m-d', strtotime('-6 days')); $dtFim = date('Y-m-d'); }
elseif ($preset === '30d')    { $dtIni = date('Y-m-d', strtotime('-29 days')); $dtFim = date('Y-m-d'); }
elseif ($preset === 'mes')    { $dtIni = date('Y-m-01'); $dtFim = date('Y-m-d'); }
elseif ($preset === 'mesant') { $dtIni = date('Y-m-01', strtotime('first day of last month')); $dtFim = date('Y-m-t', strtotime('last day of last month')); }

// Validação das datas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtIni)) $dtIni = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtFim)) $dtFim = date('Y-m-d');
$dtIniSql = $dtIni . ' 00:00:00';
$dtFimSql = $dtFim . ' 23:59:59';

// WHERE builders — reutilizados em várias queries
$wCanal = ''; $wCanalArgs = array();
if ($canal === '21' || $canal === '24') { $wCanal = " AND co.canal = ?"; $wCanalArgs[] = $canal; }

$wAtend = ''; $wAtendArgs = array();
if ($atendFiltro > 0) { $wAtend = " AND co.atendente_id = ?"; $wAtendArgs[] = $atendFiltro; }

$wTodoSuffix = $wCanal . $wAtend;
$wTodoArgs   = array_merge($wCanalArgs, $wAtendArgs);

// Periodo aplicado sobre mensagens
$wPeriodMsg = " AND m.created_at BETWEEN ? AND ?";
$wPeriodMsgArgs = array($dtIniSql, $dtFimSql);

// ── Utilitário ───────────────────────────────────────────────────
function qVal(PDO $pdo, $sql, $params = array()) {
    $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchColumn();
}
function qAll(PDO $pdo, $sql, $params = array()) {
    $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll();
}

// ── MÉTRICAS GERAIS ─────────────────────────────────────────────
$kTotalConvs = (int)qVal($pdo,
    "SELECT COUNT(DISTINCT co.id)
     FROM zapi_conversas co
     JOIN zapi_mensagens m ON m.conversa_id = co.id
     WHERE 1=1 {$wTodoSuffix} {$wPeriodMsg}",
    array_merge($wTodoArgs, $wPeriodMsgArgs)
);

$kMsgRec = (int)qVal($pdo,
    "SELECT COUNT(*) FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE m.direcao = 'recebida' {$wTodoSuffix} {$wPeriodMsg}",
    array_merge($wTodoArgs, $wPeriodMsgArgs)
);

$kMsgEnv = (int)qVal($pdo,
    "SELECT COUNT(*) FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE m.direcao = 'enviada' AND m.enviado_por_bot = 0 {$wTodoSuffix} {$wPeriodMsg}",
    array_merge($wTodoArgs, $wPeriodMsgArgs)
);

$kMsgBot = (int)qVal($pdo,
    "SELECT COUNT(*) FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE m.enviado_por_bot = 1 {$wTodoSuffix} {$wPeriodMsg}",
    array_merge($wTodoArgs, $wPeriodMsgArgs)
);

$kNovas = (int)qVal($pdo,
    "SELECT COUNT(*) FROM zapi_conversas co
     WHERE co.created_at BETWEEN ? AND ? {$wTodoSuffix}",
    array_merge(array($dtIniSql, $dtFimSql), $wTodoArgs)
);

$kResolvidas = (int)qVal($pdo,
    "SELECT COUNT(*) FROM zapi_conversas co
     WHERE co.status = 'resolvido' AND co.updated_at BETWEEN ? AND ? {$wTodoSuffix}",
    array_merge(array($dtIniSql, $dtFimSql), $wTodoArgs)
);

// ── TEMPO MÉDIO DE RESPOSTA (global) ──
$tempoGeral = qVal($pdo,
    "SELECT AVG(TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at))
     FROM zapi_mensagens m1
     JOIN zapi_mensagens m2
       ON m2.conversa_id = m1.conversa_id
      AND m2.id > m1.id
      AND m2.direcao = 'enviada'
      AND m2.enviado_por_bot = 0
      AND m2.id = (SELECT MIN(m3.id) FROM zapi_mensagens m3
                   WHERE m3.conversa_id = m1.conversa_id AND m3.id > m1.id
                     AND m3.direcao='enviada' AND m3.enviado_por_bot=0)
     JOIN zapi_conversas co ON co.id = m1.conversa_id
     WHERE m1.direcao = 'recebida'
       AND m1.created_at BETWEEN ? AND ?
       AND TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at) <= 1440  /* max 24h pra não distorcer */
       {$wTodoSuffix}",
    array_merge(array($dtIniSql, $dtFimSql), $wTodoArgs)
);
$tempoGeral = $tempoGeral !== null ? round((float)$tempoGeral, 1) : null;

// ── POR ATENDENTE ────────────────────────────────────────────────
$porAtendente = qAll($pdo,
    "SELECT u.id AS user_id, u.name,
            COUNT(DISTINCT co.id) AS conversas_atribuidas,
            SUM(CASE WHEN co.status='resolvido' AND co.updated_at BETWEEN ? AND ? THEN 1 ELSE 0 END) AS resolvidas_no_periodo,
            (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.enviado_por_id = u.id AND m.enviado_por_bot = 0 AND m.created_at BETWEEN ? AND ?) AS msgs_enviadas
     FROM users u
     LEFT JOIN zapi_conversas co ON co.atendente_id = u.id {$wCanal}
     WHERE u.is_active = 1
     GROUP BY u.id, u.name
     HAVING conversas_atribuidas > 0 OR msgs_enviadas > 0
     ORDER BY conversas_atribuidas DESC, msgs_enviadas DESC",
    array_merge(
        array($dtIniSql, $dtFimSql, $dtIniSql, $dtFimSql),
        $wCanalArgs
    )
);

// Tempo médio de resposta por atendente (no período)
$tempoPorAtend = qAll($pdo,
    "SELECT m2.enviado_por_id AS user_id, u.name,
            AVG(TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at)) AS avg_min,
            COUNT(*) AS n_respostas
     FROM zapi_mensagens m1
     JOIN zapi_mensagens m2
       ON m2.conversa_id = m1.conversa_id
      AND m2.id > m1.id
      AND m2.direcao = 'enviada'
      AND m2.enviado_por_bot = 0
      AND m2.id = (SELECT MIN(m3.id) FROM zapi_mensagens m3
                   WHERE m3.conversa_id = m1.conversa_id AND m3.id > m1.id
                     AND m3.direcao='enviada' AND m3.enviado_por_bot=0)
     JOIN zapi_conversas co ON co.id = m1.conversa_id
     LEFT JOIN users u ON u.id = m2.enviado_por_id
     WHERE m1.direcao = 'recebida'
       AND m1.created_at BETWEEN ? AND ?
       AND TIMESTAMPDIFF(MINUTE, m1.created_at, m2.created_at) <= 1440
       AND m2.enviado_por_id IS NOT NULL
       {$wTodoSuffix}
     GROUP BY m2.enviado_por_id, u.name
     HAVING n_respostas >= 3
     ORDER BY avg_min ASC",
    array_merge(array($dtIniSql, $dtFimSql), $wTodoArgs)
);
$tempoPorUserId = array();
foreach ($tempoPorAtend as $r) $tempoPorUserId[(int)$r['user_id']] = $r;

// ── VOLUME DIÁRIO ──
$volumeDiario = qAll($pdo,
    "SELECT DATE(m.created_at) AS dia,
            SUM(CASE WHEN m.direcao='recebida' THEN 1 ELSE 0 END) AS recebidas,
            SUM(CASE WHEN m.direcao='enviada' AND m.enviado_por_bot=0 THEN 1 ELSE 0 END) AS enviadas,
            COUNT(DISTINCT m.conversa_id) AS convs
     FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE 1=1 {$wTodoSuffix} {$wPeriodMsg}
     GROUP BY DATE(m.created_at)
     ORDER BY dia ASC",
    array_merge($wTodoArgs, $wPeriodMsgArgs)
);

// ── DISTRIBUIÇÃO POR HORA ──
$porHora = qAll($pdo,
    "SELECT HOUR(m.created_at) AS h,
            SUM(CASE WHEN m.direcao='recebida' THEN 1 ELSE 0 END) AS rec,
            SUM(CASE WHEN m.direcao='enviada' AND m.enviado_por_bot=0 THEN 1 ELSE 0 END) AS env
     FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     WHERE 1=1 {$wTodoSuffix} {$wPeriodMsg}
     GROUP BY HOUR(m.created_at)
     ORDER BY h ASC",
    array_merge($wTodoArgs, $wPeriodMsgArgs)
);
$porHoraMap = array();
foreach ($porHora as $h) $porHoraMap[(int)$h['h']] = $h;
$horaRec = array(); $horaEnv = array();
for ($h = 0; $h < 24; $h++) {
    $horaRec[] = (int)($porHoraMap[$h]['rec'] ?? 0);
    $horaEnv[] = (int)($porHoraMap[$h]['env'] ?? 0);
}

// ── TOP CONTATOS (por volume de mensagens no período) ──
$topContatos = qAll($pdo,
    "SELECT co.id, co.telefone, COALESCE(co.nome_contato, cl.name, pl.name) AS nome,
            cl.name AS client_name, co.canal,
            COUNT(m.id) AS total_msgs,
            SUM(CASE WHEN m.direcao='recebida' THEN 1 ELSE 0 END) AS rec,
            SUM(CASE WHEN m.direcao='enviada' AND m.enviado_por_bot=0 THEN 1 ELSE 0 END) AS env
     FROM zapi_mensagens m
     JOIN zapi_conversas co ON co.id = m.conversa_id
     LEFT JOIN clients cl ON cl.id = co.client_id
     LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
     WHERE 1=1 {$wTodoSuffix} {$wPeriodMsg}
     GROUP BY co.id
     ORDER BY total_msgs DESC
     LIMIT 15",
    array_merge($wTodoArgs, $wPeriodMsgArgs)
);

// ── ETIQUETAS NO PERÍODO ──
$etiquetasPeriodo = qAll($pdo,
    "SELECT e.nome, e.cor, COUNT(DISTINCT ce.conversa_id) AS qtd
     FROM zapi_conversa_etiquetas ce
     JOIN zapi_etiquetas e ON e.id = ce.etiqueta_id
     JOIN zapi_conversas co ON co.id = ce.conversa_id
     WHERE EXISTS (SELECT 1 FROM zapi_mensagens m WHERE m.conversa_id = co.id AND m.created_at BETWEEN ? AND ?)
       {$wTodoSuffix}
     GROUP BY e.id, e.nome, e.cor
     ORDER BY qtd DESC
     LIMIT 10",
    array_merge(array($dtIniSql, $dtFimSql), $wTodoArgs)
);

// Lista de atendentes pra filtro
$atendentes = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Helper pra formatar tempo
function fmtTempo($minutos) {
    if ($minutos === null || $minutos === '') return '—';
    $m = (int)round((float)$minutos);
    if ($m < 60) return $m . ' min';
    $h = intdiv($m, 60); $r = $m % 60;
    return $h . 'h' . ($r > 0 ? (' ' . $r . 'min') : '');
}

// Export CSV se solicitado
if (isset($_GET['csv']) && $_GET['csv'] === 'atendentes') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="atendentes_' . $dtIni . '_a_' . $dtFim . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM utf-8
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Atendente','Conversas atribuídas','Resolvidas no período','Mensagens enviadas','Tempo médio de resposta (min)','Nº respostas medidas'), ';');
    foreach ($porAtendente as $a) {
        $t = $tempoPorUserId[(int)$a['user_id']] ?? null;
        fputcsv($out, array(
            $a['name'],
            $a['conversas_atribuidas'],
            $a['resolvidas_no_periodo'],
            $a['msgs_enviadas'],
            $t ? round((float)$t['avg_min'], 1) : '',
            $t ? $t['n_respostas'] : '',
        ), ';');
    }
    fclose($out); exit;
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.rep-toolbar { background:#fff;border:1px solid var(--border);border-radius:12px;padding:.8rem 1rem;margin-bottom:1rem;display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap; }
.rep-toolbar .fld { display:flex;flex-direction:column;gap:3px; }
.rep-toolbar label { font-size:.65rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px; }
.rep-toolbar input, .rep-toolbar select { padding:6px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.8rem;background:#fff; }
.rep-presets { display:flex;gap:4px;flex-wrap:wrap; }
.rep-preset { padding:5px 10px;font-size:.7rem;background:#f3f4f6;border:1px solid var(--border);border-radius:999px;text-decoration:none;color:var(--text); }
.rep-preset.active { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
.rep-grid-kpi { display:grid;grid-template-columns:repeat(auto-fit, minmax(160px,1fr));gap:.7rem;margin-bottom:1.2rem; }
.rep-card { background:#fff;border:1px solid var(--border);border-radius:12px;padding:.9rem 1rem; }
.rep-num { font-size:1.6rem;font-weight:800;color:var(--petrol-900);line-height:1; }
.rep-lbl { font-size:.7rem;color:var(--text-muted);margin-top:4px;text-transform:uppercase;letter-spacing:.3px; }
.rep-row { display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem; }
.rep-panel { background:#fff;border:1px solid var(--border);border-radius:12px;padding:1rem; }
.rep-panel h3 { margin:0 0 .6rem;font-size:.95rem;color:var(--petrol-900); }
.rep-tbl { width:100%;border-collapse:collapse;font-size:.8rem; }
.rep-tbl th { text-align:left;padding:6px 8px;background:#f9fafb;border-bottom:1px solid var(--border);font-size:.68rem;text-transform:uppercase;letter-spacing:.3px;color:var(--text-muted); }
.rep-tbl td { padding:6px 8px;border-bottom:1px solid #f3f4f6; }
.rep-tbl td.num { text-align:right;font-variant-numeric:tabular-nums; }
@media (max-width:900px) { .rep-row{grid-template-columns:1fr;} }
</style>

<div style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
    <h1 style="margin:0;">📈 Relatórios WhatsApp</h1>
    <div style="margin-left:auto;display:flex;gap:.4rem;">
        <a href="<?= module_url('whatsapp', 'dashboard.php') ?>" class="btn btn-outline btn-sm">← Dashboard</a>
        <a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm">📱 Inbox</a>
    </div>
</div>

<!-- Toolbar de filtros -->
<form method="get" class="rep-toolbar">
    <div class="fld">
        <label>📅 De</label>
        <input type="date" name="dt_ini" value="<?= e($dtIni) ?>">
    </div>
    <div class="fld">
        <label>até</label>
        <input type="date" name="dt_fim" value="<?= e($dtFim) ?>">
    </div>
    <div class="fld">
        <label>📞 Canal</label>
        <select name="canal">
            <option value="todos" <?= $canal === 'todos' ? 'selected' : '' ?>>Todos</option>
            <option value="21" <?= $canal === '21' ? 'selected' : '' ?>>DDD 21 (Comercial)</option>
            <option value="24" <?= $canal === '24' ? 'selected' : '' ?>>DDD 24 (CX)</option>
        </select>
    </div>
    <div class="fld">
        <label>👤 Atendente</label>
        <select name="atendente">
            <option value="0">Todos</option>
            <?php foreach ($atendentes as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $atendFiltro === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fld">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-sm">Aplicar</button>
    </div>
    <div class="fld" style="margin-left:auto;">
        <label>Presets rápidos</label>
        <div class="rep-presets">
            <?php
            $presets = array(
                'hoje'=>'Hoje', 'ontem'=>'Ontem', '7d'=>'7 dias', '30d'=>'30 dias',
                'mes'=>'Este mês', 'mesant'=>'Mês anterior',
            );
            $ps = $_GET; unset($ps['preset'], $ps['dt_ini'], $ps['dt_fim']);
            foreach ($presets as $k => $lbl):
                $qs = http_build_query(array_merge($ps, array('preset' => $k)));
            ?>
                <a href="?<?= $qs ?>" class="rep-preset <?= $preset === $k ? 'active' : '' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>
    </div>
</form>

<div style="font-size:.78rem;color:var(--text-muted);margin-bottom:1rem;">
    Período analisado: <strong><?= date('d/m/Y', strtotime($dtIni)) ?> até <?= date('d/m/Y', strtotime($dtFim)) ?></strong>
    <?php if ($canal !== 'todos'): ?> · Canal: <strong>DDD <?= e($canal) ?></strong><?php endif; ?>
    <?php if ($atendFiltro): ?>
        <?php foreach ($atendentes as $a) if ((int)$a['id'] === $atendFiltro) echo ' · Atendente: <strong>' . e($a['name']) . '</strong>'; ?>
    <?php endif; ?>
</div>

<!-- KPIs principais -->
<div class="rep-grid-kpi">
    <div class="rep-card"><div class="rep-num" style="color:#3b82f6;"><?= $kTotalConvs ?></div><div class="rep-lbl">💬 Conversas com atividade</div></div>
    <div class="rep-card"><div class="rep-num" style="color:#22c55e;"><?= $kNovas ?></div><div class="rep-lbl">🆕 Conversas novas</div></div>
    <div class="rep-card"><div class="rep-num" style="color:#10b981;"><?= $kResolvidas ?></div><div class="rep-lbl">✅ Resolvidas</div></div>
    <div class="rep-card"><div class="rep-num" style="color:#f59e0b;"><?= $kMsgRec ?></div><div class="rep-lbl">📩 Msgs recebidas</div></div>
    <div class="rep-card"><div class="rep-num" style="color:#6366f1;"><?= $kMsgEnv ?></div><div class="rep-lbl">📤 Msgs enviadas</div></div>
    <div class="rep-card"><div class="rep-num" style="color:#7c3aed;"><?= $kMsgBot ?></div><div class="rep-lbl">🤖 Msgs do bot</div></div>
    <div class="rep-card"><div class="rep-num" style="color:#dc2626;font-size:1.3rem;"><?= fmtTempo($tempoGeral) ?></div><div class="rep-lbl">⏱ Tempo médio de resposta</div></div>
</div>

<!-- Por atendente -->
<div class="rep-panel" style="margin-bottom:1rem;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
        <h3 style="margin:0;">👥 Desempenho por atendente</h3>
        <a href="?<?= http_build_query(array_merge($_GET, array('csv' => 'atendentes'))) ?>" class="btn btn-outline btn-sm">📥 Exportar CSV</a>
    </div>
    <?php if (empty($porAtendente)): ?>
        <p style="color:var(--text-muted);">Sem atividade de atendentes no período.</p>
    <?php else: ?>
    <table class="rep-tbl">
        <thead><tr>
            <th>Atendente</th>
            <th class="num">Conversas atribuídas</th>
            <th class="num">Resolvidas no período</th>
            <th class="num">Mensagens enviadas</th>
            <th class="num">Tempo médio resp.</th>
            <th class="num">Nº respostas</th>
        </tr></thead>
        <tbody>
        <?php foreach ($porAtendente as $a):
            $t = $tempoPorUserId[(int)$a['user_id']] ?? null; ?>
            <tr>
                <td><strong><?= e($a['name']) ?></strong></td>
                <td class="num"><?= (int)$a['conversas_atribuidas'] ?></td>
                <td class="num"><?= (int)$a['resolvidas_no_periodo'] ?></td>
                <td class="num"><?= (int)$a['msgs_enviadas'] ?></td>
                <td class="num" style="color:<?= $t && (float)$t['avg_min'] < 60 ? '#059669' : '#d97706' ?>;"><?= $t ? fmtTempo($t['avg_min']) : '—' ?></td>
                <td class="num" style="color:#6b7280;"><?= $t ? (int)$t['n_respostas'] : 0 ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:.7rem;color:var(--text-muted);margin-top:.5rem;">Tempo médio considera apenas respostas em até 24h (pra não distorcer com mensagens antigas). Mínimo 3 respostas pra aparecer.</p>
    <?php endif; ?>
</div>

<div class="rep-row">
    <!-- Volume diário -->
    <div class="rep-panel">
        <h3>📊 Volume por dia</h3>
        <canvas id="chartDiario" height="180"></canvas>
    </div>
    <!-- Por hora -->
    <div class="rep-panel">
        <h3>🕒 Distribuição por hora</h3>
        <canvas id="chartHora" height="180"></canvas>
    </div>
</div>

<div class="rep-row">
    <!-- Top contatos -->
    <div class="rep-panel">
        <h3>🔥 Contatos mais ativos</h3>
        <?php if (empty($topContatos)): ?>
            <p style="color:var(--text-muted);">Sem dados no período.</p>
        <?php else: ?>
        <table class="rep-tbl">
            <thead><tr><th>Contato</th><th class="num">Total</th><th class="num">Rec.</th><th class="num">Env.</th></tr></thead>
            <tbody>
            <?php foreach ($topContatos as $c): ?>
                <tr>
                    <td>
                        <a href="<?= module_url('whatsapp', '?canal=' . e($c['canal']) . '&conversa=' . (int)$c['id']) ?>" style="color:var(--petrol-900);text-decoration:none;">
                            <strong><?= e($c['nome'] ?? $c['telefone']) ?></strong>
                        </a>
                        <?php if ($c['client_name']): ?><span style="font-size:.7rem;color:#059669;"> · 🎯 Cliente</span><?php endif; ?>
                        <div style="font-size:.7rem;color:var(--text-muted);"><?= e($c['telefone']) ?> · DDD <?= e($c['canal']) ?></div>
                    </td>
                    <td class="num"><strong><?= (int)$c['total_msgs'] ?></strong></td>
                    <td class="num"><?= (int)$c['rec'] ?></td>
                    <td class="num"><?= (int)$c['env'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <!-- Etiquetas -->
    <div class="rep-panel">
        <h3>🏷 Etiquetas no período</h3>
        <?php if (empty($etiquetasPeriodo)): ?>
            <p style="color:var(--text-muted);">Nenhuma etiqueta aplicada em conversas do período.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:6px;">
                <?php $max = max(array_column($etiquetasPeriodo, 'qtd')); foreach ($etiquetasPeriodo as $e):
                    $pct = round($e['qtd'] / max($max, 1) * 100); ?>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span style="min-width:140px;font-size:.8rem;">
                            <span style="display:inline-block;width:10px;height:10px;background:<?= e($e['cor']) ?>;border-radius:50%;margin-right:4px;"></span>
                            <?= e($e['nome']) ?>
                        </span>
                        <div style="flex:1;background:#e5e7eb;height:10px;border-radius:5px;overflow:hidden;">
                            <div style="height:100%;background:<?= e($e['cor']) ?>;width:<?= $pct ?>%;"></div>
                        </div>
                        <span style="min-width:30px;text-align:right;font-weight:700;font-size:.82rem;"><?= (int)$e['qtd'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Volume diário
(function(){
    var ctx = document.getElementById('chartDiario');
    if (!ctx) return;
    var labels = <?= json_encode(array_map(function($v){ return date('d/m', strtotime($v['dia'])); }, $volumeDiario)) ?>;
    var rec = <?= json_encode(array_map(function($v){ return (int)$v['recebidas']; }, $volumeDiario)) ?>;
    var env = <?= json_encode(array_map(function($v){ return (int)$v['enviadas']; }, $volumeDiario)) ?>;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Recebidas', data: rec, borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,.1)', fill: true, tension: .3 },
                { label: 'Enviadas',  data: env, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,.1)', fill: true, tension: .3 }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
})();
// Por hora
(function(){
    var ctx = document.getElementById('chartHora');
    if (!ctx) return;
    var labels = <?= json_encode(array_map(function($h){ return sprintf('%02d', $h) . 'h'; }, range(0, 23))) ?>;
    var rec = <?= json_encode($horaRec) ?>;
    var env = <?= json_encode($horaEnv) ?>;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'Recebidas', data: rec, backgroundColor: '#f59e0b' },
                { label: 'Enviadas',  data: env, backgroundColor: '#6366f1' }
            ]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
    });
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
