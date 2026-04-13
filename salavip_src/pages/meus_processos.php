<?php
/**
 * Sala VIP F&S — Meus Processos
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Buscar processos ---
$stmt = $pdo->prepare(
    "SELECT * FROM cases WHERE client_id = ? AND salavip_ativo = 1 ORDER BY opened_at DESC"
);
$stmt->execute([$clienteId]);
$processos = $stmt->fetchAll();

$pageTitle = 'Meus Processos';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($processos)): ?>
    <div class="sv-empty">Nenhum processo encontrado.</div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:1rem;">
        <?php foreach ($processos as $caso): ?>
            <div class="sv-card">
                <div style="display:flex;flex-direction:column;gap:.5rem;">
                    <h3 style="margin:0;color:#e2e8f0;"><?= sv_e($caso['title']) ?></h3>

                    <?php if (!empty($caso['case_number'])): ?>
                        <div style="color:#94a3b8;font-size:.85rem;">
                            N&ordm; <?= sv_e($caso['case_number']) ?>
                        </div>
                    <?php endif; ?>

                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <?php if (!empty($caso['case_type'])): ?>
                            <span style="background:#1e293b;color:#c9a94e;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                                <?= sv_e(ucfirst($caso['case_type'])) ?>
                            </span>
                        <?php endif; ?>
                        <?= sv_badge_status_processo($caso['status'] ?? '') ?>
                    </div>

                    <?php if (!empty($caso['court']) || !empty($caso['comarca'])): ?>
                        <div style="color:#94a3b8;font-size:.85rem;">
                            <?php
                            $infos = [];
                            if (!empty($caso['court']))   $infos[] = $caso['court'];
                            if (!empty($caso['comarca'])) $infos[] = $caso['comarca'];
                            echo sv_e(implode(' | ', $infos));
                            ?>
                        </div>
                    <?php endif; ?>

                    <div style="margin-top:.5rem;">
                        <a href="<?= sv_url('pages/processo_detalhe.php?id=' . (int)$caso['id']) ?>" style="color:#c9a94e;font-weight:600;font-size:.9rem;">
                            Ver Detalhes &rarr;
                        </a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
