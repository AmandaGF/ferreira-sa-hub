<?php
/**
 * Ferreira & Sá Hub — Formulário de Lead
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$errors = [];
$lead = null;

$editId = (int)($_GET['id'] ?? 0);
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM pipeline_leads WHERE id = ?');
    $stmt->execute([$editId]);
    $lead = $stmt->fetch();
    if (!$lead) { flash_set('error', 'Lead não encontrado.'); redirect(module_url('pipeline')); }
    $pageTitle = 'Editar Lead';
} else {
    $pageTitle = 'Novo Lead';
}

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { $errors[] = 'Token inválido.'; }

    $f = [
        'name'      => clean_str($_POST['name'] ?? '', 150),
        'phone'     => clean_str($_POST['phone'] ?? '', 40),
        'email'     => trim($_POST['email'] ?? ''),
        'source'    => $_POST['source'] ?? 'outro',
        'case_type' => clean_str($_POST['case_type'] ?? '', 60),
        'estimated_value' => (int)str_replace(['.', ','], ['', ''], $_POST['estimated_value'] ?? '0'),
        'assigned_to' => (int)($_POST['assigned_to'] ?? 0) ?: null,
        'notes'     => clean_str($_POST['notes'] ?? '', 2000),
    ];

    if (empty($f['name'])) $errors[] = 'Nome é obrigatório.';
    $validSources = ['calculadora','landing','indicacao','instagram','google','whatsapp','outro'];
    if (!in_array($f['source'], $validSources)) $f['source'] = 'outro';

    if (empty($errors)) {
        if ($editId) {
            $pdo->prepare(
                'UPDATE pipeline_leads SET name=?, phone=?, email=?, source=?, case_type=?,
                 estimated_value_cents=?, assigned_to=?, notes=?, updated_at=NOW() WHERE id=?'
            )->execute([
                $f['name'], $f['phone'] ?: null, $f['email'] ?: null, $f['source'],
                $f['case_type'] ?: null, $f['estimated_value'] ?: null,
                $f['assigned_to'], $f['notes'] ?: null, $editId
            ]);
            audit_log('lead_updated', 'lead', $editId);
            flash_set('success', 'Lead atualizado.');
        } else {
            $pdo->prepare(
                'INSERT INTO pipeline_leads (name, phone, email, source, stage, case_type, estimated_value_cents, assigned_to, notes)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            )->execute([
                $f['name'], $f['phone'] ?: null, $f['email'] ?: null, $f['source'],
                'novo', $f['case_type'] ?: null, $f['estimated_value'] ?: null,
                $f['assigned_to'], $f['notes'] ?: null
            ]);
            $newId = (int)$pdo->lastInsertId();

            // Registrar histórico
            $pdo->prepare('INSERT INTO pipeline_history (lead_id, to_stage, changed_by) VALUES (?,?,?)')
                ->execute([$newId, 'novo', current_user_id()]);

            audit_log('lead_created', 'lead', $newId);
            flash_set('success', 'Lead criado.');
        }
        redirect(module_url('pipeline'));
    }
} else {
    $f = [
        'name'      => $lead['name'] ?? '',
        'phone'     => $lead['phone'] ?? '',
        'email'     => $lead['email'] ?? '',
        'source'    => $lead['source'] ?? 'outro',
        'case_type' => $lead['case_type'] ?? '',
        'estimated_value' => $lead['estimated_value_cents'] ?? '',
        'assigned_to' => $lead['assigned_to'] ?? '',
        'notes'     => $lead['notes'] ?? '',
    ];
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width: 600px;">
    <a href="<?= module_url('pipeline') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><span class="alert-icon">✕</span><div><?= implode('<br>', array_map('e', $errors)) ?></div></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrf_input() ?>

            <div class="form-group">
                <label class="form-label">Nome do lead *</label>
                <input type="text" name="name" class="form-input" value="<?= e($f['name']) ?>" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Telefone / WhatsApp</label>
                    <input type="text" name="phone" class="form-input" value="<?= e($f['phone']) ?>" placeholder="(00) 00000-0000">
                </div>
                <div class="form-group">
                    <label class="form-label">E-mail</label>
                    <input type="email" name="email" class="form-input" value="<?= e($f['email']) ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Origem</label>
                    <select name="source" class="form-select">
                        <option value="outro" <?= $f['source'] === 'outro' ? 'selected' : '' ?>>Outro</option>
                        <option value="indicacao" <?= $f['source'] === 'indicacao' ? 'selected' : '' ?>>Indicação</option>
                        <option value="instagram" <?= $f['source'] === 'instagram' ? 'selected' : '' ?>>Instagram</option>
                        <option value="google" <?= $f['source'] === 'google' ? 'selected' : '' ?>>Google</option>
                        <option value="whatsapp" <?= $f['source'] === 'whatsapp' ? 'selected' : '' ?>>WhatsApp</option>
                        <option value="landing" <?= $f['source'] === 'landing' ? 'selected' : '' ?>>Site/Landing</option>
                        <option value="calculadora" <?= $f['source'] === 'calculadora' ? 'selected' : '' ?>>Calculadora</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Tipo de caso</label>
                    <input type="text" name="case_type" class="form-input" value="<?= e($f['case_type']) ?>" placeholder="Ex: Divórcio, Pensão...">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Valor estimado (centavos)</label>
                    <input type="number" name="estimated_value" class="form-input" value="<?= e($f['estimated_value']) ?>" placeholder="0">
                    <span class="form-hint">Em centavos. Ex: 500000 = R$ 5.000,00</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Responsável</label>
                    <select name="assigned_to" class="form-select">
                        <option value="">— Selecionar —</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= (int)$f['assigned_to'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Observações</label>
                <textarea name="notes" class="form-textarea" rows="3"><?= e($f['notes']) ?></textarea>
            </div>

            <div class="card-footer" style="border-top:none;padding:1rem 0 0;">
                <a href="<?= module_url('pipeline') ?>" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary"><?= $editId ? 'Salvar' : 'Criar Lead' ?></button>
            </div>
        </form>
    </div></div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
