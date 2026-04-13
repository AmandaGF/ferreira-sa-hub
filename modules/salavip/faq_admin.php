<?php
/**
 * Ferreira & Sa Hub -- Sala VIP -- FAQ Admin (CRUD)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pageTitle = 'FAQ — Sala VIP';
$pdo = db();

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    // ── Adicionar FAQ ───────────────────────────────────
    if ($action === 'add_faq') {
        $pergunta = trim($_POST['pergunta'] ?? '');
        $resposta = trim($_POST['resposta'] ?? '');
        $ordem    = (int)($_POST['ordem'] ?? 0);

        if (!$pergunta || !$resposta) {
            flash_set('error', 'Pergunta e resposta sao obrigatorias.');
        } else {
            $pdo->prepare(
                "INSERT INTO salavip_faq (pergunta, resposta, ordem, ativo, criado_em) VALUES (?, ?, ?, 1, NOW())"
            )->execute([$pergunta, $resposta, $ordem]);
            audit_log('salavip_faq_add', 'salavip_faq', (int)$pdo->lastInsertId());
            flash_set('success', 'FAQ adicionada.');
        }
        redirect(module_url('salavip', 'faq_admin.php'));
    }

    // ── Editar FAQ ──────────────────────────────────────
    if ($action === 'edit_faq') {
        $id       = (int)($_POST['id'] ?? 0);
        $pergunta = trim($_POST['pergunta'] ?? '');
        $resposta = trim($_POST['resposta'] ?? '');
        $ordem    = (int)($_POST['ordem'] ?? 0);

        if (!$pergunta || !$resposta) {
            flash_set('error', 'Pergunta e resposta sao obrigatorias.');
        } else {
            $pdo->prepare(
                "UPDATE salavip_faq SET pergunta = ?, resposta = ?, ordem = ? WHERE id = ?"
            )->execute([$pergunta, $resposta, $ordem, $id]);
            audit_log('salavip_faq_edit', 'salavip_faq', $id);
            flash_set('success', 'FAQ atualizada.');
        }
        redirect(module_url('salavip', 'faq_admin.php'));
    }

    // ── Excluir FAQ ─────────────────────────────────────
    if ($action === 'delete_faq') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM salavip_faq WHERE id = ?")->execute([$id]);
        audit_log('salavip_faq_delete', 'salavip_faq', $id);
        flash_set('success', 'FAQ excluida.');
        redirect(module_url('salavip', 'faq_admin.php'));
    }

    // ── Toggle ativo ────────────────────────────────────
    if ($action === 'toggle_faq') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE salavip_faq SET ativo = NOT ativo WHERE id = ?")->execute([$id]);
        audit_log('salavip_faq_toggle', 'salavip_faq', $id);
        flash_set('success', 'Status alterado.');
        redirect(module_url('salavip', 'faq_admin.php'));
    }
}

// ── Listar FAQs ─────────────────────────────────────────
$faqs = $pdo->query("SELECT * FROM salavip_faq ORDER BY ordem ASC, id ASC")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.faq-table { width:100%; border-collapse:collapse; font-size:.82rem; }
.faq-table th { background:var(--petrol-900); color:#fff; padding:.5rem .75rem; text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; }
.faq-table td { padding:.5rem .75rem; border-bottom:1px solid var(--border); vertical-align:top; }
.faq-table tr:hover { background:rgba(215,171,144,.04); }
.faq-form { display:grid; grid-template-columns:1fr; gap:.75rem; }
.faq-edit-modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:999; align-items:center; justify-content:center; }
.faq-edit-modal.show { display:flex; }
.faq-edit-box { background:#fff; border-radius:var(--radius-lg); padding:1.5rem; width:90%; max-width:550px; max-height:90vh; overflow-y:auto; }
</style>

<a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<!-- Add FAQ Form -->
<div class="card mb-2">
    <div class="card-header"><h3>Adicionar FAQ</h3></div>
    <div class="card-body">
        <form method="POST" class="faq-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_faq">

            <div>
                <label class="form-label">Pergunta *</label>
                <input type="text" name="pergunta" class="form-control" required maxlength="500">
            </div>
            <div>
                <label class="form-label">Resposta *</label>
                <textarea name="resposta" class="form-control" rows="3" required></textarea>
            </div>
            <div style="display:flex;gap:.75rem;align-items:flex-end;">
                <div style="width:100px;">
                    <label class="form-label">Ordem</label>
                    <input type="number" name="ordem" class="form-control" value="0" min="0">
                </div>
                <button type="submit" class="btn btn-primary">Adicionar</button>
            </div>
        </form>
    </div>
</div>

<!-- FAQ List -->
<div class="card">
    <div class="card-header"><h3>FAQs Cadastradas (<?= count($faqs) ?>)</h3></div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($faqs)): ?>
            <div style="text-align:center;padding:2rem;">
                <p class="text-muted text-sm">Nenhuma FAQ cadastrada.</p>
            </div>
        <?php else: ?>
            <table class="faq-table">
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>Pergunta</th>
                        <th>Resposta</th>
                        <th style="width:60px;">Ordem</th>
                        <th style="width:60px;">Ativo</th>
                        <th style="width:120px;">Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($faqs as $faq): ?>
                        <tr>
                            <td><?= $faq['id'] ?></td>
                            <td style="font-weight:600;"><?= e($faq['pergunta']) ?></td>
                            <td class="text-muted"><?= e(mb_strimwidth($faq['resposta'], 0, 100, '...')) ?></td>
                            <td><?= $faq['ordem'] ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="toggle_faq">
                                    <input type="hidden" name="id" value="<?= $faq['id'] ?>">
                                    <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.85rem;">
                                        <?= $faq['ativo'] ? '&#9989;' : '&#10060;' ?>
                                    </button>
                                </form>
                            </td>
                            <td>
                                <div style="display:flex;gap:.3rem;">
                                    <button type="button" class="btn btn-outline btn-sm" onclick="openEditFaq(<?= $faq['id'] ?>, <?= e(json_encode($faq['pergunta'])) ?>, <?= e(json_encode($faq['resposta'])) ?>, <?= $faq['ordem'] ?>)">&#9998;</button>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esta FAQ?');">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="action" value="delete_faq">
                                        <input type="hidden" name="id" value="<?= $faq['id'] ?>">
                                        <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);">&#128465;</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Modal -->
<div class="faq-edit-modal" id="editModal">
    <div class="faq-edit-box">
        <h3 style="margin-bottom:1rem;">Editar FAQ</h3>
        <form method="POST" class="faq-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="edit_faq">
            <input type="hidden" name="id" id="edit_id">
            <div>
                <label class="form-label">Pergunta *</label>
                <input type="text" name="pergunta" id="edit_pergunta" class="form-control" required>
            </div>
            <div>
                <label class="form-label">Resposta *</label>
                <textarea name="resposta" id="edit_resposta" class="form-control" rows="4" required></textarea>
            </div>
            <div style="display:flex;gap:.75rem;align-items:flex-end;">
                <div style="width:100px;">
                    <label class="form-label">Ordem</label>
                    <input type="number" name="ordem" id="edit_ordem" class="form-control" min="0">
                </div>
                <button type="submit" class="btn btn-primary">Salvar</button>
                <button type="button" class="btn btn-outline" onclick="closeEditFaq()">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditFaq(id, pergunta, resposta, ordem) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_pergunta').value = pergunta;
    document.getElementById('edit_resposta').value = resposta;
    document.getElementById('edit_ordem').value = ordem;
    document.getElementById('editModal').classList.add('show');
}
function closeEditFaq() {
    document.getElementById('editModal').classList.remove('show');
}
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditFaq();
});
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
