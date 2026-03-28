<?php
/**
 * Ferreira & Sá Hub — Novo Chamado
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pageTitle = 'Novo Chamado';
$pdo = db();
$errors = [];

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf()) { $errors[] = 'Token inválido.'; }

    $title       = clean_str($_POST['title'] ?? '', 200);
    $description = clean_str($_POST['description'] ?? '', 5000);
    $category    = clean_str($_POST['category'] ?? '', 60);
    $priority    = $_POST['priority'] ?? 'normal';
    $clientName  = clean_str($_POST['client_name'] ?? '', 150);
    $clientContact = clean_str($_POST['client_contact'] ?? '', 100);
    $caseNumber  = clean_str($_POST['case_number'] ?? '', 30);
    $dueDate     = $_POST['due_date'] ?? null;
    $assignees   = $_POST['assignees'] ?? [];

    if (empty($title)) $errors[] = 'Título é obrigatório.';
    if (!in_array($priority, ['baixa', 'normal', 'urgente'])) $priority = 'normal';
    if ($dueDate === '') $dueDate = null;

    // Auto-urgente para Prazo e Audiência
    if (in_array($category, ['Prazo', 'Audiência'])) $priority = 'urgente';

    $department = clean_str($_POST['department'] ?? '', 60);

    if (empty($errors)) {
        $pdo->prepare(
            'INSERT INTO tickets (title, description, category, department, priority, status, requester_id, client_name, client_contact, case_number, due_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title, $description ?: null, $category ?: null, $department ?: null, $priority, 'aberto',
            current_user_id(), $clientName ?: null, $clientContact ?: null,
            $caseNumber ?: null, $dueDate
        ]);
        $ticketId = (int)$pdo->lastInsertId();

        // Atribuir responsáveis
        if (!empty($assignees)) {
            $stmtAssign = $pdo->prepare('INSERT INTO ticket_assignees (ticket_id, user_id) VALUES (?, ?)');
            foreach ($assignees as $uid) {
                $uid = (int)$uid;
                if ($uid > 0) $stmtAssign->execute([$ticketId, $uid]);
            }
        }

        audit_log('ticket_created', 'ticket', $ticketId);
        flash_set('success', 'Chamado #' . $ticketId . ' criado!');
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width:650px;">
    <a href="<?= module_url('helpdesk') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><span class="alert-icon">✕</span><div><?= implode('<br>', array_map('e', $errors)) ?></div></div>
    <?php endif; ?>

    <div class="card"><div class="card-body">
        <form method="POST">
            <?= csrf_input() ?>

            <div class="form-group">
                <label class="form-label">Título *</label>
                <input type="text" name="title" class="form-input" required value="<?= e($_POST['title'] ?? '') ?>"
                       placeholder="Ex: Protocolar petição urgente">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Categoria</label>
                    <select name="category" class="form-select">
                        <option value="">— Selecionar —</option>
                        <option value="Prazo">📅 Prazo</option>
                        <option value="Audiência">⚖️ Audiência</option>
                        <option value="WhatsApp">💬 WhatsApp</option>
                        <option value="Documentos">📄 Documentos</option>
                        <option value="Administrativo">🏢 Administrativo</option>
                        <option value="Outros">📌 Outros</option>
                    </select>
                    <span class="form-hint">Prazo e Audiência são auto-urgentes</span>
                </div>
                <div class="form-group">
                    <label class="form-label">Prioridade</label>
                    <select name="priority" class="form-select">
                        <option value="normal">Normal</option>
                        <option value="urgente">Urgente</option>
                        <option value="baixa">Baixa</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Setor</label>
                    <select name="department" class="form-select">
                        <option value="">— Selecionar —</option>
                        <option value="Operacional">Operacional</option>
                        <option value="Comercial">Comercial</option>
                        <option value="Financeiro">Financeiro</option>
                        <option value="Administrativo">Administrativo</option>
                        <option value="Marketing">Marketing</option>
                    </select>
                    <span class="form-hint">Quem precisa ver este chamado</span>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Descrição</label>
                <textarea name="description" class="form-textarea" rows="4" placeholder="Descreva o que precisa ser feito..."><?= e($_POST['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nome do cliente</label>
                    <input type="text" name="client_name" class="form-input" value="<?= e($_POST['client_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Contato do cliente</label>
                    <input type="text" name="client_contact" class="form-input" value="<?= e($_POST['client_contact'] ?? '') ?>" placeholder="Telefone ou e-mail">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nº do processo</label>
                    <input type="text" name="case_number" class="form-input" value="<?= e($_POST['case_number'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Prazo/SLA</label>
                    <input type="date" name="due_date" class="form-input" value="<?= e($_POST['due_date'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Responsáveis</label>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
                    <?php foreach ($users as $u): ?>
                        <label style="display:flex;align-items:center;gap:.35rem;font-size:.85rem;cursor:pointer;">
                            <input type="checkbox" name="assignees[]" value="<?= $u['id'] ?>">
                            <?= e($u['name']) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card-footer" style="border-top:none;padding:1rem 0 0;">
                <a href="<?= module_url('helpdesk') ?>" class="btn btn-outline">Cancelar</a>
                <button type="submit" class="btn btn-primary">Criar Chamado</button>
            </div>
        </form>
    </div></div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
