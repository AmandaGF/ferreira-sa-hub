<?php
/**
 * Painel de acompanhamento dos shortlinks WhatsApp.
 * Amanda 07/07/2026.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
// Amanda 07/07/2026: liberado pra todos os colaboradores do escritorio.
// Killswitch (ligar/desligar) protegido dentro do handler POST.
$_slIsGestao = has_min_role('gestao');

$pdo = db();
$pageTitle = '🔗 Rastreio de Cliques';

$filtro = $_GET['filtro'] ?? 'engajados';
if (!in_array($filtro, array('engajados','recentes','sem_clique','todos'), true)) $filtro = 'engajados';
$busca = trim($_GET['q'] ?? '');

// Estatísticas gerais
$totLinks = (int)$pdo->query("SELECT COUNT(*) FROM short_links")->fetchColumn();
$totCliques = (int)$pdo->query("SELECT COUNT(*) FROM link_clicks")->fetchColumn();
$totClicados = (int)$pdo->query("SELECT COUNT(*) FROM short_links WHERE cliques_total > 0")->fetchColumn();
$totCli7d = (int)$pdo->query("SELECT COUNT(DISTINCT link_id) FROM link_clicks WHERE clicked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// Killswitch
$killswitch = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='shortlinks_ativo'")->fetchColumn();
$killswitchOn = ($killswitch === '1');

// Toggle killswitch — só admin/gestão liga/desliga
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf() && $_slIsGestao) {
    if (($_POST['action'] ?? '') === 'toggle_kill') {
        $novo = $killswitchOn ? '0' : '1';
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('shortlinks_ativo', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute(array($novo));
        flash_set('success', 'Rastreio de cliques ' . ($novo === '1' ? 'LIGADO' : 'DESLIGADO'));
        redirect($_SERVER['REQUEST_URI']);
    }
}

// Query principal
$where = '';
$params = array();
switch ($filtro) {
    case 'engajados':
        $where = 'WHERE sl.cliques_total > 0';
        break;
    case 'recentes':
        $where = 'WHERE sl.ultimo_clique_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)';
        break;
    case 'sem_clique':
        $where = 'WHERE sl.cliques_total = 0 AND sl.criado_em >= DATE_SUB(NOW(), INTERVAL 14 DAY)';
        break;
    case 'todos':
        $where = '';
        break;
}

if ($busca !== '') {
    $like = '%' . $busca . '%';
    $extra = " (c.name LIKE ? OR sl.url_original LIKE ? OR sl.codigo = ?)";
    $where = $where ? ($where . ' AND' . $extra) : ('WHERE ' . $extra);
    $params[] = $like; $params[] = $like; $params[] = $busca;
}

$sql = "SELECT sl.id, sl.codigo, sl.url_original, sl.canal, sl.cliques_total,
               sl.ultimo_clique_em, sl.criado_em, sl.criado_por,
               sl.client_id, sl.lead_id, sl.case_id, sl.conversa_id,
               c.name AS client_name, c.phone AS client_phone,
               u.name AS criador_name,
               co.telefone AS conversa_telefone,
               co.nome_contato AS conversa_nome,
               (SELECT title FROM cases WHERE id = sl.case_id) AS case_title,
               (SELECT name FROM pipeline_leads WHERE id = sl.lead_id) AS lead_name
        FROM short_links sl
        LEFT JOIN clients c ON c.id = sl.client_id
        LEFT JOIN users u ON u.id = sl.criado_por
        LEFT JOIN zapi_conversas co ON co.id = sl.conversa_id
        {$where}
        ORDER BY " . ($filtro === 'sem_clique' ? 'sl.criado_em DESC' : 'sl.ultimo_clique_em DESC, sl.criado_em DESC') . "
        LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$links = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fallback: pra links "sem vínculo" mas COM conversa/telefone, tentar achar
// lead ativo por match dos últimos 8 dígitos do telefone. Amanda 07/07/2026:
// links criados via conversa aberta no WA nao guardam lead_id, mas ainda vale
// mostrar quem é (o comercial precisa saber pra fechar contrato).
$_leadsPorTel = array(); // sufixo_8 => ['id' => N, 'name' => X, 'stage' => S]
try {
    $telefonesParaBuscar = array();
    foreach ($links as $l) {
        if (empty($l['client_id']) && empty($l['lead_id']) && empty($l['case_id']) && !empty($l['conversa_telefone'])) {
            $suf = substr(preg_replace('/\D/', '', $l['conversa_telefone']), -8);
            if ($suf) $telefonesParaBuscar[$suf] = true;
        }
    }
    if ($telefonesParaBuscar) {
        // Pega leads ATIVOS (nao arquivados/perdidos) com telefone terminando nos sufixos
        $st = $pdo->query("SELECT id, name, stage, phone FROM pipeline_leads
                           WHERE stage NOT IN ('arquivado','perdido','cancelado')
                             AND phone IS NOT NULL AND phone <> ''
                           ORDER BY updated_at DESC LIMIT 2000");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pl) {
            $suf = substr(preg_replace('/\D/', '', $pl['phone']), -8);
            if ($suf && isset($telefonesParaBuscar[$suf]) && !isset($_leadsPorTel[$suf])) {
                $_leadsPorTel[$suf] = array('id' => (int)$pl['id'], 'name' => $pl['name'], 'stage' => $pl['stage']);
            }
        }
    }
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.sl-wrap { max-width: 1200px; margin: 0 auto; }
.sl-hero { background: linear-gradient(135deg,#052228,#0369a1); color:#fff; padding: 1.2rem 1.5rem; border-radius: 14px; margin-bottom: 1.25rem; display:flex; align-items:center; gap:1rem; flex-wrap:wrap; }
.sl-hero h1 { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; margin: 0 0 .25rem; font-weight: 600; color:#fff; }
.sl-hero .sub { font-size: .85rem; opacity: .85; }
.sl-hero .stats { margin-left:auto; display:flex; gap:.6rem; flex-wrap:wrap; }
.sl-stat { background:rgba(255,255,255,.1); padding:8px 14px; border-radius:8px; text-align:center; }
.sl-stat b { display:block; font-family:'Cormorant Garamond',serif; font-size:1.35rem; color:#fff; }
.sl-stat .k { font-size:.68rem; opacity:.8; text-transform:uppercase; letter-spacing:.06em; }
.sl-kill { padding:6px 12px; border-radius:8px; font-size:.72rem; font-weight:700; margin-left:auto; }
.sl-kill.on { background:#059669; color:#fff; }
.sl-kill.off { background:#dc2626; color:#fff; }

.sl-toolbar { display:flex; gap:.5rem; margin-bottom:1rem; flex-wrap:wrap; align-items:center; }
.sl-tab { padding:6px 14px; background:#fff; border:1.5px solid #e5e7eb; border-radius:20px; font-size:.8rem; font-weight:600; color:#052228; text-decoration:none; }
.sl-tab.on { background:#0369a1; color:#fff; border-color:#0369a1; }
.sl-tab .n { display:inline-block; background:rgba(255,255,255,.2); padding:1px 7px; border-radius:8px; font-size:.68rem; margin-left:5px; }
.sl-tab:not(.on) .n { background:#fef3c7; color:#78350f; }
.sl-search { padding:6px 12px; border:1.5px solid #e5e7eb; border-radius:8px; font-size:.85rem; font-family:inherit; min-width:220px; }

.sl-tabela { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; font-size:.82rem; }
.sl-tabela thead { background:#052228; color:#fff; }
.sl-tabela th { padding:10px 14px; text-align:left; font-size:.68rem; text-transform:uppercase; letter-spacing:.06em; font-weight:700; }
.sl-tabela td { padding:10px 14px; border-bottom:1px solid #f3f4f6; vertical-align:top; }
.sl-tabela tr:hover td { background:#fafbfc; }
.sl-tabela tr.hot td { background:#fef9c3; }
.sl-tabela tr.hot:hover td { background:#fef3c7; }
.sl-tabela a { color:#0369a1; text-decoration:none; font-weight:600; }
.sl-tabela a:hover { text-decoration:underline; }
.sl-tabela .badge { display:inline-block; font-size:.62rem; padding:1px 7px; border-radius:5px; font-weight:700; }
.sl-tabela .badge.canal-21 { background:#e0f2fe; color:#075985; }
.sl-tabela .badge.canal-24 { background:#f0fdf4; color:#166534; }
.sl-tabela .badge.hot { background:#fee2e2; color:#991b1b; }
.sl-tabela .cliques { font-family:'Cormorant Garamond', serif; font-size:1.15rem; font-weight:600; color:#0369a1; }
.sl-tabela .url { font-family:'JetBrains Mono', ui-monospace, monospace; font-size:.72rem; color:#052228; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:inline-block; vertical-align:middle; }
.sl-tabela .meta { font-size:.68rem; color:#8b7a68; }
.sl-vazio { padding:2.5rem; text-align:center; color:#94a3b8; }

.sl-detalhes { margin-top:.25rem; font-size:.72rem; color:#8b7a68; }
.sl-copy-btn { background:#fff; border:1px solid #e5e7eb; color:#052228; padding:2px 8px; border-radius:5px; font-size:.68rem; cursor:pointer; }
.sl-copy-btn:hover { border-color:#0369a1; color:#0369a1; }
.sl-copy-btn.ok { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }
</style>

<div class="sl-wrap">

<div class="sl-hero">
  <div>
    <h1>🔗 Rastreio de Cliques</h1>
    <div class="sub">Toda URL enviada por WhatsApp vira link curto rastreável. Aqui você acompanha quem clicou.</div>
  </div>
  <div class="stats">
    <div class="sl-stat"><b><?= number_format($totLinks, 0, ',', '.') ?></b><span class="k">Links criados</span></div>
    <div class="sl-stat"><b><?= number_format($totClicados, 0, ',', '.') ?></b><span class="k">Com clique</span></div>
    <div class="sl-stat"><b><?= number_format($totCliques, 0, ',', '.') ?></b><span class="k">Cliques total</span></div>
    <div class="sl-stat"><b><?= number_format($totCli7d, 0, ',', '.') ?></b><span class="k">Ativos 7d</span></div>
  </div>
</div>

<div class="sl-toolbar">
  <a href="?filtro=engajados<?= $busca ? '&q=' . urlencode($busca) : '' ?>" class="sl-tab <?= $filtro==='engajados'?'on':'' ?>">
    🔥 Engajados <span class="n"><?= $totClicados ?></span>
  </a>
  <a href="?filtro=recentes<?= $busca ? '&q=' . urlencode($busca) : '' ?>" class="sl-tab <?= $filtro==='recentes'?'on':'' ?>">
    ⚡ Clique nas últimas 24h <span class="n"><?= (int)$pdo->query("SELECT COUNT(*) FROM short_links WHERE ultimo_clique_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn() ?></span>
  </a>
  <a href="?filtro=sem_clique<?= $busca ? '&q=' . urlencode($busca) : '' ?>" class="sl-tab <?= $filtro==='sem_clique'?'on':'' ?>">
    ❄️ Sem clique (14d) <span class="n"><?= (int)$pdo->query("SELECT COUNT(*) FROM short_links WHERE cliques_total = 0 AND criado_em >= DATE_SUB(NOW(), INTERVAL 14 DAY)")->fetchColumn() ?></span>
  </a>
  <a href="?filtro=todos<?= $busca ? '&q=' . urlencode($busca) : '' ?>" class="sl-tab <?= $filtro==='todos'?'on':'' ?>">📋 Todos</a>

  <form method="GET" style="margin-left:auto;display:flex;gap:.4rem;align-items:center;">
    <input type="hidden" name="filtro" value="<?= e($filtro) ?>">
    <input type="text" name="q" class="sl-search" placeholder="Buscar por cliente, URL ou código…" value="<?= e($busca) ?>">
    <?php if ($busca): ?><a href="?filtro=<?= e($filtro) ?>" style="color:#8b7a68;font-size:.72rem;">✕ limpar</a><?php endif; ?>
  </form>

  <?php if ($_slIsGestao): ?>
  <form method="POST" style="margin:0;">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="toggle_kill">
    <button type="submit" class="sl-kill <?= $killswitchOn ? 'on' : 'off' ?>" title="Killswitch geral" onclick="return confirm('<?= $killswitchOn ? 'Desligar' : 'Ligar' ?> rastreio de cliques?')">
      ● <?= $killswitchOn ? 'LIGADO' : 'DESLIGADO' ?>
    </button>
  </form>
  <?php else: ?>
  <span class="sl-kill <?= $killswitchOn ? 'on' : 'off' ?>" style="cursor:default;opacity:.85;" title="Somente admin/gestão liga ou desliga">
    ● <?= $killswitchOn ? 'LIGADO' : 'DESLIGADO' ?>
  </span>
  <?php endif; ?>
</div>

<?php if (empty($links)): ?>
  <div class="sl-vazio">
    <?php if ($busca): ?>Nenhum link encontrado pra "<em><?= e($busca) ?></em>".<?php else: ?>Nenhum link nesse filtro ainda.<?php endif; ?>
  </div>
<?php else: ?>
<table class="sl-tabela">
  <thead>
    <tr>
      <th>Cliente / Lead / Case</th>
      <th>URL destino</th>
      <th>Canal</th>
      <th>Cliques</th>
      <th>Último clique</th>
      <th>Enviado por</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($links as $l):
    $hot = $l['cliques_total'] >= 5;
    $urlCurta = 'https://ferreiraesa.com.br/conecta/l/' . $l['codigo'];
    $ultimo = $l['ultimo_clique_em'] ? strtotime($l['ultimo_clique_em']) : 0;
    $criado = strtotime($l['criado_em']);
  ?>
    <tr class="<?= $hot ? 'hot' : '' ?>">
      <td>
        <?php
        // Match por telefone quando sem vínculo direto
        $_leadFallback = null;
        if (empty($l['client_name']) && empty($l['lead_name']) && empty($l['case_title']) && !empty($l['conversa_telefone'])) {
            $_sufTel = substr(preg_replace('/\D/', '', $l['conversa_telefone']), -8);
            if ($_sufTel && isset($_leadsPorTel[$_sufTel])) $_leadFallback = $_leadsPorTel[$_sufTel];
        }
        ?>
        <?php if ($l['client_name']): ?>
          <strong><?= e($l['client_name']) ?></strong>
          <?php if ($l['client_phone']): ?><div class="meta">📱 <?= e($l['client_phone']) ?></div><?php endif; ?>
        <?php elseif ($l['lead_name']): ?>
          <strong><?= e($l['lead_name']) ?></strong> <span class="meta">(lead)</span>
        <?php elseif ($l['case_title']): ?>
          <strong><?= e($l['case_title']) ?></strong> <span class="meta">(pasta)</span>
        <?php elseif ($_leadFallback): ?>
          <strong><?= e($_leadFallback['name']) ?></strong> <span class="meta">(lead · <?= e($_leadFallback['stage']) ?>)</span>
          <?php if ($l['conversa_telefone']): ?><div class="meta">📱 <?= e($l['conversa_telefone']) ?></div><?php endif; ?>
        <?php elseif ($l['conversa_nome']): ?>
          <span style="color:#052228;"><?= e($l['conversa_nome']) ?></span>
          <div class="meta">📱 <?= e($l['conversa_telefone'] ?: '—') ?> <span style="opacity:.7;">(contato WA, sem lead)</span></div>
        <?php elseif ($l['conversa_telefone']): ?>
          <span style="color:#052228;">📱 <?= e($l['conversa_telefone']) ?></span>
          <div class="meta">(contato WA, sem lead)</div>
        <?php else: ?>
          <span class="meta">Sem vínculo</span>
        <?php endif; ?>
        <?php if ($l['case_title'] && $l['client_name']): ?>
          <div class="meta">📁 <?= e($l['case_title']) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <a href="<?= e($l['url_original']) ?>" target="_blank" class="url" title="<?= e($l['url_original']) ?>"><?= e($l['url_original']) ?></a>
        <div class="sl-detalhes">
          <span class="url" title="Link enviado ao cliente">/l/<?= e($l['codigo']) ?></span>
          <button type="button" class="sl-copy-btn" onclick="slCopiar(this, '<?= e($urlCurta) ?>')">📋</button>
        </div>
      </td>
      <td>
        <?php if ($l['canal']): ?>
          <span class="badge canal-<?= e($l['canal']) ?>">Canal <?= e($l['canal']) ?></span>
        <?php else: ?>
          <span class="meta">—</span>
        <?php endif; ?>
      </td>
      <td>
        <span class="cliques"><?= (int)$l['cliques_total'] ?></span>
        <?php if ($hot): ?><br><span class="badge hot">🔥 Quente</span><?php endif; ?>
      </td>
      <td>
        <?php if ($ultimo): ?>
          <?php
            $dif = time() - $ultimo;
            if ($dif < 3600) $rel = floor($dif/60) . ' min';
            elseif ($dif < 86400) $rel = floor($dif/3600) . ' h';
            elseif ($dif < 604800) $rel = floor($dif/86400) . ' d';
            else $rel = date('d/m', $ultimo);
          ?>
          <strong>há <?= $rel ?></strong>
          <div class="meta"><?= date('d/m/Y H:i', $ultimo) ?></div>
        <?php else: ?>
          <span class="meta">nunca</span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($l['criador_name']): ?>
          <?= e(explode(' ', $l['criador_name'])[0]) ?>
        <?php else: ?>
          <span class="meta">—</span>
        <?php endif; ?>
        <div class="meta"><?= date('d/m/Y', $criado) ?></div>
      </td>
      <td>
        <?php if ($l['lead_id']): ?>
          <a href="<?= url('modules/pipeline/lead_ver.php?id=' . (int)$l['lead_id']) ?>" title="Abrir lead">👉</a>
        <?php elseif ($l['case_id']): ?>
          <a href="<?= url('modules/operacional/caso_ver.php?id=' . (int)$l['case_id']) ?>" title="Abrir pasta">👉</a>
        <?php elseif ($l['client_id']): ?>
          <a href="<?= url('modules/clientes/ver.php?id=' . (int)$l['client_id']) ?>" title="Abrir cliente">👉</a>
        <?php elseif (!empty($_leadFallback)): ?>
          <a href="<?= url('modules/pipeline/lead_ver.php?id=' . (int)$_leadFallback['id']) ?>" title="Abrir lead (achado por telefone)">👉</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>

</div>

<script>
function slCopiar(btn, url) {
  var texto = btn.textContent;
  var ok = function(){ btn.classList.add('ok'); btn.textContent = '✓'; setTimeout(function(){ btn.classList.remove('ok'); btn.textContent = texto; }, 1500); };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(url).then(ok).catch(function(){ ok(); });
  } else {
    var ta = document.createElement('textarea'); ta.value = url; ta.style.position='fixed'; ta.style.left='-9999px'; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); ok(); } catch(e) {}
    document.body.removeChild(ta);
  }
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
