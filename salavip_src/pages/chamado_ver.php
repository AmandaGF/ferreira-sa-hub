<?php
/**
 * Central VIP F&S — Detalhe do Chamado (conversa)
 * Cliente vê histórico completo + responde se ainda estiver aberto
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

if (!$clienteId) {
    sv_redirect('index.php');
}

$ticketId = (int)($_GET['id'] ?? 0);
if (!$ticketId) {
    sv_redirect('pages/chamados.php');
}

// Buscar ticket — só do próprio cliente
$stmt = $pdo->prepare(
    "SELECT t.*, c.title as processo_titulo
     FROM tickets t
     LEFT JOIN cases c ON c.id = t.case_id
     WHERE t.id = ? AND t.client_id = ?"
);
$stmt->execute(array($ticketId, $clienteId));
$ticket = $stmt->fetch();

if (!$ticket) {
    sv_flash('error', 'Chamado não encontrado.');
    sv_redirect('pages/chamados.php');
}

$encerrado = in_array($ticket['status'], array('resolvido', 'cancelado'));

// --- POST: Adicionar resposta ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'responder') {
    if (!salavip_validar_csrf($_POST['csrf_token'] ?? '')) {
        sv_flash('error', 'Token inválido.');
        sv_redirect('pages/chamado_ver.php?id=' . $ticketId);
    }

    if ($encerrado) {
        sv_flash('error', 'Este chamado já foi encerrado. Abra um novo chamado.');
        sv_redirect('pages/chamado_ver.php?id=' . $ticketId);
    }

    $msg = trim($_POST['mensagem'] ?? '');
    if (!$msg) {
        sv_flash('error', 'Digite uma mensagem.');
        sv_redirect('pages/chamado_ver.php?id=' . $ticketId);
    }

    try {
        $pdo->prepare(
            "INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, user_id, message, created_at)
             VALUES (?, 'cliente', ?, NULL, ?, NOW())"
        )->execute(array($ticketId, $clienteId, $msg));

        // Atualizar updated_at do ticket
        $pdo->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?")->execute(array($ticketId));

        sv_flash('success', 'Resposta enviada!');
    } catch (Exception $e) {
        sv_flash('error', 'Erro ao enviar resposta.');
    }
    sv_redirect('pages/chamado_ver.php?id=' . $ticketId);
}

// Buscar mensagens
$stmtMsg = $pdo->prepare(
    "SELECT tm.*, u.name as user_name
     FROM ticket_messages tm
     LEFT JOIN users u ON u.id = tm.user_id
     WHERE tm.ticket_id = ?
     ORDER BY tm.created_at ASC"
);
$stmtMsg->execute(array($ticketId));
$mensagens = $stmtMsg->fetchAll();

$statusMap = array(
    'aberto' => array('#6366f1', 'Aberto'),
    'em_andamento' => array('#d97706', 'Em andamento'),
    'aguardando' => array('#0ea5e9', 'Aguardando você'),
    'resolvido' => array('#059669', 'Resolvido'),
    'cancelado' => array('#dc2626', 'Cancelado'),
);
$st = isset($statusMap[$ticket['status']]) ? $statusMap[$ticket['status']] : array('#888', ucfirst($ticket['status']));

$categoriaLabels = array(
    'urgencia' => '🚨 Urgência',
    'financeiro' => '💰 Financeiro',
    'duvida' => '❓ Dúvida',
    'atualizacao' => '📋 Atualização cadastral',
    'documento' => '📄 Documento',
    'outro' => '📌 Outro',
);

$pageTitle = 'Chamado #' . $ticketId;
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.ch-msg { display:flex; gap:.7rem; margin-bottom:1rem; max-width:85%; }
.ch-msg.cliente { margin-left:auto; flex-direction:row-reverse; }
.ch-msg-avatar { width:36px; height:36px; border-radius:50%; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:.85rem; color:#fff; }
.ch-msg.cliente .ch-msg-avatar { background:linear-gradient(135deg,#B87333,#8B5A2B); }
.ch-msg.equipe .ch-msg-avatar { background:linear-gradient(135deg,#052228,#0d3640); }
.ch-msg-bubble { background:var(--sv-card-bg,#fff); border:1px solid var(--sv-border); border-radius:14px; padding:.7rem 1rem; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.ch-msg.cliente .ch-msg-bubble { background:rgba(184,115,51,.08); border-color:rgba(184,115,51,.25); }
.ch-msg-author { font-size:.7rem; font-weight:700; color:var(--sv-accent); margin-bottom:.2rem; }
.ch-msg.cliente .ch-msg-author { text-align:right; }
.ch-msg-text { font-size:.88rem; color:var(--sv-text); white-space:pre-wrap; line-height:1.5; }
.ch-msg-time { font-size:.65rem; color:var(--sv-text-muted); margin-top:.3rem; }
.ch-msg.cliente .ch-msg-time { text-align:right; }
</style>

<a href="<?= SALAVIP_BASE_URL ?>/pages/chamados.php" style="display:inline-flex;align-items:center;gap:.3rem;color:var(--sv-accent);text-decoration:none;font-size:.85rem;font-weight:600;margin-bottom:1rem;">
    ← Voltar para Meus Chamados
</a>

<div class="sv-card" style="margin-bottom:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem;">
        <div style="flex:1;min-width:200px;">
            <div style="font-size:.7rem;color:var(--sv-text-muted);font-weight:600;">Chamado #<?= $ticket['id'] ?></div>
            <h2 style="margin:.2rem 0 0;font-size:1.15rem;color:var(--sv-text);"><?= sv_e($ticket['title']) ?></h2>
        </div>
        <span style="background:<?= $st[0] ?>;color:#fff;padding:4px 12px;border-radius:9999px;font-size:.75rem;font-weight:700;"><?= $st[1] ?></span>
    </div>

    <div style="display:flex;gap:.5rem;flex-wrap:wrap;font-size:.72rem;color:var(--sv-text-muted);">
        <span><?= $categoriaLabels[$ticket['category']] ?? ucfirst($ticket['category'] ?? '') ?></span>
        <?php if ($ticket['processo_titulo']): ?>
            <span>· ⚖️ <?= sv_e($ticket['processo_titulo']) ?></span>
        <?php endif; ?>
        <span>· Aberto em <?= date('d/m/Y H:i', strtotime($ticket['created_at'])) ?></span>
    </div>
</div>

<!-- Conversa -->
<div class="sv-card" style="margin-bottom:1rem;">
    <h3 style="margin:0 0 1rem;">Conversa</h3>

    <?php if (empty($mensagens)): ?>
        <p class="sv-empty">Nenhuma mensagem.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;">
            <?php foreach ($mensagens as $msg):
                $isCliente = ($msg['sender_type'] === 'cliente');
                $autor = $isCliente ? ($user['nome_exibicao'] ?: 'Você') : ($msg['user_name'] ?: 'Equipe Ferreira & Sá');
                $iniciais = mb_strtoupper(mb_substr($autor, 0, 2, 'UTF-8'), 'UTF-8');
            ?>
            <div class="ch-msg <?= $isCliente ? 'cliente' : 'equipe' ?>">
                <div class="ch-msg-avatar"><?= $iniciais ?></div>
                <div class="ch-msg-bubble">
                    <div class="ch-msg-author"><?= sv_e($autor) ?></div>
                    <div class="ch-msg-text"><?= sv_e($msg['message']) ?></div>
                    <div class="ch-msg-time"><?= date('d/m/Y H:i', strtotime($msg['created_at'])) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Responder -->
<div class="sv-card">
    <?php if ($encerrado): ?>
        <div style="text-align:center;padding:1rem;background:rgba(5,150,105,.08);border:1px solid rgba(5,150,105,.25);border-radius:10px;">
            <div style="font-size:1.5rem;margin-bottom:.3rem;">✅</div>
            <h4 style="margin:0 0 .3rem;color:#059669;">Este chamado foi <?= $ticket['status'] === 'resolvido' ? 'resolvido' : 'encerrado' ?></h4>
            <p style="font-size:.82rem;color:var(--sv-text-muted);margin:0 0 .8rem;">
                Não é mais possível responder a este chamado. Caso precise de uma nova solicitação, abra um novo chamado.
            </p>
            <a href="<?= SALAVIP_BASE_URL ?>/pages/chamados.php" class="sv-btn sv-btn-gold">+ Abrir Novo Chamado</a>
        </div>
    <?php else: ?>
        <h3 style="margin:0 0 .8rem;">Responder</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">
            <input type="hidden" name="action" value="responder">

            <textarea name="mensagem" rows="4" required placeholder="Digite sua resposta..." style="width:100%;padding:.7rem .9rem;border:1.5px solid var(--sv-border);border-radius:10px;font-size:.9rem;background:var(--sv-bg);color:var(--sv-text);resize:vertical;font-family:inherit;margin-bottom:.6rem;"></textarea>

            <button type="submit" class="sv-btn sv-btn-gold">Enviar Resposta</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
