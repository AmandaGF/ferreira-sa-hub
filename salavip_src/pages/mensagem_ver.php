<?php
/**
 * Central VIP F&S — Ver Conversa
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Validar thread ---
$threadId = (int)($_GET['id'] ?? 0);
if ($threadId <= 0) {
    sv_flash('error', 'Conversa n&atilde;o encontrada.');
    sv_redirect('pages/mensagens.php');
}

$stmtThread = $pdo->prepare(
    "SELECT t.*, c.title AS processo_titulo
     FROM salavip_threads t
     LEFT JOIN cases c ON c.id = t.case_id
     WHERE t.id = ? AND t.cliente_id = ?"
);
$stmtThread->execute([$threadId, $clienteId]);
$thread = $stmtThread->fetch();

if (!$thread) {
    sv_flash('error', 'Conversa n&atilde;o encontrada.');
    sv_redirect('pages/mensagens.php');
}

// --- Marcar como lidas ---
$stmtLidas = $pdo->prepare(
    "UPDATE salavip_mensagens SET lida_cliente = 1 WHERE thread_id = ? AND origem = 'conecta' AND lida_cliente = 0"
);
$stmtLidas->execute([$threadId]);

// --- Buscar mensagens ---
$stmtMsgs = $pdo->prepare(
    "SELECT * FROM salavip_mensagens WHERE thread_id = ? ORDER BY criado_em ASC"
);
$stmtMsgs->execute([$threadId]);
$mensagens = $stmtMsgs->fetchAll();

$pageTitle = $thread['assunto'];
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Info da conversa -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;">
        <?php if (!empty($thread['categoria'])): ?>
            <span style="background:var(--sv-accent-bg);color:var(--sv-accent);padding:2px 8px;border-radius:9999px;font-size:.75rem;font-weight:600;">
                <?= sv_e(ucfirst($thread['categoria'])) ?>
            </span>
        <?php endif; ?>
        <?php
        $statusThreadMap = [
            'aberta'     => ['#059669', 'Aberta'],
            'respondida' => ['#f59e0b', 'Respondida'],
            'fechada'    => ['#6b7280', 'Fechada'],
        ];
        $ts = $statusThreadMap[$thread['status'] ?? ''] ?? ['#888', ucfirst($thread['status'] ?? '')];
        ?>
        <span style="background:<?= $ts[0] ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.75rem;font-weight:600;">
            <?= sv_e($ts[1]) ?>
        </span>
        <?php if (!empty($thread['processo_titulo'])): ?>
            <span style="color:var(--sv-text-muted);font-size:.85rem;">
                Processo: <?= sv_e($thread['processo_titulo']) ?>
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Mensagens -->
<div style="display:flex;flex-direction:column;gap:1rem;margin-bottom:1.5rem;">
    <?php foreach ($mensagens as $msg): ?>
        <?php
        $isCliente = ($msg['origem'] === 'salavip');
        $bgColor   = $isCliente ? 'rgba(201,169,78,.08)' : 'rgba(30,41,59,.6)';
        $borderColor = $isCliente ? 'rgba(201,169,78,.3)' : 'rgba(100,116,139,.3)';
        $align     = $isCliente ? 'margin-left:2rem;' : 'margin-right:2rem;';
        $nome      = $isCliente ? 'Voc&ecirc;' : sv_e($msg['remetente_nome'] ?? 'Equipe F&S');
        $nomeCor   = $isCliente ? '#c9a94e' : '#94a3b8';
        ?>
        <div class="sv-msg <?= $isCliente ? 'sv-msg-cliente' : 'sv-msg-equipe' ?>"
             style="background:<?= $bgColor ?>;border:1px solid <?= $borderColor ?>;border-radius:.75rem;padding:1rem;<?= $align ?>">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem;flex-wrap:wrap;gap:.25rem;">
                <strong style="color:<?= $nomeCor ?>;font-size:.85rem;"><?= $nome ?></strong>
                <span style="color:#64748b;font-size:.75rem;"><?= sv_formatar_data_hora($msg['criado_em']) ?></span>
            </div>
            <div style="color:var(--sv-text);line-height:1.6;white-space:pre-wrap;"><?= sv_e($msg['mensagem']) ?></div>
            <?php if (!empty($msg['anexo_nome'])): ?>
                <div style="margin-top:.75rem;padding-top:.5rem;border-top:1px solid rgba(100,116,139,.2);">
                    <span style="color:var(--sv-text-muted);font-size:.8rem;">
                        &#128206; <?= sv_e($msg['anexo_nome']) ?>
                        <?php if (!empty($msg['anexo_url'])): ?>
                            &mdash; <a href="<?= sv_e($msg['anexo_url']) ?>" target="_blank" rel="noopener" style="color:var(--sv-accent);">Baixar</a>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if (($thread['status'] ?? '') !== 'fechada'): ?>
    <!-- Formulário de Resposta -->
    <div class="sv-card">
        <h3>Responder</h3>
        <form action="<?= sv_url('api/mensagem_enviar.php') ?>" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">
            <input type="hidden" name="thread_id" value="<?= (int)$thread['id'] ?>">

            <div class="form-group">
                <label class="form-label">Mensagem *</label>
                <textarea name="mensagem" class="form-input" rows="4" required placeholder="Digite sua resposta..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Anexo (opcional)</label>
                <input type="file" name="anexo" class="form-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.txt">
            </div>

            <div style="display:flex;gap:1rem;align-items:center;margin-top:1rem;">
                <button type="submit" class="sv-btn sv-btn-gold">Enviar Resposta</button>
                <a href="<?= sv_url('pages/mensagens.php') ?>" class="sv-btn sv-btn-outline">Voltar</a>
            </div>
        </form>
    </div>
<?php else: ?>
    <div class="sv-card" style="text-align:center;">
        <p style="color:var(--sv-text-muted);margin:0;">Esta conversa foi encerrada.</p>
    </div>
<?php endif; ?>

<div style="margin-top:1rem;">
    <a href="<?= sv_url('pages/mensagens.php') ?>" style="color:var(--sv-accent);font-size:.85rem;">&larr; Voltar para Mensagens</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
