<?php
/**
 * Ferreira & Sa Hub -- Central VIP -- Ver / Responder Thread
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

if (!has_min_role('gestao')) {
    flash_set('error', 'Acesso restrito.');
    redirect(url('modules/dashboard/index.php'));
}

$pdo = db();
$threadId = (int)($_GET['thread_id'] ?? 0);

// ── Carregar thread ─────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT t.*, c.name as client_name, c.email as client_email, c.phone as client_phone
     FROM salavip_threads t
     JOIN clients c ON c.id = t.cliente_id
     WHERE t.id = ?"
);
$stmt->execute([$threadId]);
$thread = $stmt->fetch();

if (!$thread) {
    flash_set('error', 'Conversa nao encontrada.');
    redirect(module_url('salavip'));
}

$pageTitle = e($thread['assunto']);

// ── Marcar como lidas ───────────────────────────────────
$pdo->prepare(
    "UPDATE salavip_mensagens SET lida_equipe = 1 WHERE thread_id = ? AND lida_equipe = 0"
)->execute([$threadId]);

// ── POST: Responder ou Fechar ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf();
    $action = $_POST['action'] ?? 'responder';

    if ($action === 'fechar_thread') {
        $motivo = trim($_POST['motivo_fechamento'] ?? '');
        $pdo->prepare("UPDATE salavip_threads SET status = 'fechada', atualizado_em = NOW() WHERE id = ?")->execute([$threadId]);
        // Registra motivo opcional como mensagem interna (só equipe vê — origem=conecta)
        if ($motivo !== '') {
            $userName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $userName->execute([current_user_id()]);
            $userName = $userName->fetchColumn() ?: 'Equipe';
            $pdo->prepare("INSERT INTO salavip_mensagens (thread_id, origem, remetente_id, remetente_nome, mensagem, lida_equipe, lida_cliente, criado_em)
                           VALUES (?, 'conecta', ?, ?, ?, 1, 0, NOW())")
                ->execute(array($threadId, current_user_id(), $userName, '[Conversa fechada sem resposta] Motivo: ' . $motivo));
        }
        audit_log('salavip_thread_fechar', 'salavip_threads', $threadId, $motivo);
        flash_set('success', 'Conversa fechada.');
        redirect(module_url('salavip'));
    }

    if ($action === 'apagar_msg') {
        $msgId = (int)($_POST['msg_id'] ?? 0);
        // Só apaga mensagens da EQUIPE (não do cliente)
        $pdo->prepare("DELETE FROM salavip_mensagens WHERE id = ? AND thread_id = ? AND origem = 'conecta'")
            ->execute(array($msgId, $threadId));
        audit_log('salavip_msg_apagar', 'salavip_mensagens', $msgId);
        flash_set('success', 'Mensagem apagada.');
        redirect(module_url('salavip', 'ver_mensagem.php?thread_id=' . $threadId));
    }

    if ($action === 'responder') {
        $mensagem = trim($_POST['mensagem'] ?? '');
        if (!$mensagem) {
            flash_set('error', 'Digite uma mensagem.');
            redirect(module_url('salavip', 'ver_mensagem.php?thread_id=' . $threadId));
        }

        // Get current user name
        $userName = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $userName->execute([current_user_id()]);
        $userName = $userName->fetchColumn() ?: 'Equipe';

        // Handle optional attachment
        $anexo = null;
        $anexoNome = null;
        if (isset($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
            $allowedExt = array('pdf', 'jpg', 'jpeg', 'png', 'docx');
            $ext = strtolower(pathinfo($_FILES['anexo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt) && $_FILES['anexo']['size'] <= 10 * 1024 * 1024) {
                $dir = APP_ROOT . '/salavip/uploads/mensagens/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $anexo = uniqid('msg_') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['anexo']['name']);
                move_uploaded_file($_FILES['anexo']['tmp_name'], $dir . $anexo);
                $anexoNome = $_FILES['anexo']['name'];
            }
        }

        $stmt = $pdo->prepare(
            "INSERT INTO salavip_mensagens (thread_id, origem, remetente_id, remetente_nome, mensagem, anexo_path, anexo_nome, lida_equipe, lida_cliente, criado_em)
             VALUES (?, 'conecta', ?, ?, ?, ?, ?, 1, 0, NOW())"
        );
        $stmt->execute([$threadId, current_user_id(), $userName, $mensagem, $anexo, $anexoNome]);

        // Update thread status
        $pdo->prepare("UPDATE salavip_threads SET status = 'respondida', atualizado_em = NOW() WHERE id = ?")->execute([$threadId]);

        // Create notification for client (if notifications table exists)
        try {
            $pdo->prepare(
                "INSERT INTO notifications (user_id, type, title, message, link, created_at)
                 SELECT su.cliente_id, 'salavip', 'Nova resposta na Central VIP', ?, ?, NOW()
                 FROM salavip_usuarios su
                 JOIN salavip_threads t ON t.cliente_id = su.cliente_id
                 WHERE t.id = ?"
            )->execute([
                mb_strimwidth($mensagem, 0, 100, '...'),
                '/salavip/thread.php?id=' . $threadId,
                $threadId
            ]);
        } catch (Exception $e) {
            // Notifications table may not exist yet, ignore
        }

        audit_log('salavip_responder', 'salavip_threads', $threadId);
        flash_set('success', 'Resposta enviada.');
        redirect(module_url('salavip', 'ver_mensagem.php?thread_id=' . $threadId));
    }
}

// ── Carregar mensagens ──────────────────────────────────
$mensagens = $pdo->prepare(
    "SELECT * FROM salavip_mensagens WHERE thread_id = ? ORDER BY criado_em ASC"
);
$mensagens->execute([$threadId]);
$mensagens = $mensagens->fetchAll();

$statusLabels = array(
    'aberta' => 'Aberta', 'respondida' => 'Respondida', 'fechada' => 'Fechada',
    'aguardando' => 'Aguardando'
);
$statusBadge = array(
    'aberta' => 'warning', 'respondida' => 'success', 'fechada' => 'danger',
    'aguardando' => 'info'
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.msg-thread { display:flex; flex-direction:column; gap:.75rem; margin-bottom:1.5rem; }
.msg-bubble { max-width:75%; padding:.85rem 1rem; border-radius:var(--radius-lg); font-size:.88rem; line-height:1.5; }
.msg-bubble.equipe { background:var(--petrol-100); border:1px solid var(--petrol-300); align-self:flex-end; border-bottom-right-radius:4px; }
.msg-bubble.cliente { background:var(--bg-card); border:1px solid var(--border); align-self:flex-start; border-bottom-left-radius:4px; }
.msg-meta { font-size:.7rem; color:var(--text-muted); margin-top:.3rem; display:flex; justify-content:space-between; gap:.5rem; }
.msg-sender { font-weight:700; font-size:.78rem; margin-bottom:.2rem; }
.msg-anexo { font-size:.75rem; margin-top:.35rem; padding-top:.25rem; border-top:1px solid rgba(0,0,0,.08); }
.thread-info { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:.5rem; font-size:.82rem; margin-bottom:1rem; }
.thread-info dt { color:var(--text-muted); font-size:.72rem; font-weight:600; text-transform:uppercase; }
.thread-info dd { color:var(--petrol-900); font-weight:500; margin:0 0 .5rem 0; }
</style>

<a href="<?= module_url('salavip') ?>" class="btn btn-outline btn-sm mb-2">&larr; Voltar</a>

<!-- Thread Header -->
<div class="card mb-2">
    <div class="card-header" style="flex-wrap:wrap;gap:.5rem;">
        <div>
            <h3 style="margin-bottom:.2rem;"><?= e($thread['assunto']) ?></h3>
            <span class="text-sm text-muted">Por <?= e($thread['client_name']) ?> &middot; <?= date('d/m/Y H:i', strtotime($thread['criado_em'])) ?></span>
        </div>
        <div style="display:flex;gap:.4rem;align-items:center;">
            <span class="badge badge-<?= $statusBadge[$thread['status']] ?? 'gestao' ?>">
                <?= $statusLabels[$thread['status']] ?? e($thread['status']) ?>
            </span>
            <?php if (!empty($thread['categoria'])): ?>
                <span class="badge" style="background:var(--petrol-100);color:var(--petrol-900);"><?= e($thread['categoria']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <dl class="thread-info">
            <div>
                <dt>Cliente</dt>
                <dd><?= e($thread['client_name']) ?></dd>
            </div>
            <?php if (!empty($thread['client_email'])): ?>
            <div>
                <dt>Email</dt>
                <dd><?= e($thread['client_email']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($thread['client_phone'])): ?>
            <div>
                <dt>Telefone</dt>
                <dd><?= e($thread['client_phone']) ?></dd>
            </div>
            <?php endif; ?>
            <?php if (!empty($thread['processo_id'])): ?>
            <div>
                <dt>Processo</dt>
                <dd>
                    <?php
                    $proc = $pdo->prepare("SELECT case_number, title FROM cases WHERE id = ?");
                    $proc->execute([$thread['processo_id']]);
                    $proc = $proc->fetch();
                    echo $proc ? e($proc['case_number'] . ' - ' . $proc['title']) : '—';
                    ?>
                </dd>
            </div>
            <?php endif; ?>
        </dl>
    </div>
</div>

<!-- Messages -->
<div class="card mb-2">
    <div class="card-header"><h3>Mensagens</h3></div>
    <div class="card-body">
        <div class="msg-thread">
            <?php if (empty($mensagens)): ?>
                <p class="text-muted text-sm" style="text-align:center;">Nenhuma mensagem nesta conversa.</p>
            <?php else: ?>
                <?php foreach ($mensagens as $m): ?>
                    <div class="msg-bubble <?= $m['origem'] === 'conecta' ? 'equipe' : 'cliente' ?>">
                        <div class="msg-sender">
                            <?= e($m['remetente_nome']) ?>
                            <span style="font-weight:400;color:var(--text-muted);font-size:.7rem;">
                                (<?= $m['origem'] === 'conecta' ? 'Equipe' : 'Cliente' ?>)
                            </span>
                        </div>
                        <div><?= nl2br(e($m['mensagem'])) ?></div>
                        <?php if (!empty($m['anexo_path'])): ?>
                            <div class="msg-anexo">
                                &#128206; <a href="<?= url('salavip/uploads/mensagens/' . $m['anexo_path']) ?>" target="_blank"><?= e($m['anexo_nome'] ?: $m['anexo_path']) ?></a>
                            </div>
                        <?php endif; ?>
                        <div class="msg-meta">
                            <span><?= date('d/m/Y H:i', strtotime($m['criado_em'])) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Reply Form -->
<?php if ($thread['status'] !== 'fechada'): ?>
<div class="card mb-2">
    <div class="card-header"><h3>Responder</h3></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="responder">

            <div class="mb-1">
                <textarea name="mensagem" class="form-control" rows="4" placeholder="Digite sua resposta..." required></textarea>
            </div>

            <div class="mb-1">
                <label class="form-label">Anexo (opcional)</label>
                <input type="file" name="anexo" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.docx">
            </div>

            <div style="display:flex;gap:.5rem;justify-content:space-between;align-items:center;">
                <button type="submit" class="btn btn-primary">Enviar Resposta</button>

                <form method="POST" style="display:inline;" onsubmit="return confirm('Fechar esta conversa?');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="fechar_thread">
                    <button type="submit" class="btn btn-outline btn-sm" style="color:var(--danger);">Fechar Conversa</button>
                </form>
            </div>
        </form>
    </div>
</div>
<?php else: ?>
<div class="card mb-2">
    <div class="card-body" style="text-align:center;padding:1.5rem;">
        <p class="text-muted">Esta conversa foi fechada.</p>
    </div>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
