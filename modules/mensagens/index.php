<?php
/**
 * Ferreira & Sá Hub — Biblioteca de Mensagens Prontas
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Mensagens Prontas';
$pdo = db();
$isAdmin = has_role('admin');

$filterCat = isset($_GET['cat']) ? $_GET['cat'] : '';
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Buscar templates
$where = array('is_active = 1');
$params = array();
if ($filterCat) { $where[] = 'category = ?'; $params[] = $filterCat; }
if ($search) { $where[] = '(title LIKE ? OR body LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$whereStr = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM message_templates WHERE $whereStr ORDER BY category, sort_order, title");
$stmt->execute($params);
$templates = $stmt->fetchAll();

// Agrupar por categoria
$byCategory = array();
foreach ($templates as $t) {
    $byCategory[$t['category']][] = $t;
}

$categoryLabels = array(
    'documentos' => array('icon' => '📄', 'label' => 'Solicitação de Documentos', 'color' => '#6366f1'),
    'onboarding' => array('icon' => '👋', 'label' => 'Onboarding / Boas-vindas', 'color' => '#059669'),
    'contrato'   => array('icon' => '📝', 'label' => 'Contrato', 'color' => '#0ea5e9'),
    'audiencia'  => array('icon' => '⚖️', 'label' => 'Audiência', 'color' => '#d97706'),
    'andamento'  => array('icon' => '📊', 'label' => 'Andamento Processual', 'color' => '#8b5cf6'),
    'financeiro' => array('icon' => '💰', 'label' => 'Financeiro', 'color' => '#dc2626'),
    'oficio'     => array('icon' => '✉️', 'label' => 'Ofícios', 'color' => '#173d46'),
    'geral'      => array('icon' => '💬', 'label' => 'Geral', 'color' => '#9ca3af'),
);

$allCategories = array_keys($categoryLabels);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.msg-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:1.25rem; flex-wrap:wrap; gap:.75rem; }
.msg-filters { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
.msg-filter-chip {
    padding:.35rem .75rem; font-size:.75rem; font-weight:600;
    border:1.5px solid var(--border); border-radius:100px;
    background:var(--bg-card); color:var(--text-muted); cursor:pointer;
    text-decoration:none; transition:all var(--transition);
}
.msg-filter-chip:hover { border-color:var(--petrol-300); color:var(--petrol-500); }
.msg-filter-chip.active { background:var(--petrol-900); color:#fff; border-color:var(--petrol-900); }
.msg-search input { font-size:.8rem; padding:.4rem .75rem; border:1.5px solid var(--border); border-radius:var(--radius); width:220px; }
.msg-search input:focus { border-color:var(--rose); outline:none; }

.msg-cat-title {
    font-size:.88rem; font-weight:700; color:var(--petrol-900);
    padding:.5rem 0; margin-top:1rem; margin-bottom:.5rem;
    border-bottom:2px solid var(--rose-light);
    display:flex; align-items:center; gap:.5rem;
}
.msg-cat-title .count { font-size:.68rem; font-weight:600; background:var(--petrol-100); color:var(--petrol-500); padding:.1rem .45rem; border-radius:100px; }

.msg-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(350px, 1fr)); gap:.75rem; margin-bottom:1.25rem; }
.msg-card {
    background:var(--bg-card); border-radius:var(--radius-lg);
    border:1px solid var(--border); overflow:hidden;
    transition:all var(--transition);
}
.msg-card:hover { box-shadow:var(--shadow-md); }
.msg-card-header {
    padding:.75rem 1rem; display:flex; align-items:center; justify-content:space-between; gap:.5rem;
    border-bottom:1px solid var(--border);
}
.msg-card-title { font-size:.85rem; font-weight:700; color:var(--petrol-900); flex:1; }
.msg-card-badges { display:flex; gap:.25rem; }
.msg-badge-wpp { font-size:.6rem; background:#25D366; color:#fff; padding:.1rem .35rem; border-radius:4px; font-weight:700; }
.msg-badge-email { font-size:.6rem; background:#0ea5e9; color:#fff; padding:.1rem .35rem; border-radius:4px; font-weight:700; }

.msg-card-body { padding:.75rem 1rem; }
.msg-card-preview {
    font-size:.78rem; color:var(--text-muted); line-height:1.5;
    max-height:120px; overflow:hidden; white-space:pre-wrap;
    position:relative;
}
.msg-card-preview::after {
    content:''; position:absolute; bottom:0; left:0; right:0;
    height:30px; background:linear-gradient(transparent, var(--bg-card));
}
.msg-card-footer { padding:.5rem 1rem .75rem; display:flex; gap:.35rem; flex-wrap:wrap; }
.msg-card-footer .btn { font-size:.72rem; }

.msg-placeholder { font-size:.68rem; color:var(--rose); background:rgba(215,171,144,.1); padding:.1rem .3rem; border-radius:3px; font-weight:600; }

/* Modal de visualização */
.msg-modal-body { white-space:pre-wrap; font-size:.88rem; line-height:1.6; color:var(--text); padding:1rem; background:var(--bg); border-radius:var(--radius); max-height:400px; overflow-y:auto; }

@media (max-width:768px) {
    .msg-grid { grid-template-columns:1fr; }
}
</style>

<!-- Toolbar -->
<div class="msg-toolbar">
    <form method="GET" class="msg-filters">
        <a href="<?= module_url('mensagens') ?>" class="msg-filter-chip <?= !$filterCat ? 'active' : '' ?>">Todas</a>
        <?php foreach ($categoryLabels as $key => $cat): ?>
            <a href="?cat=<?= $key ?>" class="msg-filter-chip <?= $filterCat === $key ? 'active' : '' ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></a>
        <?php endforeach; ?>
    </form>
    <div style="display:flex;gap:.5rem;align-items:center;">
        <form method="GET" class="msg-search" style="display:flex;gap:.35rem;">
            <?php if ($filterCat): ?><input type="hidden" name="cat" value="<?= e($filterCat) ?>"><?php endif; ?>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar mensagem...">
            <button type="submit" class="btn btn-outline btn-sm">🔍</button>
        </form>
        <?php if ($isAdmin): ?>
            <a href="#" class="btn btn-primary btn-sm" data-modal="modalMsg" onclick="openNewMsg()">+ Nova</a>
        <?php endif; ?>
    </div>
</div>

<!-- Lista -->
<?php if (empty($templates)): ?>
    <div class="card" style="text-align:center;padding:3rem;">
        <div style="font-size:2rem;margin-bottom:.5rem;">💬</div>
        <h3>Nenhuma mensagem encontrada</h3>
        <p style="color:var(--text-muted);font-size:.85rem;">
            <?= $search ? 'Tente outros termos.' : 'Rode a migração para popular os templates padrão.' ?>
        </p>
    </div>
<?php else: ?>
    <?php foreach ($byCategory as $catKey => $catTemplates): ?>
        <?php $catInfo = isset($categoryLabels[$catKey]) ? $categoryLabels[$catKey] : array('icon' => '💬', 'label' => $catKey, 'color' => '#9ca3af'); ?>
        <div class="msg-cat-title">
            <?= $catInfo['icon'] ?> <?= $catInfo['label'] ?>
            <span class="count"><?= count($catTemplates) ?></span>
        </div>
        <div class="msg-grid">
            <?php foreach ($catTemplates as $t): ?>
            <div class="msg-card">
                <div class="msg-card-header">
                    <div class="msg-card-title"><?= e($t['title']) ?></div>
                    <div class="msg-card-badges">
                        <?php if ($t['for_whatsapp']): ?><span class="msg-badge-wpp">WhatsApp</span><?php endif; ?>
                        <?php if ($t['for_email']): ?><span class="msg-badge-email">E-mail</span><?php endif; ?>
                    </div>
                </div>
                <div class="msg-card-body">
                    <div class="msg-card-preview"><?= e($t['body']) ?></div>
                </div>
                <div class="msg-card-footer">
                    <button class="btn btn-primary btn-sm" onclick="copyMsg(<?= $t['id'] ?>)">📋 Copiar</button>
                    <?php if ($t['for_whatsapp']): ?>
                    <button class="btn btn-sm" style="background:#25D366;color:#fff;" onclick="sendWhatsApp(<?= $t['id'] ?>)">💬 WhatsApp</button>
                    <?php endif; ?>
                    <button class="btn btn-outline btn-sm" onclick="viewMsg(<?= $t['id'] ?>)">👁️ Ver</button>
                    <?php if ($isAdmin): ?>
                        <button class="btn btn-outline btn-sm" onclick="editMsg(<?= $t['id'] ?>)">✏️</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Modal: Ver mensagem completa -->
<div class="modal-overlay" id="modalView">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <h3 id="viewTitle">Mensagem</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="msg-modal-body" id="viewBody"></div>
            <div style="margin-top:1rem;display:flex;gap:.5rem;justify-content:center;">
                <button class="btn btn-primary" onclick="copyFromView()">📋 Copiar Mensagem</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Novo/Editar (admin) -->
<?php if ($isAdmin): ?>
<div class="modal-overlay" id="modalMsg">
    <div class="modal" style="max-width:650px;">
        <div class="modal-header">
            <h3 id="msgModalTitle">Nova Mensagem</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="<?= module_url('mensagens', 'api.php') ?>" id="msgForm">
                <?= csrf_input() ?>
                <input type="hidden" name="action" id="msgAction" value="create">
                <input type="hidden" name="msg_id" id="msgId" value="">

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Categoria *</label>
                        <select name="category" id="msgCategory" class="form-select" required>
                            <?php foreach ($categoryLabels as $key => $cat): ?>
                                <option value="<?= $key ?>"><?= $cat['icon'] ?> <?= $cat['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Título *</label>
                        <input type="text" name="title" id="msgTitle" class="form-input" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Corpo da mensagem *</label>
                    <textarea name="body" id="msgBody" class="form-textarea" rows="8" required placeholder="Use {nome}, {tipo_acao}, etc. como placeholders"></textarea>
                    <small style="color:var(--text-muted);font-size:.72rem;">Placeholders: {nome}, {tipo_acao}, {data_audiencia}, {local_audiencia}, {numero_processo}, {vara}, {atualizacao}</small>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Canal</label>
                        <div style="display:flex;gap:1rem;">
                            <label style="display:flex;align-items:center;gap:.35rem;font-size:.82rem;">
                                <input type="checkbox" name="for_whatsapp" value="1" id="msgWpp" checked> WhatsApp
                            </label>
                            <label style="display:flex;align-items:center;gap:.35rem;font-size:.82rem;">
                                <input type="checkbox" name="for_email" value="1" id="msgEmail"> E-mail
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Ordem</label>
                        <input type="number" name="sort_order" id="msgOrder" class="form-input" value="0" min="0">
                    </div>
                </div>

                <div class="modal-footer" style="border:none;padding:1rem 0 0;">
                    <button type="button" class="btn btn-outline" data-modal-close>Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var msgData = <?= json_encode(array_map(function($t) {
    return array(
        'id' => (int)$t['id'], 'category' => $t['category'],
        'title' => $t['title'], 'body' => $t['body'],
        'for_whatsapp' => $t['for_whatsapp'], 'for_email' => $t['for_email'],
        'sort_order' => $t['sort_order'],
    );
}, $templates), JSON_UNESCAPED_UNICODE) ?>;

function copyMsg(id) {
    var msg = msgData.find(function(m) { return m.id === id; });
    if (!msg) return;
    copyToClipboard(msg.body);
    showToast('Mensagem copiada!');
}

function sendWhatsApp(id) {
    var msg = msgData.find(function(m) { return m.id === id; });
    if (!msg) return;
    var phone = prompt('Informe o número do WhatsApp (com DDD):\nEx: 24999001234');
    if (!phone) return;
    phone = phone.replace(/\D/g, '');
    waSenderOpen({telefone: phone, mensagem: msg.body});
}

function viewMsg(id) {
    var msg = msgData.find(function(m) { return m.id === id; });
    if (!msg) return;
    document.getElementById('viewTitle').textContent = msg.title;
    document.getElementById('viewBody').textContent = msg.body;
    document.getElementById('modalView').classList.add('open');
}

function copyFromView() {
    var text = document.getElementById('viewBody').textContent;
    copyToClipboard(text);
    showToast('Mensagem copiada!');
}

function openNewMsg() {
    document.getElementById('msgModalTitle').textContent = 'Nova Mensagem';
    document.getElementById('msgAction').value = 'create';
    document.getElementById('msgId').value = '';
    document.getElementById('msgCategory').value = 'geral';
    document.getElementById('msgTitle').value = '';
    document.getElementById('msgBody').value = '';
    document.getElementById('msgWpp').checked = true;
    document.getElementById('msgEmail').checked = false;
    document.getElementById('msgOrder').value = '0';
}

function editMsg(id) {
    var msg = msgData.find(function(m) { return m.id === id; });
    if (!msg) return;
    document.getElementById('msgModalTitle').textContent = 'Editar Mensagem';
    document.getElementById('msgAction').value = 'update';
    document.getElementById('msgId').value = msg.id;
    document.getElementById('msgCategory').value = msg.category;
    document.getElementById('msgTitle').value = msg.title;
    document.getElementById('msgBody').value = msg.body;
    document.getElementById('msgWpp').checked = !!msg.for_whatsapp;
    document.getElementById('msgEmail').checked = !!msg.for_email;
    document.getElementById('msgOrder').value = msg.sort_order;
    document.getElementById('modalMsg').classList.add('open');
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
