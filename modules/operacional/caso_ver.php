<?php
/**
 * Ferreira & Sá Hub — Detalhe do Caso (Operacional)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$caseId = (int)($_GET['id'] ?? 0);
$userId = current_user_id();
$isColaborador = has_role('colaborador');

$stmt = $pdo->prepare(
    'SELECT cs.*, c.name as client_name, c.phone as client_phone, c.id as client_id, u.name as responsible_name
     FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.id = ?'
);
$stmt->execute([$caseId]);
$case = $stmt->fetch();

if (!$case) { flash_set('error', 'Caso não encontrado.'); redirect(module_url('operacional')); }

// Colaborador só vê seus próprios casos
if ($isColaborador && (int)$case['responsible_user_id'] !== $userId) {
    flash_set('error', 'Sem permissão.'); redirect(module_url('operacional'));
}

$pageTitle = $case['title'];

// Tarefas
$tasks = $pdo->prepare(
    'SELECT ct.*, u.name as assigned_name FROM case_tasks ct
     LEFT JOIN users u ON u.id = ct.assigned_to
     WHERE ct.case_id = ? ORDER BY ct.status ASC, ct.sort_order ASC, ct.created_at ASC'
);
$tasks->execute([$caseId]);
$tasks = $tasks->fetchAll();

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$statusLabels = [
    'aguardando_docs' => 'Aguardando docs', 'em_elaboracao' => 'Em elaboração',
    'aguardando_prazo' => 'Aguardando prazo', 'distribuido' => 'Distribuído',
    'em_andamento' => 'Em andamento', 'concluido' => 'Concluído',
    'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso',
];

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.caso-header { background:linear-gradient(135deg, var(--petrol-900), var(--petrol-500)); color:#fff; border-radius:var(--radius-lg); padding:1.5rem; margin-bottom:1.5rem; }
.caso-header h2 { font-size:1.2rem; margin-bottom:.25rem; }
.caso-header .meta { font-size:.82rem; color:var(--rose); }
.caso-header .actions { margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap; }

.task-list { list-style:none; padding:0; }
.task-item { display:flex; align-items:center; gap:.75rem; padding:.75rem 0; border-bottom:1px solid var(--border); }
.task-item:last-child { border-bottom:none; }
.task-check { width:22px; height:22px; border-radius:6px; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; transition:all var(--transition); }
.task-check:hover { border-color:var(--success); }
.task-check.done { background:var(--success); border-color:var(--success); color:#fff; font-size:.7rem; }
.task-text { flex:1; font-size:.88rem; }
.task-text.done { text-decoration:line-through; color:var(--text-muted); }
.task-meta { font-size:.72rem; color:var(--text-muted); flex-shrink:0; }
</style>

<a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm mb-2">← Voltar</a>

<!-- Header do caso -->
<div class="caso-header">
    <h2><?= e($case['title']) ?></h2>
    <div class="meta">
        👤 <?= e($case['client_name'] ?? 'Sem cliente') ?>
        · <?= e($case['case_type']) ?>
        · <?= e($case['responsible_name'] ?: 'Sem responsável') ?>
        <?php if ($case['deadline']): ?> · Prazo: <?= data_br($case['deadline']) ?><?php endif; ?>
    </div>
    <div class="actions">
        <?php if ($case['client_phone']): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $case['client_phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
        <?php if ($case['drive_folder_url']): ?>
            <a href="<?= e($case['drive_folder_url']) ?>" target="_blank" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);">📁 Abrir pasta Drive</a>
        <?php endif; ?>
        <?php if ($case['client_id']): ?>
            <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $case['client_id']) ?>" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);">👤 Ver no CRM</a>
        <?php endif; ?>
    </div>
</div>

<!-- Status e Informações -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
    <!-- Alterar status -->
    <div class="card">
        <div class="card-header"><h3>Status</h3></div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="case_id" value="<?= $case['id'] ?>">
                <div class="form-group" style="margin:0;">
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($statusLabels as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $case['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Alterar prioridade e responsável -->
    <div class="card">
        <div class="card-header"><h3>Prioridade / Responsável</h3></div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:flex;gap:.5rem;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_case_info">
                <input type="hidden" name="case_id" value="<?= $case['id'] ?>">
                <select name="priority" class="form-select" style="flex:1;">
                    <option value="baixa" <?= $case['priority'] === 'baixa' ? 'selected' : '' ?>>Baixa</option>
                    <option value="normal" <?= $case['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="alta" <?= $case['priority'] === 'alta' ? 'selected' : '' ?>>Alta</option>
                    <option value="urgente" <?= $case['priority'] === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                </select>
                <select name="responsible_user_id" class="form-select" style="flex:1;">
                    <option value="">Sem resp.</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)$case['responsible_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
            </form>
        </div>
    </div>
</div>

<!-- Checklist de Tarefas -->
<div class="card mb-2">
    <div class="card-header">
        <h3>Tarefas (<?= count($tasks) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($tasks)): ?>
            <p class="text-muted text-sm">Nenhuma tarefa cadastrada.</p>
        <?php else: ?>
            <ul class="task-list">
                <?php foreach ($tasks as $task): ?>
                <li class="task-item">
                    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="toggle_task">
                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                        <input type="hidden" name="case_id" value="<?= $caseId ?>">
                        <button type="submit" class="task-check <?= $task['status'] === 'feito' ? 'done' : '' ?>" title="<?= $task['status'] === 'feito' ? 'Desfazer' : 'Concluir' ?>">
                            <?= $task['status'] === 'feito' ? '✓' : '' ?>
                        </button>
                    </form>
                    <span class="task-text <?= $task['status'] === 'feito' ? 'done' : '' ?>"><?= e($task['title']) ?></span>
                    <span class="task-meta">
                        <?php if ($task['assigned_name']): ?><?= e($task['assigned_name']) ?><?php endif; ?>
                        <?php if ($task['due_date']): ?> · <?= data_br($task['due_date']) ?><?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- Adicionar tarefa -->
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:flex;gap:.5rem;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_task">
            <input type="hidden" name="case_id" value="<?= $caseId ?>">
            <input type="text" name="title" class="form-input" placeholder="Nova tarefa..." required style="flex:1;">
            <select name="assigned_to" class="form-select" style="width:140px;">
                <option value="">Quem?</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="due_date" class="form-input" style="width:140px;">
            <button type="submit" class="btn btn-primary btn-sm">+</button>
        </form>
    </div>
</div>

<!-- Observações -->
<?php if ($case['notes']): ?>
<div class="card">
    <div class="card-header"><h3>Observações</h3></div>
    <div class="card-body">
        <p style="white-space:pre-wrap;font-size:.88rem;"><?= e($case['notes']) ?></p>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
