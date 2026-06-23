<?php
/**
 * CRM Operacional — painel da equipe operacional/CX (canal 24 + petições pendentes).
 *
 *  🔴 Responder agora     → conversas canal 24 cuja última msg foi do CLIENTE
 *  🟡 Follow-up           → conversas canal 24 com última msg nossa (cliente sumiu)
 *  📝 Petições pendentes  → cases em em_elaboracao / aguardando_prazo, ordenadas por
 *                           mais tempo na coluna (proxy: cases.updated_at).
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('crm_operacional');

require_once __DIR__ . '/../../core/functions_crm_operacional.php';

$pdo = db();
$pageTitle = 'CRM Operacional';
crm_op_self_heal($pdo);

// Stage → label/ícone/cor (replicando o que o Kanban Operacional usa)
$stageMap = array(
    'em_elaboracao'    => array('label' => 'Pasta Apta',           'color' => '#059669', 'icon' => '✔️'),
    'aguardando_prazo' => array('label' => 'Aguard. Distribuição', 'color' => '#8b5cf6', 'icon' => '⏳'),
);

// ── Dados ─────────────────────────────────────────────────────
$pendentes = crm_op_fetch_wa($pdo, 'recebida', 45);  // última msg do cliente
$fupRaw    = crm_op_fetch_wa($pdo, 'enviada',  45);  // última nossa
$aquecendo = crm_op_fetch_wa($pdo, 'enviada',  0, 300, 'aquecendo');
$aqIds = array();
foreach ($aquecendo as $a) $aqIds[(int)$a['conversa_id']] = true;
$followups = $aquecendo;
foreach ($fupRaw as $f) {
    if (($f['status'] ?? '') === 'resolvido') continue;
    if (isset($aqIds[(int)$f['conversa_id']])) continue;
    $followups[] = $f;
}
$peticoes = crm_op_fetch_peticoes($pdo);
$umap = crm_op_users_map($pdo);

function cop_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function cop_resp_nome($row, $umap)
{
    $id = crm_op_responsavel_id($row);
    if (!$id) return array('—', true);
    return array(isset($umap[$id]) ? $umap[$id] : ('#' . $id), false);
}

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cop-tabs { display:flex; gap:8px; margin:0 0 16px; flex-wrap:wrap; }
.cop-tab { padding:10px 18px; border-radius:999px; border:1px solid var(--border,#e3e3e3); background:#fff; cursor:pointer; font-weight:600; font-size:14px; color:#444; display:flex; align-items:center; gap:8px; }
.cop-tab.active { background:#0f3d3e; color:#fff; border-color:#0f3d3e; }
.cop-tab .cop-badge { background:rgba(0,0,0,.12); border-radius:999px; padding:1px 9px; font-size:12px; }
.cop-tab.active .cop-badge { background:rgba(255,255,255,.25); }
.cop-pane { display:none; }
.cop-pane.active { display:block; }
.cop-table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.cop-table th, .cop-table td { padding:11px 12px; text-align:left; font-size:13.5px; border-bottom:1px solid #f0f0f0; vertical-align:top; }
.cop-table th { background:#fafafa; font-size:12px; text-transform:uppercase; letter-spacing:.3px; color:#888; }
.cop-table tr:last-child td { border-bottom:none; }
.cop-nome { font-weight:600; color:#0f3d3e; text-decoration:none; }
.cop-nome:hover { text-decoration:underline; }
.cop-sub { color:#999; font-size:12px; margin-top:2px; }
.cop-chip { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; }
.cop-chip.dono { background:#e8f3f1; color:#0f3d3e; }
.cop-chip.semdono { background:#fdecec; color:#c0392b; }
.cop-chip.tempo-quente { background:#fdecec; color:#c0392b; }
.cop-chip.tempo-morno { background:#fff4e0; color:#b9770e; }
.cop-chip.tempo-frio { background:#eef1f4; color:#667; }
.cop-stage { display:inline-block; padding:3px 10px; border-radius:6px; font-size:12px; font-weight:700; color:#fff; }
.cop-obs { width:100%; border:1px solid #e3e3e3; border-radius:8px; padding:6px 8px; font-size:13px; font-family:inherit; resize:vertical; min-height:34px; }
.cop-obs:focus { outline:none; border-color:#0f3d3e; }
.cop-date { border:1px solid #e3e3e3; border-radius:8px; padding:5px 7px; font-size:12.5px; }
.cop-saved { border-color:#27ae60 !important; }
.cop-empty { text-align:center; padding:48px 16px; color:#999; }
.cop-btn { background:#0f3d3e; color:#fff; border:none; border-radius:8px; padding:9px 16px; font-weight:600; cursor:pointer; font-size:13.5px; }
.cop-btn.ghost { background:#fff; color:#0f3d3e; border:1px solid #0f3d3e; }
.cop-aq { background:#fff; border:1px solid #e0a64a; color:#b9770e; border-radius:8px; padding:6px 10px; font-weight:600; cursor:pointer; font-size:12.5px; }
.cop-aq:hover { background:#fff4e0; }
.cop-aq.on { background:#fff4e0; border-color:#d98a1f; color:#9c5d05; box-shadow:inset 0 0 0 1px #d98a1f; }
.cop-rs { background:#fff; border:1px solid #cfd8d6; color:#3a6b5f; border-radius:8px; padding:6px 10px; font-weight:600; cursor:pointer; font-size:12.5px; }
.cop-rs:hover { background:#e8f3f1; }
tr.cop-pin { background:#fffaf0; }
tr.cop-pin td:first-child { box-shadow:inset 3px 0 0 #e0a64a; }
.cop-dias { font-weight:700; }
.cop-dias.urg { color:#c0392b; }
.cop-dias.med { color:#b9770e; }
.cop-dias.ok  { color:#3a6b5f; }
</style>

<div class="page-header" style="margin-bottom:14px;">
  <h1 style="margin:0;">🛠️ CRM Operacional</h1>
  <p style="color:#777; margin:4px 0 0;">Cliente esperando resposta no canal 24, follow-up e petições iniciais ainda pendentes de distribuição.</p>
</div>

<div class="cop-tabs">
  <div class="cop-tab active" data-pane="pendentes" onclick="copTab(this)">🔴 Responder agora <span class="cop-badge"><?= count($pendentes) ?></span></div>
  <div class="cop-tab" data-pane="followup" onclick="copTab(this)">🟡 Follow-up <span class="cop-badge"><?= count($followups) ?></span></div>
  <div class="cop-tab" data-pane="peticoes" onclick="copTab(this)">📝 Petições pendentes <span class="cop-badge"><?= count($peticoes) ?></span></div>
</div>

<?php
function cop_render_wa($rows, $umap, $modo)
{
    if (!$rows) {
        $msg = $modo === 'pendentes'
            ? '🎉 Nenhum cliente esperando resposta no canal 24.'
            : '✅ Nenhum follow-up pendente nos últimos 45 dias.';
        echo '<div class="cop-empty">' . $msg . '</div>';
        return;
    }
    $colTempo = $modo === 'pendentes' ? 'Esperando há' : 'Sem retorno há';
    echo '<table class="cop-table"><thead><tr>';
    echo '<th>Cliente</th><th>Case vinculado</th><th>Responsável</th><th>' . $colTempo . '</th><th style="width:28%;">Observação</th><th>Follow-up</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $convId = (int)$r['conversa_id'];
        $nome   = $r['client_name'] ? $r['client_name'] : ($r['nome_contato'] ? $r['nome_contato'] : $r['telefone']);
        list($respNome, $semDono) = cop_resp_nome($r, $umap);
        $baseTempo = $modo === 'pendentes' ? $r['ultima_em'] : ($r['ultima_nossa_em'] ? $r['ultima_nossa_em'] : $r['ultima_em']);
        $segs = time() - strtotime($baseTempo);
        $classeTempo = $segs < 3600 ? 'tempo-quente' : ($segs < 86400 ? 'tempo-morno' : 'tempo-frio');
        $abrir = url('modules/whatsapp/?abrir=' . $convId . '&canal=24');
        $caseHref = !empty($r['case_id']) ? url('modules/operacional/caso_ver.php?id=' . (int)$r['case_id']) : '';
        $prox = $r['proximo_followup'] ?? '';
        $obs  = $r['observacao'] ?? '';
        $aquecido = (($r['status'] ?? '') === 'aquecendo');

        echo '<tr class="' . ($modo === 'followup' && $aquecido ? 'cop-pin' : '') . '">';
        echo '<td><a class="cop-nome" href="' . cop_e($abrir) . '">' . cop_e($nome) . '</a>';
        echo '<div class="cop-sub">' . cop_e($r['telefone']) . '</div></td>';
        if (!empty($r['case_title'])) {
            echo '<td><a href="' . cop_e($caseHref) . '" style="color:#0f3d3e;text-decoration:none;font-weight:600;">' . cop_e($r['case_title']) . '</a>';
            echo '<div class="cop-sub">' . cop_e($r['case_stage'] ?: '') . '</div></td>';
        } else {
            echo '<td><span class="cop-sub">— sem case ativo —</span></td>';
        }
        echo '<td><span class="cop-chip ' . ($semDono ? 'semdono' : 'dono') . '">' . cop_e($semDono ? 'sem dono' : strtok($respNome, ' ')) . '</span></td>';
        echo '<td><span class="cop-chip ' . $classeTempo . '">' . cop_e(crm_op_tempo($baseTempo)) . '</span></td>';
        echo '<td><textarea class="cop-obs" data-conv="' . $convId . '" placeholder="Anotações, combinados, próximo passo…" onblur="copSalvarObs(this)">' . cop_e($obs) . '</textarea></td>';
        echo '<td><input type="date" class="cop-date" data-conv="' . $convId . '" value="' . cop_e($prox) . '" onchange="copSalvarObs(this)"></td>';
        echo '<td style="white-space:nowrap;">';
        if ($modo === 'followup') {
            echo '<button type="button" class="cop-aq' . ($aquecido ? ' on' : '') . '" onclick="copAquecer(' . $convId . ',this)">🔥 ' . ($aquecido ? 'Aquecendo' : 'Aquecer') . '</button> ';
            echo '<button type="button" class="cop-rs" onclick="copResolver(' . $convId . ',this)">✅ Resolvido</button> ';
        }
        echo '<a class="cop-btn ghost" style="padding:6px 10px; text-decoration:none;" href="' . cop_e($abrir) . '">Abrir</a>';
        echo '</td></tr>';
    }
    echo '</tbody></table>';
}

function cop_render_peticoes($rows, $umap, $stageMap)
{
    if (!$rows) {
        echo '<div class="cop-empty">✅ Nenhuma petição inicial pendente. Fila vazia, time mandando bem!</div>';
        return;
    }
    echo '<table class="cop-table"><thead><tr>';
    echo '<th>Cliente</th><th>Título do caso</th><th>Stage</th><th>Tempo parado</th><th>Responsável</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $caseId = (int)$r['id'];
        $cliente = $r['client_name'] ?: '(sem cliente)';
        $stage = $r['stage'];
        $stInfo = isset($stageMap[$stage]) ? $stageMap[$stage] : array('label' => $stage, 'color' => '#888', 'icon' => '•');
        $dias = (int)$r['dias_parado'];
        $diasClass = $dias >= 14 ? 'urg' : ($dias >= 7 ? 'med' : 'ok');
        $rid = (int)$r['responsible_user_id'];
        $respNome = $rid && isset($umap[$rid]) ? $umap[$rid] : null;
        $abrir = url('modules/operacional/caso_ver.php?id=' . $caseId);

        echo '<tr>';
        echo '<td><strong>' . cop_e($cliente) . '</strong></td>';
        echo '<td><a class="cop-nome" href="' . cop_e($abrir) . '">' . cop_e($r['title'] ?: '(sem título)') . '</a></td>';
        echo '<td><span class="cop-stage" style="background:' . $stInfo['color'] . ';">' . $stInfo['icon'] . ' ' . cop_e($stInfo['label']) . '</span></td>';
        echo '<td><span class="cop-dias ' . $diasClass . '">' . $dias . ' dia' . ($dias === 1 ? '' : 's') . '</span>';
        echo '<div class="cop-sub">desde ' . cop_e(date('d/m', strtotime($r['updated_at']))) . '</div></td>';
        echo '<td>' . ($respNome ? '<span class="cop-chip dono">' . cop_e(strtok($respNome, ' ')) . '</span>' : '<span class="cop-chip semdono">sem dono</span>') . '</td>';
        echo '<td style="white-space:nowrap;"><a class="cop-btn ghost" style="padding:6px 12px;text-decoration:none;" href="' . cop_e($abrir) . '">Abrir caso</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
?>

<div class="cop-pane active" id="pane-pendentes">
  <?php cop_render_wa($pendentes, $umap, 'pendentes'); ?>
</div>
<div class="cop-pane" id="pane-followup">
  <?php cop_render_wa($followups, $umap, 'followup'); ?>
</div>
<div class="cop-pane" id="pane-peticoes">
  <?php cop_render_peticoes($peticoes, $umap, $stageMap); ?>
</div>

<script>
var COP_API = '<?= module_url('crm_operacional', 'api.php') ?>';
var COP_CSRF = '<?= $csrf ?>';

function copTab(el) {
  document.querySelectorAll('.cop-tab').forEach(function(t){ t.classList.remove('active'); });
  document.querySelectorAll('.cop-pane').forEach(function(p){ p.classList.remove('active'); });
  el.classList.add('active');
  document.getElementById('pane-' + el.dataset.pane).classList.add('active');
}

function copPost(data, cb) {
  var fd = new FormData();
  fd.append('csrf_token', COP_CSRF);
  for (var k in data) fd.append(k, data[k]);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', COP_API);
  xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.onload = function() {
    if (xhr.status === 401 && window.fsaMostrarSessaoExpirada) { window.fsaMostrarSessaoExpirada(); return; }
    var resp = null;
    try { resp = JSON.parse(xhr.responseText); } catch(e) {}
    cb(resp, xhr.status);
  };
  xhr.onerror = function(){ cb(null, 0); };
  xhr.send(fd);
}

function copSalvarObs(el) {
  var conv = el.dataset.conv;
  var row = el.closest('tr');
  var obs = row.querySelector('.cop-obs') ? row.querySelector('.cop-obs').value : '';
  var prox = row.querySelector('.cop-date') ? row.querySelector('.cop-date').value : '';
  copPost({ action:'salvar_obs', conversa_id:conv, observacao:obs, proximo_followup:prox }, function(resp){
    if (resp && resp.ok) {
      el.classList.add('cop-saved');
      setTimeout(function(){ el.classList.remove('cop-saved'); }, 1200);
    } else {
      alert('⚠️ Não consegui salvar a observação. Recarregue a página (F5).');
    }
  });
}

function copAquecer(conv, btn) {
  var ativo = btn.classList.contains('on');
  var novo = ativo ? '' : 'aquecendo';
  copPost({ action:'definir_status', conversa_id:conv, status:novo }, function(resp){
    if (!resp || !resp.ok) { alert('⚠️ Não consegui salvar. Recarregue (F5).'); return; }
    var tr = btn.closest('tr');
    if (novo === 'aquecendo') { btn.classList.add('on'); btn.textContent = '🔥 Aquecendo'; if (tr) tr.classList.add('cop-pin'); }
    else { btn.classList.remove('on'); btn.textContent = '🔥 Aquecer'; if (tr) tr.classList.remove('cop-pin'); }
  });
}

function copResolver(conv, btn) {
  if (!confirm('Marcar como resolvido? O cliente sai da lista de follow-up.')) return;
  copPost({ action:'definir_status', conversa_id:conv, status:'resolvido' }, function(resp){
    if (!resp || !resp.ok) { alert('⚠️ Não consegui salvar. Recarregue (F5).'); return; }
    var tr = btn.closest('tr');
    if (tr) { tr.style.transition = 'opacity .3s'; tr.style.opacity = '0'; setTimeout(function(){ tr.remove(); }, 300); }
  });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
