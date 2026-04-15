<?php
/**
 * Central VIP F&S — Mensagens
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Buscar threads ---
$stmt = $pdo->prepare(
    "SELECT t.*,
        (SELECT COUNT(*) FROM salavip_mensagens WHERE thread_id = t.id AND origem = 'conecta' AND lida_cliente = 0) AS nao_lidas,
        (SELECT criado_em FROM salavip_mensagens WHERE thread_id = t.id ORDER BY criado_em DESC LIMIT 1) AS ultima_msg
     FROM salavip_threads t
     WHERE t.cliente_id = ?
     ORDER BY ultima_msg DESC"
);
$stmt->execute([$clienteId]);
$threads = $stmt->fetchAll();

$pageTitle = 'Mensagens';
require_once __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1.5rem;">
    <h3 style="margin:0;color:var(--sv-text);">Suas Conversas</h3>
    <a href="<?= sv_url('pages/mensagem_nova.php') ?>" class="sv-btn sv-btn-gold">Nova Mensagem</a>
</div>

<?php if (empty($threads)): ?>
    <div class="sv-empty">Nenhuma conversa ainda. Envie sua primeira mensagem!</div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:.75rem;">
        <?php foreach ($threads as $thread): ?>
            <a href="<?= sv_url('pages/mensagem_ver.php?id=' . (int)$thread['id']) ?>" class="sv-card sv-thread-item" style="text-decoration:none;display:block;transition:border-color .2s;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:.5rem;">
                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                            <span style="color:var(--sv-text);font-size:1rem;<?= $thread['nao_lidas'] > 0 ? 'font-weight:700;' : 'font-weight:400;' ?>">
                                <?= sv_e($thread['assunto']) ?>
                            </span>
                            <?php if ($thread['nao_lidas'] > 0): ?>
                                <span style="background:#dc2626;color:#fff;padding:1px 7px;border-radius:9999px;font-size:.65rem;font-weight:700;">
                                    <?= (int)$thread['nao_lidas'] ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;margin-top:.35rem;flex-wrap:wrap;">
                            <?php if (!empty($thread['categoria'])): ?>
                                <span style="background:var(--sv-accent-bg);color:var(--sv-accent);padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
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
                            <span style="background:<?= $ts[0] ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                                <?= sv_e($ts[1]) ?>
                            </span>
                        </div>
                    </div>
                    <div style="color:#64748b;font-size:.8rem;white-space:nowrap;">
                        <?= sv_formatar_data_hora($thread['ultima_msg'] ?? $thread['criado_em'] ?? '') ?>
                    </div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
