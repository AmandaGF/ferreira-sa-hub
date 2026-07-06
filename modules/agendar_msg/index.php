<?php
/**
 * Agendamento pontual de mensagens WhatsApp.
 * Escolhe cliente + canal + data/hora + mensagem escrita à mão.
 * cron/wa_agendamentos_tick.php varre pendentes vencidos e envia.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('agendar_msg');

$pdo = db();
$pageTitle = 'Agendar Mensagem WhatsApp';
$csrf = generate_csrf_token();
$userId = current_user_id();

// Self-heal defensivo
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS wa_agendamentos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        canal ENUM('21','24') NOT NULL DEFAULT '24',
        client_id INT UNSIGNED NULL,
        case_id INT UNSIGNED NULL,
        telefone VARCHAR(30) NOT NULL,
        nome_contato VARCHAR(150) NULL,
        mensagem TEXT NOT NULL,
        agendado_para DATETIME NOT NULL,
        status ENUM('pendente','enviado','cancelado','falhou') NOT NULL DEFAULT 'pendente',
        enviado_em DATETIME NULL,
        zapi_message_id VARCHAR(100) NULL,
        erro TEXT NULL,
        tentativas TINYINT UNSIGNED NOT NULL DEFAULT 0,
        criado_por INT UNSIGNED NULL,
        cancelado_por INT UNSIGNED NULL,
        cancelado_em DATETIME NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_status_data (status, agendado_para),
        INDEX idx_client (client_id),
        INDEX idx_case (case_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

$aba = $_GET['aba'] ?? 'pendentes';
if (!in_array($aba, array('pendentes','historico'), true)) $aba = 'pendentes';

if ($aba === 'pendentes') {
    $lista = $pdo->query("
        SELECT a.*, c.name AS client_name_real, u.name AS criado_por_nome
        FROM wa_agendamentos a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN users u ON u.id = a.criado_por
        WHERE a.status = 'pendente'
        ORDER BY a.agendado_para ASC
        LIMIT 300
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $lista = $pdo->query("
        SELECT a.*, c.name AS client_name_real, u.name AS criado_por_nome
        FROM wa_agendamentos a
        LEFT JOIN clients c ON c.id = a.client_id
        LEFT JOIN users u ON u.id = a.criado_por
        WHERE a.status <> 'pendente'
        ORDER BY COALESCE(a.enviado_em, a.cancelado_em, a.criado_em) DESC
        LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$totPend = (int)$pdo->query("SELECT COUNT(*) FROM wa_agendamentos WHERE status='pendente'")->fetchColumn();
$totProxHora = (int)$pdo->query("SELECT COUNT(*) FROM wa_agendamentos WHERE status='pendente' AND agendado_para <= DATE_ADD(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
$totEnv7d = (int)$pdo->query("SELECT COUNT(*) FROM wa_agendamentos WHERE status='enviado' AND enviado_em >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$totFalhou = (int)$pdo->query("SELECT COUNT(*) FROM wa_agendamentos WHERE status='falhou'")->fetchColumn();
$killswitch = $pdo->query("SELECT valor FROM configuracoes WHERE chave='wa_agenda_ativo'")->fetchColumn();
$killswitchOn = ($killswitch === '1' || $killswitch === 1);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.ag-hdr { background:linear-gradient(135deg,#052228,#0d3640); color:#fff; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.ag-hdr h2 { font-size:1.1rem; margin:0 0 .25rem; }
.ag-hdr .sub { font-size:.8rem; color:rgba(255,255,255,.75); }
.ag-hdr .stats { margin-left:auto; display:flex; gap:.75rem; flex-wrap:wrap; }
.ag-stat { background:rgba(255,255,255,.1); padding:6px 12px; border-radius:8px; font-size:.75rem; }
.ag-stat b { font-size:1rem; display:block; }
.ag-kill { padding:6px 10px; border-radius:8px; font-size:.7rem; font-weight:700; }
.ag-kill.on { background:#059669; color:#fff; }
.ag-kill.off { background:#dc2626; color:#fff; }

.ag-tabs { display:flex; gap:.3rem; margin-bottom:1rem; flex-wrap:wrap; }
.ag-tab { padding:6px 14px; background:#fff; border:1.5px solid #e5e7eb; border-radius:20px; font-size:.8rem; font-weight:600; cursor:pointer; text-decoration:none; color:#052228; }
.ag-tab.active { background:#052228; color:#fff; border-color:#052228; }
.ag-tab .n { display:inline-block; margin-left:6px; background:#fef3c7; color:#78350f; padding:1px 8px; border-radius:10px; font-size:.7rem; }
.ag-tab.active .n { background:rgba(255,255,255,.2); color:#fff; }

.ag-form { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.25rem; }
.ag-form h3 { margin:0 0 .75rem; font-size:.95rem; color:#052228; display:flex; align-items:center; gap:.5rem; }
.ag-form .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:.75rem; }
.ag-form label { font-size:.75rem; color:#475569; font-weight:600; display:block; margin-bottom:.25rem; }
.ag-form input, .ag-form select, .ag-form textarea { width:100%; padding:8px 10px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:.85rem; font-family:inherit; box-sizing:border-box; }
.ag-form textarea { min-height:88px; resize:vertical; }
.ag-form .row-full { grid-column:1/-1; }
.ag-form .actions { display:flex; gap:.5rem; align-items:center; margin-top:.75rem; }
.ag-form button[type=submit] { background:#059669; color:#fff; border:none; padding:9px 18px; border-radius:8px; font-weight:700; cursor:pointer; font-size:.85rem; }
.ag-form button[type=submit]:hover { background:#047857; }
.ag-form button[type=submit]:disabled { background:#9ca3af; cursor:not-allowed; }
.ag-hint { font-size:.7rem; color:#6b7280; margin-top:.35rem; }
.ag-var { background:#f5ede3; color:#78350f; padding:1px 6px; border-radius:4px; font-family:monospace; font-size:.72rem; cursor:pointer; }

.ag-cli-wrap { position:relative; }
.ag-cli-sug { position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-top:none; border-radius:0 0 8px 8px; max-height:220px; overflow-y:auto; z-index:20; display:none; box-shadow:0 6px 16px rgba(0,0,0,.08); }
.ag-cli-sug.aberto { display:block; }
.ag-cli-sug .item { padding:8px 12px; cursor:pointer; border-bottom:1px solid #f3f4f6; font-size:.82rem; }
.ag-cli-sug .item:hover, .ag-cli-sug .item.hov { background:#f0f9ff; }
.ag-cli-sug .item small { color:#6b7280; display:block; }

.ag-item { background:#fff; border:1px solid #e5e7eb; border-left:4px solid #f59e0b; border-radius:10px; padding:.9rem 1.1rem; margin-bottom:.6rem; }
.ag-item.enviado { border-left-color:#059669; }
.ag-item.falhou { border-left-color:#dc2626; }
.ag-item.cancelado { border-left-color:#9ca3af; opacity:.75; }
.ag-item .top { display:flex; align-items:center; gap:.5rem; margin-bottom:.4rem; flex-wrap:wrap; }
.ag-item .quem { font-weight:700; color:#052228; }
.ag-item .canal { padding:2px 8px; border-radius:6px; font-size:.7rem; font-weight:700; background:#e0f2fe; color:#075985; }
.ag-item .canal.c24 { background:#f0fdf4; color:#166534; }
.ag-item .quando { font-size:.75rem; color:#6b7280; }
.ag-item .quando b { color:#052228; }
.ag-item .msg { background:#f9fafb; border-radius:8px; padding:.6rem .8rem; font-size:.83rem; white-space:pre-wrap; line-height:1.45; margin:.4rem 0; color:#1f2937; }
.ag-item .bot { display:flex; gap:.4rem; align-items:center; font-size:.72rem; color:#6b7280; flex-wrap:wrap; }
.ag-item .badge { padding:2px 8px; border-radius:6px; font-weight:700; font-size:.68rem; text-transform:uppercase; letter-spacing:.3px; }
.ag-item .badge.pend { background:#fef3c7; color:#78350f; }
.ag-item .badge.env  { background:#d1fae5; color:#065f46; }
.ag-item .badge.fal  { background:#fee2e2; color:#991b1b; }
.ag-item .badge.can  { background:#e5e7eb; color:#4b5563; }
.ag-item .cancelar { margin-left:auto; background:#fee2e2; color:#991b1b; border:1px solid #fecaca; padding:4px 12px; border-radius:6px; cursor:pointer; font-size:.72rem; font-weight:600; }
.ag-item .cancelar:hover { background:#fecaca; }
.ag-item .erro { background:#fef2f2; color:#991b1b; border-radius:6px; padding:.4rem .6rem; font-size:.75rem; margin-top:.3rem; }
.ag-vazio { padding:2rem; text-align:center; color:#94a3b8; background:#fff; border:1px dashed #e5e7eb; border-radius:12px; }
</style>

<div class="ag-hdr">
  <div>
    <h2>📅 Agendar Mensagem WhatsApp</h2>
    <div class="sub">Escolha cliente, data/hora e a mensagem. O cron manda automaticamente no horário marcado.</div>
  </div>
  <div class="stats">
    <div class="ag-stat"><b><?= $totPend ?></b>pendentes</div>
    <div class="ag-stat"><b><?= $totProxHora ?></b>próxima hora</div>
    <div class="ag-stat"><b><?= $totEnv7d ?></b>enviados 7d</div>
    <?php if ($totFalhou): ?><div class="ag-stat" style="background:rgba(220,38,38,.25);"><b><?= $totFalhou ?></b>falharam</div><?php endif; ?>
    <span class="ag-kill <?= $killswitchOn ? 'on' : 'off' ?>" title="Killswitch geral do cron"><?= $killswitchOn ? '● LIGADO' : '● DESLIGADO' ?></span>
  </div>
</div>

<div class="ag-form">
  <h3>➕ Novo agendamento</h3>
  <form id="agForm" onsubmit="agSalvar(event)">
    <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="client_id" id="agClientId" value="">
    <div class="grid">
      <div class="ag-cli-wrap" style="grid-column:1/-1;">
        <label>Cliente</label>
        <input type="text" id="agCliente" placeholder="Digite pra buscar…" autocomplete="off" required>
        <div class="ag-cli-sug" id="agCliSug"></div>
        <div class="ag-hint">Telefone é preenchido automaticamente. Você pode editar se necessário.</div>
      </div>
      <div>
        <label>Telefone</label>
        <input type="text" name="telefone" id="agTelefone" placeholder="Ex: 21 99999-9999" required>
      </div>
      <div>
        <label>Canal</label>
        <select name="canal" id="agCanal">
          <option value="24" selected>24 — CX / Operacional</option>
          <option value="21">21 — Comercial</option>
        </select>
      </div>
      <div>
        <label>Data</label>
        <input type="date" name="data" id="agData" required min="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label>Hora</label>
        <input type="time" name="hora" id="agHora" required value="10:00">
      </div>
      <div class="row-full">
        <label>Mensagem</label>
        <textarea name="mensagem" id="agMsg" placeholder="Digite a mensagem que será enviada…" required maxlength="3000"></textarea>
        <div class="ag-hint">
          Variáveis:
          <span class="ag-var" onclick="agInserirVar('{{primeiro_nome}}')">{{primeiro_nome}}</span>
          <span class="ag-var" onclick="agInserirVar('{{nome}}')">{{nome}}</span>
          <span class="ag-var" onclick="agInserirVar('{{data_hoje}}')">{{data_hoje}}</span>
        </div>
      </div>
    </div>
    <div class="actions">
      <button type="submit" id="agBtnSalvar">Agendar</button>
      <span id="agFeedback" style="font-size:.8rem;"></span>
    </div>
  </form>
</div>

<div class="ag-tabs">
  <a href="?aba=pendentes" class="ag-tab <?= $aba === 'pendentes' ? 'active' : '' ?>">⏳ Pendentes <span class="n"><?= $totPend ?></span></a>
  <a href="?aba=historico" class="ag-tab <?= $aba === 'historico' ? 'active' : '' ?>">📜 Histórico</a>
</div>

<?php if (empty($lista)): ?>
  <div class="ag-vazio">
    <?= $aba === 'pendentes' ? 'Nenhum agendamento pendente. Use o form acima pra criar.' : 'Nenhum agendamento no histórico ainda.' ?>
  </div>
<?php else: foreach ($lista as $r):
    $status = $r['status'];
    $nome = $r['client_name_real'] ?: $r['nome_contato'] ?: '(sem nome)';
    $agenda = strtotime($r['agendado_para']);
    $vencido = ($status === 'pendente' && $agenda < time() - 300);
    $classe = 'ag-item ' . $status;
?>
  <div class="<?= e($classe) ?>" data-id="<?= (int)$r['id'] ?>">
    <div class="top">
      <span class="quem"><?= e($nome) ?></span>
      <span class="canal <?= $r['canal'] === '24' ? 'c24' : '' ?>">Canal <?= e($r['canal']) ?></span>
      <span class="quando">Para: <b><?= date('d/m/Y H:i', $agenda) ?></b><?= $vencido ? ' ⚠️ atrasado' : '' ?></span>
      <?php if ($status === 'pendente'): ?>
        <span class="badge pend">Pendente</span>
        <button class="cancelar" onclick="agCancelar(<?= (int)$r['id'] ?>)">✕ Cancelar</button>
      <?php elseif ($status === 'enviado'): ?>
        <span class="badge env">✓ Enviado</span>
      <?php elseif ($status === 'falhou'): ?>
        <span class="badge fal">✕ Falhou</span>
      <?php else: ?>
        <span class="badge can">Cancelado</span>
      <?php endif; ?>
    </div>
    <div class="msg"><?= e($r['mensagem']) ?></div>
    <div class="bot">
      <span>📱 <?= e($r['telefone']) ?></span>
      <?php if (!empty($r['criado_por_nome'])): ?><span>· Criado por <?= e($r['criado_por_nome']) ?></span><?php endif; ?>
      <?php if ($status === 'enviado' && !empty($r['enviado_em'])): ?><span>· Enviado <?= date('d/m/Y H:i', strtotime($r['enviado_em'])) ?></span><?php endif; ?>
      <?php if ($status === 'cancelado' && !empty($r['cancelado_em'])): ?><span>· Cancelado <?= date('d/m/Y H:i', strtotime($r['cancelado_em'])) ?></span><?php endif; ?>
      <?php if ((int)$r['tentativas'] > 0): ?><span>· <?= (int)$r['tentativas'] ?> tentativa(s)</span><?php endif; ?>
    </div>
    <?php if ($status === 'falhou' && !empty($r['erro'])): ?>
      <div class="erro">Erro: <?= e($r['erro']) ?></div>
    <?php endif; ?>
  </div>
<?php endforeach; endif; ?>

<script>
var AG_API = '<?= url('modules/agendar_msg/api.php') ?>';
var AG_CSRF = '<?= e($csrf) ?>';

var agCliInp = document.getElementById('agCliente');
var agCliSug = document.getElementById('agCliSug');
var agClientId = document.getElementById('agClientId');
var agTelefone = document.getElementById('agTelefone');
var agCliTimer = null;
var agCliHov = -1;

agCliInp.addEventListener('input', function() {
  var q = this.value.trim();
  agClientId.value = ''; // reseta se editou
  if (agCliTimer) clearTimeout(agCliTimer);
  if (q.length < 2) { agCliSug.classList.remove('aberto'); return; }
  agCliTimer = setTimeout(function() { agBuscarClientes(q); }, 220);
});

agCliInp.addEventListener('keydown', function(e) {
  var itens = agCliSug.querySelectorAll('.item');
  if (!itens.length) return;
  if (e.key === 'ArrowDown') { e.preventDefault(); agCliHov = Math.min(agCliHov + 1, itens.length - 1); agAtualizarHov(itens); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); agCliHov = Math.max(agCliHov - 1, 0); agAtualizarHov(itens); }
  else if (e.key === 'Enter' && agCliHov >= 0) { e.preventDefault(); itens[agCliHov].click(); }
  else if (e.key === 'Escape') { agCliSug.classList.remove('aberto'); }
});

function agAtualizarHov(itens) {
  itens.forEach(function(it, i) { it.classList.toggle('hov', i === agCliHov); });
}

function agBuscarClientes(q) {
  var fd = new FormData();
  fd.append('action', 'buscar_cliente');
  fd.append('q', q);
  fd.append('csrf_token', AG_CSRF);
  fetch(AG_API, { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (!d.ok || !d.itens || !d.itens.length) { agCliSug.innerHTML = '<div class="item" style="color:#9ca3af;cursor:default;">Nenhum cliente encontrado</div>'; agCliSug.classList.add('aberto'); return; }
      agCliSug.innerHTML = d.itens.map(function(c) {
        return '<div class="item" data-id="' + c.id + '" data-phone="' + (c.phone || '') + '" data-nome="' + agEsc(c.name) + '">' +
          '<div>' + agEsc(c.name) + '</div>' +
          '<small>' + agEsc(c.phone || '(sem telefone)') + (c.case_title ? ' · ' + agEsc(c.case_title) : '') + '</small>' +
          '</div>';
      }).join('');
      agCliSug.classList.add('aberto');
      agCliHov = -1;
      Array.from(agCliSug.querySelectorAll('.item')).forEach(function(it) {
        it.addEventListener('click', function() {
          var id = this.getAttribute('data-id');
          var phone = this.getAttribute('data-phone');
          var nome = this.getAttribute('data-nome');
          agClientId.value = id;
          agCliInp.value = nome;
          if (phone) agTelefone.value = phone;
          agCliSug.classList.remove('aberto');
        });
      });
    });
}

document.addEventListener('click', function(e) {
  if (e.target !== agCliInp && !agCliSug.contains(e.target)) agCliSug.classList.remove('aberto');
});

function agEsc(s) { return (s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

function agInserirVar(v) {
  var ta = document.getElementById('agMsg');
  var i = ta.selectionStart, j = ta.selectionEnd;
  ta.value = ta.value.substring(0, i) + v + ta.value.substring(j);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = i + v.length;
}

function agSalvar(e) {
  e.preventDefault();
  var btn = document.getElementById('agBtnSalvar');
  var fb = document.getElementById('agFeedback');
  fb.textContent = '';
  btn.disabled = true; btn.textContent = 'Salvando…';

  var fd = new FormData(document.getElementById('agForm'));
  fd.append('action', 'criar');

  fetch(AG_API, { method: 'POST', body: fd })
    .then(function(r) {
      if (r.status === 401) { if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); throw new Error('Sessão expirada'); }
      return r.json();
    })
    .then(function(d) {
      btn.disabled = false; btn.textContent = 'Agendar';
      if (d.error) { fb.textContent = '❌ ' + d.error; fb.style.color = '#dc2626'; return; }
      fb.textContent = '✓ Agendado!'; fb.style.color = '#059669';
      setTimeout(function() { location.reload(); }, 500);
    })
    .catch(function(err) {
      btn.disabled = false; btn.textContent = 'Agendar';
      fb.textContent = '❌ ' + err.message; fb.style.color = '#dc2626';
    });
}

function agCancelar(id) {
  if (!confirm('Cancelar este agendamento?')) return;
  var fd = new FormData();
  fd.append('action', 'cancelar');
  fd.append('id', id);
  fd.append('csrf_token', AG_CSRF);
  fetch(AG_API, { method: 'POST', body: fd })
    .then(function(r) {
      if (r.status === 401) { if (window.fsaMostrarSessaoExpirada) window.fsaMostrarSessaoExpirada(); throw new Error('Sessão expirada'); }
      return r.json();
    })
    .then(function(d) {
      if (d.error) { alert('Erro: ' + d.error); return; }
      location.reload();
    })
    .catch(function(err) { alert(err.message); });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
