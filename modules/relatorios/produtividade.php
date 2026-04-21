<?php
/**
 * Relatório de Produtividade por Usuário
 *
 * Métricas mensais por usuário:
 * - Tarefas concluídas (no prazo vs atrasadas)
 * - Documentos/Petições gerados (document_history)
 * - Processos distribuídos (cases.responsible_user_id + updated_at no mês)
 * - Prazos cumpridos vs perdidos (prazos_processuais.concluido)
 * - Contratos fechados (pipeline_leads.stage = 'contrato_assinado' + converted_at no mês)
 *
 * Acesso: admin + gestao apenas.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    http_response_code(403);
    exit('Acesso negado — só gestão/admin.');
}

$pdo = db();

// Filtros
$mes = (int)($_GET['mes'] ?? date('n'));
$ano = (int)($_GET['ano'] ?? date('Y'));
if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($ano < 2020 || $ano > 2100) $ano = (int)date('Y');
$userFiltro = (int)($_GET['user'] ?? 0);

$mesAno = sprintf('%04d-%02d', $ano, $mes);

// Usuários ativos
$users = $pdo->query("SELECT id, name, role FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Carrega métricas por usuário
$metricas = array();
foreach ($users as $u) {
    if ($userFiltro && (int)$u['id'] !== $userFiltro) continue;
    $uid = (int)$u['id'];

    $m = array(
        'user' => $u,
        'tarefas_no_prazo' => 0,
        'tarefas_atrasadas' => 0,
        'tarefas_total'     => 0,
        'documentos'        => 0,
        'processos_distrib' => 0,
        'prazos_cumpridos'  => 0,
        'prazos_perdidos'   => 0,
        'contratos'         => 0,
        'pontos_gamif'      => 0,
    );

    // Tarefas concluídas no mês
    $t = $pdo->prepare(
        "SELECT
           SUM(CASE WHEN due_date IS NULL OR completed_at <= due_date THEN 1 ELSE 0 END) AS no_prazo,
           SUM(CASE WHEN due_date IS NOT NULL AND completed_at > due_date THEN 1 ELSE 0 END) AS atrasadas,
           COUNT(*) AS total
         FROM case_tasks
         WHERE assigned_to = ? AND status = 'concluido' AND DATE_FORMAT(completed_at, '%Y-%m') = ?"
    );
    $t->execute(array($uid, $mesAno));
    $tr = $t->fetch();
    $m['tarefas_no_prazo']  = (int)($tr['no_prazo'] ?? 0);
    $m['tarefas_atrasadas'] = (int)($tr['atrasadas'] ?? 0);
    $m['tarefas_total']     = (int)($tr['total'] ?? 0);

    // Documentos/petições geradas
    try {
        $d = $pdo->prepare("SELECT COUNT(*) FROM document_history WHERE generated_by = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
        $d->execute(array($uid, $mesAno));
        $m['documentos'] = (int)$d->fetchColumn();
    } catch (Exception $e) {}

    // Processos distribuídos no mês
    try {
        $p = $pdo->prepare(
            "SELECT COUNT(*) FROM cases
             WHERE responsible_user_id = ? AND status = 'distribuido'
               AND DATE_FORMAT(updated_at, '%Y-%m') = ?"
        );
        $p->execute(array($uid, $mesAno));
        $m['processos_distrib'] = (int)$p->fetchColumn();
    } catch (Exception $e) {}

    // Prazos: cumpridos (concluido=1 no mês) vs perdidos (prazo_fatal no mês E não concluído E já passou)
    try {
        $pz = $pdo->prepare(
            "SELECT
               SUM(CASE WHEN concluido = 1 AND DATE_FORMAT(concluido_em, '%Y-%m') = ? THEN 1 ELSE 0 END) AS cumpridos,
               SUM(CASE WHEN concluido = 0 AND prazo_fatal < CURDATE() AND DATE_FORMAT(prazo_fatal, '%Y-%m') = ? THEN 1 ELSE 0 END) AS perdidos
             FROM prazos_processuais
             WHERE responsavel_id = ?"
        );
        $pz->execute(array($mesAno, $mesAno, $uid));
        $pzr = $pz->fetch();
        $m['prazos_cumpridos'] = (int)($pzr['cumpridos'] ?? 0);
        $m['prazos_perdidos']  = (int)($pzr['perdidos'] ?? 0);
    } catch (Exception $e) {}

    // Contratos fechados (comercial)
    try {
        $c = $pdo->prepare(
            "SELECT COUNT(*) FROM pipeline_leads
             WHERE assigned_to = ? AND stage = 'contrato_assinado'
               AND DATE_FORMAT(converted_at, '%Y-%m') = ?"
        );
        $c->execute(array($uid, $mesAno));
        $m['contratos'] = (int)$c->fetchColumn();
    } catch (Exception $e) {}

    // Pontos de gamificação do mês (qualquer área)
    try {
        $g = $pdo->prepare(
            "SELECT COALESCE(SUM(pontos), 0) FROM gamificacao_pontos
             WHERE user_id = ? AND mes = ? AND ano = ?"
        );
        $g->execute(array($uid, $mes, $ano));
        $m['pontos_gamif'] = (int)$g->fetchColumn();
    } catch (Exception $e) {}

    // Score composto: pondera métricas pra ranking
    $m['score'] = $m['tarefas_no_prazo'] * 2
                + $m['documentos'] * 3
                + $m['processos_distrib'] * 5
                + $m['prazos_cumpridos'] * 4
                + $m['contratos'] * 10
                - $m['tarefas_atrasadas'] * 2
                - $m['prazos_perdidos'] * 15;

    $metricas[] = $m;
}

// Ordenar por score desc pro ranking
usort($metricas, function($a, $b) { return $b['score'] - $a['score']; });

$pageTitle = 'Produtividade — ' . sprintf('%02d/%04d', $mes, $ano);
require_once APP_ROOT . '/templates/layout_start.php';

$meses = array(1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro');
?>

<style>
.prod-filtros { background:#fff; border:1px solid var(--border); border-radius:var(--radius-lg); padding:1rem 1.25rem; margin-bottom:1rem; display:flex; gap:.75rem; flex-wrap:wrap; align-items:center; }
.prod-filtros select { padding:.4rem .6rem; border:1px solid var(--border); border-radius:8px; font-size:.85rem; background:#fff; }
.prod-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(290px,1fr)); gap:1rem; }
.prod-card { background:#fff; border:1px solid var(--border); border-radius:var(--radius-lg); padding:1rem 1.15rem; position:relative; }
.prod-card.rank-1 { border-top:3px solid #eab308; }
.prod-card.rank-2 { border-top:3px solid #94a3b8; }
.prod-card.rank-3 { border-top:3px solid #B87333; }
.prod-rank-badge { position:absolute; top:-10px; right:12px; background:var(--petrol-900); color:#fff; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; }
.prod-rank-badge.rank-1 { background:#eab308; }
.prod-rank-badge.rank-2 { background:#94a3b8; }
.prod-rank-badge.rank-3 { background:#B87333; }
.prod-user { display:flex; align-items:center; gap:.6rem; margin-bottom:.6rem; padding-bottom:.6rem; border-bottom:1px solid var(--border); }
.prod-avatar { width:38px; height:38px; border-radius:50%; background:var(--petrol-900); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.85rem; }
.prod-nome { font-size:.92rem; font-weight:700; color:var(--petrol-900); }
.prod-role { font-size:.68rem; color:#64748b; text-transform:uppercase; }
.prod-metrica { display:flex; justify-content:space-between; align-items:center; padding:.3rem 0; font-size:.82rem; }
.prod-metrica-label { color:#475569; display:flex; align-items:center; gap:.35rem; }
.prod-metrica-val { font-weight:700; color:var(--petrol-900); }
.prod-metrica-val.neg { color:#dc2626; }
.prod-metrica-val.pos { color:#059669; }
.prod-score { background:#f1f5f9; border-radius:8px; padding:.5rem .7rem; margin-top:.5rem; display:flex; justify-content:space-between; align-items:center; }
.prod-score-label { font-size:.68rem; text-transform:uppercase; color:#64748b; font-weight:700; }
.prod-score-val { font-size:1.2rem; font-weight:800; color:var(--petrol-900); }
</style>

<h2 style="font-size:1.3rem;font-weight:800;color:var(--petrol-900);margin-bottom:.75rem;">📊 Produtividade — <?= $meses[$mes] ?>/<?= $ano ?></h2>

<!-- Filtros -->
<form method="GET" class="prod-filtros">
    <label style="font-size:.78rem;color:#64748b;">Mês:</label>
    <select name="mes">
        <?php foreach ($meses as $mn => $ml): ?>
            <option value="<?= $mn ?>" <?= $mes === $mn ? 'selected' : '' ?>><?= $ml ?></option>
        <?php endforeach; ?>
    </select>
    <label style="font-size:.78rem;color:#64748b;">Ano:</label>
    <select name="ano">
        <?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?>
            <option value="<?= $y ?>" <?= $ano === $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
    <label style="font-size:.78rem;color:#64748b;">Usuário:</label>
    <select name="user">
        <option value="0">Todos</option>
        <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $userFiltro === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm" style="background:#052228;">Filtrar</button>
    <a href="<?= module_url('relatorios', 'produtividade.php') ?>" class="btn btn-outline btn-sm">Limpar</a>
</form>

<!-- Cards por usuário -->
<div class="prod-grid">
<?php foreach ($metricas as $idx => $m):
    $rank = $idx + 1;
    $u = $m['user'];
    $iniciais = '';
    $parts = explode(' ', $u['name']);
    if (count($parts) >= 2) $iniciais = mb_strtoupper(mb_substr($parts[0],0,1) . mb_substr(end($parts),0,1));
    else $iniciais = mb_strtoupper(mb_substr($u['name'],0,2));

    $semDados = ($m['tarefas_total'] + $m['documentos'] + $m['processos_distrib'] + $m['contratos']) === 0 && $m['score'] === 0;
    if ($semDados && !$userFiltro) continue; // Esconde quem não teve atividade (só quando lista todos)
?>
    <div class="prod-card <?= $rank <= 3 ? 'rank-' . $rank : '' ?>">
        <span class="prod-rank-badge <?= $rank <= 3 ? 'rank-' . $rank : '' ?>">
            <?= $rank === 1 ? '🥇 1º' : ($rank === 2 ? '🥈 2º' : ($rank === 3 ? '🥉 3º' : '#' . $rank)) ?>
        </span>
        <div class="prod-user">
            <div class="prod-avatar"><?= e($iniciais) ?></div>
            <div style="flex:1;min-width:0;">
                <div class="prod-nome"><?= e($u['name']) ?></div>
                <div class="prod-role"><?= e($u['role']) ?></div>
            </div>
        </div>

        <div class="prod-metrica">
            <span class="prod-metrica-label">✅ Tarefas no prazo</span>
            <span class="prod-metrica-val pos"><?= $m['tarefas_no_prazo'] ?></span>
        </div>
        <?php if ($m['tarefas_atrasadas']): ?>
        <div class="prod-metrica">
            <span class="prod-metrica-label">⏰ Tarefas atrasadas</span>
            <span class="prod-metrica-val neg"><?= $m['tarefas_atrasadas'] ?></span>
        </div>
        <?php endif; ?>
        <div class="prod-metrica">
            <span class="prod-metrica-label">⚖️ Processos distribuídos</span>
            <span class="prod-metrica-val"><?= $m['processos_distrib'] ?></span>
        </div>
        <div class="prod-metrica">
            <span class="prod-metrica-label">📄 Documentos gerados</span>
            <span class="prod-metrica-val"><?= $m['documentos'] ?></span>
        </div>
        <?php if ($m['prazos_cumpridos'] || $m['prazos_perdidos']): ?>
        <div class="prod-metrica">
            <span class="prod-metrica-label">🎯 Prazos cumpridos</span>
            <span class="prod-metrica-val pos"><?= $m['prazos_cumpridos'] ?></span>
        </div>
        <?php endif; ?>
        <?php if ($m['prazos_perdidos']): ?>
        <div class="prod-metrica">
            <span class="prod-metrica-label">🔴 Prazos perdidos</span>
            <span class="prod-metrica-val neg"><?= $m['prazos_perdidos'] ?></span>
        </div>
        <?php endif; ?>
        <?php if (in_array($u['role'], array('admin','gestao','comercial'))): ?>
        <div class="prod-metrica">
            <span class="prod-metrica-label">📋 Contratos fechados</span>
            <span class="prod-metrica-val pos"><?= $m['contratos'] ?></span>
        </div>
        <?php endif; ?>
        <div class="prod-metrica">
            <span class="prod-metrica-label">🎮 Pontos (mês)</span>
            <span class="prod-metrica-val"><?= $m['pontos_gamif'] ?></span>
        </div>

        <div class="prod-score">
            <span class="prod-score-label">Score composto</span>
            <span class="prod-score-val"><?= $m['score'] ?></span>
        </div>
    </div>
<?php endforeach; ?>
</div>

<div style="margin-top:1.2rem;padding:.85rem 1rem;background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius-md);font-size:.75rem;color:#64748b;">
    <strong>Score composto:</strong> tarefa no prazo = 2 · doc gerado = 3 · processo distribuído = 5 · prazo cumprido = 4 · contrato fechado = 10 · tarefa atrasada = −2 · prazo perdido = −15
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
