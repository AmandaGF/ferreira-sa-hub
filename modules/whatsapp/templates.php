<?php
/**
 * Ferreira & Sá Hub — CRUD de Templates / Respostas Rápidas
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/whatsapp/'));
}

$pdo = db();
$pageTitle = 'Templates WhatsApp';
$userId    = current_user_id();

// ── POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'salvar') {
        $id        = (int)($_POST['id'] ?? 0);
        $nome      = trim($_POST['nome'] ?? '');
        $conteudo  = trim($_POST['conteudo'] ?? '');
        $canal     = in_array($_POST['canal'] ?? 'ambos', array('21','24','ambos'), true) ? $_POST['canal'] : 'ambos';
        $categoria = trim($_POST['categoria'] ?? '');
        $ativo     = !empty($_POST['ativo']) ? 1 : 0;

        if (!$nome || !$conteudo) {
            flash_set('error', 'Nome e conteúdo são obrigatórios.');
        } else {
            if ($id) {
                $pdo->prepare("UPDATE zapi_templates SET nome=?, conteudo=?, canal=?, categoria=?, ativo=? WHERE id=?")
                    ->execute(array($nome, $conteudo, $canal, $categoria, $ativo, $id));
                audit_log('zapi_tpl_editar', 'zapi_templates', $id, $nome);
                flash_set('success', 'Template atualizado.');
            } else {
                $pdo->prepare("INSERT INTO zapi_templates (nome, conteudo, canal, categoria, ativo, created_by) VALUES (?,?,?,?,?,?)")
                    ->execute(array($nome, $conteudo, $canal, $categoria, $ativo, $userId));
                $newId = (int)$pdo->lastInsertId();
                audit_log('zapi_tpl_criar', 'zapi_templates', $newId, $nome);
                flash_set('success', 'Template criado.');
            }
        }
        redirect(module_url('whatsapp', 'templates.php'));
    }

    if ($action === 'toggle_ativo') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE zapi_templates SET ativo = NOT ativo WHERE id = ?")->execute(array($id));
        audit_log('zapi_tpl_toggle', 'zapi_templates', $id);
        echo json_encode(array('ok' => true));
        exit;
    }

    if ($action === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM zapi_templates WHERE id = ?")->execute(array($id));
        audit_log('zapi_tpl_excluir', 'zapi_templates', $id);
        flash_set('success', 'Template excluído.');
        redirect(module_url('whatsapp', 'templates.php'));
    }
}

// ── Carregar edição? ────────────────────────────────────
$editId = (int)($_GET['editar'] ?? 0);
$editTpl = null;
if ($editId) {
    $s = $pdo->prepare("SELECT * FROM zapi_templates WHERE id = ?");
    $s->execute(array($editId));
    $editTpl = $s->fetch();
}
$novo = !empty($_GET['novo']);

// ── Listar ──────────────────────────────────────────────
$templates = $pdo->query("SELECT * FROM zapi_templates ORDER BY categoria, nome")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.tpl-card { background:#fff;border:1px solid var(--border);border-radius:12px;padding:1rem;margin-bottom:.7rem; }
.tpl-head { display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem; }
.tpl-nome { font-weight:700;font-size:.95rem;color:var(--petrol-900); }
.tpl-meta { font-size:.7rem;color:var(--text-muted);margin-left:auto; }
.tpl-canal { display:inline-block;padding:2px 8px;border-radius:10px;font-size:.65rem;font-weight:700; }
.tpl-canal-21 { background:#fdf5ed;color:#b08d6e; }
.tpl-canal-24 { background:#eef2f8;color:#0f3460; }
.tpl-canal-ambos { background:#f3f4f6;color:#6b7280; }
.tpl-cat { display:inline-block;padding:2px 8px;background:#e0e7ff;color:#3730a3;border-radius:10px;font-size:.65rem;font-weight:600; }
.tpl-content { font-size:.82rem;color:var(--text);white-space:pre-wrap;background:#f9fafb;padding:.5rem .75rem;border-radius:8px;margin:.4rem 0; }
.tpl-actions { display:flex;gap:.3rem;flex-wrap:wrap; }
.tpl-inactive { opacity:.55; }
</style>

<a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar ao WhatsApp</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">📋 Templates / Respostas Rápidas</h1>
    <div style="margin-left:auto;">
        <a href="<?= module_url('whatsapp', 'automacoes.php') ?>" class="btn btn-outline btn-sm">⚙️ Automações →</a>
        <a href="?novo=1" class="btn btn-primary btn-sm">+ Novo Template</a>
    </div>
</div>

<p class="text-sm text-muted">Estes templates aparecem no botão 📋 dentro de cada conversa. Os nomes especiais <strong>"Fora do horário"</strong> e <strong>"Confirmação de documentos"</strong> também são usados nas automações.</p>

<?php if ($novo || $editTpl): ?>
    <div class="tpl-card" style="border-color:var(--rose);">
        <h3 style="margin:0 0 .7rem;"><?= $editTpl ? '✏️ Editar' : '➕ Novo' ?> Template</h3>
        <form method="POST">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="salvar">
            <input type="hidden" name="id" value="<?= $editTpl ? $editTpl['id'] : 0 ?>">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.6rem;margin-bottom:.6rem;">
                <div>
                    <label class="text-sm" style="font-weight:600;">Nome*</label>
                    <input type="text" name="nome" value="<?= e($editTpl['nome'] ?? '') ?>" class="form-control" required>
                </div>
                <div>
                    <label class="text-sm" style="font-weight:600;">Canal</label>
                    <select name="canal" class="form-control">
                        <option value="ambos" <?= ($editTpl['canal'] ?? 'ambos') === 'ambos' ? 'selected' : '' ?>>Ambos</option>
                        <option value="21" <?= ($editTpl['canal'] ?? '') === '21' ? 'selected' : '' ?>>DDD 21 (Comercial)</option>
                        <option value="24" <?= ($editTpl['canal'] ?? '') === '24' ? 'selected' : '' ?>>DDD 24 (CX/Oper.)</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm" style="font-weight:600;">Categoria</label>
                    <input type="text" name="categoria" value="<?= e($editTpl['categoria'] ?? '') ?>" class="form-control" placeholder="recepcao, agenda, processo...">
                </div>
            </div>
            <label class="text-sm" style="font-weight:600;">Conteúdo*</label>
            <textarea name="conteudo" rows="5" class="form-control" required placeholder="Olá, {{nome}}! ..."><?= e($editTpl['conteudo'] ?? '') ?></textarea>
            <p class="text-sm text-muted" style="margin:.3rem 0;">Variáveis disponíveis: <code>{{nome}}</code>, <code>{{data}}</code>, <code>{{hora}}</code>, <code>{{numero_processo}}</code> — serão substituídas no envio automático.</p>
            <div style="display:flex;align-items:center;gap:.5rem;margin:.5rem 0;">
                <label><input type="checkbox" name="ativo" value="1" <?= (isset($editTpl['ativo']) ? $editTpl['ativo'] : 1) ? 'checked' : '' ?>> Ativo (aparece no menu 📋)</label>
            </div>
            <div class="tpl-actions">
                <button type="submit" class="btn btn-primary btn-sm">💾 Salvar</button>
                <a href="<?= module_url('whatsapp', 'templates.php') ?>" class="btn btn-outline btn-sm">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php
// Agrupar por categoria
$grupos = array();
foreach ($templates as $t) {
    $cat = $t['categoria'] ?: 'Sem categoria';
    if (!isset($grupos[$cat])) $grupos[$cat] = array();
    $grupos[$cat][] = $t;
}
?>

<?php if (empty($templates)): ?>
    <div class="card"><div class="card-body"><p class="text-muted text-sm">Nenhum template cadastrado. Clique em <strong>+ Novo Template</strong> para começar.</p></div></div>
<?php else: foreach ($grupos as $cat => $items): ?>
    <h3 style="margin:1.2rem 0 .5rem;font-size:.95rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;"><?= e($cat) ?> (<?= count($items) ?>)</h3>
    <?php foreach ($items as $t): ?>
        <div class="tpl-card <?= !$t['ativo'] ? 'tpl-inactive' : '' ?>">
            <div class="tpl-head">
                <span class="tpl-nome"><?= e($t['nome']) ?></span>
                <span class="tpl-canal tpl-canal-<?= e($t['canal']) ?>">
                    <?= $t['canal'] === 'ambos' ? 'AMBOS' : 'DDD ' . e($t['canal']) ?>
                </span>
                <?php if (!$t['ativo']): ?><span class="tpl-cat" style="background:#fee2e2;color:#991b1b;">INATIVO</span><?php endif; ?>
                <span class="tpl-meta">criado <?= date('d/m/Y', strtotime($t['created_at'])) ?></span>
            </div>
            <div class="tpl-content"><?= e($t['conteudo']) ?></div>
            <div class="tpl-actions">
                <a href="?editar=<?= $t['id'] ?>" class="btn btn-outline btn-sm">✏️ Editar</a>
                <form method="POST" style="display:inline;" onsubmit="event.preventDefault();toggleAtivo(<?= $t['id'] ?>);">
                    <?= csrf_input() ?>
                    <button type="submit" class="btn btn-outline btn-sm">
                        <?= $t['ativo'] ? '🚫 Desativar' : '✅ Ativar' ?>
                    </button>
                </form>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir o template &quot;<?= e($t['nome']) ?>&quot;?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="excluir">
                    <input type="hidden" name="id" value="<?= $t['id'] ?>">
                    <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑 Excluir</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endforeach; endif; ?>

<script>
function toggleAtivo(id) {
    var fd = new FormData();
    fd.append('action', 'toggle_ativo');
    fd.append('id', id);
    fd.append('csrf_token', '<?= e(generate_csrf_token()) ?>');
    fetch(window.location.href, { method: 'POST', body: fd }).then(function(){ location.reload(); });
}
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
