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

// --- Buscar eventos (vinculados ao cliente diretamente OU ao processo do cliente) ---
$stmt = $pdo->prepare(
    "SELECT DISTINCT ae.*, c.title as processo_titulo
     FROM agenda_eventos ae
     LEFT JOIN cases c ON c.id = ae.case_id
     WHERE ae.visivel_cliente = 1 AND ae.status NOT IN ('cancelado')
     AND (ae.client_id = ? OR ae.case_id IN (SELECT id FROM cases WHERE client_id = ? AND salavip_ativo = 1)
          OR ae.case_id IN (SELECT cp.case_id FROM case_partes cp INNER JOIN cases cs ON cs.id = cp.case_id WHERE cp.client_id = ? AND cs.salavip_ativo = 1))
     ORDER BY ae.data_inicio DESC"
);
$stmt->execute([$clienteId, $clienteId, $clienteId]);
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
                        <th>Processo</th>
                        <th>Local / Link</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($futuros as $ev): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700;color:var(--sv-accent);"><?= date('d/m/Y', strtotime($ev['data_inicio'])) ?></div>
                                <?php if (empty($ev['dia_todo']) || $ev['dia_todo'] != 1): ?>
                                <div style="font-size:.8rem;color:var(--sv-text-muted);"><?= date('H:i', strtotime($ev['data_inicio'])) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:600;"><?= sv_e($ev['titulo']) ?></td>
                            <td>
                                <span style="background:var(--sv-accent-bg);color:var(--sv-accent);padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                                    <?= sv_e(sv_nome_tipo_evento($ev['tipo'] ?? '')) ?>
                                </span>
                            </td>
                            <td style="font-size:.82rem;color:var(--sv-text-muted);"><?= sv_e($ev['processo_titulo'] ?? '-') ?></td>
                            <td>
                                <?= sv_e($ev['local'] ?? '') ?>
                                <?php if (!empty($ev['meet_link'])): ?>
                                    <a href="<?= sv_e($ev['meet_link']) ?>" target="_blank" style="display:inline-block;margin-top:3px;background:#052228;color:#fff;padding:2px 8px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none;">Acessar Reunião</a>
                                <?php endif; ?>
                            </td>
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
