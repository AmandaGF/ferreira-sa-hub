<?php
/**
 * Central VIP F&S — Financeiro
 * Card Situação Financeira + cobranças agrupadas por processo.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

$cobrancas = [];
$processos = [];
$moduloDisponivel = true;

try {
    $stmt = $pdo->prepare(
        "SELECT ac.*, c.title AS case_title, c.case_number
         FROM asaas_cobrancas ac
         LEFT JOIN cases c ON c.id = ac.case_id
         WHERE ac.client_id = ?
         ORDER BY ac.vencimento DESC"
    );
    $stmt->execute([$clienteId]);
    $cobrancas = $stmt->fetchAll();
} catch (PDOException $e) {
    $moduloDisponivel = false;
}

// Totais consolidados (situação financeira)
$totalPago = 0.0;
$totalPendente = 0.0;
$totalVencido = 0.0;
$totalGeral = 0.0;
foreach ($cobrancas as $c) {
    $valor = (float)($c['valor'] ?? 0);
    $valorPago = (float)($c['valor_pago'] ?? 0);
    $status = $c['status'] ?? '';
    $totalGeral += $valor;
    if (in_array($status, ['RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'], true)) {
        $totalPago += $valorPago > 0 ? $valorPago : $valor;
    } elseif ($status === 'PENDING') {
        $totalPendente += $valor;
    } elseif ($status === 'OVERDUE') {
        $totalVencido += $valor;
    }
}

// Agrupa cobranças por processo (case_id null = "Sem vínculo / histórico")
$grupos = [];
foreach ($cobrancas as $c) {
    $caseId = (int)($c['case_id'] ?? 0);
    $chave = $caseId ?: 0;
    if (!isset($grupos[$chave])) {
        $grupos[$chave] = [
            'case_id'     => $caseId,
            'case_title'  => $caseId ? ($c['case_title'] ?: ('Processo #' . $caseId)) : 'Sem processo vinculado',
            'case_number' => $c['case_number'] ?? '',
            'cobrancas'   => [],
        ];
    }
    $grupos[$chave]['cobrancas'][] = $c;
}

$pageTitle = 'Financeiro';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (!$moduloDisponivel): ?>
    <div class="sv-card">
        <p class="sv-empty">M&oacute;dulo financeiro n&atilde;o dispon&iacute;vel no momento.</p>
    </div>
<?php else: ?>

<!-- Card Situação Financeira -->
<div class="sv-card" style="margin-bottom:1rem;">
    <h3 style="margin-bottom:.5rem;">&#128176; Situa&ccedil;&atilde;o Financeira</h3>
    <p style="font-size:.78rem;color:#64748b;margin-bottom:.9rem;">Hist&oacute;rico consolidado de todas as suas cobran&ccedil;as conosco.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.6rem;">
        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:.7rem;text-align:center;">
            <div style="font-size:.68rem;color:#059669;font-weight:700;text-transform:uppercase;">&#10003; Pago</div>
            <div style="font-size:1rem;font-weight:800;color:#059669;margin-top:2px;">R$ <?= number_format($totalPago, 2, ',', '.') ?></div>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.7rem;text-align:center;">
            <div style="font-size:.68rem;color:#b45309;font-weight:700;text-transform:uppercase;">&#9203; Pendente</div>
            <div style="font-size:1rem;font-weight:800;color:#b45309;margin-top:2px;">R$ <?= number_format($totalPendente, 2, ',', '.') ?></div>
        </div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:.7rem;text-align:center;">
            <div style="font-size:.68rem;color:#dc2626;font-weight:700;text-transform:uppercase;">&#9888; Vencido</div>
            <div style="font-size:1rem;font-weight:800;color:#dc2626;margin-top:2px;">R$ <?= number_format($totalVencido, 2, ',', '.') ?></div>
        </div>
        <div style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;padding:.7rem;text-align:center;">
            <div style="font-size:.68rem;color:#475569;font-weight:700;text-transform:uppercase;">&#128202; Total</div>
            <div style="font-size:1rem;font-weight:800;color:#334155;margin-top:2px;">R$ <?= number_format($totalGeral, 2, ',', '.') ?></div>
        </div>
    </div>
</div>

<!-- Cobranças agrupadas por processo -->
<?php if (empty($cobrancas)): ?>
    <div class="sv-card"><p class="sv-empty">Nenhuma cobran&ccedil;a encontrada.</p></div>
<?php else: ?>
    <?php foreach ($grupos as $g): ?>
    <div class="sv-card" style="margin-bottom:.9rem;">
        <h3 style="margin-bottom:.6rem;font-size:.95rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
            <?php if ($g['case_id']): ?>
                &#128193; <?= sv_e($g['case_title']) ?>
                <?php if ($g['case_number']): ?>
                    <span style="font-size:.72rem;color:#64748b;font-weight:500;">(<?= sv_e($g['case_number']) ?>)</span>
                <?php endif; ?>
            <?php else: ?>
                &#128196; <?= sv_e($g['case_title']) ?>
            <?php endif; ?>
            <span style="font-size:.72rem;color:#64748b;font-weight:500;margin-left:auto;"><?= count($g['cobrancas']) ?> cobran&ccedil;a(s)</span>
        </h3>
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
                    <?php foreach ($g['cobrancas'] as $cob): ?>
                        <tr>
                            <td><?= sv_e($cob['descricao'] ?? '-') ?></td>
                            <td>R$ <?= number_format((float)($cob['valor'] ?? 0), 2, ',', '.') ?></td>
                            <td><?= sv_formatar_data($cob['vencimento'] ?? '') ?></td>
                            <td><?= sv_badge_status_parcela($cob['status'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($cob['invoice_url'])): ?>
                                    <a href="<?= sv_e($cob['invoice_url']) ?>" target="_blank" rel="noopener" style="color:var(--sv-accent);font-weight:600;font-size:.85rem;">
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
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
