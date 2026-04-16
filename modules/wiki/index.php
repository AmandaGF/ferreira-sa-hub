<?php
/**
 * Ferreira & Sá Hub — Wiki / Base de Conhecimento
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$userId = current_user_id();
$isGestao = has_min_role('gestao');
$busca = trim($_GET['q'] ?? '');
$catFiltro = trim($_GET['cat'] ?? '');
$tagFiltro = trim($_GET['tag'] ?? '');

// Categorias
$categorias = array('Processos Internos','Jurídico','RH','TI','Financeiro','Atendimento','Outros');
try {
    $catsDb = $pdo->query("SELECT DISTINCT categoria FROM wiki_artigos WHERE ativo = 1 ORDER BY categoria")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($catsDb as $c) { if (!in_array($c, $categorias)) $categorias[] = $c; }
} catch (Exception $e) {}

// Busca full-text
$artigos = array();
$where = $isGestao ? '1=1' : 'w.ativo = 1';
$params = array();

if ($busca) {
    $where .= " AND MATCH(w.titulo, w.conteudo, w.tags) AGAINST(? IN BOOLEAN MODE)";
    $params[] = $busca;
}
if ($catFiltro) {
    $where .= " AND w.categoria = ?";
    $params[] = $catFiltro;
}
if ($tagFiltro) {
    $where .= " AND w.tags LIKE ?";
    $params[] = '%' . $tagFiltro . '%';
}

try {
    $order = $busca ? "w.fixado DESC, MATCH(w.titulo, w.conteudo, w.tags) AGAINST('$busca' IN BOOLEAN MODE) DESC" : "w.fixado DESC, w.atualizado_em DESC, w.criado_em DESC";
    $stmt = $pdo->prepare(
        "SELECT w.*, u.name as autor_nome FROM wiki_artigos w
         LEFT JOIN users u ON u.id = w.autor_id
         WHERE $where ORDER BY $order LIMIT 50"
    );
    $stmt->execute($params);
    $artigos = $stmt->fetchAll();
} catch (Exception $e) {}

$fixados = array_filter($artigos, function($a) { return $a['fixado']; });

$pageTitle = 'Base de Conhecimento';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.wiki-top{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;}
.wiki-search{position:relative;flex:1;max-width:500px;}
.wiki-search input{width:100%;padding:.6rem .8rem .6rem 2.2rem;border:1.5px solid var(--border);border-radius:10px;font-size:.88rem;background:var(--bg-card);}
.wiki-search input:focus{border-color:#B87333;outline:none;}
.wiki-search::before{content:'🔍';position:absolute;left:.7rem;top:50%;transform:translateY(-50%);font-size:.85rem;}
.wiki-cats{display:flex;gap:.3rem;flex-wrap:wrap;margin-bottom:1rem;}
.wiki-cat-pill{padding:.35rem .75rem;border-radius:100px;font-size:.72rem;font-weight:600;border:1.5px solid var(--border);background:var(--bg-card);color:var(--text-muted);cursor:pointer;text-decoration:none;transition:all .15s;}
.wiki-cat-pill:hover{border-color:#B87333;color:#B87333;}
.wiki-cat-pill.active{background:#052228;color:#fff;border-color:#052228;}
.wiki-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.75rem;}
.wiki-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;transition:all .2s;cursor:pointer;text-decoration:none;color:inherit;display:block;}
.wiki-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);}
.wiki-card-title{font-size:.9rem;font-weight:700;color:var(--petrol-900);margin-bottom:.3rem;display:flex;align-items:center;gap:.3rem;}
.wiki-card-meta{font-size:.65rem;color:var(--text-muted);display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.4rem;}
.wiki-card-snippet{font-size:.75rem;color:var(--text-muted);line-height:1.4;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.wiki-card-tags{display:flex;gap:.2rem;flex-wrap:wrap;margin-top:.4rem;}
.wiki-tag{font-size:.58rem;padding:1px 6px;border-radius:100px;background:rgba(184,115,51,.1);color:#B87333;font-weight:600;}
.wiki-fixados{background:rgba(184,115,51,.04);border:1px solid rgba(184,115,51,.15);border-radius:var(--radius-lg);padding:.75rem 1rem;margin-bottom:1rem;}
.wiki-fixados h4{font-size:.78rem;font-weight:700;color:#B87333;margin:0 0 .5rem;}
.wiki-fixados-list{display:flex;gap:.4rem;flex-wrap:wrap;}
.wiki-fix-link{padding:.35rem .75rem;border-radius:8px;font-size:.78rem;font-weight:600;background:#052228;color:#fff;text-decoration:none;transition:all .15s;}
.wiki-fix-link:hover{background:#B87333;}
</style>

<div class="wiki-top">
    <form method="GET" class="wiki-search" style="display:flex;gap:.3rem;">
        <div style="position:relative;flex:1;">
            <span style="position:absolute;left:.7rem;top:50%;transform:translateY(-50%);font-size:.85rem;">🔍</span>
            <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar em toda a wiki...">
        </div>
        <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;">Buscar</button>
        <?php if ($busca || $catFiltro || $tagFiltro): ?>
            <a href="<?= module_url('wiki') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Limpar</a>
        <?php endif; ?>
    </form>
    <a href="<?= module_url('wiki', 'editor.php') ?>" class="btn btn-primary btn-sm" style="background:#B87333;">+ Novo Artigo</a>
</div>

<!-- Fixados -->
<?php if (!empty($fixados) && !$busca && !$catFiltro && !$tagFiltro): ?>
<div class="wiki-fixados">
    <h4>📌 Fixados</h4>
    <div class="wiki-fixados-list">
        <?php foreach ($fixados as $f): ?>
            <a href="<?= module_url('wiki', 'ver.php?id=' . $f['id']) ?>" class="wiki-fix-link"><?= e($f['titulo']) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Categorias -->
<div class="wiki-cats">
    <a href="<?= module_url('wiki') ?>" class="wiki-cat-pill <?= !$catFiltro ? 'active' : '' ?>">Todos</a>
    <?php foreach ($categorias as $cat): ?>
        <a href="<?= module_url('wiki', '?cat=' . urlencode($cat)) ?>" class="wiki-cat-pill <?= $catFiltro === $cat ? 'active' : '' ?>"><?= e($cat) ?></a>
    <?php endforeach; ?>
</div>

<!-- Grid de artigos -->
<?php if (empty($artigos)): ?>
    <div style="text-align:center;padding:3rem;color:var(--text-muted);">
        <div style="font-size:2.5rem;margin-bottom:.5rem;">📚</div>
        <h3>Nenhum artigo encontrado</h3>
        <p style="font-size:.85rem;">Crie o primeiro artigo clicando em "+ Novo Artigo".</p>
    </div>
<?php else: ?>
<div class="wiki-grid">
    <?php foreach ($artigos as $a): ?>
    <a href="<?= module_url('wiki', 'ver.php?id=' . $a['id']) ?>" class="wiki-card">
        <div class="wiki-card-title">
            <?php if ($a['fixado']): ?><span>📌</span><?php endif; ?>
            <?php if (!$a['ativo']): ?><span style="font-size:.6rem;background:#fef3c7;color:#d97706;padding:1px 5px;border-radius:3px;">📝 Rascunho</span><?php endif; ?>
            <?= e($a['titulo']) ?>
        </div>
        <div class="wiki-card-meta">
            <span><?= e($a['categoria']) ?></span>
            <span>· <?= e(explode(' ', $a['autor_nome'] ?: '')[0]) ?></span>
            <span>· <?= date('d/m/Y', strtotime($a['atualizado_em'] ?: $a['criado_em'])) ?></span>
            <span>· 👁 <?= $a['visualizacoes'] ?></span>
        </div>
        <div class="wiki-card-snippet"><?= e(mb_substr(strip_tags(str_replace(array('#','*','`','-'), '', $a['conteudo'])), 0, 150, 'UTF-8')) ?>...</div>
        <?php if ($a['tags']): ?>
        <div class="wiki-card-tags">
            <?php foreach (explode(',', $a['tags']) as $tag): $tag = trim($tag); if (!$tag) continue; ?>
                <span class="wiki-tag"><?= e($tag) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
