<?php
/**
 * Ferreira & Sá Hub — Novo Caso/Processo
 */

require_once __DIR__ . '/../../core/middleware.php';
require_min_role('gestao');

$pdo = db();
$clientId = (int)($_GET['client_id'] ?? 0);

$stmt = $pdo->prepare('SELECT id, name FROM clients WHERE id = ?');
$stmt->execute([$clientId]);
$client = $stmt->fetch();

if (!$client) {
    flash_set('error', 'Cliente não encontrado.');
    redirect(module_url('crm'));
}

$pageTitle = 'Novo Caso — ' . $client['name'];

// Buscar usuários para responsável
$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width: 600px;">
    <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $clientId) ?>" class="btn btn-outline btn-sm mb-2">← Voltar para <?= e($client['name']) ?></a>

    <div class="card">
        <div class="card-body">
            <form method="POST" action="<?= module_url('crm', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="add_case">
                <input type="hidden" name="client_id" value="<?= $clientId ?>">

                <div class="form-group">
                    <label class="form-label">Título do caso *</label>
                    <input type="text" name="title" class="form-input" required placeholder="Ex: Divórcio consensual João x Maria">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Tipo</label>
                        <select name="case_type" class="form-select">
                            <option value="familia">Família</option>
                            <option value="pensao">Pensão</option>
                            <option value="divorcio">Divórcio</option>
                            <option value="guarda">Guarda</option>
                            <option value="convivencia">Convivência</option>
                            <option value="inventario">Inventário</option>
                            <option value="responsabilidade_civil">Resp. Civil</option>
                            <option value="outro">Outro</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prioridade</label>
                        <select name="priority" class="form-select">
                            <option value="normal">Normal</option>
                            <option value="alta">Alta</option>
                            <option value="urgente">Urgente</option>
                            <option value="baixa">Baixa</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Nº do processo</label>
                        <input type="text" name="case_number" class="form-input" placeholder="0000000-00.0000.0.00.0000">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Vara/Tribunal</label>
                        <input type="text" name="court" class="form-input" placeholder="Ex: 1ª Vara de Família">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Responsável</label>
                        <select name="responsible_user_id" class="form-select">
                            <option value="">— Selecionar —</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Prazo</label>
                        <input type="date" name="deadline" class="form-input">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Observações</label>
                    <textarea name="notes" class="form-textarea" rows="3"></textarea>
                </div>

                <div class="card-footer" style="border-top:none;padding:1rem 0 0;">
                    <a href="<?= module_url('crm', 'cliente_ver.php?id=' . $clientId) ?>" class="btn btn-outline">Cancelar</a>
                    <button type="submit" class="btn btn-primary">Criar Caso</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
