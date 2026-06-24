<?php
/**
 * Contatos Profissionais — cartórios, fóruns/varas, peritos, órgãos públicos,
 * bancos. Tabela separada (contatos_profissionais) pra não misturar com `clients`
 * (que é usado por CRM/Pipeline/Cases e quebraria se virasse genérico).
 *
 * Categorias "Parceiro" e "Audiencista" leem das tabelas próprias já existentes
 * (parceiros, audiencistas) — link pra editar no módulo dedicado. Não duplicam.
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('clientes');

$pdo = db();
$pageTitle = 'Contatos Profissionais';

// ── Self-heal ────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contatos_profissionais (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        categoria VARCHAR(40) NOT NULL,
        nome VARCHAR(200) NOT NULL,
        contato_principal VARCHAR(150) NULL,
        telefone VARCHAR(30) NULL,
        telefone2 VARCHAR(30) NULL,
        email VARCHAR(190) NULL,
        site VARCHAR(255) NULL,
        endereco TEXT NULL,
        cidade VARCHAR(100) NULL,
        uf CHAR(2) NULL,
        horario VARCHAR(100) NULL,
        observacoes TEXT NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_categoria (categoria),
        INDEX idx_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Exception $e) {}

// Categorias suportadas. As 2 últimas (parceiro/audiencista) leem de tabelas próprias.
$CATEGORIAS = array(
    'cartorio'      => array('label' => 'Cartório',       'icon' => '📜', 'cor' => '#7c3aed'),
    'forum'         => array('label' => 'Fórum / Vara',   'icon' => '🏛️', 'cor' => '#0c4a6e'),
    'perito'        => array('label' => 'Perito',         'icon' => '🔬', 'cor' => '#15803d'),
    'orgao_publico' => array('label' => 'Órgão público',  'icon' => '🏢', 'cor' => '#b45309'),
    'banco'         => array('label' => 'Banco',          'icon' => '🏦', 'cor' => '#1e40af'),
    'outro'         => array('label' => 'Outro',          'icon' => '📋', 'cor' => '#475569'),
    // Externas (leem de tabelas próprias):
    'parceiro'      => array('label' => 'Parceiro',       'icon' => '🤝', 'cor' => '#0f3d3e', 'externa' => 'parceiros'),
    'audiencista'   => array('label' => 'Audiencista',    'icon' => '👩‍⚖️', 'cor' => '#b87333', 'externa' => 'audiencistas'),
);

// ── POST handlers (categorias próprias só — parceiro/audiencista são read-only aqui) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { flash_set('error', 'Sessão expirada — tente de novo.'); redirect(module_url('clientes', 'profissionais.php')); }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'salvar') {
        $id  = (int)($_POST['id'] ?? 0);
        $cat = $_POST['categoria'] ?? '';
        if (!isset($CATEGORIAS[$cat]) || isset($CATEGORIAS[$cat]['externa'])) {
            flash_set('error', 'Categoria inválida.'); redirect(module_url('clientes', 'profissionais.php'));
        }
        $nome = clean_str($_POST['nome'] ?? '', 200);
        if ($nome === '') { flash_set('error', 'Informe o nome.'); redirect(module_url('clientes', 'profissionais.php')); }
        $campos = array(
            'categoria' => $cat,
            'nome' => $nome,
            'contato_principal' => clean_str($_POST['contato_principal'] ?? '', 150),
            'telefone' => clean_str($_POST['telefone'] ?? '', 30),
            'telefone2' => clean_str($_POST['telefone2'] ?? '', 30),
            'email' => clean_str($_POST['email'] ?? '', 190),
            'site' => clean_str($_POST['site'] ?? '', 255),
            'endereco' => clean_str($_POST['endereco'] ?? '', 2000),
            'cidade' => clean_str($_POST['cidade'] ?? '', 100),
            'uf' => strtoupper(substr(clean_str($_POST['uf'] ?? '', 2), 0, 2)),
            'horario' => clean_str($_POST['horario'] ?? '', 100),
            'observacoes' => clean_str($_POST['observacoes'] ?? '', 2000),
        );
        if ($id > 0) {
            $setSql = implode(',', array_map(function ($k) { return $k . '=?'; }, array_keys($campos)));
            $params = array_values($campos);
            $params[] = $id;
            $pdo->prepare("UPDATE contatos_profissionais SET $setSql WHERE id=?")->execute($params);
            audit_log('contato_prof_editar', 'contato_prof', $id, $nome);
            flash_set('success', 'Contato atualizado.');
        } else {
            $campos['created_by'] = current_user_id();
            $cols = implode(',', array_keys($campos));
            $ph   = implode(',', array_fill(0, count($campos), '?'));
            $pdo->prepare("INSERT INTO contatos_profissionais ($cols) VALUES ($ph)")->execute(array_values($campos));
            $newId = (int)$pdo->lastInsertId();
            audit_log('contato_prof_criar', 'contato_prof', $newId, $nome);
            flash_set('success', 'Contato cadastrado! 🎉');
        }
        redirect(module_url('clientes', 'profissionais.php?cat=' . urlencode($cat)));
    }

    if ($acao === 'toggle') {
        $id = (int)($_POST['id'] ?? 0); $novo = !empty($_POST['ativar']) ? 1 : 0;
        $pdo->prepare("UPDATE contatos_profissionais SET ativo=? WHERE id=?")->execute(array($novo, $id));
        flash_set('success', $novo ? 'Contato reativado.' : 'Contato arquivado.');
        redirect(module_url('clientes', 'profissionais.php'));
    }
}

// ── Filtros ──────────────────────────────────────────────
$catFiltro = $_GET['cat'] ?? '';
$busca = trim($_GET['q'] ?? '');

// Contadores por categoria (próprias + externas)
$counts = array();
try {
    foreach ($pdo->query("SELECT categoria, COUNT(*) c FROM contatos_profissionais WHERE ativo=1 GROUP BY categoria") as $r) {
        $counts[$r['categoria']] = (int)$r['c'];
    }
} catch (Exception $e) {}
try { $counts['parceiro']    = (int)$pdo->query("SELECT COUNT(*) FROM parceiros WHERE ativo=1")->fetchColumn(); } catch (Exception $e) { $counts['parceiro']    = 0; }
try { $counts['audiencista'] = (int)$pdo->query("SELECT COUNT(*) FROM audiencistas WHERE ativo=1")->fetchColumn(); } catch (Exception $e) { $counts['audiencista'] = 0; }

// Dataset baseado em categoria escolhida
$lista = array();
$isExterna = $catFiltro && isset($CATEGORIAS[$catFiltro]['externa']);

if ($catFiltro === 'parceiro') {
    try {
        $sql = "SELECT id, nome, oab, area, telefone, email, observacoes, ativo FROM parceiros WHERE 1=1";
        $params = array();
        if ($busca !== '') { $sql .= " AND (nome LIKE ? OR area LIKE ?)"; $params[] = "%$busca%"; $params[] = "%$busca%"; }
        $sql .= " ORDER BY ativo DESC, nome ASC LIMIT 300";
        $st = $pdo->prepare($sql); $st->execute($params);
        $lista = $st->fetchAll();
    } catch (Exception $e) {}
} elseif ($catFiltro === 'audiencista') {
    try {
        $sql = "SELECT id, nome, oab, telefone, email, areas, ativo FROM audiencistas WHERE 1=1";
        $params = array();
        if ($busca !== '') { $sql .= " AND (nome LIKE ? OR areas LIKE ?)"; $params[] = "%$busca%"; $params[] = "%$busca%"; }
        $sql .= " ORDER BY ativo DESC, nome ASC LIMIT 300";
        $st = $pdo->prepare($sql); $st->execute($params);
        $lista = $st->fetchAll();
    } catch (Exception $e) {}
} else {
    $where = " WHERE 1=1";
    $params = array();
    if ($catFiltro && isset($CATEGORIAS[$catFiltro])) { $where .= " AND categoria=?"; $params[] = $catFiltro; }
    if ($busca !== '') { $where .= " AND (nome LIKE ? OR cidade LIKE ? OR observacoes LIKE ?)"; $params[] = "%$busca%"; $params[] = "%$busca%"; $params[] = "%$busca%"; }
    try {
        $st = $pdo->prepare("SELECT * FROM contatos_profissionais $where ORDER BY ativo DESC, categoria ASC, nome ASC LIMIT 500");
        $st->execute($params);
        $lista = $st->fetchAll();
    } catch (Exception $e) {}
}

function cp_wa_link($telefone, $msg = '') {
    $d = preg_replace('/\D/', '', (string)$telefone);
    if ($d === '') return '';
    if (substr($d, 0, 2) !== '55') $d = '55' . $d;
    return url('modules/whatsapp/') . '?canal=24&telefone=' . $d . ($msg !== '' ? '&texto=' . rawurlencode($msg) : '');
}

$csrf = generate_csrf_token();
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cp-tabs { display:flex; gap:6px; border-bottom:2px solid #e5e7eb; margin-bottom:14px; flex-wrap:wrap; }
.cp-tab { padding:9px 16px; border:none; background:transparent; font-size:.92rem; font-weight:700; color:#6b7280; cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-2px; text-decoration:none; }
.cp-tab:hover { color:#0f3d3e; }
.cp-tab.active { color:#0f3d3e; border-bottom-color:#0f3d3e; }
.cp-chips { display:flex; gap:6px; margin-bottom:14px; flex-wrap:wrap; }
.cp-chip { display:inline-flex; align-items:center; gap:5px; padding:6px 13px; border-radius:999px; background:#fff; border:1.5px solid #e2e8f0; cursor:pointer; font-size:.84rem; font-weight:600; color:#475569; text-decoration:none; }
.cp-chip:hover { border-color:#0f3d3e; }
.cp-chip.on { background:#0f3d3e; color:#fff; border-color:#0f3d3e; }
.cp-chip .num { background:rgba(0,0,0,.12); border-radius:999px; padding:1px 8px; font-size:.74rem; }
.cp-chip.on .num { background:rgba(255,255,255,.25); }
.cp-card { background:#fff; border-radius:12px; padding:14px 16px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:11px; border-left:4px solid #cbd5e1; }
.cp-card h3 { margin:0; color:#0f3d3e; font-size:1rem; }
.cp-card .meta { color:#666; font-size:.84rem; margin-top:3px; }
.cp-cat-label { display:inline-block; padding:2px 9px; border-radius:6px; font-size:.7rem; font-weight:700; color:#fff; margin-right:6px; }
.cp-mini { background:#0f3d3e; color:#fff; border:none; border-radius:7px; padding:5px 10px; font-weight:600; cursor:pointer; font-size:.78rem; text-decoration:none; display:inline-block; }
.cp-mini.gh { background:#fff; color:#0f3d3e; border:1px solid #cfd8d6; }
.cp-mini.wa { background:#25d366; }
.cp-form-card { background:#fff; border-radius:12px; padding:16px 18px; box-shadow:0 1px 3px rgba(0,0,0,.06); margin-bottom:14px; max-width:820px; }
.cp-form-card label { display:block; font-size:.78rem; font-weight:600; color:#444; margin:0 0 4px; }
.cp-form-card input, .cp-form-card select, .cp-form-card textarea { width:100%; border:1px solid #ddd; border-radius:8px; padding:8px 10px; font-size:.9rem; font-family:inherit; }
.cp-form-card textarea { min-height:60px; resize:vertical; }
.cp-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:11px; margin-bottom:11px; }
.cp-btn { background:#0f3d3e; color:#fff; border:none; border-radius:8px; padding:9px 16px; font-weight:700; cursor:pointer; font-size:.9rem; }
.cp-btn.ghost { background:#fff; color:#0f3d3e; border:1px solid #0f3d3e; }
.cp-busca { display:flex; gap:6px; margin-bottom:12px; max-width:480px; }
.cp-busca input { flex:1; border:1px solid #ddd; border-radius:8px; padding:8px 10px; font-size:.9rem; }
.cp-empty { text-align:center; padding:36px; color:#999; background:#fff; border-radius:10px; }
</style>

<div class="cp-tabs">
  <a href="<?= url('modules/clientes/') ?>" class="cp-tab">👤 Clientes</a>
  <a href="<?= module_url('clientes', 'profissionais.php') ?>" class="cp-tab active">🏛️ Profissionais</a>
</div>

<div class="page-header" style="margin-bottom:.6rem;">
  <h1 style="margin:0;">🏛️ Contatos Profissionais</h1>
  <p style="color:#777;margin:4px 0 0;">Cartórios, fóruns, peritos, parceiros, audiencistas, órgãos públicos e bancos.</p>
</div>

<div class="cp-chips">
  <a href="?" class="cp-chip <?= !$catFiltro ? 'on' : '' ?>">Todos <span class="num"><?= array_sum($counts) ?></span></a>
  <?php foreach ($CATEGORIAS as $key => $c): ?>
    <a href="?cat=<?= urlencode($key) ?>" class="cp-chip <?= $catFiltro === $key ? 'on' : '' ?>"><?= $c['icon'] ?> <?= e($c['label']) ?> <span class="num"><?= (int)($counts[$key] ?? 0) ?></span></a>
  <?php endforeach; ?>
</div>

<form method="get" class="cp-busca">
  <?php if ($catFiltro): ?><input type="hidden" name="cat" value="<?= e($catFiltro) ?>"><?php endif; ?>
  <input type="text" name="q" placeholder="🔍 Buscar por nome ou cidade…" value="<?= e($busca) ?>">
  <button type="submit" class="cp-btn">Buscar</button>
  <?php if ($busca): ?><a href="?cat=<?= e($catFiltro) ?>" class="cp-btn ghost">×</a><?php endif; ?>
</form>

<?php if ($isExterna): ?>
  <div style="background:#e0f2fe;border-radius:10px;padding:10px 14px;margin-bottom:14px;color:#0c4a6e;font-size:.86rem;">
    💡 <b><?= e($CATEGORIAS[$catFiltro]['label']) ?>s</b> tem módulo dedicado.
    <a href="<?= url('modules/' . $CATEGORIAS[$catFiltro]['externa'] . '/') ?>" style="color:#0c4a6e;font-weight:700;">Abrir módulo →</a>
  </div>
<?php else: ?>
  <details class="cp-form-card" id="formCard" <?= !empty($_GET['novo']) || !empty($_GET['edit']) ? 'open' : '' ?>>
    <summary style="font-weight:700;color:#0f3d3e;cursor:pointer;" id="cpSummary">
      <?= !empty($_GET['edit']) ? '✏️ Editando contato' : '➕ Novo contato profissional' ?>
    </summary>
    <?php
    $edit = null;
    if (!empty($_GET['edit'])) {
        $eid = (int)$_GET['edit'];
        $st = $pdo->prepare("SELECT * FROM contatos_profissionais WHERE id=?");
        $st->execute(array($eid));
        $edit = $st->fetch();
    }
    $editCat = $edit ? $edit['categoria'] : ($_GET['cat'] ?? ($catFiltro && !$isExterna ? $catFiltro : 'cartorio'));
    ?>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <input type="hidden" name="acao" value="salvar">
      <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : '' ?>">

      <div class="cp-grid2">
        <div><label>Categoria *</label>
          <select name="categoria" required>
            <?php foreach ($CATEGORIAS as $key => $c): if (isset($c['externa'])) continue; ?>
              <option value="<?= e($key) ?>" <?= $editCat === $key ? 'selected' : '' ?>><?= $c['icon'] ?> <?= e($c['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Nome *</label><input type="text" name="nome" required value="<?= e($edit['nome'] ?? '') ?>" placeholder="Ex: Cartório do 2º Ofício de Barra Mansa"></div>
      </div>
      <div class="cp-grid2">
        <div><label>Pessoa de contato</label><input type="text" name="contato_principal" value="<?= e($edit['contato_principal'] ?? '') ?>" placeholder="Nome de uma pessoa, se houver"></div>
        <div><label>Horário de atendimento</label><input type="text" name="horario" value="<?= e($edit['horario'] ?? '') ?>" placeholder="Ex: Seg-Sex 9h-17h"></div>
      </div>
      <div class="cp-grid2">
        <div><label>Telefone</label><input type="text" name="telefone" value="<?= e($edit['telefone'] ?? '') ?>" placeholder="(00) 00000-0000"></div>
        <div><label>Telefone 2 (alternativo)</label><input type="text" name="telefone2" value="<?= e($edit['telefone2'] ?? '') ?>"></div>
      </div>
      <div class="cp-grid2">
        <div><label>E-mail</label><input type="email" name="email" value="<?= e($edit['email'] ?? '') ?>"></div>
        <div><label>Site</label><input type="text" name="site" value="<?= e($edit['site'] ?? '') ?>" placeholder="https://…"></div>
      </div>
      <div style="margin-bottom:11px;"><label>Endereço</label><textarea name="endereco"><?= e($edit['endereco'] ?? '') ?></textarea></div>
      <div class="cp-grid2">
        <div><label>Cidade</label><input type="text" name="cidade" value="<?= e($edit['cidade'] ?? '') ?>"></div>
        <div><label>UF</label><input type="text" name="uf" maxlength="2" value="<?= e($edit['uf'] ?? '') ?>" placeholder="RJ"></div>
      </div>
      <div style="margin-bottom:11px;"><label>Observações</label><textarea name="observacoes"><?= e($edit['observacoes'] ?? '') ?></textarea></div>
      <button type="submit" class="cp-btn"><?= $edit ? '💾 Salvar alterações' : '➕ Cadastrar contato' ?></button>
      <?php if ($edit): ?><a href="?cat=<?= e($catFiltro) ?>" class="cp-btn ghost">Cancelar</a><?php endif; ?>
    </form>
  </details>
<?php endif; ?>

<?php if (empty($lista)): ?>
  <div class="cp-empty">
    <?= $busca ? 'Nada encontrado pra "<b>' . e($busca) . '</b>".' : 'Nenhum contato nesta categoria ainda.' ?>
    <?php if (!$isExterna && !$busca): ?><br><br><a href="?<?= $catFiltro ? 'cat=' . urlencode($catFiltro) . '&' : '' ?>novo=1#formCard" class="cp-btn">➕ Cadastrar o primeiro</a><?php endif; ?>
  </div>
<?php else: foreach ($lista as $r):
    if ($catFiltro === 'parceiro') {
        $catKey = 'parceiro'; $catInfo = $CATEGORIAS['parceiro'];
        $infoLine = ($r['oab'] ? '⚖️ OAB ' . e($r['oab']) . ' · ' : '') . ($r['area'] ? '🏷️ ' . e($r['area']) . ' · ' : '');
        $editUrl = url('modules/parceiros/');
    } elseif ($catFiltro === 'audiencista') {
        $catKey = 'audiencista'; $catInfo = $CATEGORIAS['audiencista'];
        $infoLine = ($r['oab'] ? '⚖️ OAB ' . e($r['oab']) . ' · ' : '') . ($r['areas'] ? '🗺️ ' . e(mb_substr($r['areas'], 0, 50)) . ' · ' : '');
        $editUrl = url('modules/audiencistas/');
    } else {
        $catKey = $r['categoria']; $catInfo = $CATEGORIAS[$catKey] ?? array('icon' => '📋', 'label' => $catKey, 'cor' => '#475569');
        $cidUf = trim(($r['cidade'] ?? '') . (!empty($r['uf']) ? '/' . $r['uf'] : ''), '/');
        $infoLine = ($cidUf ? '📍 ' . e($cidUf) . ' · ' : '') . ($r['horario'] ? '🕐 ' . e($r['horario']) . ' · ' : '');
        $editUrl = '?cat=' . urlencode($catKey) . '&edit=' . (int)$r['id'] . '#formCard';
    }
    $waLink = !empty($r['telefone']) ? cp_wa_link($r['telefone']) : '';
?>
<div class="cp-card" style="border-left-color:<?= $catInfo['cor'] ?>;<?= (int)($r['ativo'] ?? 1) !== 1 ? 'opacity:.55;' : '' ?>">
  <div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:flex-start;">
    <div style="flex:1;min-width:240px;">
      <h3><span class="cp-cat-label" style="background:<?= $catInfo['cor'] ?>;"><?= $catInfo['icon'] ?> <?= e($catInfo['label']) ?></span><?= e($r['nome']) ?>
        <?php if ((int)($r['ativo'] ?? 1) !== 1): ?><span style="font-size:.7rem;color:#b91c1c;">(arquivado)</span><?php endif; ?>
      </h3>
      <?php if (!empty($r['contato_principal'])): ?><div class="meta">👤 <?= e($r['contato_principal']) ?></div><?php endif; ?>
      <div class="meta">
        <?= $infoLine ?>
        <?= !empty($r['telefone']) ? '📞 ' . e($r['telefone']) . ' · ' : '' ?>
        <?= !empty($r['telefone2']) ? '📞 ' . e($r['telefone2']) . ' · ' : '' ?>
        <?= !empty($r['email']) ? '✉️ ' . e($r['email']) : '' ?>
      </div>
      <?php if (!empty($r['site'])): ?><div class="meta">🌐 <a href="<?= e($r['site']) ?>" target="_blank" rel="noopener"><?= e($r['site']) ?></a></div><?php endif; ?>
      <?php if (!empty($r['endereco'])): ?><div class="meta">📍 <?= nl2br(e($r['endereco'])) ?></div><?php endif; ?>
      <?php if (!empty($r['observacoes'])): ?><div class="meta" style="margin-top:5px;color:#777;font-style:italic;"><?= nl2br(e($r['observacoes'])) ?></div><?php endif; ?>
    </div>
    <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:flex-start;">
      <?php if ($waLink): ?><a href="<?= e($waLink) ?>" class="cp-mini wa">💬 WhatsApp</a><?php endif; ?>
      <a href="<?= e($editUrl) ?>" class="cp-mini gh">✏️ <?= $isExterna ? 'Abrir' : 'Editar' ?></a>
      <?php if (!$isExterna): ?>
        <form method="post" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="acao" value="toggle">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input type="hidden" name="ativar" value="<?= (int)$r['ativo'] === 1 ? '0' : '1' ?>">
          <button type="submit" class="cp-mini gh"><?= (int)$r['ativo'] === 1 ? '🗄️' : '♻️' ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
