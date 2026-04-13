<?php
/**
 * Sala VIP F&S — Financeiro
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

$cobrancas = [];
$moduloDisponivel = true;

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM asaas_cobrancas WHERE client_id = ? ORDER BY due_date DESC"
    );
    $stmt->execute([$clienteId]);
    $cobrancas = $stmt->fetchAll();
} catch (PDOException $e) {
    $moduloDisponivel = false;
}

$pageTitle = 'Financeiro';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="sv-card">
    <h3>Cobran&ccedil;as</h3>

    <?php if (!$moduloDisponivel): ?>
        <p class="sv-empty">M&oacute;dulo financeiro n&atilde;o dispon&iacute;vel no momento.</p>
    <?php elseif (empty($cobrancas)): ?>
        <p class="sv-empty">Nenhuma cobran&ccedil;a encontrada.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="sv-table">
                <thead>
                    <tr>
                        <th>Descri&ccedil;&atilde;o</th>
                        <th>Valor</th>
                        <th>Vencimento</th>
                        <th>Status</th>
                        <th>A&ccedil;&atilde;o</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cobrancas as $cob): ?>
                        <tr>
                            <td><?= sv_e($cob['description'] ?? '-') ?></td>
                            <td><?= sv_formatar_moeda((int)($cob['value'] ?? 0)) ?></td>
                            <td><?= sv_formatar_data($cob['due_date'] ?? '') ?></td>
                            <td><?= sv_badge_status_parcela($cob['status'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($cob['invoice_url'])): ?>
                                    <a href="<?= sv_e($cob['invoice_url']) ?>" target="_blank" rel="noopener" style="color:#c9a94e;font-weight:600;font-size:.85rem;">
                                        Ver Boleto &rarr;
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
