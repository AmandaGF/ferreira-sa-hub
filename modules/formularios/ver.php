<?php
/**
 * Ferreira & Sá Hub — Ver Formulário Submetido
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT fs.*, u.name as assigned_name, c.name as linked_client_name
     FROM form_submissions fs
     LEFT JOIN users u ON u.id = fs.assigned_to
     LEFT JOIN clients c ON c.id = fs.linked_client_id
     WHERE fs.id = ?'
);
$stmt->execute([$id]);
$form = $stmt->fetch();

if (!$form) { flash_set('error', 'Formulário não encontrado.'); redirect(module_url('formularios')); }

$pageTitle = 'Formulário ' . $form['protocol'];

// Decodificar JSON
$payload = json_decode($form['payload_json'], true);

$statusLabels = ['novo' => 'Novo', 'em_analise' => 'Em análise', 'processado' => 'Processado', 'arquivado' => 'Arquivado'];
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$clients = $pdo->query("SELECT id, name FROM clients ORDER BY name LIMIT 100")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.payload-grid { display:grid; grid-template-columns:1fr; gap:.5rem; }
.payload-item { padding:.6rem .85rem; background:var(--bg); border-radius:var(--radius); }
.payload-item label { font-size:.68rem; text-transform:uppercase; letter-spacing:.5px; color:var(--text-muted); font-weight:700; display:block; }
.payload-item span { font-size:.88rem; color:var(--text); word-break:break-word; }
</style>

<a href="<?= module_url('formularios') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

<!-- Cabeçalho -->
<div class="card mb-2">
    <div class="card-header">
        <div>
            <h3><?= e($form['protocol']) ?></h3>
            <span class="text-sm text-muted"><?= e($form['form_type']) ?> · <?= data_hora_br($form['created_at']) ?></span>
        </div>
        <?php if ($form['client_phone']): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $form['client_phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
            <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Nome</label><br><?= e($form['client_name'] ?: '—') ?></div>
            <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">Telefone</label><br><?= e($form['client_phone'] ?: '—') ?></div>
            <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">E-mail</label><br><?= e($form['client_email'] ?: '—') ?></div>
            <div><label style="font-size:.7rem;text-transform:uppercase;color:var(--text-muted);font-weight:700;">IP</label><br><span class="text-sm"><?= e($form['ip_address'] ?: '—') ?></span></div>
        </div>
    </div>
</div>

<!-- Ações -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
    <!-- Status -->
    <div class="card">
        <div class="card-header"><h3>Status & Responsável</h3></div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('formularios', 'api.php') ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                <select name="status" class="form-select" style="flex:1;">
                    <?php foreach ($statusLabels as $k => $v): ?>
                        <option value="<?= $k ?>" <?= $form['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="assigned_to" class="form-select" style="flex:1;">
                    <option value="">Sem responsável</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)$form['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
            </form>
        </div>
    </div>

    <!-- Vincular cliente -->
    <div class="card">
        <div class="card-header"><h3>Vincular ao CRM</h3></div>
        <div class="card-body">
            <?php if ($form['linked_client_id']): ?>
                <p class="text-sm">Vinculado a: <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $form['linked_client_id']) ?>" class="font-bold"><?= e($form['linked_client_name']) ?></a></p>
            <?php else: ?>
                <form method="POST" action="<?= module_url('formularios', 'api.php') ?>" style="display:flex;gap:.5rem;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="link_client">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <select name="client_id" class="form-select" style="flex:1;">
                        <option value="">— Selecionar cliente —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm">Vincular</button>
                </form>
                <form method="POST" action="<?= module_url('formularios', 'api.php') ?>" style="margin-top:.5rem;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="create_client_from_form">
                    <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
                    <button type="submit" class="btn btn-success btn-sm">+ Criar cliente com estes dados</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Respostas do formulário -->
<div class="card">
    <div class="card-header"><h3>Respostas</h3></div>
    <div class="card-body">
        <?php if ($payload && is_array($payload)): ?>
            <div class="payload-grid">
                <?php foreach ($payload as $key => $value): ?>
                    <div class="payload-item">
                        <label><?= e(str_replace('_', ' ', $key)) ?></label>
                        <span><?php
                            if (is_array($value)) {
                                echo e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                            } else {
                                echo nl2br(e((string)$value));
                            }
                        ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <pre style="font-size:.82rem;white-space:pre-wrap;word-break:break-word;"><?= e($form['payload_json']) ?></pre>
        <?php endif; ?>
    </div>
</div>

<!-- Notas -->
<div class="card mt-2">
    <div class="card-header"><h3>Notas internas</h3></div>
    <div class="card-body">
        <form method="POST" action="<?= module_url('formularios', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_notes">
            <input type="hidden" name="form_id" value="<?= $form['id'] ?>">
            <textarea name="notes" class="form-textarea" rows="3" placeholder="Anotações sobre este formulário..."><?= e($form['notes'] ?? '') ?></textarea>
            <button type="submit" class="btn btn-outline btn-sm mt-1">Salvar notas</button>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
