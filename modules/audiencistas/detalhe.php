<?php
/**
 * Audiencistas — Detalhe / Acerto financeiro por audiencista.
 *
 * Mostra cabeçalho com totais (a pagar, pago, saldo, audiências por status,
 * nota média) + lista cronológica de todas as audiências dela com botões
 * inline pra Marcar Pago / Avaliar / Substab.
 *
 * Acesso via /modules/audiencistas/?id=X ou clicando no card de uma audiencista.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('audiencistas');

$pdo = db();
$audId = (int)($_GET['id'] ?? 0);
if (!$audId) { http_response_code(404); die('Audiencista não informada.'); }

$st = $pdo->prepare("SELECT * FROM audiencistas WHERE id=?");
$st->execute(array($audId));
$aud = $st->fetch();
if (!$aud) { http_response_code(404); die('Audiencista não encontrada.'); }

$pageTitle = '👩‍⚖️ ' . $aud['nome'];

// Filtro de período (default: TODAS, mas botões pra "este mês" / "mês anterior")
$mes = $_GET['mes'] ?? '';
$ano = $_GET['ano'] ?? '';
$wherePeriodo = ''; $paramsPeriodo = array();
if ($mes && $ano) {
    $wherePeriodo = " AND YEAR(COALESCE(au.data_hora, au.created_at))=? AND MONTH(COALESCE(au.data_hora, au.created_at))=?";
    $paramsPeriodo = array((int)$ano, (int)$mes);
}

$sql = "SELECT au.*, cl.name AS client_name, c.case_number, c.title AS case_title
        FROM audiencias au
        LEFT JOIN clients cl ON cl.id = au.client_id
        LEFT JOIN cases c ON c.id = au.case_id
        WHERE au.audiencista_id = ? $wherePeriodo
        ORDER BY COALESCE(au.data_hora, au.created_at) DESC";
$st = $pdo->prepare($sql);
$st->execute(array_merge(array($audId), $paramsPeriodo));
$lista = $st->fetchAll();

// Totalizadores
$totalAPagar = 0; $totalPago = 0; $qtdRealizadas = 0; $qtdPagas = 0; $somaNotas = 0; $qtdAvaliadas = 0;
$porStatus = array('aberta' => 0, 'designada' => 0, 'realizada' => 0, 'cancelada' => 0);
foreach ($lista as $r) {
    $porStatus[$r['status']] = ($porStatus[$r['status']] ?? 0) + 1;
    if ($r['status'] === 'cancelada') continue;
    $valor = $r['valor_cents'] !== null ? (int)$r['valor_cents'] : (int)($aud['valor_medio_cents'] ?? 0);
    if ($r['pago_em']) {
        $totalPago += (int)($r['pago_valor_cents'] !== null ? $r['pago_valor_cents'] : $valor);
        $qtdPagas++;
    } else {
        $totalAPagar += $valor;
    }
    if ($r['status'] === 'realizada') $qtdRealizadas++;
    if ($r['avaliacao_nota']) { $somaNotas += (int)$r['avaliacao_nota']; $qtdAvaliadas++; }
}
$saldoDevedor = $totalAPagar;
$notaMedia = $qtdAvaliadas > 0 ? $somaNotas / $qtdAvaliadas : 0;

function aud_money_d($cents) { return $cents !== null ? 'R$ ' . number_format($cents / 100, 2, ',', '.') : '—'; }

$csrf = generate_csrf_token();
$STATUS = array('aberta' => 'Aberta', 'designada' => 'Designada', 'realizada' => 'Realizada', 'cancelada' => 'Cancelada');
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.dt-head { background:#fff; border-radius:12px; padding:18px 22px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:16px; }
.dt-kpis { display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin-top:14px; }
.dt-kpi { background:#f8fafc; border-radius:10px; padding:10px 14px; border-left:4px solid #b87333; }
.dt-kpi.verde { border-left-color:#15803d; } .dt-kpi.azul { border-left-color:#1e40af; }
.dt-kpi.laranja { border-left-color:#b45309; } .dt-kpi.cinza { border-left-color:#64748b; }
.dt-kpi h4 { margin:0 0 4px; font-size:.74rem; text-transform:uppercase; color:#64748b; letter-spacing:.3px; }
.dt-kpi .v { font-size:1.35rem; font-weight:800; color:#0f3d3e; }
.dt-kpi .sub { font-size:.74rem; color:#888; margin-top:2px; }
.dt-row { background:#fff; border-radius:10px; padding:12px 14px; box-shadow:0 1px 2px rgba(0,0,0,.05); margin-bottom:10px; border-left:4px solid #e2e8f0; }
.dt-row.paga { border-left-color:#15803d; }
.dt-row.pendente { border-left-color:#b45309; }
.dt-row.cancelada { border-left-color:#94a3b8; opacity:.7; }
.au-chip { display:inline-block; padding:2px 9px; border-radius:999px; font-size:.72rem; font-weight:700; }
.au-st-aberta { background:#fef3c7; color:#92400e; } .au-st-designada { background:#dbeafe; color:#1e40af; }
.au-st-realizada { background:#dcfce7; color:#15803d; } .au-st-cancelada { background:#fee2e2; color:#b91c1c; }
.au-mini { background:#0f3d3e; color:#fff; border:none; border-radius:7px; padding:5px 10px; font-weight:600; cursor:pointer; font-size:.78rem; text-decoration:none; display:inline-block; }
.au-mini.gh { background:#fff; color:#0f3d3e; border:1px solid #cfd8d6; }
.dt-filtros { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; align-items:center; }
.dt-fbtn { background:#fff; border:1px solid #cfd8d6; color:#0f3d3e; border-radius:8px; padding:6px 12px; cursor:pointer; font-size:.84rem; font-weight:600; text-decoration:none; }
.dt-fbtn.on { background:#0f3d3e; color:#fff; border-color:#0f3d3e; }
.dt-mini-form { display:flex; gap:4px; align-items:center; flex-wrap:wrap; font-size:.78rem; }
.dt-mini-form input, .dt-mini-form select { border:1px solid #cfd8d6; border-radius:6px; padding:4px 6px; font-size:.78rem; }
</style>

<div style="margin-bottom:.7rem;"><a href="<?= module_url('audiencistas') ?>" style="color:#0f3d3e;text-decoration:none;font-weight:600;">← Voltar para Audiencistas</a></div>

<div class="dt-head">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;">
    <div>
      <h1 style="margin:0;color:#0f3d3e;">👩‍⚖️ <?= e($aud['nome']) ?></h1>
      <div style="color:#666;font-size:.9rem;margin-top:5px;">
        <?= !empty($aud['oab']) ? '⚖️ OAB ' . e($aud['oab']) . ' · ' : '' ?>
        <?= $aud['telefone'] ? '📱 ' . e($aud['telefone']) . ' · ' : '' ?>
        <?= $aud['email'] ? '✉️ ' . e($aud['email']) . ' · ' : '' ?>
        <?= $aud['valor_medio_cents'] !== null ? '💰 ' . aud_money_d($aud['valor_medio_cents']) . ' médio' : '' ?>
      </div>
      <?php if ($aud['areas']): ?><div style="font-size:.84rem;margin-top:4px;color:#444;">🗺️ <strong>Áreas:</strong> <?= e($aud['areas']) ?></div><?php endif; ?>
      <?php if ($aud['dados_deposito']): ?><div style="font-size:.84rem;margin-top:4px;color:#444;">🏦 <?= nl2br(e($aud['dados_deposito'])) ?></div><?php endif; ?>
    </div>
    <a href="<?= module_url('audiencistas', 'exportar_csv.php?id=' . $audId . ($mes ? '&mes=' . $mes . '&ano=' . $ano : '')) ?>" class="au-mini">📥 Exportar CSV</a>
  </div>

  <div class="dt-kpis">
    <div class="dt-kpi"><h4>A pagar (pendente)</h4><div class="v"><?= aud_money_d($saldoDevedor) ?></div><div class="sub"><?= count($lista) - $qtdPagas - ($porStatus['cancelada'] ?? 0) ?> audiência(s)</div></div>
    <div class="dt-kpi verde"><h4>Já pago</h4><div class="v"><?= aud_money_d($totalPago) ?></div><div class="sub"><?= $qtdPagas ?> paga(s)</div></div>
    <div class="dt-kpi azul"><h4>Audiências</h4><div class="v"><?= count($lista) ?></div><div class="sub"><?= $qtdRealizadas ?> realizadas · <?= $porStatus['designada'] ?? 0 ?> designadas</div></div>
    <div class="dt-kpi laranja"><h4>Nota média</h4><div class="v"><?= $qtdAvaliadas ? number_format($notaMedia, 1, ',', '.') . ' ⭐' : '—' ?></div><div class="sub"><?= $qtdAvaliadas ?> avaliação(ões)</div></div>
  </div>
</div>

<div class="dt-filtros">
  <strong style="color:#475569;font-size:.82rem;">Período:</strong>
  <a href="?id=<?= $audId ?>" class="dt-fbtn <?= !$mes ? 'on' : '' ?>">Todas</a>
  <a href="?id=<?= $audId ?>&mes=<?= date('n') ?>&ano=<?= date('Y') ?>" class="dt-fbtn <?= ($mes == date('n') && $ano == date('Y')) ? 'on' : '' ?>">Este mês</a>
  <a href="?id=<?= $audId ?>&mes=<?= date('n', strtotime('first day of last month')) ?>&ano=<?= date('Y', strtotime('first day of last month')) ?>" class="dt-fbtn <?= ($mes && date('n') != $mes) ? 'on' : '' ?>">Mês anterior</a>
  <form method="get" style="display:flex;gap:5px;align-items:center;margin-left:8px;">
    <input type="hidden" name="id" value="<?= $audId ?>">
    <select name="mes" style="border:1px solid #cfd8d6;border-radius:6px;padding:4px 6px;font-size:.82rem;">
      <option value="">Mês…</option>
      <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= (int)$mes === $m ? 'selected' : '' ?>><?= str_pad($m,2,'0',STR_PAD_LEFT) ?></option><?php endfor; ?>
    </select>
    <input type="number" name="ano" value="<?= e($ano ?: date('Y')) ?>" style="width:75px;border:1px solid #cfd8d6;border-radius:6px;padding:4px 6px;font-size:.82rem;">
    <button type="submit" class="dt-fbtn">Filtrar</button>
  </form>
</div>

<?php if (!$lista): ?>
  <div style="background:#fff;border-radius:10px;padding:32px;text-align:center;color:#888;">Nenhuma audiência <?= $mes ? 'no período selecionado' : 'ainda' ?>.</div>
<?php else: foreach ($lista as $a):
  $cls = $a['pago_em'] ? 'paga' : ($a['status'] === 'cancelada' ? 'cancelada' : 'pendente');
  $proc = $a['case_number'] ?: ($a['processo_numero'] ?: ($a['case_title'] ?: '—'));
?>
<div class="dt-row <?= $cls ?>">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;">
    <div style="flex:1;min-width:280px;">
      <div style="font-weight:700;color:#0f3d3e;">⚖️ <?= e($a['tipo']) ?>
        <span class="au-chip au-st-<?= e($a['status']) ?>"><?= e($STATUS[$a['status']] ?? $a['status']) ?></span>
        <?php if ($a['pago_em']): ?><span class="au-chip" style="background:#dcfce7;color:#15803d;">💰 pago</span><?php endif; ?>
        <?php if ($a['avaliacao_nota']): ?><span class="au-chip" style="background:#fff4e0;color:#b9770e;">⭐ <?= (int)$a['avaliacao_nota'] ?></span><?php endif; ?>
        <?php if ($a['substab_path']): ?><span class="au-chip" style="background:#e0e7ff;color:#3730a3;">📜 substab</span><?php endif; ?>
      </div>
      <div style="color:#666;font-size:.84rem;margin-top:3px;">
        <?= $a['data_hora'] ? '📅 ' . date('d/m/Y H:i', strtotime($a['data_hora'])) . ' · ' : '' ?>
        <?= $a['comarca'] ? '📍 ' . e($a['comarca']) . ' · ' : '' ?>
        📄 <?= e($proc) ?><?= $a['client_name'] ? ' · 👤 ' . e($a['client_name']) : '' ?>
      </div>
    </div>
    <div style="text-align:right;font-size:.92rem;">
      <?php $v = $a['valor_cents'] !== null ? $a['valor_cents'] : $aud['valor_medio_cents']; ?>
      <div style="font-weight:700;color:#0f3d3e;"><?= aud_money_d($v) ?></div>
      <?php if ($a['pago_em']): ?>
        <div style="font-size:.74rem;color:#15803d;">✅ <?= date('d/m/Y', strtotime($a['pago_em'])) ?><?= $a['pago_forma'] ? ' · ' . e($a['pago_forma']) : '' ?></div>
        <?php if ($a['pago_comprovante_path']): ?><a href="<?= module_url('audiencistas') ?>?baixar=<?= (int)$a['id'] ?>&tipo=comprov" target="_blank" style="font-size:.74rem;color:#0f3d3e;">📎 comprovante</a><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($a['status'] !== 'cancelada'): ?>
  <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center;border-top:1px solid #f0f0f0;padding-top:8px;">
    <?php if (!$a['pago_em']): ?>
      <form method="post" action="<?= module_url('audiencistas') ?>" class="dt-mini-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="acao" value="marcar_pago">
        <input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
        <input type="hidden" name="voltar_detalhe" value="<?= $audId ?>">
        <input type="date" name="pago_em" value="<?= date('Y-m-d') ?>">
        <input type="text" name="valor_pago" placeholder="R$" value="<?= $v ? number_format($v/100, 2, '.', '') : '' ?>" style="width:80px;">
        <select name="forma"><option value="">forma</option><option>PIX</option><option>Transferência</option><option>Dinheiro</option><option>Outro</option></select>
        <input type="file" name="comprovante" accept=".pdf,image/*" style="font-size:.72rem;">
        <button type="submit" class="au-mini">💰 Pagar</button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= module_url('audiencistas') ?>" style="display:inline;" onsubmit="return confirm('Desmarcar pagamento?');">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="acao" value="desfazer_pago">
        <input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
        <input type="hidden" name="voltar_detalhe" value="<?= $audId ?>">
        <button type="submit" class="au-mini gh">↩️ Desfazer pago</button>
      </form>
    <?php endif; ?>

    <details style="display:inline-block;">
      <summary style="cursor:pointer;font-size:.82rem;color:#475569;font-weight:600;">⭐ Avaliar</summary>
      <form method="post" action="<?= module_url('audiencistas') ?>" style="margin-top:5px;padding:8px;background:#f8fafc;border-radius:6px;">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="acao" value="avaliar">
        <input type="hidden" name="audiencia_id" value="<?= (int)$a['id'] ?>">
        <input type="hidden" name="voltar_detalhe" value="<?= $audId ?>">
        <div style="display:flex;gap:8px;">
          <?php for ($n = 1; $n <= 5; $n++): ?>
            <label style="cursor:pointer;font-size:.82rem;"><input type="radio" name="nota" value="<?= $n ?>" <?= (int)$a['avaliacao_nota'] === $n ? 'checked' : '' ?>><?= $n ?>⭐</label>
          <?php endfor; ?>
        </div>
        <textarea name="comentario" placeholder="Comentário (opcional)" style="width:100%;margin-top:5px;font-size:.78rem;padding:5px;border:1px solid #cfd8d6;border-radius:5px;min-height:40px;"><?= e($a['avaliacao_comentario'] ?? '') ?></textarea>
        <button type="submit" class="au-mini" style="margin-top:4px;">Salvar</button>
      </form>
    </details>

    <a href="<?= module_url('audiencistas') ?>#a-<?= (int)$a['id'] ?>" class="au-mini gh">📋 Card completo</a>
  </div>
  <?php endif; ?>

  <?php if ($a['avaliacao_comentario']): ?>
    <div style="margin-top:6px;font-size:.78rem;color:#92400e;background:#fff4e0;border-radius:6px;padding:6px 8px;">⭐ <?= e($a['avaliacao_comentario']) ?></div>
  <?php endif; ?>
</div>
<?php endforeach; endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
