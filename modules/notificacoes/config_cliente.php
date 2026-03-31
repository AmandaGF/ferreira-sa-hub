<?php
/**
 * Ferreira & Sá Hub — Configuração de Notificações ao Cliente
 * Apenas Admin pode editar os templates
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_role('admin')) { flash_set('error', 'Sem permissão.'); redirect(url('modules/dashboard/')); }

$pageTitle = 'Notificações ao Cliente';
$pdo = db();

// Salvar alteração
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validate_csrf()) {
    $tipo = clean_str($_POST['tipo'] ?? '', 60);
    $msg = $_POST['mensagem_whatsapp'] ?? '';
    $msgEmail = $_POST['mensagem_email'] ?? '';
    $ativo = isset($_POST['ativo']) ? 1 : 0;

    if ($tipo) {
        $pdo->prepare("UPDATE notificacao_config SET mensagem_whatsapp=?, mensagem_email=?, ativo=?, updated_by=?, updated_at=NOW() WHERE tipo=?")
            ->execute(array($msg, $msgEmail ?: null, $ativo, current_user_id(), $tipo));
        flash_set('success', 'Template atualizado!');
    }
    redirect(module_url('notificacoes', 'config_cliente.php'));
}

// Buscar templates
$templates = $pdo->query("SELECT * FROM notificacao_config ORDER BY id")->fetchAll();

require_once __DIR__ . '/../../templates/layout_start.php';
?>

<div class="page-header">
    <h1>Notificações ao Cliente</h1>
    <p style="color: var(--text-secondary); margin-top: 4px;">Configure as mensagens automáticas enviadas aos clientes via WhatsApp e e-mail.</p>
</div>

<div style="display: flex; gap: 12px; margin-bottom: 20px;">
    <a href="<?= e(module_url('notificacoes')) ?>" class="btn btn-secondary btn-sm">Minhas Notificações</a>
    <a href="<?= e(module_url('notificacoes', 'log_cliente.php')) ?>" class="btn btn-secondary btn-sm">Log de Envios</a>
</div>

<?php foreach ($templates as $tpl): ?>
<div class="card" style="margin-bottom: 16px;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <strong><?= e($tpl['titulo']) ?></strong>
            <span class="badge badge-<?= $tpl['ativo'] ? 'success' : 'secondary' ?>" style="margin-left: 8px;">
                <?= $tpl['ativo'] ? 'Ativo' : 'Desativado' ?>
            </span>
        </div>
        <span style="font-size: 12px; color: var(--text-secondary);">Tipo: <?= e($tpl['tipo']) ?></span>
    </div>
    <div class="card-body">
        <form method="post" action="">
            <?= csrf_field() ?>
            <input type="hidden" name="tipo" value="<?= e($tpl['tipo']) ?>">

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 600; display: block; margin-bottom: 4px;">
                    Mensagem WhatsApp
                </label>
                <textarea name="mensagem_whatsapp" rows="5" class="form-control" style="width:100%; font-family: monospace; font-size: 13px;"><?= e($tpl['mensagem_whatsapp']) ?></textarea>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="font-weight: 600; display: block; margin-bottom: 4px;">
                    Mensagem E-mail <span style="font-weight:normal; color: var(--text-secondary);">(deixe vazio para usar a mesma do WhatsApp)</span>
                </label>
                <textarea name="mensagem_email" rows="4" class="form-control" style="width:100%; font-family: monospace; font-size: 13px;"><?= e($tpl['mensagem_email'] ?: '') ?></textarea>
            </div>

            <div style="margin-bottom: 12px; font-size: 13px; color: var(--text-secondary);">
                <strong>Variáveis disponíveis:</strong> <?= e($tpl['variaveis_disponiveis'] ?: 'Nenhuma') ?>
            </div>

            <div style="display: flex; align-items: center; gap: 16px;">
                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                    <input type="checkbox" name="ativo" value="1" <?= $tpl['ativo'] ? 'checked' : '' ?>>
                    Ativo
                </label>
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
            </div>
        </form>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($templates)): ?>
<div class="card">
    <div class="card-body" style="text-align: center; color: var(--text-secondary); padding: 40px;">
        Nenhum template configurado. Execute a migração primeiro.
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
