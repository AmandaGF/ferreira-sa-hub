<?php
/**
 * Admin: Entregas mensais dos prestadores de serviços.
 * Aprovar / pedir ajuste / comentar. Filtros por colaborador + mês.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin') && !has_role('gestao')) { flash_set('error','Apenas gestão/admin.'); redirect(url('modules/dashboard/')); }

$pdo = db();

// Self-heal (espelho do publico)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS colaboradores_entregas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        colaborador_id INT NOT NULL,
        mes_ref CHAR(7) NOT NULL,
        tipo VARCHAR(30) NOT NULL DEFAULT 'outro',
        descricao VARCHAR(500) NOT NULL,
        link VARCHAR(500) NULL,
        data_entrega DATE NULL,
        metricas TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pendente',
        comentario_admin TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_col_mes (colaborador_id, mes_ref),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $id = (int)($_POST['id'] ?? 0);
    $acao = $_POST['acao'] ?? '';
    if ($id && in_array($acao, array('aprovar','ajustar','pendente'), true)) {
        $statusNovo = $acao === 'aprovar' ? 'aprovada' : ($acao === 'ajustar' ? 'ajustar' : 'pendente');
        $com = trim($_POST['comentario_admin'] ?? '');
        $pdo->prepare("UPDATE colaboradores_entregas SET status=?, comentario_admin=? WHERE id=?")
            ->execute(array($statusNovo, $com ?: null, $id));
        audit_log('onb_entrega_'.$acao, 'colaboradores_entregas', $id);
        flash_set('success', 'Atualizado.');
    } elseif ($id && $acao === 'excluir') {
        $pdo->prepare("DELETE FROM colaboradores_entregas WHERE id=?")->execute(array($id));
        audit_log('onb_entrega_del', 'colaboradores_entregas', $id);
        flash_set('success', 'Removido.');
    }
    redirect($_SERVER['HTTP_REFERER'] ?? module_url('admin','onboarding_entregas.php'));
}

// Filtros
$col = (int)($_GET['col'] ?? 0);
$mes = $_GET['mes'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $mes)) $mes = date('Y-m');

$sql = "SELECT e.*, c.nome_completo, c.perfil_cargo, c.foto_path
        FROM colaboradores_entregas e
        INNER JOIN colaboradores_onboarding c ON c.id = e.colaborador_id
        WHERE e.mes_ref = ?";
$params = array($mes);
if ($col > 0) { $sql .= " AND e.colaborador_id = ?"; $params[] = $col; }
$sql .= " ORDER BY c.nome_completo, e.data_entrega DESC, e.id DESC";
$st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll();

// Lista de colaboradores prestadores (pra filtro)
$colabs = $pdo->query("SELECT id, nome_completo, perfil_cargo FROM colaboradores_onboarding
                       WHERE perfil_cargo IN ('prestador_pj','prestador_mei','prestador_autonomo') AND status != 'arquivado'
                       ORDER BY nome_completo")->fetchAll();

$totais = array('total'=>count($rows), 'aprov'=>0, 'pend'=>0, 'aj'=>0);
foreach ($rows as $r) {
    if ($r['status']==='aprovada') $totais['aprov']++;
    elseif ($r['status']==='ajustar') $totais['aj']++;
    else $totais['pend']++;
}

$mesAnt = date('Y-m', strtotime($mes . '-01 -1 month'));
$mesProx = date('Y-m', strtotime($mes . '-01 +1 month'));
$mesLabel = (function($m){
    $meses = array(1=>'Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro');
    list($y,$mm) = explode('-', $m); return $meses[(int)$mm] . '/' . $y;
})($mes);

$tipoLabel = array('post'=>'📷 Post','reels'=>'🎥 Reels','campanha'=>'📣 Campanha','relatorio'=>'📊 Relatório','reuniao'=>'🤝 Reunião','design'=>'🎨 Design','outro'=>'• Outro');
$statusBadge = array('pendente'=>array('⏳ Pendente','#f59e0b'),'aprovada'=>array('✓ Aprovada','#059669'),'ajustar'=>array('↻ Ajustar','#dc2626'));

$pageTitle = 'Entregas dos prestadores';
require_once APP_ROOT . '/templates/layout_start.php';
?>
<style>
.ent-row{background:#fff;border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px;border-left:4px solid #ccc}
.ent-row.aprov{border-left-color:#059669}
.ent-row.aj{border-left-color:#dc2626}
.ent-row.pend{border-left-color:#f59e0b}
.ent-head{display:flex;justify-content:space-between;align-items:flex-start;gap:.6rem;flex-wrap:wrap}
.ent-meta{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;font-size:.78rem;color:var(--text-muted)}
.ent-meta .who{font-weight:700;color:var(--petrol-900)}
.ent-tipo{font-size:.7rem;background:var(--petrol-900);color:#fff;padding:.18rem .55rem;border-radius:5px;font-weight:600}
.ent-status{font-size:.72rem;padding:.2rem .55rem;border-radius:5px;font-weight:700;color:#fff}
.ent-desc{margin:.5rem 0;font-size:.95rem}
.ent-met{font-size:.82rem;color:var(--text-muted);font-style:italic}
.ent-link{font-size:.78rem}
.ent-com{margin-top:.4rem;padding:.5rem .7rem;background:#fef3c7;border-radius:6px;font-size:.84rem;color:#854d0e}
.ent-acts{margin-top:.7rem;display:flex;flex-wrap:wrap;gap:.4rem;align-items:flex-start}
.ent-acts textarea{flex:1;min-width:220px;padding:.45rem .55rem;border:1px solid var(--border);border-radius:6px;font-family:inherit;font-size:.83rem;min-height:38px}
.ent-acts button{padding:.4rem .8rem;border-radius:6px;font-weight:600;font-size:.78rem;cursor:pointer;border:1px solid;background:#fff}
.btn-aprov{border-color:#059669;color:#059669}
.btn-aprov:hover{background:#059669;color:#fff}
.btn-ajust{border-color:#dc2626;color:#dc2626}
.btn-ajust:hover{background:#dc2626;color:#fff}
.btn-pend{border-color:#6f7370;color:#6f7370}
.btn-del{border-color:#6f7370;color:#6f7370;opacity:.55}
.btn-del:hover{opacity:1;background:#dc2626;border-color:#dc2626;color:#fff}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:.6rem;margin-bottom:1rem}
.kpi{background:#fff;border:1px solid var(--border);border-radius:10px;padding:.7rem 1rem;text-align:center}
.kpi .n{font-size:1.5rem;font-weight:800;color:var(--petrol-900)}
.kpi .l{font-size:.7rem;color:var(--text-muted);text-transform:uppercase}
@media(max-width:680px){.kpis{grid-template-columns:repeat(2,1fr)}}
</style>

<h2 style="margin-bottom:1rem;">📈 Entregas dos prestadores</h2>

<form method="GET" style="display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1rem;">
    <div>
        <label style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Mês</label>
        <div style="display:flex;align-items:center;gap:.4rem;">
            <a href="?col=<?= $col ?>&mes=<?= $mesAnt ?>" class="btn btn-outline btn-sm">◀</a>
            <input type="month" name="mes" value="<?= e($mes) ?>" onchange="this.form.submit()" style="padding:.45rem .6rem;border:1px solid var(--border);border-radius:8px;font-family:inherit;">
            <a href="?col=<?= $col ?>&mes=<?= $mesProx ?>" class="btn btn-outline btn-sm">▶</a>
        </div>
    </div>
    <div style="flex:1;min-width:200px;">
        <label style="font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;">Prestador</label>
        <select name="col" onchange="this.form.submit()" style="width:100%;padding:.5rem .6rem;border:1px solid var(--border);border-radius:8px;">
            <option value="0">— Todos —</option>
            <?php foreach ($colabs as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $col===(int)$c['id']?'selected':'' ?>><?= e($c['nome_completo']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="kpis">
    <div class="kpi"><div class="n"><?= $totais['total'] ?></div><div class="l">entregas em <?= $mesLabel ?></div></div>
    <div class="kpi"><div class="n" style="color:#059669;"><?= $totais['aprov'] ?></div><div class="l">aprovadas</div></div>
    <div class="kpi"><div class="n" style="color:#f59e0b;"><?= $totais['pend'] ?></div><div class="l">pendentes</div></div>
    <div class="kpi"><div class="n" style="color:#dc2626;"><?= $totais['aj'] ?></div><div class="l">ajustar</div></div>
</div>

<?php if (empty($rows)): ?>
    <div class="card"><div class="card-body" style="text-align:center;padding:2rem;color:var(--text-muted);">Nenhuma entrega <?= $col ? 'desse prestador' : '' ?> em <?= $mesLabel ?>.</div></div>
<?php else: foreach ($rows as $e):
    $cls = $e['status']==='aprovada'?'aprov':($e['status']==='ajustar'?'aj':'pend');
    list($sl,$sc) = $statusBadge[$e['status']] ?? array('—','#888');
?>
<div class="ent-row <?= $cls ?>">
    <div class="ent-head">
        <div>
            <div class="ent-meta">
                <span class="who"><?= e($e['nome_completo']) ?></span>
                <span class="ent-tipo"><?= e($tipoLabel[$e['tipo']] ?? $e['tipo']) ?></span>
                <span><?= $e['data_entrega'] ? date('d/m/Y', strtotime($e['data_entrega'])) : '' ?></span>
            </div>
        </div>
        <span class="ent-status" style="background:<?= $sc ?>;"><?= $sl ?></span>
    </div>
    <div class="ent-desc"><?= nl2br(e($e['descricao'])) ?></div>
    <?php if ($e['metricas']): ?><div class="ent-met"><?= e($e['metricas']) ?></div><?php endif; ?>
    <?php if ($e['link']): ?><div class="ent-link">🔗 <a href="<?= e($e['link']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($e['link'], 0, 70, '…')) ?></a></div><?php endif; ?>
    <?php if ($e['comentario_admin']): ?><div class="ent-com">💬 <?= nl2br(e($e['comentario_admin'])) ?></div><?php endif; ?>
    <form method="POST" class="ent-acts">
        <?= csrf_input() ?>
        <input type="hidden" name="id" value="<?= $e['id'] ?>">
        <textarea name="comentario_admin" placeholder="Comentário (opcional)"><?= e($e['comentario_admin'] ?? '') ?></textarea>
        <button type="submit" name="acao" value="aprovar" class="btn-aprov">✓ Aprovar</button>
        <button type="submit" name="acao" value="ajustar" class="btn-ajust">↻ Pedir ajuste</button>
        <button type="submit" name="acao" value="pendente" class="btn-pend">⏳ Pendente</button>
        <button type="submit" name="acao" value="excluir" class="btn-del" onclick="return confirm('Excluir entrega?')">🗑</button>
    </form>
</div>
<?php endforeach; endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
