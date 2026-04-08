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
$clients = $pdo->query("SELECT id, name, phone FROM clients ORDER BY name")->fetchAll();

// AJAX: buscar processos do cliente
if (isset($_GET['ajax_cases']) && (int)$_GET['client_id'] > 0) {
    header('Content-Type: application/json');
    $cases = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE client_id = ? ORDER BY created_at DESC");
    $cases->execute(array((int)$_GET['client_id']));
    echo json_encode($cases->fetchAll());
    exit;
}

// Pré-carregar caso_id (vindo da pasta do processo)
$preCaseId = (int)($_GET['caso_id'] ?? 0);
$preCase = null; $preClientId = 0;
if ($preCaseId) {
    $stmtC = $pdo->prepare("SELECT cs.*, cl.name as client_name FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id WHERE cs.id = ?");
    $stmtC->execute(array($preCaseId));
    $preCase = $stmtC->fetch();
    if ($preCase) $preClientId = (int)$preCase['client_id'];
}

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

    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
    $caseId = (int)($_POST['case_id'] ?? 0) ?: null;

    if (empty($errors)) {
        $pdo->prepare(
            'INSERT INTO tickets (title, description, category, department, priority, status, requester_id, client_id, case_id, client_name, client_contact, case_number, due_date)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $title, $description ?: null, $category ?: null, $department ?: null, $priority, 'aberto',
            current_user_id(), $clientId, $caseId, $clientName ?: null, $clientContact ?: null,
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

        // Andamento automático no processo vinculado
        if ($caseId) {
            try {
                $pdo->prepare(
                    "INSERT INTO case_andamentos (case_id, data_andamento, tipo, descricao, created_by, created_at) VALUES (?,?,?,?,?,NOW())"
                )->execute(array(
                    $caseId,
                    date('Y-m-d'),
                    'chamado',
                    'CHAMADO INTERNO ABERTO - Chamado #' . $ticketId . ': ' . $title,
                    current_user_id()
                ));
            } catch (Exception $e) { /* tabela pode não existir */ }
        }

        audit_log('ticket_created', 'ticket', $ticketId);
        flash_set('success', 'Chamado #' . $ticketId . ' criado!');
        redirect(module_url('helpdesk', 'ver.php?id=' . $ticketId));
    }
}

require_once APP_ROOT . '/templates/layout_start.php';
echo voltar_ao_processo_html();
?>

<div style="max-width:650px;">
    <a href="<?= module_url('helpdesk') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

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
                        <option value="CX">CX</option>
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
                    <label class="form-label">Cliente vinculado</label>
                    <select name="client_id" id="clienteSelect" class="form-select" onchange="carregarProcessos()">
                        <option value="">— Selecionar —</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $preClientId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['phone'] ? ' — ' . e($c['phone']) : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Processo vinculado</label>
                    <select name="case_id" id="processoSelect" class="form-select">
                        <option value="">— Selecionar —</option>
                        <?php if ($preCase): ?>
                        <option value="<?= $preCaseId ?>" selected><?= e($preCase['title']) ?></option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nome do cliente (texto livre)</label>
                    <input type="text" name="client_name" class="form-input" value="<?= e($_POST['client_name'] ?? ($preCase ? $preCase['client_name'] : '')) ?>" placeholder="Se não encontrou no select acima">
                </div>
                <div class="form-group">
                    <label class="form-label">Contato</label>
                    <input type="text" name="client_contact" class="form-input" value="<?= e($_POST['client_contact'] ?? '') ?>" placeholder="Telefone ou e-mail">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nº do processo</label>
                    <input type="text" name="case_number" class="form-input" value="<?= e($_POST['case_number'] ?? ($preCase ? $preCase['case_number'] : '')) ?>">
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

<script>
function carregarProcessos() {
    var clientId = document.getElementById('clienteSelect').value;
    var select = document.getElementById('processoSelect');
    select.innerHTML = '<option value="">— Selecionar —</option>';
    if (!clientId) return;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '<?= module_url("helpdesk", "novo.php") ?>?ajax_cases=1&client_id=' + clientId);
    xhr.onload = function() {
        if (xhr.status !== 200) {
            select.innerHTML = '<option value="">Erro ao carregar</option>';
            return;
        }
        try {
            var cases = JSON.parse(xhr.responseText);
            for (var i = 0; i < cases.length; i++) {
                var opt = document.createElement('option');
                opt.value = cases[i].id;
                opt.textContent = cases[i].title + (cases[i].case_number ? ' — ' + cases[i].case_number : '');
                select.appendChild(opt);
            }
            if (cases.length === 0) {
                select.innerHTML = '<option value="">Nenhum processo</option>';
            }
        } catch(e) {
            select.innerHTML = '<option value="">Erro ao carregar</option>';
        }
    };
    xhr.send();
}
<?php if ($preClientId): ?>
document.getElementById('clienteSelect').value = '<?= (int)$preClientId ?>';
carregarProcessos();
<?php if ($preCaseId): ?>
setTimeout(function(){ document.getElementById('processoSelect').value = '<?= (int)$preCaseId ?>'; }, 500);
<?php endif; ?>
<?php endif; ?>
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
