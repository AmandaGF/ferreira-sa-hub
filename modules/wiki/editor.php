<?php
/**
 * Wiki — Editor de artigo (novo / editar)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$userId = current_user_id();
$isGestao = has_min_role('gestao');
$id = (int)($_GET['id'] ?? 0);

$art = null;
if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM wiki_artigos WHERE id = ?");
    $stmt->execute(array($id));
    $art = $stmt->fetch();
    if (!$art) { flash_set('error', 'Artigo não encontrado.'); redirect(module_url('wiki')); }
    if (!$isGestao && (int)$art['autor_id'] !== $userId) {
        flash_set('error', 'Sem permissão para editar este artigo.');
        redirect(module_url('wiki', 'ver.php?id=' . $id));
    }
}

$categorias = array('Processos Internos','Jurídico','RH','TI','Financeiro','Atendimento','Outros');

$pageTitle = $art ? 'Editar Artigo' : 'Novo Artigo';
require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.wiki-editor{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;}
@media(max-width:900px){.wiki-editor{grid-template-columns:1fr;}}
.wiki-editor textarea{width:100%;min-height:400px;font-family:'Consolas','Monaco',monospace;font-size:.85rem;padding:.75rem;border:1.5px solid var(--border);border-radius:8px;resize:vertical;line-height:1.6;tab-size:4;}
.wiki-editor textarea:focus{border-color:#B87333;outline:none;}
.wiki-preview{background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:.75rem;min-height:400px;overflow-y:auto;font-size:.88rem;line-height:1.7;}
.wiki-preview h2{font-size:1.05rem;font-weight:700;color:var(--petrol-900);margin:1.2rem 0 .4rem;padding-bottom:.3rem;border-bottom:2px solid rgba(184,115,51,.2);}
.wiki-preview h3{font-size:.92rem;font-weight:700;color:var(--petrol-900);margin:.8rem 0 .3rem;}
.wiki-preview ul,.wiki-preview ol{padding-left:1.5rem;}
.wiki-preview code{background:rgba(184,115,51,.08);padding:1px 5px;border-radius:3px;font-size:.82rem;}
.wiki-preview blockquote{border-left:3px solid #B87333;padding:.5rem 1rem;background:rgba(184,115,51,.04);margin:1rem 0;font-style:italic;}
</style>

<a href="<?= $art ? module_url('wiki', 'ver.php?id=' . $id) : module_url('wiki') ?>" style="font-size:.82rem;color:#B87333;text-decoration:none;font-weight:600;margin-bottom:.75rem;display:inline-block;">← Voltar</a>

<form method="POST" action="<?= module_url('wiki', 'api.php') ?>">
    <?= csrf_input() ?>
    <input type="hidden" name="action" value="salvar_artigo">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div style="display:grid;grid-template-columns:1fr 200px 200px;gap:.6rem;margin-bottom:.75rem;">
        <div>
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Título *</label>
            <input type="text" name="titulo" class="form-input" required value="<?= e($art['titulo'] ?? '') ?>" placeholder="Título do artigo">
        </div>
        <div>
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Categoria *</label>
            <select name="categoria" class="form-select" required>
                <?php foreach ($categorias as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= ($art && $art['categoria'] === $cat) ? 'selected' : '' ?>><?= e($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Tags (separadas por vírgula)</label>
            <input type="text" name="tags" class="form-input" value="<?= e($art['tags'] ?? '') ?>" placeholder="Ex: cliente,cadastro">
        </div>
    </div>

    <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:.4rem;">Escreva em <strong>Markdown</strong> (# Título, ## Subtítulo, **negrito**, - lista, > citação)</div>

    <div class="wiki-editor">
        <textarea name="conteudo" id="wikiTextarea" required oninput="atualizarPreview()"><?= e($art['conteudo'] ?? '') ?></textarea>
        <div class="wiki-preview" id="wikiPreview">
            <span style="color:var(--text-muted);font-size:.82rem;">Preview aparecerá aqui...</span>
        </div>
    </div>

    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
        <a href="<?= $art ? module_url('wiki', 'ver.php?id=' . $id) : module_url('wiki') ?>" class="btn btn-outline btn-sm">Cancelar</a>
        <button type="submit" name="status" value="0" class="btn btn-outline btn-sm" style="color:#d97706;border-color:#d97706;">📝 Salvar Rascunho</button>
        <button type="submit" name="status" value="1" class="btn btn-primary btn-sm" style="background:#059669;">✅ Publicar</button>
        <?php if ($isGestao && $art): ?>
            <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;margin-left:auto;cursor:pointer;">
                <input type="checkbox" name="fixado" value="1" <?= ($art && $art['fixado']) ? 'checked' : '' ?>>
                📌 Fixar no topo
            </label>
        <?php endif; ?>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script>
function atualizarPreview() {
    var raw = document.getElementById('wikiTextarea').value;
    document.getElementById('wikiPreview').innerHTML = marked.parse(raw) || '<span style="color:var(--text-muted);">Preview aparecerá aqui...</span>';
}
atualizarPreview();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
