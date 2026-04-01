<?php
/**
 * Ferreira & Sá Hub — Ver Chamado
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$ticketId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT t.*, u.name as requester_name FROM tickets t
     LEFT JOIN users u ON u.id = t.requester_id WHERE t.id = ?'
);
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) { flash_set('error', 'Chamado não encontrado.'); redirect(module_url('helpdesk')); }

$pageTitle = 'Chamado #' . $ticket['id'];

// Responsáveis
$assignees = $pdo->prepare(
    'SELECT u.id, u.name FROM ticket_assignees ta JOIN users u ON u.id = ta.user_id WHERE ta.ticket_id = ?'
);
$assignees->execute([$ticketId]);
$assignees = $assignees->fetchAll();

// Mensagens
$messages = $pdo->prepare(
    'SELECT tm.*, u.name as user_name FROM ticket_messages tm
     LEFT JOIN users u ON u.id = tm.user_id WHERE tm.ticket_id = ? ORDER BY tm.created_at ASC'
);
$messages->execute([$ticketId]);
$messages = $messages->fetchAll();

$statusLabels = ['aberto' => 'Aberto', 'em_andamento' => 'Em andamento', 'aguardando' => 'Aguardando', 'resolvido' => 'Resolvido', 'cancelado' => 'Cancelado'];
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.msg-list { display:flex; flex-direction:column; gap:.75rem; }
.msg-item { padding:1rem; border-radius:var(--radius); border:1px solid var(--border); }
.msg-item.own { background:var(--petrol-100); border-color:var(--petrol-300); }
.msg-header { display:flex; justify-content:space-between; margin-bottom:.35rem; }
.msg-user { font-weight:700; font-size:.82rem; color:var(--petrol-900); }
.msg-date { font-size:.72rem; color:var(--text-muted); }
.msg-text { font-size:.88rem; white-space:pre-wrap; }
</style>

<a href="<?= module_url('helpdesk') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

<!-- Cabeçalho -->
<div class="card mb-2">
    <div class="card-header">
        <div>
            <h3>#<?= $ticket['id'] ?> — <?= e($ticket['title']) ?></h3>
            <span class="text-sm text-muted">
                Por <?= e($ticket['requester_name']) ?> · <?= data_hora_br($ticket['created_at']) ?>
                <?php if ($ticket['category']): ?> · <?= e($ticket['category']) ?><?php endif; ?>
            </span>
        </div>
        <div class="flex gap-1">
            <span class="badge badge-<?= ['aberto'=>'warning','em_andamento'=>'info','aguardando'=>'gestao','resolvido'=>'success','cancelado'=>'danger'][$ticket['status']] ?? 'gestao' ?>">
                <?= $statusLabels[$ticket['status']] ?? $ticket['status'] ?>
            </span>
            <span class="badge badge-<?= ['urgente'=>'danger','normal'=>'gestao','baixa'=>'colaborador'][$ticket['priority']] ?? 'gestao' ?>">
                <?= e($ticket['priority']) ?>
            </span>
        </div>
    </div>
    <div class="card-body">
        <?php if ($ticket['description']): ?>
            <p style="white-space:pre-wrap;font-size:.9rem;margin-bottom:1rem;"><?= e($ticket['description']) ?></p>
        <?php endif; ?>

        <div style="display:flex;gap:2rem;flex-wrap:wrap;font-size:.82rem;color:var(--text-muted);">
            <?php
            // Buscar cliente e processo vinculados
            $linkedClient = null; $linkedCase = null;
            if (isset($ticket['client_id']) && $ticket['client_id']) {
                $lc = $pdo->prepare("SELECT id, name, phone FROM clients WHERE id = ?"); $lc->execute(array($ticket['client_id'])); $linkedClient = $lc->fetch();
            }
            if (isset($ticket['case_id']) && $ticket['case_id']) {
                $lcs = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE id = ?"); $lcs->execute(array($ticket['case_id'])); $linkedCase = $lcs->fetch();
            }
            ?>
            <?php if ($linkedClient): ?>
                <span>👤 <a href="<?= module_url('clientes', 'ver.php?id=' . $linkedClient['id']) ?>" style="color:var(--petrol-900);font-weight:600;"><?= e($linkedClient['name']) ?></a></span>
            <?php elseif ($ticket['client_name']): ?>
                <span>👤 <?= e($ticket['client_name']) ?></span>
            <?php endif; ?>
            <?php if ($linkedClient && $linkedClient['phone']): ?>
                <span>📱 <a href="https://wa.me/55<?= preg_replace('/\D/', '', $linkedClient['phone']) ?>" target="_blank" style="color:#25D366;"><?= e($linkedClient['phone']) ?></a></span>
            <?php elseif ($ticket['client_contact']): ?>
                <span>📱 <?= e($ticket['client_contact']) ?></span>
            <?php endif; ?>
            <?php if ($linkedCase): ?>
                <span>📁 <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $linkedCase['id']) ?>" style="color:var(--petrol-900);font-weight:600;"><?= e($linkedCase['title']) ?><?= $linkedCase['case_number'] ? ' — ' . e($linkedCase['case_number']) : '' ?></a></span>
            <?php elseif ($ticket['case_number']): ?>
                <span>📁 <?= e($ticket['case_number']) ?></span>
            <?php endif; ?>
            <?php if ($ticket['due_date']): ?><span>⏰ Prazo: <?= data_br($ticket['due_date']) ?></span><?php endif; ?>
            <span>👥 <?= !empty($assignees) ? e(implode(', ', array_column($assignees, 'name'))) : 'Sem responsável' ?></span>
        </div>
    </div>
</div>

<!-- Alterar status -->
<div class="card mb-2">
    <div class="card-body">
        <form method="POST" action="<?= module_url('helpdesk', 'api.php') ?>" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
            <span class="text-sm font-bold">Status:</span>
            <select name="status" class="form-select" style="width:auto;">
                <?php foreach ($statusLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $ticket['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
            <span class="text-sm font-bold">Prioridade:</span>
            <select name="priority" class="form-select" style="width:auto;">
                <option value="baixa" <?= $ticket['priority'] === 'baixa' ? 'selected' : '' ?>>Baixa</option>
                <option value="normal" <?= $ticket['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="urgente" <?= $ticket['priority'] === 'urgente' ? 'selected' : '' ?>>Urgente</option>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Atualizar</button>
        </form>
    </div>
</div>

<!-- Mensagens -->
<div class="card mb-2">
    <div class="card-header"><h3>Mensagens (<?= count($messages) ?>)</h3></div>
    <div class="card-body">
        <?php if (empty($messages)): ?>
            <p class="text-muted text-sm">Nenhuma mensagem ainda.</p>
        <?php else: ?>
            <div class="msg-list">
                <?php foreach ($messages as $msg): ?>
                <div class="msg-item <?= (int)$msg['user_id'] === current_user_id() ? 'own' : '' ?>">
                    <div class="msg-header">
                        <span class="msg-user"><?= e($msg['user_name']) ?></span>
                        <span class="msg-date"><?= data_hora_br($msg['created_at']) ?></span>
                    </div>
                    <div class="msg-text"><?= nl2br(e($msg['message'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Nova mensagem -->
        <form method="POST" action="<?= module_url('helpdesk', 'api.php') ?>" style="margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_message">
            <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
            <textarea name="message" class="form-textarea" rows="3" placeholder="Escreva uma mensagem..." required></textarea>
            <button type="submit" class="btn btn-primary btn-sm mt-1">Enviar</button>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
