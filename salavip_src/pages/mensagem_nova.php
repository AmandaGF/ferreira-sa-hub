<?php
/**
 * Central VIP F&S — Nova Mensagem
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Processos do cliente ---
$stmtProc = $pdo->prepare(
    "SELECT id, title FROM cases WHERE client_id = ? AND salavip_ativo = 1 ORDER BY title ASC"
);
$stmtProc->execute([$clienteId]);
$processos = $stmtProc->fetchAll();

$pageTitle = 'Nova Mensagem';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="sv-card">
    <h3>Enviar Nova Mensagem</h3>
    <form action="<?= sv_url('api/mensagem_enviar.php') ?>" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">

        <div class="form-group">
            <label class="form-label">Assunto *</label>
            <input type="text" name="assunto" class="form-input" required placeholder="Assunto da mensagem">
        </div>

        <div class="form-group">
            <label class="form-label">Categoria *</label>
            <select name="categoria" class="form-input" required>
                <option value="">Selecione...</option>
                <option value="duvida">D&uacute;vida</option>
                <option value="solicitacao">Solicita&ccedil;&atilde;o</option>
                <option value="documentos">Documentos</option>
                <option value="financeiro">Financeiro</option>
                <option value="agendamento">Agendamento</option>
                <option value="outro">Outro</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Processo Vinculado</label>
            <select name="case_id" class="form-input">
                <option value="">Nenhum (geral)</option>
                <?php foreach ($processos as $proc): ?>
                    <option value="<?= (int)$proc['id'] ?>"><?= sv_e($proc['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Mensagem *</label>
            <textarea name="mensagem" class="form-input" rows="6" required minlength="10" placeholder="Digite sua mensagem (m&iacute;nimo 10 caracteres)"></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Anexo (opcional - m&aacute;x 10MB)</label>
            <input type="file" name="anexo" class="form-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt">
        </div>

        <div style="display:flex;gap:1rem;align-items:center;margin-top:1rem;">
            <button type="submit" class="sv-btn sv-btn-gold">Enviar Mensagem</button>
            <a href="<?= sv_url('pages/mensagens.php') ?>" class="sv-btn sv-btn-outline">Voltar</a>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
