<?php
/**
 * Sala VIP F&S — Chamados (Tickets)
 * Cliente pode abrir chamados que vão para o Helpdesk do Conecta
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- POST: Abrir novo chamado ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'abrir_chamado') {
    if (!salavip_validar_csrf()) {
        sv_flash('error', 'Token inválido.');
        sv_redirect('pages/chamados.php');
    }

    $assunto = trim($_POST['assunto'] ?? '');
    $descricao = trim($_POST['descricao'] ?? '');
    $categoria = trim($_POST['categoria'] ?? 'duvida');
    $processoId = (int)($_POST['processo_id'] ?? 0);

    if (!$assunto || !$descricao) {
        sv_flash('error', 'Assunto e descrição são obrigatórios.');
        sv_redirect('pages/chamados.php');
    }

    try {
        // Criar ticket no Helpdesk
        $pdo->prepare(
            "INSERT INTO tickets (client_id, case_id, title, category, status, priority, origem, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'aberto', 'normal', 'salavip', NOW(), NOW())"
        )->execute(array($clienteId, $processoId ?: null, $assunto, $categoria));
        $ticketId = (int)$pdo->lastInsertId();

        // Criar primeira mensagem do ticket
        $pdo->prepare(
            "INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, message, created_at)
             VALUES (?, 'cliente', ?, ?, NOW())"
        )->execute(array($ticketId, $clienteId, $descricao));

        sv_flash('success', 'Chamado #' . $ticketId . ' aberto com sucesso! Nossa equipe responderá em breve.');
    } catch (Exception $e) {
        sv_flash('error', 'Erro ao abrir chamado.');
    }
    sv_redirect('pages/chamados.php');
}

// --- Buscar processos do cliente ---
$processos = array();
try {
    $stmtProc = $pdo->prepare(
        "SELECT id, title, case_number FROM cases WHERE client_id = ? AND salavip_ativo = 1 AND status NOT IN ('cancelado','arquivado') ORDER BY title"
    );
    $stmtProc->execute(array($clienteId));
    $processos = $stmtProc->fetchAll();
} catch (Exception $e) {}

// --- Buscar chamados do cliente ---
$chamados = array();
try {
    $stmtCh = $pdo->prepare(
        "SELECT t.*, c.title as processo_titulo,
                (SELECT COUNT(*) FROM ticket_messages tm WHERE tm.ticket_id = t.id) as total_msgs,
                (SELECT tm2.message FROM ticket_messages tm2 WHERE tm2.ticket_id = t.id ORDER BY tm2.created_at DESC LIMIT 1) as ultima_msg,
                (SELECT tm3.sender_type FROM ticket_messages tm3 WHERE tm3.ticket_id = t.id ORDER BY tm3.created_at DESC LIMIT 1) as ultimo_remetente
         FROM tickets t
         LEFT JOIN cases c ON c.id = t.case_id
         WHERE t.client_id = ?
         ORDER BY t.updated_at DESC"
    );
    $stmtCh->execute(array($clienteId));
    $chamados = $stmtCh->fetchAll();
} catch (Exception $e) {}

$statusMap = array(
    'aberto' => array('#6366f1', 'Aberto'),
    'em_andamento' => array('#d97706', 'Em andamento'),
    'aguardando' => array('#0ea5e9', 'Aguardando'),
    'concluido' => array('#059669', 'Concluído'),
    'cancelado' => array('#dc2626', 'Cancelado'),
);

$pageTitle = 'Chamados';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Abrir novo chamado -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
        <h3 style="margin:0;">Abrir Novo Chamado</h3>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">
        <input type="hidden" name="action" value="abrir_chamado">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem;">
            <div>
                <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Assunto *</label>
                <input type="text" name="assunto" class="sv-input" required placeholder="Descreva resumidamente sua solicitação" style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);">
            </div>
            <div>
                <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Categoria</label>
                <select name="categoria" style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);">
                    <option value="duvida">Dúvida</option>
                    <option value="documento">Solicitação de documento</option>
                    <option value="atualizacao">Atualização de dados</option>
                    <option value="financeiro">Financeiro / Pagamento</option>
                    <option value="urgencia">Urgência</option>
                    <option value="outro">Outro</option>
                </select>
            </div>
        </div>

        <?php if (!empty($processos)): ?>
        <div style="margin-bottom:.75rem;">
            <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Processo relacionado (opcional)</label>
            <select name="processo_id" style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);">
                <option value="">— Nenhum (assunto geral) —</option>
                <?php foreach ($processos as $proc): ?>
                <option value="<?= $proc['id'] ?>"><?= sv_e($proc['title']) ?><?= $proc['case_number'] ? ' (' . sv_e($proc['case_number']) . ')' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div style="margin-bottom:.75rem;">
            <label style="font-size:.8rem;font-weight:600;color:var(--sv-text-muted);display:block;margin-bottom:.3rem;">Descrição *</label>
            <textarea name="descricao" rows="4" required placeholder="Descreva com detalhes sua solicitação ou dúvida..." style="width:100%;padding:.6rem .8rem;border:1.5px solid var(--sv-border);border-radius:8px;font-size:.88rem;background:var(--sv-bg);color:var(--sv-text);resize:vertical;font-family:inherit;"></textarea>
        </div>

        <button type="submit" class="sv-btn sv-btn-gold">Enviar Chamado</button>
    </form>
</div>

<!-- Meus Chamados -->
<div class="sv-card">
    <h3>Meus Chamados</h3>
    <?php if (empty($chamados)): ?>
        <p class="sv-empty">Você ainda não abriu nenhum chamado.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.6rem;">
            <?php foreach ($chamados as $ch):
                $st = $statusMap[$ch['status'] ?? ''] ?? array('#888', ucfirst($ch['status'] ?? ''));
                $isRespondido = ($ch['ultimo_remetente'] ?? '') !== 'cliente';
            ?>
            <div style="border:1px solid var(--sv-border);border-radius:10px;padding:.8rem 1rem;<?= $isRespondido && $ch['status'] !== 'concluido' ? 'border-left:3px solid var(--sv-accent);' : '' ?>">
                <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.3rem;">
                    <strong style="color:var(--sv-accent);font-size:.85rem;">#<?= $ch['id'] ?></strong>
                    <span style="font-weight:600;color:var(--sv-text);font-size:.9rem;"><?= sv_e($ch['title']) ?></span>
                    <span style="background:<?= $st[0] ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.68rem;font-weight:600;"><?= $st[1] ?></span>
                    <?php if ($isRespondido && $ch['status'] !== 'concluido'): ?>
                    <span style="background:#ecfdf5;color:#059669;padding:2px 8px;border-radius:9999px;font-size:.65rem;font-weight:700;">Nova resposta</span>
                    <?php endif; ?>
                </div>
                <div style="font-size:.82rem;color:var(--sv-text-muted);">
                    <?= sv_e(mb_strimwidth($ch['ultima_msg'] ?? '', 0, 120, '...')) ?>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.4rem;">
                    <span style="font-size:.72rem;color:var(--sv-text-muted);">
                        <?= date('d/m/Y H:i', strtotime($ch['created_at'])) ?>
                        <?php if ($ch['processo_titulo']): ?> · <?= sv_e($ch['processo_titulo']) ?><?php endif; ?>
                        · <?= $ch['total_msgs'] ?> msg<?= $ch['total_msgs'] != 1 ? 's' : '' ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
