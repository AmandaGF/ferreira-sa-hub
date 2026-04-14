<?php
/**
 * Ferreira & Sá Hub — Sala VIP — FAQ Admin (CRUD) v2
 * Com áreas, destaque e filtro
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pageTitle = 'FAQ — Sala VIP';
$pdo = db();

$areaLabels = array(
    'familia' => 'Família',
    'consumidor' => 'Consumidor',
    'civel' => 'Cível',
    'previdenciario' => 'Previdenciário',
    'imobiliario' => 'Imobiliário',
);

// ── POST handlers ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'add_faq') {
        $area     = trim($_POST['area'] ?? 'familia');
        $pergunta = trim($_POST['pergunta'] ?? '');
        $resposta = trim($_POST['resposta'] ?? '');
        $ordem    = (int)($_POST['ordem'] ?? 0);
        $destaque = isset($_POST['destaque']) ? 1 : 0;
        if ($pergunta && $resposta) {
            $pdo->prepare("INSERT INTO salavip_faq (area, pergunta, resposta, ordem, ativo, destaque) VALUES (?, ?, ?, ?, 1, ?)")
                ->execute(array($area, $pergunta, $resposta, $ordem, $destaque));
            audit_log('salavip_faq_add', 'salavip_faq', (int)$pdo->lastInsertId());
            flash_set('success', 'FAQ adicionada.');
        } else {
            flash_set('error', 'Pergunta e resposta são obrigatórias.');
        }
        redirect(module_url('salavip', 'faq_admin.php'));
    }

    if ($action === 'edit_faq') {
        $id       = (int)($_POST['id'] ?? 0);
        $area     = trim($_POST['area'] ?? 'familia');
        $pergunta = trim($_POST['pergunta'] ?? '');
        $resposta = trim($_POST['resposta'] ?? '');
        $ordem    = (int)($_POST['ordem'] ?? 0);
        $destaque = isset($_POST['destaque']) ? 1 : 0;
        if ($pergunta && $resposta) {
            $pdo->prepare("UPDATE salavip_faq SET area = ?, pergunta = ?, resposta = ?, ordem = ?, destaque = ? WHERE id = ?")
                ->execute(array($area, $pergunta, $resposta, $ordem, $destaque, $id));
            audit_log('salavip_faq_edit', 'salavip_faq', $id);
            flash_set('success', 'FAQ atualizada.');
        }
        redirect(module_url('salavip', 'faq_admin.php'));
    }

    if ($action === 'delete_faq') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM salavip_faq WHERE id = ?")->execute(array($id));
        audit_log('salavip_faq_delete', 'salavip_faq', $id);
        flash_set('success', 'FAQ excluída.');
        redirect(module_url('salavip', 'faq_admin.php'));
    }

    if ($action === 'toggle_faq') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE salavip_faq SET ativo = NOT ativo WHERE id = ?")->execute(array($id));
        flash_set('success', 'Status alterado.');
        redirect(module_url('salavip', 'faq_admin.php'));
    }
}

// ── Filtro por área ──────────────────────────────────────
$filtroArea = $_GET['area'] ?? '';
$sql = "SELECT * FROM salavip_faq";
$params = array();
if ($filtroArea && isset($areaLabels[$filtroArea])) {
    $sql .= " WHERE area = ?";
    $params[] = $filtroArea;
}
$sql .= " ORDER BY area ASC, ordem ASC, id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$faqs = $stmt->fetchAll();

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
.faq-edit-box { background:#fff; border-radius:var(--radius-lg); padding:1.5rem; width:90%; max-width:600px; max-height:90vh; overflow-y:auto; }
.area-badge { padding:2px 8px; border-radius:6px; font-size:.68rem; font-weight:700; color:#fff; }
.faq-filter-pills { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:1rem; }
.faq-filter-pill { padding:4px 12px; border-radius:999px; font-size:.75rem; font-weight:600; text-decoration:none; border:1.5px solid var(--border); color:var(--text-muted); }
.faq-filter-pill:hover { border-color:var(--rose); color:var(--rose); }
.faq-filter-pill.active { background:var(--petrol-900); color:#fff; border-color:var(--petrol-900); }
</style>

<a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<!-- Add FAQ Form -->
<div class="card mb-2">
    <div class="card-header"><h3>+ Nova Pergunta</h3></div>
    <div class="card-body">
        <form method="POST" class="faq-form">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_faq">
            <div style="display:grid;grid-template-columns:1fr 1fr 80px;gap:.75rem;">
                <div>
                    <label class="form-label">Área *</label>
                    <select name="area" class="form-control">
                        <?php foreach ($areaLabels as $k => $l): ?>
                        <option value="<?= $k ?>"><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Pergunta *</label>
                    <input type="text" name="pergunta" class="form-control" required maxlength="500">
                </div>
                <div>
                    <label class="form-label">Ordem</label>
                    <input type="number" name="ordem" class="form-control" value="0" min="0">
                </div>
            </div>
            <div>
                <label class="form-label">Resposta *</label>
                <textarea name="resposta" class="form-control" rows="3" required></textarea>
            </div>
            <div style="display:flex;gap:1rem;align-items:center;">
                <label style="display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;">
                    <input type="checkbox" name="destaque" value="1"> &#11088; Marcar como destaque
                </label>
                <button type="submit" class="btn btn-primary" style="margin-left:auto;">Adicionar</button>
            </div>
        </form>
    </div>
</div>

<!-- Filtro -->
<div class="faq-filter-pills">
    <a href="<?= module_url('salavip', 'faq_admin.php') ?>" class="faq-filter-pill <?= !$filtroArea ? 'active' : '' ?>">Todas</a>
    <?php foreach ($areaLabels as $k => $l): ?>
    <a href="<?= module_url('salavip', 'faq_admin.php?area=' . $k) ?>" class="faq-filter-pill <?= $filtroArea === $k ? 'active' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</div>

<!-- FAQ List -->
<div class="card">
    <div class="card-header"><h3>FAQs Cadastradas (<?= count($faqs) ?>)</h3></div>
    <div class="card-body" style="padding:0;overflow-x:auto;">
        <?php if (empty($faqs)): ?>
            <div style="text-align:center;padding:2rem;"><p class="text-muted text-sm">Nenhuma FAQ cadastrada.</p></div>
        <?php else: ?>
            <table class="faq-table">
                <thead><tr>
                    <th style="width:30px;">#</th>
                    <th style="width:90px;">Área</th>
                    <th>Pergunta</th>
                    <th style="width:50px;">Ord.</th>
                    <th style="width:50px;">&#11088;</th>
                    <th style="width:50px;">Ativo</th>
                    <th style="width:100px;">Ações</th>
                </tr></thead>
                <tbody>
                <?php
                $areaCores = array('familia'=>'#d97706','consumidor'=>'#059669','civel'=>'#6366f1','previdenciario'=>'#0ea5e9','imobiliario'=>'#B87333');
                foreach ($faqs as $faq):
                    $corArea = isset($areaCores[$faq['area']]) ? $areaCores[$faq['area']] : '#64748b';
                    $labelArea = isset($areaLabels[$faq['area']]) ? $areaLabels[$faq['area']] : ucfirst($faq['area']);
                ?>
                <tr>
                    <td><?= $faq['id'] ?></td>
                    <td><span class="area-badge" style="background:<?= $corArea ?>;"><?= e($labelArea) ?></span></td>
                    <td style="font-weight:600;"><?= e(mb_strimwidth($faq['pergunta'], 0, 80, '...')) ?></td>
                    <td><?= $faq['ordem'] ?></td>
                    <td><?= $faq['destaque'] ? '&#11088;' : '' ?></td>
                    <td>
                        <form method="POST" style="display:inline;"><?= csrf_input() ?>
                            <input type="hidden" name="action" value="toggle_faq">
                            <input type="hidden" name="id" value="<?= $faq['id'] ?>">
                            <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.85rem;"><?= $faq['ativo'] ? '&#9989;' : '&#10060;' ?></button>
                        </form>
                    </td>
                    <td>
                        <div style="display:flex;gap:.3rem;">
                            <button type="button" class="btn btn-outline btn-sm" onclick="openEditFaq(<?= $faq['id'] ?>, <?= e(json_encode($faq['area'])) ?>, <?= e(json_encode($faq['pergunta'])) ?>, <?= e(json_encode($faq['resposta'])) ?>, <?= $faq['ordem'] ?>, <?= $faq['destaque'] ?>)">&#9998;</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Excluir esta FAQ?');"><?= csrf_input() ?>
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
            <div style="display:grid;grid-template-columns:1fr 80px;gap:.75rem;">
                <div>
                    <label class="form-label">Área</label>
                    <select name="area" id="edit_area" class="form-control">
                        <?php foreach ($areaLabels as $k => $l): ?>
                        <option value="<?= $k ?>"><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Ordem</label>
                    <input type="number" name="ordem" id="edit_ordem" class="form-control" min="0">
                </div>
            </div>
            <div>
                <label class="form-label">Pergunta *</label>
                <input type="text" name="pergunta" id="edit_pergunta" class="form-control" required>
            </div>
            <div>
                <label class="form-label">Resposta *</label>
                <textarea name="resposta" id="edit_resposta" class="form-control" rows="5" required></textarea>
            </div>
            <div style="display:flex;gap:1rem;align-items:center;">
                <label style="display:flex;align-items:center;gap:.3rem;font-size:.82rem;cursor:pointer;">
                    <input type="checkbox" name="destaque" id="edit_destaque" value="1"> &#11088; Destaque
                </label>
                <div style="margin-left:auto;display:flex;gap:.5rem;">
                    <button type="submit" class="btn btn-primary">Salvar</button>
                    <button type="button" class="btn btn-outline" onclick="closeEditFaq()">Cancelar</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openEditFaq(id, area, pergunta, resposta, ordem, destaque) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_area').value = area;
    document.getElementById('edit_pergunta').value = pergunta;
    document.getElementById('edit_resposta').value = resposta;
    document.getElementById('edit_ordem').value = ordem;
    document.getElementById('edit_destaque').checked = !!destaque;
    document.getElementById('editModal').classList.add('show');
}
function closeEditFaq() { document.getElementById('editModal').classList.remove('show'); }
document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeEditFaq(); });
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
