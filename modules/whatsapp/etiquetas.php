<?php
/**
 * Ferreira & Sá Hub — CRUD de Etiquetas WhatsApp
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/whatsapp/'));
}

$pdo = db();
$pageTitle = 'Etiquetas WhatsApp';
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'salvar') {
        $id    = (int)($_POST['id'] ?? 0);
        $nome  = trim($_POST['nome'] ?? '');
        $cor   = trim($_POST['cor'] ?? '#6b7280');
        $ordem = (int)($_POST['ordem'] ?? 0);
        $ativo = !empty($_POST['ativo']) ? 1 : 0;

        if (!$nome) {
            flash_set('error', 'Nome obrigatório.');
        } else {
            if ($id) {
                $pdo->prepare("UPDATE zapi_etiquetas SET nome=?, cor=?, ordem=?, ativo=? WHERE id=?")
                    ->execute(array($nome, $cor, $ordem, $ativo, $id));
                audit_log('zapi_etiqueta_editar', 'zapi_etiquetas', $id, $nome);
            } else {
                $pdo->prepare("INSERT INTO zapi_etiquetas (nome, cor, ordem, ativo) VALUES (?,?,?,?)")
                    ->execute(array($nome, $cor, $ordem, $ativo));
                audit_log('zapi_etiqueta_criar', 'zapi_etiquetas', (int)$pdo->lastInsertId(), $nome);
            }
            flash_set('success', 'Etiqueta salva.');
        }
        redirect(module_url('whatsapp', 'etiquetas.php'));
    }

    if ($action === 'excluir') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM zapi_etiquetas WHERE id = ?")->execute(array($id));
        audit_log('zapi_etiqueta_excluir', 'zapi_etiquetas', $id);
        flash_set('success', 'Etiqueta excluída.');
        redirect(module_url('whatsapp', 'etiquetas.php'));
    }
}

$editId = (int)($_GET['editar'] ?? 0);
$editEtq = null;
if ($editId) {
    $s = $pdo->prepare("SELECT * FROM zapi_etiquetas WHERE id = ?");
    $s->execute(array($editId));
    $editEtq = $s->fetch();
}
$novo = !empty($_GET['novo']);

$etiquetas = $pdo->query("SELECT e.*, (SELECT COUNT(*) FROM zapi_conversa_etiquetas WHERE etiqueta_id = e.id) as uso
                          FROM zapi_etiquetas e ORDER BY ordem, nome")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.etq-card { background:#fff;border:1px solid var(--border);border-radius:12px;padding:.7rem 1rem;margin-bottom:.5rem;display:flex;align-items:center;gap:.7rem; }
.etq-chip { display:inline-block;padding:4px 12px;border-radius:14px;font-size:.8rem;font-weight:600;color:#fff; }
.etq-name { font-weight:600;flex:1; }
.etq-meta { font-size:.72rem;color:var(--text-muted); }
.etq-actions { display:flex;gap:.3rem; }
.etq-inactive { opacity:.5; }
.color-swatch { display:inline-block;width:14px;height:14px;border-radius:3px;vertical-align:middle;margin-right:4px;border:1px solid rgba(0,0,0,.15); }
</style>

<a href="<?= module_url('whatsapp') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;">
    <h1 style="margin:0;">🏷 Etiquetas WhatsApp</h1>
    <div style="margin-left:auto;">
        <a href="?novo=1" class="btn btn-primary btn-sm">+ Nova Etiqueta</a>
    </div>
</div>

<p class="text-sm text-muted">Use etiquetas para organizar conversas (ex: "Urgente", "Aguardando Docs", "VIP"). Podem ser aplicadas a qualquer conversa do WhatsApp.</p>

<?php if ($novo || $editEtq): ?>
    <div class="card" style="border-color:var(--rose);">
        <div class="card-body">
            <h3 style="margin:0 0 .7rem;"><?= $editEtq ? '✏️ Editar' : '➕ Nova' ?> Etiqueta</h3>
            <form method="POST">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="salvar">
                <input type="hidden" name="id" value="<?= $editEtq ? $editEtq['id'] : 0 ?>">
                <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.6rem;margin-bottom:.6rem;">
                    <div>
                        <label class="text-sm" style="font-weight:600;">Nome*</label>
                        <input type="text" name="nome" value="<?= e($editEtq['nome'] ?? '') ?>" class="form-control" required placeholder="Ex: 🔴 Urgente">
                    </div>
                    <div>
                        <label class="text-sm" style="font-weight:600;">Cor</label>
                        <input type="color" name="cor" value="<?= e($editEtq['cor'] ?? '#6b7280') ?>" class="form-control" style="height:38px;padding:2px;">
                    </div>
                    <div>
                        <label class="text-sm" style="font-weight:600;">Ordem</label>
                        <input type="number" name="ordem" value="<?= (int)($editEtq['ordem'] ?? 0) ?>" class="form-control">
                    </div>
                </div>
                <label><input type="checkbox" name="ativo" value="1" <?= (isset($editEtq['ativo']) ? $editEtq['ativo'] : 1) ? 'checked' : '' ?>> Ativa</label>
                <div style="margin-top:.5rem;">
                    <button type="submit" class="btn btn-primary btn-sm">💾 Salvar</button>
                    <a href="<?= module_url('whatsapp', 'etiquetas.php') ?>" class="btn btn-outline btn-sm">Cancelar</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($etiquetas)): ?>
    <div class="card"><div class="card-body"><p class="text-muted text-sm">Nenhuma etiqueta. Crie a primeira.</p></div></div>
<?php else: foreach ($etiquetas as $e): ?>
    <div class="etq-card <?= !$e['ativo'] ? 'etq-inactive' : '' ?>">
        <span class="etq-chip" style="background:<?= e($e['cor']) ?>;"><?= e($e['nome']) ?></span>
        <span class="etq-meta">Usada em <?= (int)$e['uso'] ?> conversas</span>
        <span style="margin-left:auto;"></span>
        <span class="etq-meta">ordem <?= (int)$e['ordem'] ?></span>
        <div class="etq-actions">
            <a href="?editar=<?= $e['id'] ?>" class="btn btn-outline btn-sm">✏️ Editar</a>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir etiqueta &quot;<?= e($e['nome']) ?>&quot;? Isso também remove de todas conversas.');">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;">🗑</button>
            </form>
        </div>
    </div>
<?php endforeach; endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
