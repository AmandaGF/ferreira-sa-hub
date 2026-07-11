<?php
/**
 * Módulo Presença — Painel.
 * Cards com verba do mês, envios por status, alertas de estoque, KPIs de retenção.
 * Amanda 11/07/2026 — Fase 1 do blueprint.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');

$pdo = db();
$pageTitle = 'Presença — Relacionamento & Retenção';

// ═══════════ Dados do painel ═══════════

$mesAtual = date('Y-m');
$mesLabel = strftime('%B/%Y', strtotime(date('Y-m-01'))); // fallback abaixo
$meses = array(1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
               7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro');
$mesLabel = $meses[(int)date('n')] . '/' . date('Y');

// Config
$cfg = array();
foreach ($pdo->query("SELECT chave, valor FROM presenca_config") as $r) $cfg[$r['chave']] = $r['valor'];
$tetoMensal = (float)($cfg['teto_mensal'] ?? 1500);
$automacaoOn = !empty($cfg['automacao_ativa']) && $cfg['automacao_ativa'] !== '0';

// Verba do mês (previsto + realizado)
$previsto = 0.0; $realizado = 0.0;
try {
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN status IN ('sugerido','aprovado','em_producao') THEN custo_previsto ELSE 0 END), 0) AS previsto,
        COALESCE(SUM(CASE WHEN status IN ('enviado','entregue') THEN COALESCE(custo_real, custo_previsto) ELSE 0 END), 0) AS realizado
        FROM presenca_envio WHERE DATE_FORMAT(COALESCE(data_alvo, created_at), '%Y-%m') = ?");
    $st->execute(array($mesAtual));
    if ($r = $st->fetch()) { $previsto = (float)$r['previsto']; $realizado = (float)$r['realizado']; }
} catch (Exception $e) {}

$pctTeto = $tetoMensal > 0 ? min(100, round(($realizado / $tetoMensal) * 100)) : 0;
$estourouTeto = $realizado > $tetoMensal;

// Amanda 11/07 review: card "Verba" mostrava R$0 mesmo com matriz preenchida.
// Adicionamos "ticket medio" pra sinalizar que a matriz esta configurada.
$verbaMatrizMedia = 0.0; $verbaMatrizSoma = 0.0; $verbaMatrizRegras = 0;
try {
    $r = $pdo->query("SELECT COALESCE(SUM(verba_prevista),0) s, COUNT(*) n FROM presenca_regra WHERE ativo=1 AND verba_prevista > 0")->fetch();
    $verbaMatrizSoma = (float)$r['s'];
    $verbaMatrizRegras = (int)$r['n'];
    $verbaMatrizMedia = $verbaMatrizRegras > 0 ? $verbaMatrizSoma / $verbaMatrizRegras : 0;
} catch (Exception $e) {}

// Envios por status
$porStatus = array('sugerido'=>0,'aprovado'=>0,'em_producao'=>0,'enviado'=>0,'entregue'=>0,'cancelado'=>0);
try {
    foreach ($pdo->query("SELECT status, COUNT(*) q FROM presenca_envio GROUP BY status") as $r) {
        $porStatus[$r['status']] = (int)$r['q'];
    }
} catch (Exception $e) {}

// Estoque em risco (abaixo do mínimo).
// Amanda 11/07 (review 2): card contava "count(rows) com LIMIT 6" — capava em 6
// quando o total real era maior (checklist mostrava 8, card mostrava 6). Fix:
// query 1 pega o TOTAL real (sem LIMIT), query 2 pega amostra pra listar.
$estoqueRiscoTotal = 0;
$estoqueRisco = array();
try {
    $estoqueRiscoTotal = (int)$pdo->query("SELECT COUNT(*)
        FROM presenca_estoque e JOIN presenca_brinde b ON b.id = e.brinde_id
        WHERE b.ativo = 1 AND e.estoque_atual < e.estoque_minimo")->fetchColumn();
    $st = $pdo->query("SELECT b.id, b.nome, e.estoque_atual, e.estoque_minimo
        FROM presenca_estoque e
        JOIN presenca_brinde b ON b.id = e.brinde_id
        WHERE b.ativo = 1 AND e.estoque_atual < e.estoque_minimo
        ORDER BY (e.estoque_minimo - e.estoque_atual) DESC LIMIT 6");
    $estoqueRisco = $st->fetchAll();
} catch (Exception $e) {}

// KPIs de cadastro (Fase 1 é sobre a fundação)
$totPerfis    = (int)$pdo->query("SELECT COUNT(*) FROM presenca_perfil WHERE ativo = 1")->fetchColumn();
$totFases     = (int)$pdo->query("SELECT COUNT(*) FROM presenca_fase WHERE ativo = 1")->fetchColumn();
$totFrases    = (int)$pdo->query("SELECT COUNT(*) FROM presenca_frase WHERE ativo = 1")->fetchColumn();
$totBrindes   = (int)$pdo->query("SELECT COUNT(*) FROM presenca_brinde WHERE ativo = 1")->fetchColumn();
$totFornec    = (int)$pdo->query("SELECT COUNT(*) FROM presenca_fornecedor WHERE ativo = 1")->fetchColumn();
$totOrcam     = (int)$pdo->query("SELECT COUNT(*) FROM presenca_orcamento")->fetchColumn();
$totRegras    = (int)$pdo->query("SELECT COUNT(*) FROM presenca_regra WHERE ativo = 1")->fetchColumn();
$regrasMax    = $totPerfis * $totFases; // matriz completa
$pctMatriz    = $regrasMax > 0 ? round(($totRegras / $regrasMax) * 100) : 0;

// Amanda 11/07 (review): "matriz mostra 10/15 mas nao lista quais faltam".
// Aqui achamos as combinacoes SEM regra pra listar no checklist.
$celulasVazias = array();
try {
    $perfisPresenca = $pdo->query("SELECT id, nome FROM presenca_perfil WHERE ativo = 1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
    $fases  = $pdo->query("SELECT id, nome FROM presenca_fase WHERE ativo = 1 ORDER BY ordem, id")->fetchAll(PDO::FETCH_ASSOC);
    $preenchidas = array();
    foreach ($pdo->query("SELECT perfil_id, fase_id FROM presenca_regra WHERE ativo = 1 AND (brinde_id IS NOT NULL OR frase_id IS NOT NULL OR verba_prevista > 0)") as $r) {
        $preenchidas[$r['perfil_id'] . '_' . $r['fase_id']] = true;
    }
    foreach ($perfisPresenca as $p) foreach ($fases as $f) {
        if (empty($preenchidas[$p['id'] . '_' . $f['id']])) {
            $celulasVazias[] = $p['nome'] . ' × ' . $f['nome'];
        }
    }
} catch (Exception $e) {}

// Alertas de estoque baixo — reusa contagem ja feita acima (mesma fonte que o card)
$estoqueRiscoQtd = $estoqueRiscoTotal;

// Passos do checklist — ordem lógica de configuração
$passos = array(
    array('id'=>'perfis',      'titulo'=>'Perfis & Verbas',                'meta'=>3,  'atual'=>$totPerfis,  'ok_msg'=>'perfis cadastrados',        'todo_msg'=>'perfil pra cadastrar',       'url'=>module_url('presenca','perfis.php'),        'action'=>'Ajustar faixas','icon'=>'👤'),
    array('id'=>'fases',       'titulo'=>'Fases da Jornada',               'meta'=>5,  'atual'=>$totFases,   'ok_msg'=>'fases ativas',              'todo_msg'=>'fase pra cadastrar',         'url'=>module_url('presenca','fases.php'),         'action'=>'Revisar fases','icon'=>'🛤️'),
    array('id'=>'brindes',     'titulo'=>'Catálogo de Brindes + Estoque',  'meta'=>5,  'atual'=>$totBrindes, 'ok_msg'=>'brindes cadastrados',       'todo_msg'=>'brinde pra cadastrar',       'url'=>module_url('presenca','brindes.php'),       'action'=>'Ajustar estoque','icon'=>'🎁',    'warn_extra'=>$estoqueRiscoQtd > 0 ? $estoqueRiscoQtd . ' abaixo do mínimo' : null),
    array('id'=>'frases',      'titulo'=>'Banco de Frases',                'meta'=>10, 'atual'=>$totFrases,  'ok_msg'=>'frases',                    'todo_msg'=>'frase pra cadastrar',        'url'=>module_url('presenca','frases.php'),        'action'=>'Ver frases','icon'=>'📚'),
    array('id'=>'matriz',      'titulo'=>'Matriz de Regras',               'meta'=>$regrasMax, 'atual'=>$totRegras, 'ok_msg'=>'combinações preenchidas', 'todo_msg'=>'combinação vazia',       'url'=>module_url('presenca','matriz.php'),        'action'=>'Preencher matriz','icon'=>'🗂️', 'vazias'=>$celulasVazias),
    array('id'=>'fornecedores','titulo'=>'Fornecedores',                   'meta'=>2,  'atual'=>$totFornec,  'ok_msg'=>'fornecedores ativos',       'todo_msg'=>'fornecedor pra cadastrar',   'url'=>module_url('presenca','fornecedores.php'),  'action'=>'Cadastrar fornecedor','icon'=>'🏭'),
    array('id'=>'orcamentos',  'titulo'=>'Orçamentos comparados',          'meta'=>1,  'atual'=>$totOrcam,   'ok_msg'=>'orçamento(s) registrado(s)','todo_msg'=>'orçamento pra registrar',    'url'=>module_url('presenca','fornecedores.php').'?orc=1', 'action'=>'Registrar orçamento','icon'=>'💰'),
);

// Retenção — ROI
$retTotHonor = 0.0; $retTotCusto = 0.0; $retIndicacoes = 0;
try {
    $retTotHonor = (float)$pdo->query("SELECT COALESCE(SUM(valor),0) FROM presenca_evento_retencao WHERE tipo='indicacao'")->fetchColumn();
    $retIndicacoes = (int)$pdo->query("SELECT COUNT(*) FROM presenca_evento_retencao WHERE tipo='indicacao'")->fetchColumn();
    $retTotCusto = (float)$pdo->query("SELECT COALESCE(SUM(COALESCE(custo_real, custo_previsto)),0) FROM presenca_envio WHERE status IN ('enviado','entregue')")->fetchColumn();
} catch (Exception $e) {}
$roiPct = $retTotCusto > 0 ? round(($retTotHonor / $retTotCusto) * 100) : 0;

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.pr-hero { background:linear-gradient(135deg,#0E2E36,#173d46); color:#fff; padding:22px 26px; border-radius:14px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; }
.pr-hero h1 { margin:0; font-family:'Cormorant Garamond',Georgia,serif; font-size:1.9rem; font-weight:600; color:#d7ab90; }
.pr-hero .sub { font-size:.85rem; opacity:.85; margin-top:4px; color:#e6d5c4; }
.pr-hero-actions { display:flex; gap:8px; flex-wrap:wrap; }
.pr-hero-btn { background:rgba(255,255,255,.12); color:#fff; border:1.5px solid rgba(215,171,144,.4); padding:8px 14px; border-radius:8px; text-decoration:none; font-size:.82rem; font-weight:600; display:inline-flex; align-items:center; gap:6px; }
.pr-hero-btn:hover { background:rgba(215,171,144,.2); border-color:#d7ab90; color:#fff; }
.pr-hero-btn.primary { background:#d7ab90; color:#052228; border-color:#d7ab90; }

.pr-auto-flag { display:inline-flex; align-items:center; gap:6px; font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:999px; letter-spacing:.05em; text-transform:uppercase; }
.pr-auto-flag.on  { background:#dcfce7; color:#15803d; }
.pr-auto-flag.off { background:#fef3c7; color:#78350f; }

.pr-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:14px; margin-bottom:18px; }
.pr-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.pr-card h4 { margin:0 0 10px; font-size:.72rem; font-weight:800; color:#6b7280; text-transform:uppercase; letter-spacing:.06em; }
.pr-card .num { font-size:1.9rem; font-weight:800; color:#0E2E36; line-height:1; }
.pr-card .sub { font-size:.75rem; color:#6b7280; margin-top:4px; }

.pr-verba-card { background:linear-gradient(135deg,#fff,#f5ede3); border:1.5px solid #d7ab90; }
.pr-verba-bar { height:10px; background:#e5e7eb; border-radius:6px; overflow:hidden; margin:8px 0 6px; }
.pr-verba-fill { height:100%; background:linear-gradient(90deg,#7E8F6E,#A9803B); border-radius:6px; transition:width .3s; }
.pr-verba-fill.warn { background:#dc2626; }
.pr-verba-labels { display:flex; justify-content:space-between; font-size:.72rem; color:#6b7280; }

.pr-status-grid { display:grid; grid-template-columns:repeat(6,1fr); gap:6px; margin-top:8px; }
.pr-status-item { text-align:center; padding:8px 4px; border-radius:8px; background:#f9fafb; }
.pr-status-item .n { font-size:1.1rem; font-weight:800; color:#0E2E36; }
.pr-status-item .l { font-size:.62rem; color:#6b7280; text-transform:uppercase; letter-spacing:.03em; margin-top:2px; }
.pr-status-item.warn .n { color:#d97706; }

.pr-estoque-list { display:flex; flex-direction:column; gap:6px; margin-top:8px; }
.pr-estoque-row { display:flex; justify-content:space-between; align-items:center; background:#fff5f5; border:1px solid #fecaca; padding:6px 10px; border-radius:6px; font-size:.78rem; }
.pr-estoque-row .nome { font-weight:600; color:#7f1d1d; }
.pr-estoque-row .qty { font-size:.72rem; color:#dc2626; font-weight:700; }

.pr-progresso-config { display:flex; align-items:center; gap:10px; margin-top:6px; }
.pr-progresso-config .bar { flex:1; height:6px; background:#e5e7eb; border-radius:4px; overflow:hidden; }
.pr-progresso-config .bar .fill { height:100%; background:#0E2E36; }
.pr-progresso-config .pct { font-size:.7rem; color:#6b7280; font-weight:700; min-width:36px; text-align:right; }

.pr-nav-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
.pr-nav-card { display:flex; align-items:center; gap:12px; background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:14px 16px; text-decoration:none; color:#0E2E36; transition:all .15s; }
.pr-nav-card:hover { border-color:#d7ab90; background:#fff7ed; transform:translateY(-1px); box-shadow:0 4px 12px rgba(215,171,144,.15); }
.pr-nav-card .ico { font-size:1.6rem; }
.pr-nav-card .txt { flex:1; }
.pr-nav-card .t { font-weight:800; font-size:.86rem; }
.pr-nav-card .s { font-size:.72rem; color:#6b7280; margin-top:2px; }
.pr-nav-card.soon { opacity:.55; pointer-events:none; }
.pr-nav-card.soon .s::after { content:' · em breve'; color:#B87333; font-weight:700; }
</style>

<div class="pr-hero">
    <div>
        <h1>🎁 Presença</h1>
        <div class="sub">Relacionamento &amp; Retenção — <?= e($mesLabel) ?></div>
    </div>
    <div class="pr-hero-actions">
        <span class="pr-auto-flag <?= $automacaoOn ? 'on' : 'off' ?>">
            <?= $automacaoOn ? '⚡ Automação LIGADA' : '⏸ Automação desligada' ?>
        </span>
        <a href="<?= module_url('presenca', 'perfis.php') ?>" class="pr-hero-btn primary">👤 Perfis</a>
        <a href="<?= module_url('presenca', 'brindes.php') ?>" class="pr-hero-btn">🎁 Catálogo</a>
    </div>
</div>

<div class="pr-grid">
    <!-- Verba do mês -->
    <div class="pr-card pr-verba-card">
        <h4>💰 Verba de <?= e($mesLabel) ?></h4>
        <div class="num">R$ <?= number_format($realizado, 0, ',', '.') ?></div>
        <div class="sub">de R$ <?= number_format($tetoMensal, 0, ',', '.') ?> (teto)</div>
        <div class="pr-verba-bar">
            <div class="pr-verba-fill <?= $estourouTeto ? 'warn' : '' ?>" style="width:<?= $pctTeto ?>%;"></div>
        </div>
        <div class="pr-verba-labels">
            <span><?= $pctTeto ?>% usado</span>
            <span title="Envios em pipeline (sugerido/aprovado/em producao)">Comprometido: R$ <?= number_format($previsto, 0, ',', '.') ?></span>
        </div>
        <?php if ($verbaMatrizRegras > 0): ?>
        <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #e5e7eb;font-size:.72rem;color:#6b7280;display:flex;justify-content:space-between;">
            <span title="Ticket medio configurado na Matriz de Regras">🗂️ Matriz: R$ <?= number_format($verbaMatrizMedia, 0, ',', '.') ?> médio × <?= $verbaMatrizRegras ?> regras</span>
            <span style="font-weight:700;color:#0E2E36;" title="Soma das verbas configuradas se todas as regras dispararem 1x">Potencial: R$ <?= number_format($verbaMatrizSoma, 0, ',', '.') ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Envios por status -->
    <div class="pr-card">
        <h4>📦 Envios (todos os tempos)</h4>
        <div class="num"><?= array_sum($porStatus) ?></div>
        <div class="sub">total registrados</div>
        <div class="pr-status-grid">
            <div class="pr-status-item warn"><div class="n"><?= $porStatus['sugerido'] ?></div><div class="l">Sugerido</div></div>
            <div class="pr-status-item"><div class="n"><?= $porStatus['aprovado'] ?></div><div class="l">Aprovado</div></div>
            <div class="pr-status-item"><div class="n"><?= $porStatus['em_producao'] ?></div><div class="l">Prod.</div></div>
            <div class="pr-status-item"><div class="n"><?= $porStatus['enviado'] ?></div><div class="l">Enviado</div></div>
            <div class="pr-status-item"><div class="n"><?= $porStatus['entregue'] ?></div><div class="l">Entregue</div></div>
            <div class="pr-status-item"><div class="n"><?= $porStatus['cancelado'] ?></div><div class="l">Canc.</div></div>
        </div>
    </div>

    <!-- Estoque em risco -->
    <div class="pr-card">
        <h4>📉 Estoque em risco</h4>
        <?php if ($estoqueRiscoTotal === 0): ?>
            <div class="num" style="color:#15803d;">✓</div>
            <div class="sub">Todos os brindes acima do mínimo</div>
        <?php else: ?>
            <div class="num" style="color:#dc2626;"><?= $estoqueRiscoTotal ?></div>
            <div class="sub">brinde(s) abaixo do mínimo</div>
            <div class="pr-estoque-list">
                <?php $mostrados = array_slice($estoqueRisco, 0, 4); foreach ($mostrados as $r): ?>
                <div class="pr-estoque-row">
                    <span class="nome"><?= e($r['nome']) ?></span>
                    <span class="qty"><?= (int)$r['estoque_atual'] ?> / <?= (int)$r['estoque_minimo'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ($estoqueRiscoTotal > count($mostrados)): ?>
                    <div style="font-size:.7rem;color:#6b7280;text-align:center;padding:2px;font-style:italic;">
                        + <?= $estoqueRiscoTotal - count($mostrados) ?> outro(s) —
                        <a href="<?= module_url('presenca','brindes.php') ?>" style="color:#B87333;font-weight:700;text-decoration:none;">ver todos →</a>
                    </div>
                <?php endif; ?>
            </div>
            <a href="<?= module_url('presenca','reposicao.php') ?>" style="display:block;margin-top:10px;padding:8px 12px;background:#0E2E36;color:#fff;text-align:center;text-decoration:none;border-radius:8px;font-size:.78rem;font-weight:700;">🛒 Gerar pedido de reposição</a>
        <?php endif; ?>
    </div>

    <!-- ROI Retenção -->
    <div class="pr-card">
        <h4>📈 ROI do carinho</h4>
        <?php if ($retIndicacoes === 0): ?>
            <div class="num" style="color:#6b7280;">—</div>
            <div class="sub">Sem indicações registradas ainda</div>
        <?php else: ?>
            <div class="num" style="color:#15803d;"><?= $roiPct ?>%</div>
            <div class="sub">R$ <?= number_format($retTotHonor, 0, ',', '.') ?> em <?= $retIndicacoes ?> indicação(ões) · custo R$ <?= number_format($retTotCusto, 0, ',', '.') ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- Checklist de primeiros passos (Fase 1) — Amanda 11/07 review -->
<?php
$passosFeitos = 0; $passosTotal = count($passos);
foreach ($passos as $ps) if ($ps['atual'] >= $ps['meta']) $passosFeitos++;
$prontidao = round(($passosFeitos / $passosTotal) * 100);
?>
<div class="pr-card" style="margin-bottom:18px;padding:0;overflow:hidden;">
    <div style="padding:16px 20px;background:linear-gradient(135deg,#f5ede3,#fff);border-bottom:1px solid #f3f4f6;">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div>
                <h4 style="margin:0;font-size:.9rem;color:#0E2E36;text-transform:none;letter-spacing:0;">🧭 Checklist de configuração</h4>
                <div style="font-size:.75rem;color:#6b7280;margin-top:4px;">Configure na ordem — o sistema opera quando isto estiver pronto.</div>
            </div>
            <div style="font-family:'Cormorant Garamond',Georgia,serif;font-size:1.4rem;font-weight:700;color:<?= $prontidao >= 100 ? '#15803d' : '#B87333' ?>;">
                <?= $passosFeitos ?>/<?= $passosTotal ?> passos
            </div>
        </div>
    </div>
    <div>
        <?php foreach ($passos as $i => $ps):
            $done = $ps['atual'] >= $ps['meta'];
            $pct = $ps['meta'] > 0 ? min(100, round(($ps['atual'] / $ps['meta']) * 100)) : 0;
        ?>
        <a href="<?= e($ps['url']) ?>" class="pr-passo <?= $done?'done':'todo' ?>" style="
            display:flex;align-items:center;gap:14px;padding:14px 20px;text-decoration:none;color:inherit;
            border-bottom:1px solid #f3f4f6;transition:background .12s;
            <?= $done ? 'background:#fff;' : 'background:#fafafa;' ?>
        " onmouseover="this.style.background='#f5ede3'" onmouseout="this.style.background='<?= $done?'#fff':'#fafafa' ?>'">
            <div style="width:28px;height:28px;border-radius:50%;background:<?= $done ? '#15803d' : '#e5e7eb' ?>;color:<?= $done?'#fff':'#6b7280' ?>;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.9rem;flex-shrink:0;">
                <?= $done ? '✓' : ($i + 1) ?>
            </div>
            <div style="font-size:1.5rem;line-height:1;"><?= $ps['icon'] ?></div>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;color:#0E2E36;font-size:.9rem;"><?= e($ps['titulo']) ?></div>
                <div style="font-size:.75rem;color:#6b7280;margin-top:2px;">
                    <?php if ($done): ?>
                        ✓ <?= $ps['atual'] ?> <?= e($ps['ok_msg']) ?>
                    <?php else: ?>
                        <?= $ps['atual'] ?>/<?= $ps['meta'] ?> — falta <?= max(0, $ps['meta'] - $ps['atual']) ?> <?= e($ps['todo_msg']) ?>
                    <?php endif; ?>
                    <?php if (!empty($ps['warn_extra'])): ?>
                        · <span style="color:#dc2626;font-weight:700;">⚠ <?= e($ps['warn_extra']) ?></span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($ps['vazias']) && !$done): ?>
                <div style="font-size:.7rem;color:#78350f;margin-top:6px;line-height:1.6;">
                    <strong>Combinações vazias:</strong>
                    <?php foreach (array_slice($ps['vazias'], 0, 6) as $v): ?>
                        <span style="display:inline-block;background:#fef3c7;color:#78350f;padding:2px 8px;border-radius:999px;font-weight:600;margin:1px 2px;"><?= e($v) ?></span>
                    <?php endforeach; ?>
                    <?php if (count($ps['vazias']) > 6): ?><span style="color:#a0846b;">+ <?= count($ps['vazias']) - 6 ?> outras</span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div style="background:<?= $done?'#dcfce7':'#0E2E36' ?>;color:<?= $done?'#15803d':'#fff' ?>;padding:6px 14px;border-radius:8px;font-size:.75rem;font-weight:700;flex-shrink:0;">
                <?= $done ? '✓ Ajustar' : $ps['action'] ?> →
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Navegação para as telas -->
<h3 style="font-family:'Cormorant Garamond',Georgia,serif;color:#0E2E36;font-size:1.3rem;margin:24px 0 10px;font-weight:600;">Telas do módulo</h3>
<div class="pr-nav-grid">
    <a class="pr-nav-card" href="<?= module_url('presenca', 'perfis.php') ?>">
        <div class="ico">👤</div>
        <div class="txt">
            <div class="t">Perfis &amp; Verbas</div>
            <div class="s">Essencial · Premium · Alta — faixas e tetos</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'brindes.php') ?>">
        <div class="ico">🎁</div>
        <div class="txt">
            <div class="t">Catálogo de Brindes</div>
            <div class="s">CRUD + galeria de mockups + composição de kit</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'frases.php') ?>">
        <div class="ico">📚</div>
        <div class="txt">
            <div class="t">Banco de Frases</div>
            <div class="s">Por fase e universais</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'fases.php') ?>">
        <div class="ico">🛤️</div>
        <div class="txt">
            <div class="t">Fases da Jornada</div>
            <div class="s">Boas-vindas · Fôlego · Marco · Nova fase · Efeméride</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'matriz.php') ?>">
        <div class="ico">🗂️</div>
        <div class="txt">
            <div class="t">Matriz de Regras</div>
            <div class="s">Perfil × Fase → brinde + frase + verba</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'fornecedores.php') ?>">
        <div class="ico">🏭</div>
        <div class="txt">
            <div class="t">Fornecedores &amp; Orçamentos</div>
            <div class="s">CRUD + comparativo com score de custo-benefício</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'brindes.php') ?>">
        <div class="ico">📦</div>
        <div class="txt">
            <div class="t">Estoque</div>
            <div class="s">Editável na tela de Brindes (saldo atual + mínimo)</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'restricoes.php') ?>">
        <div class="ico">🛡️</div>
        <div class="txt">
            <div class="t">Restrições de Sensibilidade</div>
            <div class="s">Não enviar · Confirmar endereço</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'aprovacao.php') ?>" style="border-color:#B87333;background:#fff7ed;">
        <div class="ico">✅</div>
        <div class="txt">
            <div class="t">Bandeja de Aprovação</div>
            <div class="s">Aprovar em lote — <?= $porStatus['sugerido'] ?? 0 ?> aguardando</div>
        </div>
    </a>
    <a class="pr-nav-card" href="<?= module_url('presenca', 'kanban.php') ?>">
        <div class="ico">📋</div>
        <div class="txt">
            <div class="t">Fila de Envios (Kanban)</div>
            <div class="s">Sugerido → Aprovado → Produção → Enviado → Entregue</div>
        </div>
    </a>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
