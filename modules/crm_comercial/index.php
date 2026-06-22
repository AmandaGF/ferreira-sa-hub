<?php
/**
 * CRM Comercial — acompanhamento do time comercial (canal 21).
 *
 *  🔴 Responder agora  → leads cuja última mensagem foi do LEAD (devemos resposta)
 *  🟡 Follow-up        → leads dos últimos 45 dias cuja última msg foi NOSSA e o lead sumiu
 *
 * Por lead: responsável, tempo desde a última msg, observação editável e data de follow-up.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('crm_comercial');

require_once __DIR__ . '/../../core/functions_comercial.php';

$pdo = db();
$pageTitle = 'CRM Comercial';
comercial_self_heal($pdo);

$podeConfig = has_min_role('gestao');

// ── Salvar config (só gestão/admin) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_config' && $podeConfig) {
    validate_csrf();
    comercial_set_cfg($pdo, 'comercial_cobranca_ativo', !empty($_POST['ativo']) ? '1' : '0');
    comercial_set_cfg($pdo, 'comercial_grupo_id', trim($_POST['grupo_id'] ?? ''));
    $canal = ($_POST['grupo_canal'] ?? '21') === '24' ? '24' : '21';
    comercial_set_cfg($pdo, 'comercial_cobranca_canal', $canal);
    $minN = max(1, (int)($_POST['min'] ?? 5));
    comercial_set_cfg($pdo, 'comercial_cobranca_min', (string)$minN);
    audit_log('comercial_cobranca_config', 'configuracoes', 0, 'ativo=' . (!empty($_POST['ativo']) ? '1' : '0'));
    flash_set('success', 'Configurações da cobrança salvas.');
    redirect(module_url('crm_comercial') . '#config');
}

$cfg = comercial_cfg($pdo);

// ── Dados ────────────────────────────────────────────────
$pendentes = comercial_fetch($pdo, 'recebida', 45, 0, 300); // última msg = do lead
$followups = comercial_fetch($pdo, 'enviada', 45, 0, 300);  // última msg = nossa, lead sumiu
$umap = comercial_users_map($pdo);

function cc_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** "há 2h 10min", "há 3 dias"… */
function cc_tempo($dt)
{
    if (!$dt) return '—';
    $s = time() - strtotime($dt);
    if ($s < 60)    return 'agora';
    $m = (int)($s / 60);
    if ($m < 60)    return 'há ' . $m . ' min';
    $h = (int)($m / 60);
    if ($h < 24)    return 'há ' . $h . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'min' : '');
    $d = (int)($h / 24);
    return 'há ' . $d . ' dia' . ($d > 1 ? 's' : '');
}

function cc_resp_nome($row, $umap)
{
    $id = comercial_responsavel_id($row);
    if (!$id) return array('—', true);
    return array(isset($umap[$id]) ? $umap[$id] : ('#' . $id), false);
}

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cc-tabs { display:flex; gap:8px; margin:0 0 16px; flex-wrap:wrap; }
.cc-tab { padding:10px 18px; border-radius:999px; border:1px solid var(--border,#e3e3e3); background:#fff; cursor:pointer; font-weight:600; font-size:14px; color:#444; display:flex; align-items:center; gap:8px; }
.cc-tab.active { background:#0f3d3e; color:#fff; border-color:#0f3d3e; }
.cc-tab .cc-badge { background:rgba(0,0,0,.12); border-radius:999px; padding:1px 9px; font-size:12px; }
.cc-tab.active .cc-badge { background:rgba(255,255,255,.25); }
.cc-pane { display:none; }
.cc-pane.active { display:block; }
.cc-table { width:100%; border-collapse:collapse; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.cc-table th, .cc-table td { padding:11px 12px; text-align:left; font-size:13.5px; border-bottom:1px solid #f0f0f0; vertical-align:top; }
.cc-table th { background:#fafafa; font-size:12px; text-transform:uppercase; letter-spacing:.3px; color:#888; }
.cc-table tr:last-child td { border-bottom:none; }
.cc-nome { font-weight:600; color:#0f3d3e; text-decoration:none; }
.cc-nome:hover { text-decoration:underline; }
.cc-sub { color:#999; font-size:12px; margin-top:2px; }
.cc-chip { display:inline-block; padding:2px 9px; border-radius:999px; font-size:12px; font-weight:600; }
.cc-chip.dono { background:#e8f3f1; color:#0f3d3e; }
.cc-chip.semdono { background:#fdecec; color:#c0392b; }
.cc-chip.tempo-quente { background:#fdecec; color:#c0392b; }
.cc-chip.tempo-morno { background:#fff4e0; color:#b9770e; }
.cc-chip.tempo-frio { background:#eef1f4; color:#667; }
.cc-obs { width:100%; border:1px solid #e3e3e3; border-radius:8px; padding:6px 8px; font-size:13px; font-family:inherit; resize:vertical; min-height:34px; }
.cc-obs:focus { outline:none; border-color:#0f3d3e; }
.cc-date { border:1px solid #e3e3e3; border-radius:8px; padding:5px 7px; font-size:12.5px; }
.cc-saved { border-color:#27ae60 !important; }
.cc-empty { text-align:center; padding:48px 16px; color:#999; }
.cc-cfg { background:#fff; border-radius:12px; padding:18px 20px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:20px; }
.cc-cfg h3 { margin:0 0 4px; font-size:16px; }
.cc-cfg .muted { color:#888; font-size:13px; margin:0 0 14px; }
.cc-cfg .row { display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; }
.cc-cfg label { font-size:12.5px; font-weight:600; color:#555; display:block; margin-bottom:4px; }
.cc-cfg input[type=text], .cc-cfg input[type=number], .cc-cfg select { border:1px solid #ddd; border-radius:8px; padding:8px 10px; font-size:13.5px; }
.cc-btn { background:#0f3d3e; color:#fff; border:none; border-radius:8px; padding:9px 16px; font-weight:600; cursor:pointer; font-size:13.5px; }
.cc-btn.ghost { background:#fff; color:#0f3d3e; border:1px solid #0f3d3e; }
.cc-switch { display:flex; align-items:center; gap:8px; font-weight:600; color:#444; }
.cc-preview { margin-top:12px; background:#f7f9f9; border:1px dashed #bcd; border-radius:8px; padding:12px; font-size:13px; white-space:pre-wrap; display:none; }
</style>

<div class="page-header" style="margin-bottom:14px;">
  <h1 style="margin:0;">🎯 CRM Comercial</h1>
  <p style="color:#777; margin:4px 0 0;">Quem está esperando resposta e quem precisa de follow-up. Canal 21 (Comercial), últimos 45 dias.</p>
</div>

<?php if ($podeConfig): ?>
<div class="cc-cfg" id="config">
  <h3>⚙️ Cobrança automática</h3>
  <p class="muted">Quando um lead fica <strong><?= (int)$cfg['min'] ?> min</strong> sem resposta, o responsável é notificado (igual a um lead novo) e o grupo do WhatsApp recebe um resumo (máx. 1×/30min, em horário comercial).</p>
  <form method="post" action="<?= module_url('crm_comercial') ?>">
    <input type="hidden" name="acao" value="salvar_config">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <div class="row">
      <div>
        <label class="cc-switch"><input type="checkbox" name="ativo" value="1" <?= $cfg['ativo'] === '1' ? 'checked' : '' ?>> Ligada</label>
      </div>
      <div>
        <label>Minutos sem resposta</label>
        <input type="number" name="min" min="1" max="120" value="<?= (int)$cfg['min'] ?>" style="width:90px;">
      </div>
      <div>
        <label>Canal do grupo</label>
        <select name="grupo_canal">
          <option value="21" <?= $cfg['grupo_canal'] !== '24' ? 'selected' : '' ?>>21 (Comercial)</option>
          <option value="24" <?= $cfg['grupo_canal'] === '24' ? 'selected' : '' ?>>24 (CX)</option>
        </select>
      </div>
      <div style="flex:1; min-width:260px;">
        <label>ID do grupo no WhatsApp (…@g.us ou …-group)</label>
        <input type="text" name="grupo_id" value="<?= cc_e($cfg['grupo_id']) ?>" placeholder="ex: 120363123456789@g.us" style="width:100%;">
      </div>
      <div>
        <button type="submit" class="cc-btn">Salvar</button>
      </div>
    </div>
  </form>
  <div class="row" style="margin-top:12px;">
    <button type="button" class="cc-btn ghost" onclick="ccPreview()">👁️ Pré-visualizar pendências</button>
    <button type="button" class="cc-btn ghost" onclick="ccTestarGrupo()">📨 Enviar teste no grupo</button>
  </div>
  <div class="cc-preview" id="ccPreview"></div>
</div>
<?php endif; ?>

<div class="cc-tabs">
  <div class="cc-tab active" data-pane="pendentes" onclick="ccTab(this)">🔴 Responder agora <span class="cc-badge"><?= count($pendentes) ?></span></div>
  <div class="cc-tab" data-pane="followup" onclick="ccTab(this)">🟡 Follow-up <span class="cc-badge"><?= count($followups) ?></span></div>
</div>

<?php
// Renderizador de tabela (reuso pros 2 panes)
function cc_render_tabela($rows, $umap, $modo)
{
    if (!$rows) {
        $msg = $modo === 'pendentes'
            ? '🎉 Nenhum lead esperando resposta. Time em dia!'
            : '✅ Nenhum follow-up pendente nos últimos 45 dias.';
        echo '<div class="cc-empty">' . $msg . '</div>';
        return;
    }
    $colTempo = $modo === 'pendentes' ? 'Esperando há' : 'Sem retorno há';
    echo '<table class="cc-table"><thead><tr>';
    echo '<th>Lead</th><th>Responsável</th><th>' . $colTempo . '</th><th style="width:34%;">Observação</th><th>Follow-up</th><th></th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $convId = (int)$r['conversa_id'];
        $nome = $r['lead_name'] ? $r['lead_name'] : ($r['client_name'] ? $r['client_name'] : ($r['nome_contato'] ? $r['nome_contato'] : $r['telefone']));
        list($respNome, $semDono) = cc_resp_nome($r, $umap);
        $baseTempo = $modo === 'pendentes' ? $r['ultima_em'] : ($r['ultima_nossa_em'] ? $r['ultima_nossa_em'] : $r['ultima_em']);
        $segs = time() - strtotime($baseTempo);
        $classeTempo = $segs < 3600 ? 'tempo-quente' : ($segs < 86400 ? 'tempo-morno' : 'tempo-frio');
        $abrir = url('modules/whatsapp/?abrir=' . $convId . '&canal=21');
        $tema = $r['case_type'] ? $r['case_type'] : '';
        $prox = $r['proximo_followup'] ?? '';
        $obs  = $r['observacao'] ?? '';

        echo '<tr>';
        echo '<td><a class="cc-nome" href="' . cc_e($abrir) . '">' . cc_e($nome) . '</a>';
        echo '<div class="cc-sub">' . cc_e($r['telefone']) . ($tema ? ' · ' . cc_e($tema) : '') . '</div></td>';
        echo '<td><span class="cc-chip ' . ($semDono ? 'semdono' : 'dono') . '">' . cc_e($semDono ? 'sem dono' : strtok($respNome, ' ')) . '</span></td>';
        echo '<td><span class="cc-chip ' . $classeTempo . '">' . cc_e(cc_tempo($baseTempo)) . '</span></td>';
        echo '<td><textarea class="cc-obs" data-conv="' . $convId . '" data-lead="' . (int)$r['lead_id'] . '" placeholder="Anotações, combinados, próximo passo…" onblur="ccSalvarObs(this)">' . cc_e($obs) . '</textarea></td>';
        echo '<td><input type="date" class="cc-date" data-conv="' . $convId . '" data-lead="' . (int)$r['lead_id'] . '" value="' . cc_e($prox) . '" onchange="ccSalvarObs(this)"></td>';
        echo '<td><a class="cc-btn ghost" style="padding:6px 10px; text-decoration:none;" href="' . cc_e($abrir) . '">Abrir</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
?>

<div class="cc-pane active" id="pane-pendentes">
  <?php cc_render_tabela($pendentes, $umap, 'pendentes'); ?>
</div>
<div class="cc-pane" id="pane-followup">
  <?php cc_render_tabela($followups, $umap, 'followup'); ?>
</div>

<script>
var CC_API = '<?= module_url('crm_comercial', 'api.php') ?>';
var CC_CSRF = '<?= $csrf ?>';

function ccTab(el) {
  document.querySelectorAll('.cc-tab').forEach(function(t){ t.classList.remove('active'); });
  document.querySelectorAll('.cc-pane').forEach(function(p){ p.classList.remove('active'); });
  el.classList.add('active');
  document.getElementById('pane-' + el.dataset.pane).classList.add('active');
}

function ccPost(data, cb) {
  var fd = new FormData();
  fd.append('csrf_token', CC_CSRF);
  for (var k in data) fd.append(k, data[k]);
  var xhr = new XMLHttpRequest();
  xhr.open('POST', CC_API);
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

function ccSalvarObs(el) {
  var conv = el.dataset.conv, lead = el.dataset.lead;
  var row = el.closest('tr');
  var obs = row.querySelector('.cc-obs') ? row.querySelector('.cc-obs').value : '';
  var prox = row.querySelector('.cc-date') ? row.querySelector('.cc-date').value : '';
  ccPost({ action:'salvar_obs', conversa_id:conv, lead_id:lead, observacao:obs, proximo_followup:prox }, function(resp){
    if (resp && resp.ok) {
      el.classList.add('cc-saved');
      setTimeout(function(){ el.classList.remove('cc-saved'); }, 1200);
    } else {
      alert('⚠️ Não consegui salvar a observação. Recarregue a página (F5).');
    }
  });
}

function ccPreview() {
  var box = document.getElementById('ccPreview');
  box.style.display = 'block';
  box.textContent = 'Verificando pendências…';
  ccPost({ action:'preview_cobranca' }, function(resp){
    if (!resp || !resp.ok) { box.textContent = '⚠️ Erro ao pré-visualizar.'; return; }
    var r = resp.rep;
    var txt = 'Pendentes agora: ' + r.pendentes + ' lead(s).\n';
    if (r.detalhe && r.detalhe.length) {
      r.detalhe.forEach(function(d){ txt += '• ' + d.nome + ' — ' + d.min + 'min — ' + d.responsavel + '\n'; });
    }
    if (r.grupo_preview) { txt += '\n— Mensagem que iria pro grupo —\n' + r.grupo_preview; }
    box.textContent = txt;
  });
}

function ccTestarGrupo() {
  if (!confirm('Enviar uma mensagem de teste no grupo do WhatsApp agora?')) return;
  ccPost({ action:'testar_grupo' }, function(resp){
    if (resp && resp.ok) alert('✅ Mensagem de teste enviada no grupo!');
    else alert('⚠️ Falhou: ' + (resp && resp.error ? resp.error : 'configure o ID do grupo e tente de novo.'));
  });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
