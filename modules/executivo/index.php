<?php
/**
 * Ferreira & Sá Hub — Painel Executivo (CEO 30/60/90 dias)
 *
 * Visão da saúde do escritório como CEO/sócia vê: receita, captação,
 * produção, alertas críticos. Comparativo com período anterior (variação %).
 *
 * Não duplica /modules/relatorios — esse é detalhado por área (comercial,
 * operacional, financeiro). O Executivo é "saúde do todo de relance".
 *
 * Acesso: admin + gestao
 */
require_once __DIR__ . '/../../core/middleware.php';
require_access('executivo');

$pdo = db();
$pageTitle = 'Painel Executivo';

// ─── Seletor de período ──────────────────────────────────
$dias = isset($_GET['d']) ? (int)$_GET['d'] : 30;
if (!in_array($dias, array(30, 60, 90), true)) $dias = 30;

$hoje = date('Y-m-d');
$inicioAtual    = date('Y-m-d', strtotime("-{$dias} days"));
$inicioAnterior = date('Y-m-d', strtotime('-' . ($dias * 2) . ' days'));
$fimAnterior    = date('Y-m-d', strtotime("-{$dias} days", strtotime('-1 day') + 86400)); // dia anterior ao início atual

// Helper: variação % entre dois números (positivo = melhorou)
function _var_pct($atual, $anterior) {
    $atual = (float)$atual; $anterior = (float)$anterior;
    if ($anterior == 0) return $atual > 0 ? 100 : 0;
    return round((($atual - $anterior) / $anterior) * 100, 1);
}

// ═══════════════════════════════════════════════════════
// 1. RECEITA RECUPERADA
// ═══════════════════════════════════════════════════════
$stR1 = $pdo->prepare("SELECT COALESCE(SUM(valor_pago),0) FROM honorarios_cobranca_historico WHERE etapa IN ('pagamento_parcial','pagamento_total') AND DATE(created_at) BETWEEN ? AND ?");
$stR1->execute(array($inicioAtual, $hoje));
$receitaAtual = (float)$stR1->fetchColumn();

$stR2 = $pdo->prepare("SELECT COALESCE(SUM(valor_pago),0) FROM honorarios_cobranca_historico WHERE etapa IN ('pagamento_parcial','pagamento_total') AND DATE(created_at) BETWEEN ? AND ?");
$stR2->execute(array($inicioAnterior, $inicioAtual));
$receitaAnterior = (float)$stR2->fetchColumn();

$receitaAberto = (float)$pdo->query("SELECT COALESCE(SUM(valor_total - valor_pago),0) FROM honorarios_cobranca WHERE status NOT IN ('pago','cancelado')")->fetchColumn();

// ═══════════════════════════════════════════════════════
// 2. CAPTAÇÃO (Leads novos)
// ═══════════════════════════════════════════════════════
$stL1 = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE DATE(created_at) BETWEEN ? AND ?");
$stL1->execute(array($inicioAtual, $hoje));
$leadsAtual = (int)$stL1->fetchColumn();

$stL2 = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE DATE(created_at) BETWEEN ? AND ?");
$stL2->execute(array($inicioAnterior, $inicioAtual));
$leadsAnterior = (int)$stL2->fetchColumn();

// Conversões (lead → contrato_assinado) no período
$stC1 = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado') AND DATE(converted_at) BETWEEN ? AND ?");
$stC1->execute(array($inicioAtual, $hoje));
$conversoesAtual = (int)$stC1->fetchColumn();

$stC2 = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE stage IN ('contrato_assinado','agendado_docs','reuniao_cobranca','doc_faltante','pasta_apta','finalizado') AND DATE(converted_at) BETWEEN ? AND ?");
$stC2->execute(array($inicioAnterior, $inicioAtual));
$conversoesAnterior = (int)$stC2->fetchColumn();

$taxaConvAtual    = $leadsAtual > 0    ? round(($conversoesAtual    / $leadsAtual)    * 100, 1) : 0;
$taxaConvAnterior = $leadsAnterior > 0 ? round(($conversoesAnterior / $leadsAnterior) * 100, 1) : 0;

// ═══════════════════════════════════════════════════════
// 3. PRODUÇÃO (Casos novos / Concluídos)
// ═══════════════════════════════════════════════════════
$stCN1 = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE DATE(created_at) BETWEEN ? AND ?");
$stCN1->execute(array($inicioAtual, $hoje));
$casosNovosAtual = (int)$stCN1->fetchColumn();

$stCN2 = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE DATE(created_at) BETWEEN ? AND ?");
$stCN2->execute(array($inicioAnterior, $inicioAtual));
$casosNovosAnterior = (int)$stCN2->fetchColumn();

$stCC1 = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE status = 'concluido' AND DATE(closed_at) BETWEEN ? AND ?");
$stCC1->execute(array($inicioAtual, $hoje));
$casosConcAtual = (int)$stCC1->fetchColumn();

$stCC2 = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE status = 'concluido' AND DATE(closed_at) BETWEEN ? AND ?");
$stCC2->execute(array($inicioAnterior, $inicioAtual));
$casosConcAnterior = (int)$stCC2->fetchColumn();

$casosAtivos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('arquivado','concluido','cancelado','renunciamos') AND COALESCE(kanban_oculto,0) = 0")->fetchColumn();

// ═══════════════════════════════════════════════════════
// 4. ALERTAS CRÍTICOS
// ═══════════════════════════════════════════════════════
// Clientes com score de esfriando >= 80 (risco real)
$alertaEsfriando = 0;
try {
    $alertaEsfriando = (int)$pdo->query(
        "SELECT COUNT(DISTINCT c.id) FROM clients c
         INNER JOIN cases cs ON cs.client_id = c.id
         WHERE c.esfriando_score >= 80
           AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())
           AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
           AND COALESCE(cs.kanban_oculto,0) = 0
           AND COALESCE(cs.acompanhamento_externo,0) = 0"
    )->fetchColumn();
} catch (Exception $e) {}

// Cobranças vencidas há mais de 7 dias
$alertaCobranca = 0;
try {
    $alertaCobranca = (int)$pdo->query(
        "SELECT COUNT(*) FROM honorarios_cobranca
         WHERE status NOT IN ('pago','cancelado')
           AND vencimento < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
    )->fetchColumn();
} catch (Exception $e) {}

// Casos em "doc_faltante" há mais de 14 dias
$alertaDocFaltante = (int)$pdo->query(
    "SELECT COUNT(*) FROM cases
     WHERE status = 'doc_faltante'
       AND DATE(updated_at) < DATE_SUB(CURDATE(), INTERVAL 14 DAY)
       AND COALESCE(kanban_oculto,0) = 0"
)->fetchColumn();

// Leads parados (sem update há mais de 7 dias e não-finalizados)
$alertaLeadsParados = (int)$pdo->query(
    "SELECT COUNT(*) FROM pipeline_leads
     WHERE stage NOT IN ('finalizado','perdido','arquivado','cancelado')
       AND DATE(updated_at) < DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
)->fetchColumn();

// ═══════════════════════════════════════════════════════
// 5. TOP TIPOS DE AÇÃO (volume no período)
// ═══════════════════════════════════════════════════════
$stTipos = $pdo->prepare(
    "SELECT case_type, COUNT(*) AS qtd
     FROM cases
     WHERE DATE(created_at) BETWEEN ? AND ?
       AND case_type IS NOT NULL AND case_type != ''
     GROUP BY case_type ORDER BY qtd DESC LIMIT 6"
);
$stTipos->execute(array($inicioAtual, $hoje));
$topTipos = $stTipos->fetchAll();
$topTiposMax = 0;
foreach ($topTipos as $t) { if ($t['qtd'] > $topTiposMax) $topTiposMax = (int)$t['qtd']; }

// ═══════════════════════════════════════════════════════
// 6. EQUIPE — Top produtividade (peças geradas + tarefas concluídas no período)
// ═══════════════════════════════════════════════════════
// Schema notado em 24/05/2026: case_tasks usa 'assigned_to' (nao 'responsavel_id')
// e case_andamentos usa 'created_by' (nao 'usuario_id'). case_tasks nao tem
// updated_at — uso completed_at quando status concluido.
$stEq = $pdo->prepare(
    "SELECT u.id, u.name, u.role,
            (SELECT COUNT(*) FROM case_documents cd WHERE cd.gerado_por = u.id AND DATE(cd.created_at) BETWEEN ? AND ?) AS pecas,
            (SELECT COUNT(*) FROM case_tasks t WHERE t.assigned_to = u.id AND t.status IN ('concluido','feito') AND DATE(COALESCE(t.completed_at, t.created_at)) BETWEEN ? AND ?) AS tarefas,
            (SELECT COUNT(*) FROM case_andamentos a WHERE a.created_by = u.id AND DATE(a.created_at) BETWEEN ? AND ?) AS andamentos
     FROM users u
     WHERE u.is_active = 1 AND u.role IN ('admin','gestao','comercial','cx','operacional','estagiario','colaborador')
     ORDER BY (pecas + tarefas + andamentos) DESC LIMIT 8"
);
$stEq->execute(array($inicioAtual, $hoje, $inicioAtual, $hoje, $inicioAtual, $hoje));
$equipeTop = $stEq->fetchAll();

// ═══════════════════════════════════════════════════════
// 7. SÉRIE DIÁRIA PRA SPARKLINE (receita + leads)
// ═══════════════════════════════════════════════════════
$serieDias = array();
for ($i = $dias - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $serieDias[] = $d;
}
$stRD = $pdo->prepare("SELECT DATE(created_at) d, COALESCE(SUM(valor_pago),0) v FROM honorarios_cobranca_historico WHERE etapa IN ('pagamento_parcial','pagamento_total') AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)");
$stRD->execute(array($inicioAtual, $hoje));
$receitaDia = array();
foreach ($stRD->fetchAll() as $r) { $receitaDia[$r['d']] = (float)$r['v']; }

$stLD = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) n FROM pipeline_leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at)");
$stLD->execute(array($inicioAtual, $hoje));
$leadsDia = array();
foreach ($stLD->fetchAll() as $r) { $leadsDia[$r['d']] = (int)$r['n']; }

// Helper: sparkline SVG inline (largura responsiva)
function _sparkline($valores, $cor = '#6366f1', $w = 240, $h = 50) {
    if (empty($valores)) return '';
    $max = max($valores);
    if ($max == 0) $max = 1;
    $n = count($valores);
    $pts = array();
    for ($i = 0; $i < $n; $i++) {
        $x = $n > 1 ? ($i / ($n - 1)) * $w : $w / 2;
        $y = $h - (($valores[$i] / $max) * ($h - 4)) - 2;
        $pts[] = round($x, 2) . ',' . round($y, 2);
    }
    $poly = implode(' ', $pts);
    $area = '0,' . $h . ' ' . $poly . ' ' . $w . ',' . $h;
    return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h . '" preserveAspectRatio="none" style="display:block;">'
         . '<polygon points="' . $area . '" fill="' . $cor . '" fill-opacity=".12"/>'
         . '<polyline points="' . $poly . '" fill="none" stroke="' . $cor . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
         . '</svg>';
}

$serieReceita = array();
$serieLeads = array();
foreach ($serieDias as $d) {
    $serieReceita[] = isset($receitaDia[$d]) ? $receitaDia[$d] : 0;
    $serieLeads[]   = isset($leadsDia[$d])   ? $leadsDia[$d]   : 0;
}

// Helper render de variação %
function _renderVar($pct, $invertida = false) {
    if ($pct == 0) return '<span style="color:#9ca3af;font-size:.72rem;">— estável</span>';
    $melhor = $invertida ? $pct < 0 : $pct > 0;
    $cor = $melhor ? '#059669' : '#dc2626';
    $seta = $pct > 0 ? '↑' : '↓';
    return '<span style="color:' . $cor . ';font-size:.72rem;font-weight:600;">' . $seta . ' ' . abs($pct) . '%</span>';
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.exec-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:.75rem; margin-bottom:1.25rem; }
.exec-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:.95rem 1.1rem; box-shadow:0 1px 2px rgba(0,0,0,.04); }
.exec-card-label { font-size:.7rem; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; font-weight:600; }
.exec-card-value { font-size:1.7rem; font-weight:800; color:#1e1b4b; margin:.2rem 0 .15rem; line-height:1.1; }
.exec-card-foot { font-size:.72rem; color:#6b7280; display:flex; justify-content:space-between; align-items:center; gap:.4rem; }
.exec-card.alerta { border-left:4px solid #dc2626; }
.exec-card.alerta .exec-card-value { color:#b91c1c; }
.exec-card.ok { border-left:4px solid #059669; }
.exec-card.warn { border-left:4px solid #f59e0b; }
.exec-tabs { display:inline-flex; gap:.25rem; background:#f3f4f6; padding:.2rem; border-radius:8px; margin-bottom:1.25rem; }
.exec-tab { padding:.4rem .9rem; border-radius:6px; font-size:.82rem; font-weight:600; color:#6b7280; text-decoration:none; }
.exec-tab.ativo { background:#fff; color:#1e1b4b; box-shadow:0 1px 3px rgba(0,0,0,.08); }
.exec-section { margin-bottom:1.5rem; }
.exec-section h3 { font-size:.95rem; color:#1e1b4b; margin-bottom:.6rem; display:flex; align-items:center; gap:.4rem; }
.exec-bar { display:flex; align-items:center; gap:.6rem; padding:.45rem .55rem; border-radius:6px; }
.exec-bar:hover { background:#fafafa; }
.exec-bar-label { font-size:.85rem; color:#1f2937; min-width:140px; flex-shrink:0; }
.exec-bar-track { flex:1; height:8px; background:#f3f4f6; border-radius:99px; overflow:hidden; }
.exec-bar-fill { height:100%; background:#6366f1; border-radius:99px; transition:width .3s; }
.exec-bar-qtd { font-size:.78rem; font-weight:700; color:#1e1b4b; min-width:30px; text-align:right; }
</style>

<div style="max-width:1200px;">
<h1 style="margin-bottom:.2rem;">📈 Painel Executivo</h1>
<p style="color:#6b7280;margin-bottom:1rem;font-size:.88rem;">Saúde do escritório nos últimos <?= $dias ?> dias, com comparação ao período anterior.</p>

<div class="exec-tabs">
    <?php foreach (array(30, 60, 90) as $d): ?>
        <a class="exec-tab <?= $dias === $d ? 'ativo' : '' ?>" href="?d=<?= $d ?>"><?= $d ?> dias</a>
    <?php endforeach; ?>
</div>

<!-- KPIs principais -->
<div class="exec-grid">
    <div class="exec-card ok">
        <div class="exec-card-label">💰 Receita recuperada</div>
        <div class="exec-card-value">R$ <?= number_format($receitaAtual, 2, ',', '.') ?></div>
        <div class="exec-card-foot">
            <?= _renderVar(_var_pct($receitaAtual, $receitaAnterior)) ?>
            <span>vs período anterior</span>
        </div>
        <div style="margin-top:.5rem;"><?= _sparkline($serieReceita, '#059669') ?></div>
    </div>

    <div class="exec-card">
        <div class="exec-card-label">🆕 Leads captados</div>
        <div class="exec-card-value"><?= $leadsAtual ?></div>
        <div class="exec-card-foot">
            <?= _renderVar(_var_pct($leadsAtual, $leadsAnterior)) ?>
            <span>vs período anterior</span>
        </div>
        <div style="margin-top:.5rem;"><?= _sparkline($serieLeads, '#6366f1') ?></div>
    </div>

    <div class="exec-card">
        <div class="exec-card-label">✅ Conversões</div>
        <div class="exec-card-value"><?= $conversoesAtual ?></div>
        <div class="exec-card-foot">
            <?= _renderVar(_var_pct($conversoesAtual, $conversoesAnterior)) ?>
            <span>taxa <?= $taxaConvAtual ?>% <?= _renderVar(_var_pct($taxaConvAtual, $taxaConvAnterior)) ?></span>
        </div>
    </div>

    <div class="exec-card">
        <div class="exec-card-label">📂 Casos novos</div>
        <div class="exec-card-value"><?= $casosNovosAtual ?></div>
        <div class="exec-card-foot">
            <?= _renderVar(_var_pct($casosNovosAtual, $casosNovosAnterior)) ?>
            <span><?= $casosAtivos ?> ativos hoje</span>
        </div>
    </div>

    <div class="exec-card">
        <div class="exec-card-label">🏁 Casos concluídos</div>
        <div class="exec-card-value"><?= $casosConcAtual ?></div>
        <div class="exec-card-foot">
            <?= _renderVar(_var_pct($casosConcAtual, $casosConcAnterior)) ?>
            <span>vs período anterior</span>
        </div>
    </div>

    <div class="exec-card warn">
        <div class="exec-card-label">⏳ A receber</div>
        <div class="exec-card-value">R$ <?= number_format($receitaAberto, 2, ',', '.') ?></div>
        <div class="exec-card-foot"><span>cobranças em aberto (snapshot atual)</span></div>
    </div>
</div>

<!-- Alertas críticos -->
<?php
$alertas = array(
    array($alertaEsfriando, '🌡️ Clientes em risco real', 'esfriando_score ≥ 80, sem snooze', url('modules/clientes/em_risco.php?filtro=risco_real'), 'alerta'),
    array($alertaDocFaltante, '⚠️ Casos em "Doc Faltante" há +14 dias', 'cliente não devolveu documentação', url('modules/operacional/'), 'warn'),
    array($alertaCobranca, '💸 Cobranças vencidas há +7 dias', 'precisa de atendimento manual', url('modules/cobranca_honorarios/'), 'alerta'),
    array($alertaLeadsParados, '😴 Leads sem update há +7 dias', 'follow-up atrasado no pipeline', url('modules/pipeline/'), 'warn'),
);
?>
<div class="exec-section">
    <h3>🚨 Alertas críticos</h3>
    <div class="exec-grid">
        <?php foreach ($alertas as $a):
            list($qtd, $titulo, $sub, $href, $tipo) = $a;
            if ($qtd === 0) continue;
        ?>
        <a class="exec-card <?= $tipo ?>" href="<?= $href ?>" style="text-decoration:none;color:inherit;display:block;">
            <div class="exec-card-label"><?= $titulo ?></div>
            <div class="exec-card-value"><?= $qtd ?></div>
            <div class="exec-card-foot"><span><?= $sub ?></span><span style="color:#6366f1;">Ver →</span></div>
        </a>
        <?php endforeach; ?>
        <?php
        $totalAlertas = 0;
        foreach ($alertas as $a) { $totalAlertas += (int)$a[0]; }
        if ($totalAlertas === 0):
        ?>
        <div class="exec-card ok" style="grid-column: 1 / -1;">
            <div class="exec-card-label">✓ Tudo em ordem</div>
            <div style="font-size:.95rem;color:#059669;font-weight:600;margin-top:.4rem;">Nenhum alerta crítico no momento. 🎉</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Top tipos de ação + Equipe -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(380px,1fr));gap:1rem;">
    <div class="exec-section">
        <h3>📊 Top tipos de ação <span style="font-size:.7rem;font-weight:normal;color:#9ca3af;">(novos no período)</span></h3>
        <div class="exec-card" style="padding:.65rem;">
            <?php if (empty($topTipos)): ?>
                <div style="padding:1rem;color:#9ca3af;text-align:center;font-size:.85rem;">Nenhum caso novo no período.</div>
            <?php else: ?>
                <?php foreach ($topTipos as $t):
                    $pct = $topTiposMax > 0 ? round(((int)$t['qtd'] / $topTiposMax) * 100) : 0;
                ?>
                    <div class="exec-bar">
                        <div class="exec-bar-label"><?= e($t['case_type']) ?></div>
                        <div class="exec-bar-track"><div class="exec-bar-fill" style="width:<?= $pct ?>%;"></div></div>
                        <div class="exec-bar-qtd"><?= $t['qtd'] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="exec-section">
        <h3>🏆 Top equipe <span style="font-size:.7rem;font-weight:normal;color:#9ca3af;">(peças + tarefas + andamentos)</span></h3>
        <div class="exec-card" style="padding:.65rem;">
            <?php if (empty($equipeTop)): ?>
                <div style="padding:1rem;color:#9ca3af;text-align:center;font-size:.85rem;">Sem registros de atividade no período.</div>
            <?php else:
                $maxAtv = 0;
                foreach ($equipeTop as $u) {
                    $tot = (int)$u['pecas'] + (int)$u['tarefas'] + (int)$u['andamentos'];
                    if ($tot > $maxAtv) $maxAtv = $tot;
                }
                foreach ($equipeTop as $u):
                    $tot = (int)$u['pecas'] + (int)$u['tarefas'] + (int)$u['andamentos'];
                    if ($tot === 0) continue;
                    $pct = $maxAtv > 0 ? round(($tot / $maxAtv) * 100) : 0;
                ?>
                    <div class="exec-bar">
                        <div class="exec-bar-label"><?= e($u['name']) ?> <span style="font-size:.7rem;color:#9ca3af;">(<?= e($u['role']) ?>)</span></div>
                        <div class="exec-bar-track"><div class="exec-bar-fill" style="width:<?= $pct ?>%;background:#7c3aed;"></div></div>
                        <div class="exec-bar-qtd" title="<?= $u['pecas'] ?> peças · <?= $u['tarefas'] ?> tarefas · <?= $u['andamentos'] ?> andamentos"><?= $tot ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<p style="font-size:.72rem;color:#9ca3af;margin-top:1.5rem;">
    Período atual: <?= date('d/m/Y', strtotime($inicioAtual)) ?> a <?= date('d/m/Y', strtotime($hoje)) ?> ·
    Período anterior: <?= date('d/m/Y', strtotime($inicioAnterior)) ?> a <?= date('d/m/Y', strtotime($inicioAtual)) ?>
</p>

</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
