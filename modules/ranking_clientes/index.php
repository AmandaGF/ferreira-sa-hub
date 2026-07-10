<?php
/**
 * Ferreira & Sá Hub — Ranking Gamificado dos Clientes Mais Engajados
 *
 * Amanda 10/07/2026: quer premiar no fim do ano os clientes que mais
 * interagem com o escritorio (mensagens WA + chamados helpdesk + Central VIP
 * + compareceu em balcao virtual). Score = soma cruda de todas essas
 * interacoes no periodo escolhido.
 *
 * Nao eh publico — so admin/gestao ve (require_min_role).
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_min_role('gestao');

$pdo = db();
$pageTitle = '🏆 Ranking de Clientes Engajados';

// Periodo: mensal / anual / geral
$periodo = $_GET['periodo'] ?? 'mensal';
if (!in_array($periodo, array('mensal', 'anual', 'geral'), true)) $periodo = 'mensal';

$hoje = date('Y-m-d');
$dtRef = ($periodo === 'mensal') ? date('Y-m-01 00:00:00')
       : (($periodo === 'anual') ? date('Y-01-01 00:00:00') : '2020-01-01 00:00:00');
$dtLabel = ($periodo === 'mensal') ? mb_strtoupper(strftime_local(date('F Y')))
         : (($periodo === 'anual') ? date('Y') : 'Sempre');

function strftime_local($str) {
    $meses = array('January'=>'Janeiro','February'=>'Fevereiro','March'=>'Março','April'=>'Abril',
                   'May'=>'Maio','June'=>'Junho','July'=>'Julho','August'=>'Agosto',
                   'September'=>'Setembro','October'=>'Outubro','November'=>'Novembro','December'=>'Dezembro');
    return strtr($str, $meses);
}

// ═══════════════════════════════════════════════════════════
// COLETA DE DADOS
// ═══════════════════════════════════════════════════════════

$scores = array(); // client_id => ['msg' => X, 'ticket' => Y, 'thread' => Z, 'balcao' => W]

function _acc(&$scores, $cid, $tipo, $qtd) {
    if (!$cid) return;
    $cid = (int)$cid;
    if (!isset($scores[$cid])) $scores[$cid] = array('msg'=>0,'ticket'=>0,'thread'=>0,'balcao'=>0);
    $scores[$cid][$tipo] += (int)$qtd;
}

// 1. Mensagens WhatsApp RECEBIDAS (direção=recebida) — cliente mandou pra nós
try {
    $st = $pdo->prepare(
        "SELECT co.client_id, COUNT(m.id) AS n
         FROM zapi_mensagens m
         JOIN zapi_conversas co ON co.id = m.conversa_id
         WHERE m.direcao = 'recebida'
           AND m.created_at >= ?
           AND co.client_id IS NOT NULL AND co.client_id > 0
         GROUP BY co.client_id"
    );
    $st->execute(array($dtRef));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) _acc($scores, $r['client_id'], 'msg', $r['n']);
} catch (Exception $e) {}

// 2. Chamados abertos no helpdesk (equipe abriu em nome do cliente OU cliente via chamados.php antigo)
try {
    $st = $pdo->prepare(
        "SELECT client_id, COUNT(*) AS n FROM tickets
         WHERE created_at >= ? AND client_id IS NOT NULL AND client_id > 0
         GROUP BY client_id"
    );
    $st->execute(array($dtRef));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) _acc($scores, $r['client_id'], 'ticket', $r['n']);
} catch (Exception $e) {}

// 3. Threads da Central VIP (o cliente entrou no portal e abriu tópico)
try {
    $st = $pdo->prepare(
        "SELECT cliente_id, COUNT(*) AS n FROM salavip_threads
         WHERE criado_em >= ? AND cliente_id IS NOT NULL AND cliente_id > 0
         GROUP BY cliente_id"
    );
    $st->execute(array($dtRef));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) _acc($scores, $r['cliente_id'], 'thread', $r['n']);
} catch (Exception $e) {}

// 4. Balcões virtuais que o cliente compareceu (evento realizado)
try {
    $st = $pdo->prepare(
        "SELECT client_id, COUNT(*) AS n FROM agenda_eventos
         WHERE tipo = 'balcao_virtual'
           AND status = 'realizado'
           AND data_inicio >= ?
           AND client_id IS NOT NULL AND client_id > 0
         GROUP BY client_id"
    );
    $st->execute(array($dtRef));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) _acc($scores, $r['client_id'], 'balcao', $r['n']);
} catch (Exception $e) {}

// Ordenar por score total DESC
$ranking = array();
foreach ($scores as $cid => $s) {
    $total = $s['msg'] + $s['ticket'] + $s['thread'] + $s['balcao'];
    if ($total === 0) continue;
    $ranking[] = array_merge($s, array('client_id' => $cid, 'total' => $total));
}
usort($ranking, function($a, $b) { return $b['total'] - $a['total']; });
$ranking = array_slice($ranking, 0, 30); // top 30

// Buscar nomes/dados dos clientes do ranking
$topIds = array_map(function($r){ return (int)$r['client_id']; }, $ranking);
$clientesInfo = array();
if ($topIds) {
    $ph = implode(',', array_fill(0, count($topIds), '?'));
    $st = $pdo->prepare("SELECT id, name, phone, gender FROM clients WHERE id IN ($ph)");
    $st->execute($topIds);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) $clientesInfo[(int)$c['id']] = $c;
}

// KPIs gerais
$totalClientes = count($ranking);
$totalMsg = array_sum(array_column($ranking, 'msg'));
$totalTicket = array_sum(array_column($ranking, 'ticket'));
$totalThread = array_sum(array_column($ranking, 'thread'));
$totalBalcao = array_sum(array_column($ranking, 'balcao'));

require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.rk-wrap { max-width: 1180px; margin: 0 auto; padding: 0 .5rem; }
.rk-hero {
    background: linear-gradient(135deg, #052228 0%, #0a3238 50%, #B87333 100%);
    color: #fff;
    padding: 2rem 1.6rem;
    border-radius: 20px;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.rk-hero::before {
    content: ''; position: absolute; top: -80px; right: -80px;
    width: 300px; height: 300px; border-radius: 50%;
    background: radial-gradient(circle, rgba(210,154,95,.35), transparent 60%);
    pointer-events: none;
}
.rk-hero h1 {
    margin: 0; font-family: Georgia, 'Times New Roman', serif;
    font-size: clamp(1.7rem, 4vw, 2.4rem); line-height: 1.15;
    font-weight: 600; color: #fff;
}
.rk-hero .lede { margin: .5rem 0 0; opacity: .9; font-size: .95rem; max-width: 46rem; }
.rk-hero .periodo-atual {
    display: inline-block; margin-top: 1rem;
    background: rgba(255,255,255,.13); backdrop-filter: blur(4px);
    padding: 4px 14px; border-radius: 999px;
    font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 700;
}

/* Filtro */
.rk-filtros { display: flex; gap: .4rem; flex-wrap: wrap; margin-bottom: 1.5rem; align-items: center; }
.rk-filtro-chip {
    padding: .55rem 1.1rem; border-radius: 999px; font-size: .82rem; font-weight: 600;
    text-decoration: none; color: #64748b; background: #fff; border: 1.5px solid #e5e7eb;
    transition: all .15s; display: inline-flex; align-items: center; gap: .4rem;
}
.rk-filtro-chip:hover { border-color: #B87333; color: #052228; }
.rk-filtro-chip.ativo {
    background: linear-gradient(135deg, #052228, #0a3238); color: #fff;
    border-color: #052228; box-shadow: 0 4px 12px rgba(5,34,40,.25);
}

/* KPIs */
.rk-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .7rem; margin-bottom: 1.5rem; }
.rk-kpi {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
    padding: 1rem 1.1rem; box-shadow: 0 1px 2px rgba(0,0,0,.02);
}
.rk-kpi-num { font-family: Georgia, serif; font-size: 1.8rem; font-weight: 700; color: #052228; line-height: 1; font-variant-numeric: tabular-nums; }
.rk-kpi-lbl { font-size: .7rem; color: #6b6559; text-transform: uppercase; letter-spacing: .08em; font-weight: 600; margin-top: .3rem; }

/* Pódio */
.rk-podio {
    display: grid; grid-template-columns: 1fr 1.15fr 1fr; gap: .6rem;
    margin-bottom: 2rem; align-items: end;
}
.rk-pod-card {
    background: #fff; border-radius: 14px; padding: 1.1rem .9rem 1rem;
    text-align: center; position: relative;
    box-shadow: 0 4px 20px rgba(5,34,40,.08);
    transition: transform .2s;
}
.rk-pod-card:hover { transform: translateY(-3px); }
.rk-pod-1 { border: 3px solid #f5b800; padding-top: 1.7rem; }
.rk-pod-2 { border: 2px solid #b0b0b0; }
.rk-pod-3 { border: 2px solid #cd7f32; }
.rk-pod-medalha { position: absolute; top: -20px; left: 50%; transform: translateX(-50%); font-size: 2.4rem; filter: drop-shadow(0 4px 8px rgba(0,0,0,.15)); }
.rk-pod-1 .rk-pod-medalha { font-size: 3rem; top: -28px; }
.rk-pod-avatar {
    width: 62px; height: 62px; margin: 0 auto .5rem;
    background: linear-gradient(135deg, #052228, #B87333); color: #fff;
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-family: Georgia, serif; font-size: 1.5rem; font-weight: 700;
    box-shadow: 0 4px 12px rgba(0,0,0,.15);
}
.rk-pod-1 .rk-pod-avatar { width: 78px; height: 78px; font-size: 1.9rem; }
.rk-pod-nome { font-family: Georgia, serif; font-weight: 700; color: #052228; font-size: 1rem; margin: .3rem 0 .1rem; line-height: 1.2; word-break: break-word; }
.rk-pod-1 .rk-pod-nome { font-size: 1.15rem; }
.rk-pod-score { font-family: Georgia, serif; font-size: 1.9rem; font-weight: 700; color: #B87333; margin: .4rem 0 .1rem; font-variant-numeric: tabular-nums; }
.rk-pod-1 .rk-pod-score { font-size: 2.4rem; color: #f5b800; }
.rk-pod-lbl { font-size: .65rem; text-transform: uppercase; letter-spacing: .1em; color: #6b6559; font-weight: 700; }
.rk-pod-breakdown { display: flex; justify-content: center; gap: .5rem; flex-wrap: wrap; margin-top: .6rem; font-size: .68rem; color: #6b6559; }
.rk-pod-b { display: inline-flex; align-items: center; gap: 2px; }

/* Confete no top 1 */
.rk-pod-1::after {
    content: '🎉'; position: absolute; top: 8px; right: 12px;
    font-size: 1.4rem; animation: rkBalance 3s ease-in-out infinite;
}
@keyframes rkBalance {
    0%, 100% { transform: rotate(-10deg); }
    50% { transform: rotate(10deg); }
}

/* Lista do 4 em diante */
.rk-lista { background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; overflow: hidden; }
.rk-item {
    display: grid; grid-template-columns: 40px 40px 1fr auto auto;
    gap: 1rem; align-items: center;
    padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9;
    transition: background .12s;
}
.rk-item:last-child { border-bottom: none; }
.rk-item:hover { background: #faf7f2; }
.rk-item-pos { font-family: Georgia, serif; font-size: 1.05rem; font-weight: 700; color: #B87333; text-align: center; font-variant-numeric: tabular-nums; }
.rk-item-avatar {
    width: 36px; height: 36px; background: linear-gradient(135deg, #f5f5f5, #e5e5e5);
    color: #6b6559; border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-family: Georgia, serif; font-size: .82rem; font-weight: 700;
}
.rk-item-nome { font-weight: 600; color: #052228; font-size: .9rem; line-height: 1.3; }
.rk-item-breakdown { display: flex; gap: .55rem; font-size: .72rem; color: #6b6559; flex-wrap: wrap; }
.rk-item-breakdown span { display: inline-flex; align-items: center; gap: 3px; white-space: nowrap; }
.rk-item-score {
    font-family: Georgia, serif; font-size: 1.15rem; font-weight: 700;
    color: #052228; font-variant-numeric: tabular-nums;
    padding: 4px 12px; background: #faf7f2; border-radius: 8px; min-width: 3em; text-align: center;
}
.rk-item-abrir {
    padding: 4px 10px; background: #f8fafc; border-radius: 6px; font-size: .7rem;
    color: #052228; text-decoration: none; font-weight: 600; border: 1px solid #e5e7eb;
}
.rk-item-abrir:hover { background: #052228; color: #fff; border-color: #052228; }

.rk-vazio {
    text-align: center; padding: 3rem 1.5rem; background: #fff; border-radius: 14px; border: 1px solid #e5e7eb;
    color: #6b6559;
}
.rk-vazio-icone { font-size: 3rem; margin-bottom: .5rem; }

.rk-mimo-hint {
    margin-top: 2rem; padding: 1.2rem 1.4rem;
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border-radius: 14px; border: 1px solid #fbbf24;
    display: flex; gap: 1rem; align-items: center;
}
.rk-mimo-hint-icon { font-size: 2.2rem; }
.rk-mimo-hint-text strong { color: #78350f; font-family: Georgia, serif; }
.rk-mimo-hint-text { color: #78350f; font-size: .88rem; line-height: 1.5; }

@media (max-width: 600px) {
    .rk-podio { grid-template-columns: 1fr; }
    .rk-pod-2, .rk-pod-3 { order: 2; }
    .rk-item { grid-template-columns: 32px 36px 1fr auto; gap: .5rem; }
    .rk-item-abrir { display: none; }
    .rk-item-breakdown { font-size: .68rem; gap: .35rem; }
}
</style>

<div class="rk-wrap">
    <div class="rk-hero">
        <h1>🏆 Ranking de Clientes Engajados</h1>
        <p class="lede">Quem mais interagiu com o escritório neste período. Bora premiar os campeões no final do ano com um mimo especial! 🎁</p>
        <div class="periodo-atual"><?= e($dtLabel) ?></div>
    </div>

    <div class="rk-filtros">
        <a href="?periodo=mensal" class="rk-filtro-chip <?= $periodo === 'mensal' ? 'ativo' : '' ?>">📅 Este mês</a>
        <a href="?periodo=anual" class="rk-filtro-chip <?= $periodo === 'anual' ? 'ativo' : '' ?>">📆 Este ano</a>
        <a href="?periodo=geral" class="rk-filtro-chip <?= $periodo === 'geral' ? 'ativo' : '' ?>">🌍 Sempre</a>
        <span style="margin-left:auto;font-size:.72rem;color:#6b6559;font-style:italic;">
            Cálculo = mensagens WhatsApp recebidas + chamados + Central VIP + balcões virtuais
        </span>
    </div>

    <?php if (empty($ranking)): ?>
        <div class="rk-vazio">
            <div class="rk-vazio-icone">😴</div>
            <h3 style="margin:0 0 .3rem;color:#052228;">Silêncio total neste período</h3>
            <p style="margin:0;font-size:.85rem;">Nenhuma interação registrada — pode ser que ainda não haja dados suficientes ou os clientes andam quietos.</p>
        </div>
    <?php else: ?>

    <!-- KPIs -->
    <div class="rk-kpis">
        <div class="rk-kpi">
            <div class="rk-kpi-num"><?= number_format($totalClientes, 0, ',', '.') ?></div>
            <div class="rk-kpi-lbl">👥 Clientes ativos</div>
        </div>
        <div class="rk-kpi">
            <div class="rk-kpi-num"><?= number_format($totalMsg, 0, ',', '.') ?></div>
            <div class="rk-kpi-lbl">💬 Mensagens WA</div>
        </div>
        <div class="rk-kpi">
            <div class="rk-kpi-num"><?= number_format($totalTicket + $totalThread, 0, ',', '.') ?></div>
            <div class="rk-kpi-lbl">🎫 Chamados</div>
        </div>
        <div class="rk-kpi">
            <div class="rk-kpi-num"><?= number_format($totalBalcao, 0, ',', '.') ?></div>
            <div class="rk-kpi-lbl">🏛️ Balcões virtuais</div>
        </div>
    </div>

    <!-- Pódio dos 3 primeiros -->
    <?php
    $medalhas = array(0 => '🥇', 1 => '🥈', 2 => '🥉');
    $podioMap = array(1 => 0, 0 => 1, 2 => 2); // meio=1o, esq=2o, dir=3o
    $ordemPodio = array(1, 0, 2); // renderiza esq(2o), meio(1o), dir(3o)
    ?>
    <?php if (count($ranking) >= 3): ?>
    <div class="rk-podio">
        <?php foreach ($ordemPodio as $idxRanking):
            if (!isset($ranking[$idxRanking])) continue;
            $r = $ranking[$idxRanking];
            $c = $clientesInfo[(int)$r['client_id']] ?? array('name' => 'Cliente #' . $r['client_id']);
            $iniciais = mb_strtoupper(mb_substr(preg_replace('/[^a-zA-ZÀ-ÿ]/', '', $c['name']), 0, 1) . mb_substr(preg_replace('/[^\s]+\s/', '', $c['name']), 0, 1));
            $posClasse = 'rk-pod-' . ($idxRanking + 1);
        ?>
        <div class="rk-pod-card <?= $posClasse ?>">
            <div class="rk-pod-medalha"><?= $medalhas[$idxRanking] ?></div>
            <div class="rk-pod-avatar"><?= e($iniciais ?: '?') ?></div>
            <div class="rk-pod-nome"><?= e(mb_substr($c['name'], 0, 28)) ?></div>
            <div class="rk-pod-lbl">Score total</div>
            <div class="rk-pod-score"><?= number_format($r['total'], 0, ',', '.') ?></div>
            <div class="rk-pod-breakdown">
                <?php if ($r['msg']): ?><span class="rk-pod-b">💬 <?= $r['msg'] ?></span><?php endif; ?>
                <?php if ($r['ticket']): ?><span class="rk-pod-b">🎫 <?= $r['ticket'] ?></span><?php endif; ?>
                <?php if ($r['thread']): ?><span class="rk-pod-b">🔒 <?= $r['thread'] ?></span><?php endif; ?>
                <?php if ($r['balcao']): ?><span class="rk-pod-b">🏛️ <?= $r['balcao'] ?></span><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Lista do 4 em diante (ou tudo se < 3) -->
    <?php
    $iniciarEm = count($ranking) >= 3 ? 3 : 0;
    $restante = array_slice($ranking, $iniciarEm);
    if (!empty($restante)):
    ?>
    <div class="rk-lista">
        <?php foreach ($restante as $i => $r):
            $c = $clientesInfo[(int)$r['client_id']] ?? array('name' => 'Cliente #' . $r['client_id']);
            $posReal = $iniciarEm + $i + 1;
            $iniciais = mb_strtoupper(mb_substr(preg_replace('/[^a-zA-ZÀ-ÿ]/', '', $c['name']), 0, 1) . mb_substr(preg_replace('/[^\s]+\s/', '', $c['name']), 0, 1));
        ?>
        <div class="rk-item">
            <div class="rk-item-pos">#<?= $posReal ?></div>
            <div class="rk-item-avatar"><?= e($iniciais ?: '?') ?></div>
            <div>
                <div class="rk-item-nome"><?= e($c['name']) ?></div>
                <div class="rk-item-breakdown">
                    <?php if ($r['msg']): ?><span>💬 <?= $r['msg'] ?> msg</span><?php endif; ?>
                    <?php if ($r['ticket']): ?><span>🎫 <?= $r['ticket'] ?> chamado<?= $r['ticket']>1?'s':'' ?></span><?php endif; ?>
                    <?php if ($r['thread']): ?><span>🔒 <?= $r['thread'] ?> Central VIP</span><?php endif; ?>
                    <?php if ($r['balcao']): ?><span>🏛️ <?= $r['balcao'] ?> balcão</span><?php endif; ?>
                </div>
            </div>
            <div class="rk-item-score"><?= number_format($r['total'], 0, ',', '.') ?></div>
            <a href="<?= url('modules/clientes/ver.php?id=' . (int)$r['client_id']) ?>" class="rk-item-abrir">ficha →</a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="rk-mimo-hint">
        <div class="rk-mimo-hint-icon">🎁</div>
        <div class="rk-mimo-hint-text">
            <strong>Ideia pra fim de ano:</strong> mande um mimo pros top 3 do ano — chocolate,
            vinho, cartão personalizado, cesta. Quem é engajado no relacionamento merece
            reconhecimento. Uma lembrancinha vira boca-a-boca positivo e retenção.
        </div>
    </div>

    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
