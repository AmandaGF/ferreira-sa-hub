<?php
/**
 * Wiki — Visualizar artigo
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$userId = current_user_id();
$isGestao = has_min_role('gestao');
$id = (int)($_GET['id'] ?? 0);
if (!$id) { redirect(module_url('wiki')); }

$stmt = $pdo->prepare("SELECT w.*, u.name as autor_nome FROM wiki_artigos w LEFT JOIN users u ON u.id = w.autor_id WHERE w.id = ?");
$stmt->execute(array($id));
$art = $stmt->fetch();
if (!$art) { flash_set('error', 'Artigo não encontrado.'); redirect(module_url('wiki')); }
if (!$art['ativo'] && !$isGestao) { flash_set('error', 'Artigo não disponível.'); redirect(module_url('wiki')); }

// Incrementar views (1x por sessão)
$sessKey = 'wiki_viewed_' . $id;
if (empty($_SESSION[$sessKey])) {
    $pdo->prepare("UPDATE wiki_artigos SET visualizacoes = visualizacoes + 1 WHERE id = ?")->execute(array($id));
    $_SESSION[$sessKey] = true;
    $art['visualizacoes']++;
}

$canEdit = $isGestao || (int)$art['autor_id'] === $userId;

// Versões
$versoes = array();
if ($isGestao) {
    $stmtV = $pdo->prepare("SELECT v.*, u.name as editor_nome FROM wiki_versoes v LEFT JOIN users u ON u.id = v.editado_por WHERE v.artigo_id = ? ORDER BY v.criado_em DESC LIMIT 20");
    $stmtV->execute(array($id));
    $versoes = $stmtV->fetchAll();
}

$pageTitle = $art['titulo'];
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.wiki-breadcrumb{font-size:.72rem;color:var(--text-muted);margin-bottom:.75rem;display:flex;gap:.3rem;flex-wrap:wrap;}
.wiki-breadcrumb a{color:#B87333;text-decoration:none;}
.wiki-breadcrumb a:hover{text-decoration:underline;}
.wiki-article{display:grid;grid-template-columns:1fr 220px;gap:1.25rem;}
@media(max-width:900px){.wiki-article{grid-template-columns:1fr;}.wiki-toc{display:none;}}
.wiki-main{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1.5rem;}
.wiki-main h1{font-size:1.3rem;color:var(--petrol-900);margin:0 0 .5rem;}
.wiki-main-meta{font-size:.7rem;color:var(--text-muted);display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;padding-bottom:.75rem;border-bottom:1px solid var(--border);}
.wiki-content{font-size:.88rem;line-height:1.7;color:var(--text);} .wiki-content h2{font-size:1.05rem;font-weight:700;color:var(--petrol-900);margin:1.5rem 0 .5rem;padding-bottom:.3rem;border-bottom:2px solid rgba(184,115,51,.2);}
.wiki-content h3{font-size:.92rem;font-weight:700;color:var(--petrol-900);margin:1rem 0 .4rem;}
.wiki-content ul,.wiki-content ol{padding-left:1.5rem;margin:.5rem 0;}
.wiki-content li{margin-bottom:.3rem;}
.wiki-content strong{color:var(--petrol-900);}
.wiki-content code{background:rgba(184,115,51,.08);padding:1px 5px;border-radius:3px;font-size:.82rem;}
.wiki-content blockquote{border-left:3px solid #B87333;padding:.5rem 1rem;background:rgba(184,115,51,.04);margin:1rem 0;font-style:italic;color:var(--text-muted);}
.wiki-actions{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);}
.wiki-toc{position:sticky;top:80px;align-self:start;}
.wiki-toc-box{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:.75rem;}
.wiki-toc-box h4{font-size:.75rem;font-weight:700;color:var(--petrol-900);margin:0 0 .5rem;}
.wiki-toc-link{display:block;font-size:.72rem;padding:.2rem 0;color:var(--text-muted);text-decoration:none;border-left:2px solid transparent;padding-left:.5rem;}
.wiki-toc-link:hover{color:#B87333;border-left-color:#B87333;}
.wiki-toc-link.h3{padding-left:1rem;font-size:.68rem;}
.wiki-versoes{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius-lg);padding:1rem;margin-top:1rem;}
</style>

<div class="wiki-breadcrumb">
    <a href="<?= module_url('wiki') ?>">📚 Wiki</a> <span>›</span>
    <a href="<?= module_url('wiki', '?cat=' . urlencode($art['categoria'])) ?>"><?= e($art['categoria']) ?></a> <span>›</span>
    <span><?= e($art['titulo']) ?></span>
</div>

<div class="wiki-article">
    <div class="wiki-main">
        <h1><?php if ($art['fixado']): ?>📌 <?php endif; ?><?= e($art['titulo']) ?></h1>
        <?php if (!$art['ativo']): ?><div style="background:#fef3c7;color:#92400e;padding:.4rem .8rem;border-radius:6px;font-size:.78rem;font-weight:600;margin-bottom:.75rem;">📝 Este artigo é um rascunho (não visível para a equipe geral)</div><?php endif; ?>
        <div class="wiki-main-meta">
            <span>✍️ <?= e($art['autor_nome'] ?: 'Desconhecido') ?></span>
            <span>· 📅 <?= date('d/m/Y', strtotime($art['criado_em'])) ?></span>
            <?php if ($art['atualizado_em']): ?><span>· Atualizado <?= date('d/m/Y H:i', strtotime($art['atualizado_em'])) ?></span><?php endif; ?>
            <span>· 👁 <?= $art['visualizacoes'] ?> visualizações</span>
        </div>
        <div class="wiki-content" id="wikiContent"></div>

        <?php if ($art['tags']): ?>
        <div style="margin-top:1rem;display:flex;gap:.3rem;flex-wrap:wrap;">
            <?php foreach (explode(',', $art['tags']) as $tag): $tag = trim($tag); if (!$tag) continue; ?>
                <a href="<?= module_url('wiki', '?tag=' . urlencode($tag)) ?>" style="font-size:.65rem;padding:2px 8px;border-radius:100px;background:rgba(184,115,51,.1);color:#B87333;text-decoration:none;font-weight:600;">#<?= e($tag) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="wiki-actions">
            <?php if ($canEdit): ?>
                <a href="<?= module_url('wiki', 'editor.php?id=' . $id) ?>" class="btn btn-primary btn-sm" style="background:#B87333;font-size:.72rem;">✏️ Editar</a>
            <?php endif; ?>
            <button onclick="navigator.clipboard.writeText(location.href).then(function(){alert('Link copiado!')})" class="btn btn-outline btn-sm" style="font-size:.72rem;">📋 Copiar Link</button>
            <?php if ($isGestao): ?>
                <form method="POST" action="<?= module_url('wiki', 'api.php') ?>" style="display:inline;" onsubmit="return confirm('<?= $art['fixado'] ? 'Desafixar' : 'Fixar' ?> este artigo?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="toggle_fixado">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;"><?= $art['fixado'] ? '📌 Desafixar' : '📌 Fixar' ?></button>
                </form>
                <?php if ($userRole === 'admin'): ?>
                <form method="POST" action="<?= module_url('wiki', 'api.php') ?>" style="display:inline;" onsubmit="return confirm('Excluir este artigo permanentemente?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="excluir">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button type="submit" class="btn btn-outline btn-sm" style="font-size:.72rem;color:#dc2626;border-color:#dc2626;">🗑️ Excluir</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- TOC -->
    <aside class="wiki-toc">
        <div class="wiki-toc-box">
            <h4>📑 Índice</h4>
            <div id="wikiToc"></div>
        </div>
    </aside>
</div>

<!-- Histórico de versões -->
<?php if ($isGestao && !empty($versoes)): ?>
<div class="wiki-versoes">
    <div style="cursor:pointer;" onclick="var el=document.getElementById('versoesBody');el.style.display=el.style.display==='none'?'block':'none';">
        <h4 style="font-size:.85rem;margin:0;display:flex;align-items:center;gap:.3rem;">📜 Histórico de Versões (<?= count($versoes) ?>) <span style="font-size:.65rem;color:var(--text-muted);">▾ clique para expandir</span></h4>
    </div>
    <div id="versoesBody" style="display:none;margin-top:.75rem;">
        <?php foreach ($versoes as $v): ?>
        <div style="border-left:2px solid #B87333;padding-left:.8rem;margin-bottom:.6rem;">
            <div style="font-size:.72rem;font-weight:700;color:#B87333;"><?= e($v['editor_nome'] ?: 'Desconhecido') ?> — <?= date('d/m/Y H:i', strtotime($v['criado_em'])) ?></div>
            <div style="font-size:.72rem;color:var(--text-muted);max-height:100px;overflow-y:auto;white-space:pre-wrap;margin-top:.2rem;"><?= e(mb_substr($v['conteudo_anterior'], 0, 500, 'UTF-8')) ?><?= mb_strlen($v['conteudo_anterior'], 'UTF-8') > 500 ? '...' : '' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script>
var raw = <?= json_encode($art['conteudo'], JSON_UNESCAPED_UNICODE) ?>;
var html = marked.parse(raw);
document.getElementById('wikiContent').innerHTML = html;
// Gerar TOC
var headings = document.getElementById('wikiContent').querySelectorAll('h2,h3');
var tocHtml = '';
headings.forEach(function(h, i) {
    h.id = 'sec_' + i;
    var cls = h.tagName === 'H3' ? ' h3' : '';
    tocHtml += '<a href="#sec_' + i + '" class="wiki-toc-link' + cls + '">' + h.textContent + '</a>';
});
document.getElementById('wikiToc').innerHTML = tocHtml || '<span style="font-size:.7rem;color:var(--text-muted);">Sem títulos</span>';
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
