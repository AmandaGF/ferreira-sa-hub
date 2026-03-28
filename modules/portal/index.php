<?php
/**
 * Ferreira & Sá Hub — Portal do Colaborador
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Portal de Links';
$pdo = db();
$isAdmin = has_role('admin');

// Buscar links do banco
$links = $pdo->query('SELECT * FROM portal_links ORDER BY category ASC, sort_order ASC, title ASC')->fetchAll();

// Agrupar por categoria
$categories = [];
foreach ($links as $link) {
    $cat = $link['category'] ?: 'Sem categoria';
    if (!isset($categories[$cat])) {
        $categories[$cat] = [];
    }
    $categories[$cat][] = $link;
}

$allCategories = array_keys($categories);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
/* Portal específico */
.portal-controls {
    display: flex;
    gap: .75rem;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 1.5rem;
}

.portal-search {
    flex: 1;
    min-width: 200px;
    position: relative;
}

.portal-search input {
    width: 100%;
    padding: .65rem 1rem .65rem 2.5rem;
    font-family: var(--font);
    font-size: .88rem;
    border: 1.5px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-card);
    outline: none;
    transition: border-color var(--transition), box-shadow var(--transition);
}

.portal-search input:focus {
    border-color: var(--rose);
    box-shadow: 0 0 0 3px rgba(215,171,144,.2);
}

.portal-search .search-icon {
    position: absolute;
    left: .85rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: .9rem;
    opacity: .4;
}

.portal-filters {
    display: flex;
    gap: .35rem;
    flex-wrap: wrap;
}

.filter-chip {
    padding: .35rem .8rem;
    font-size: .75rem;
    font-weight: 600;
    border-radius: 100px;
    border: 1.5px solid var(--border);
    background: var(--bg-card);
    color: var(--text-muted);
    cursor: pointer;
    transition: all var(--transition);
    white-space: nowrap;
}

.filter-chip:hover { border-color: var(--petrol-300); color: var(--petrol-500); }
.filter-chip.active { background: var(--petrol-900); color: #fff; border-color: var(--petrol-900); }

.portal-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
    gap: 1rem;
}

.link-card {
    background: var(--bg-card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    overflow: hidden;
    transition: box-shadow var(--transition);
}

.link-card:hover { box-shadow: var(--shadow-md); }

.link-card-header {
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
}

.link-title {
    font-size: .92rem;
    font-weight: 700;
    color: var(--petrol-900);
    display: flex;
    align-items: center;
    gap: .5rem;
    flex: 1;
    min-width: 0;
}

.link-title span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.link-fav { color: var(--rose); font-size: .9rem; flex-shrink: 0; }

.link-card-body {
    padding: 0 1.25rem 1rem;
}

.link-url {
    display: block;
    font-size: .78rem;
    color: var(--text-muted);
    word-break: break-all;
    margin-bottom: .5rem;
    line-height: 1.4;
}

.link-url:hover { color: var(--rose); }

.link-hint {
    font-size: .75rem;
    color: var(--text-muted);
    font-style: italic;
    margin-bottom: .75rem;
}

.link-badges {
    display: flex;
    gap: .35rem;
    margin-bottom: .75rem;
    flex-wrap: wrap;
}

.link-creds {
    background: var(--bg);
    border-radius: var(--radius);
    padding: .6rem .85rem;
    margin-bottom: .75rem;
    font-size: .78rem;
}

.link-creds .cred-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
    padding: .15rem 0;
}

.link-creds .cred-label {
    color: var(--text-muted);
    font-weight: 600;
    font-size: .7rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    flex-shrink: 0;
}

.link-creds .cred-value {
    font-family: monospace;
    font-size: .8rem;
    color: var(--text);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.link-creds .cred-copy {
    background: none;
    border: none;
    cursor: pointer;
    font-size: .85rem;
    padding: .15rem .35rem;
    border-radius: 6px;
    transition: background var(--transition);
    flex-shrink: 0;
}

.link-creds .cred-copy:hover { background: rgba(215,171,144,.2); }

.link-actions {
    display: flex;
    gap: .35rem;
    flex-wrap: wrap;
}

.link-actions .btn { font-size: .75rem; padding: .35rem .7rem; }

.category-title {
    grid-column: 1 / -1;
    font-size: .88rem;
    font-weight: 700;
    color: var(--petrol-900);
    padding: .5rem 0;
    margin-top: .5rem;
    border-bottom: 2px solid var(--rose-light);
    display: flex;
    align-items: center;
    gap: .5rem;
}

.category-title .count {
    font-size: .7rem;
    font-weight: 600;
    background: var(--petrol-100);
    color: var(--petrol-500);
    padding: .15rem .5rem;
    border-radius: 100px;
}

@media (max-width: 768px) {
    .portal-grid { grid-template-columns: 1fr; }
    .portal-controls { flex-direction: column; }
    .portal-search { width: 100%; }
}
</style>

<!-- Controles -->
<div class="portal-controls">
    <div class="portal-search">
        <span class="search-icon">🔍</span>
        <input type="text" id="searchInput" placeholder="Buscar por nome, URL, categoria, login..."
               autocomplete="off">
    </div>

    <div class="portal-filters" id="filterChips">
        <button class="filter-chip active" data-cat="all">Todas</button>
        <?php foreach ($allCategories as $cat): ?>
            <button class="filter-chip" data-cat="<?= e($cat) ?>"><?= e($cat) ?></button>
        <?php endforeach; ?>
    </div>

    <?php if ($isAdmin): ?>
        <a href="#" class="btn btn-primary btn-sm" data-modal="modalLink" onclick="openNewLink()">+ Novo Link</a>
    <?php endif; ?>
</div>

<!-- Grid de links -->
<div class="portal-grid" id="linksGrid">
    <?php if (empty($links)): ?>
        <div class="empty-state" style="grid-column: 1/-1;">
            <div class="icon">🔗</div>
            <h3>Nenhum link cadastrado</h3>
            <p>Clique em "+ Novo Link" para adicionar o primeiro.</p>
        </div>
    <?php else: ?>
        <?php foreach ($categories as $catName => $catLinks): ?>
            <div class="category-title" data-category="<?= e($catName) ?>">
                <?= e($catName) ?>
                <span class="count"><?= count($catLinks) ?></span>
            </div>
            <?php foreach ($catLinks as $link): ?>
                <div class="link-card" data-searchable="<?= e(strtolower($link['title'] . ' ' . $link['url'] . ' ' . $link['category'] . ' ' . $link['username'] . ' ' . ($link['hint'] ?? ''))) ?>"
                     data-category="<?= e($link['category']) ?>">
                    <div class="link-card-header">
                        <div class="link-title">
                            <?php if ($link['is_favorite']): ?><span class="link-fav">★</span><?php endif; ?>
                            <span><?= e($link['title']) ?></span>
                        </div>
                        <?php if ($isAdmin): ?>
                            <div class="flex gap-1">
                                <button class="btn btn-outline btn-sm" style="padding:.25rem .4rem;font-size:.75rem;"
                                        onclick="editLink(<?= $link['id'] ?>)" title="Editar">✏️</button>
                                <form method="POST" action="<?= module_url('portal', 'links_api.php') ?>" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm" style="padding:.25rem .4rem;font-size:.75rem;"
                                            data-confirm="Excluir este link?" title="Excluir">🗑️</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="link-card-body">
                        <?php if ($link['url']): ?>
                            <a href="<?= e($link['url']) ?>" target="_blank" rel="noopener" class="link-url"><?= e($link['url']) ?></a>
                        <?php endif; ?>

                        <?php if (!empty($link['hint'])): ?>
                            <div class="link-hint"><?= e($link['hint']) ?></div>
                        <?php endif; ?>

                        <div class="link-badges">
                            <span class="badge badge-<?= $link['audience'] === 'internal' ? 'gestao' : ($link['audience'] === 'client' ? 'warning' : 'info') ?>">
                                <?= $link['audience'] === 'internal' ? 'Interno' : ($link['audience'] === 'client' ? 'Cliente' : 'Ambos') ?>
                            </span>
                        </div>

                        <?php if ($link['username'] || $link['password_encrypted']): ?>
                            <div class="link-creds">
                                <?php if ($link['username']): ?>
                                    <div class="cred-row">
                                        <span class="cred-label">Login</span>
                                        <span class="cred-value"><?= e($link['username']) ?></span>
                                        <button class="cred-copy" onclick="copyText('<?= e(addslashes($link['username'])) ?>')" title="Copiar login">📋</button>
                                    </div>
                                <?php endif; ?>
                                <?php if ($link['password_encrypted']): ?>
                                    <?php
                                        $decrypted = '';
                                        try { $decrypted = decrypt_value($link['password_encrypted']); } catch (Exception $ex) { $decrypted = '***'; }
                                    ?>
                                    <div class="cred-row">
                                        <span class="cred-label">Senha</span>
                                        <span class="cred-value password-hidden" id="pass-<?= $link['id'] ?>" data-pass="<?= e($decrypted) ?>">••••••••</span>
                                        <button class="cred-copy" onclick="togglePass(<?= $link['id'] ?>)" title="Mostrar/ocultar">👁️</button>
                                        <button class="cred-copy" onclick="copyText('<?= e(addslashes($decrypted)) ?>')" title="Copiar senha">📋</button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="link-actions">
                            <?php if ($link['url']): ?>
                                <a href="<?= e($link['url']) ?>" target="_blank" class="btn btn-primary btn-sm">Abrir ↗</a>
                                <button class="btn btn-outline btn-sm" onclick="copyText('<?= e(addslashes($link['url'])) ?>')">Copiar link</button>
                            <?php endif; ?>
                            <button class="btn btn-outline btn-sm" onclick="copyWhatsApp(<?= $link['id'] ?>)">WhatsApp</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal: Novo/Editar Link -->
<div class="modal-overlay" id="modalLink">
    <div class="modal">
        <div class="modal-header">
            <h3 id="modalTitle">Novo Link</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="<?= module_url('portal', 'links_api.php') ?>" id="linkForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="link_id" id="formLinkId" value="">

                <div class="form-group">
                    <label class="form-label">Título *</label>
                    <input type="text" name="title" id="fTitle" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Categoria *</label>
                        <input type="text" name="category" id="fCategory" class="form-input" list="catList"
                               placeholder="Ex: Geral, Gestão, Financeiro...">
                        <datalist id="catList">
                            <?php foreach ($allCategories as $cat): ?>
                                <option value="<?= e($cat) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Público</label>
                        <select name="audience" id="fAudience" class="form-select">
                            <option value="internal">Interno</option>
                            <option value="client">Cliente</option>
                            <option value="both">Ambos</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">URL / Link</label>
                    <input type="url" name="url" id="fUrl" class="form-input" placeholder="https://...">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Login / Usuário</label>
                        <input type="text" name="username" id="fUsername" class="form-input" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Senha</label>
                        <input type="text" name="password" id="fPassword" class="form-input" autocomplete="off">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Observação / Dica</label>
                    <textarea name="hint" id="fHint" class="form-textarea" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Favorito?</label>
                        <select name="is_favorite" id="fFavorite" class="form-select">
                            <option value="0">Não</option>
                            <option value="1">Sim</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ordem</label>
                        <input type="number" name="sort_order" id="fOrder" class="form-input" value="0" min="0">
                    </div>
                </div>

                <div class="modal-footer" style="border: none; padding: 1rem 0 0;">
                    <button type="button" class="btn btn-outline" data-modal-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="btnSave">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Data para WhatsApp copy -->
<script>
var linksData = <?= json_encode(array_map(function($l) {
    $pass = '';
    if ($l['password_encrypted']) {
        try { $pass = decrypt_value($l['password_encrypted']); } catch(Exception $e) {}
    }
    return [
        'id' => $l['id'],
        'title' => $l['title'],
        'url' => $l['url'],
        'username' => $l['username'],
        'password' => $pass,
        'hint' => $l['hint'],
        'category' => $l['category'],
        'audience' => $l['audience'],
        'is_favorite' => $l['is_favorite'],
        'sort_order' => $l['sort_order'],
    ];
}, $links), JSON_UNESCAPED_UNICODE) ?>;

// Busca
document.getElementById('searchInput').addEventListener('input', function() {
    var q = this.value.toLowerCase();
    document.querySelectorAll('.link-card').forEach(function(card) {
        var match = !q || card.dataset.searchable.indexOf(q) !== -1;
        card.style.display = match ? '' : 'none';
    });
    // Esconder títulos de categoria vazias
    document.querySelectorAll('.category-title').forEach(function(title) {
        var cat = title.dataset.category;
        var visible = document.querySelectorAll('.link-card[data-category="' + cat + '"]:not([style*="display: none"])');
        title.style.display = visible.length ? '' : 'none';
    });
});

// Filtro por categoria
document.querySelectorAll('.filter-chip').forEach(function(chip) {
    chip.addEventListener('click', function() {
        document.querySelectorAll('.filter-chip').forEach(function(c) { c.classList.remove('active'); });
        chip.classList.add('active');
        var cat = chip.dataset.cat;

        document.querySelectorAll('.link-card').forEach(function(card) {
            card.style.display = (cat === 'all' || card.dataset.category === cat) ? '' : 'none';
        });
        document.querySelectorAll('.category-title').forEach(function(title) {
            title.style.display = (cat === 'all' || title.dataset.category === cat) ? '' : 'none';
        });
    });
});

// Copiar texto
function copyText(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
    showToast('Copiado!');
}

// Mostrar/ocultar senha
function togglePass(id) {
    var el = document.getElementById('pass-' + id);
    if (el.classList.contains('password-hidden')) {
        el.textContent = el.dataset.pass;
        el.classList.remove('password-hidden');
    } else {
        el.textContent = '••••••••';
        el.classList.add('password-hidden');
    }
}

// Copiar formato WhatsApp
function copyWhatsApp(id) {
    var link = linksData.find(function(l) { return l.id === id; });
    if (!link) return;
    var text = '*' + link.title + '*\n';
    if (link.url) text += link.url + '\n';
    if (link.username) text += 'Login: ' + link.username + '\n';
    if (link.password) text += 'Senha: ' + link.password + '\n';
    if (link.hint) text += link.hint + '\n';
    copyText(text.trim());
}

// Modal: Novo link
function openNewLink() {
    document.getElementById('modalTitle').textContent = 'Novo Link';
    document.getElementById('formAction').value = 'create';
    document.getElementById('formLinkId').value = '';
    document.getElementById('fTitle').value = '';
    document.getElementById('fCategory').value = '';
    document.getElementById('fUrl').value = '';
    document.getElementById('fUsername').value = '';
    document.getElementById('fPassword').value = '';
    document.getElementById('fHint').value = '';
    document.getElementById('fAudience').value = 'internal';
    document.getElementById('fFavorite').value = '0';
    document.getElementById('fOrder').value = '0';
}

// Modal: Editar link
function editLink(id) {
    var link = linksData.find(function(l) { return l.id === id; });
    if (!link) return;
    document.getElementById('modalTitle').textContent = 'Editar Link';
    document.getElementById('formAction').value = 'update';
    document.getElementById('formLinkId').value = link.id;
    document.getElementById('fTitle').value = link.title;
    document.getElementById('fCategory').value = link.category;
    document.getElementById('fUrl').value = link.url;
    document.getElementById('fUsername').value = link.username || '';
    document.getElementById('fPassword').value = link.password || '';
    document.getElementById('fHint').value = link.hint || '';
    document.getElementById('fAudience').value = link.audience;
    document.getElementById('fFavorite').value = link.is_favorite ? '1' : '0';
    document.getElementById('fOrder').value = link.sort_order;
    document.getElementById('modalLink').classList.add('open');
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
