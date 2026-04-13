<?php
/**
 * Sala VIP F&S — Compromissos
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Buscar eventos ---
$stmt = $pdo->prepare(
    "SELECT * FROM agenda_eventos
     WHERE client_id = ? AND visivel_cliente = 1 AND status NOT IN ('cancelado')
     ORDER BY data_inicio DESC"
);
$stmt->execute([$clienteId]);
$eventos = $stmt->fetchAll();

// Separar futuros e passados
$futuros = [];
$passados = [];
$hoje = date('Y-m-d');

foreach ($eventos as $ev) {
    $dataEvento = date('Y-m-d', strtotime($ev['data_inicio']));
    if ($dataEvento >= $hoje) {
        $futuros[] = $ev;
    } else {
        $passados[] = $ev;
    }
}

// Futuros em ordem crescente
$futuros = array_reverse($futuros);

$statusEventoMap = [
    'agendado'         => ['#6366f1', 'Agendado'],
    'realizado'        => ['#059669', 'Realizado'],
    'nao_compareceu'   => ['#d97706', 'N&atilde;o compareceu'],
    'remarcado'        => ['#7c3aed', 'Remarcado'],
];

$pageTitle = 'Compromissos';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Próximos Compromissos -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <h3>Pr&oacute;ximos Compromissos</h3>
    <?php if (empty($futuros)): ?>
        <p class="sv-empty">Nenhum compromisso agendado.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="sv-table">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>T&iacute;tulo</th>
                        <th>Tipo</th>
                        <th>Local</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($futuros as $ev): ?>
                        <tr>
                            <td><?= sv_formatar_data_hora($ev['data_inicio']) ?></td>
                            <td><?= sv_e($ev['titulo']) ?></td>
                            <td>
                                <span style="background:#1e293b;color:#c9a94e;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                                    <?= sv_e(sv_nome_tipo_evento($ev['tipo'] ?? '')) ?>
                                </span>
                            </td>
                            <td><?= sv_e($ev['local'] ?? '-') ?></td>
                            <td>
                                <?php
                                $se = $statusEventoMap[$ev['status'] ?? ''] ?? ['#888', ucfirst($ev['status'] ?? '')];
                                ?>
                                <span style="background:<?= $se[0] ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.75rem;font-weight:600;">
                                    <?= $se[1] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Compromissos Anteriores -->
<div class="sv-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;">
        <h3 style="margin:0;">Compromissos Anteriores</h3>
        <button type="button" class="sv-btn sv-btn-outline" onclick="var el=document.getElementById('passados');el.style.display=el.style.display==='none'?'block':'none';this.textContent=el.style.display==='none'?'Mostrar':'Ocultar';">
            Mostrar
        </button>
    </div>
    <div id="passados" style="display:none;margin-top:1rem;">
        <?php if (empty($passados)): ?>
            <p class="sv-empty">Nenhum compromisso anterior.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="sv-table">
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>T&iacute;tulo</th>
                            <th>Tipo</th>
                            <th>Local</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($passados as $ev): ?>
                            <tr>
                                <td><?= sv_formatar_data_hora($ev['data_inicio']) ?></td>
                                <td><?= sv_e($ev['titulo']) ?></td>
                                <td>
                                    <span style="background:#1e293b;color:#c9a94e;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                                        <?= sv_e(sv_nome_tipo_evento($ev['tipo'] ?? '')) ?>
                                    </span>
                                </td>
                                <td><?= sv_e($ev['local'] ?? '-') ?></td>
                                <td>
                                    <?php
                                    $se = $statusEventoMap[$ev['status'] ?? ''] ?? ['#888', ucfirst($ev['status'] ?? '')];
                                    ?>
                                    <span style="background:<?= $se[0] ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.75rem;font-weight:600;">
                                        <?= $se[1] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
