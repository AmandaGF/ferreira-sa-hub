<?php
/**
 * Sala VIP F&S — Detalhe do Processo
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// --- Palavras de bloqueio ---
$palavrasBloqueio = $pdo->query("SELECT termo FROM salavip_palavras_bloqueio WHERE ativo=1")->fetchAll(PDO::FETCH_COLUMN);
function sv_andamento_visivel($descricao, $palavras) {
    foreach ($palavras as $p) {
        if (stripos($descricao, $p) !== false) return false;
    }
    return true;
}

// --- Validar processo ---
$caseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmtCase = $pdo->prepare(
    "SELECT * FROM cases WHERE id = ? AND client_id = ? AND salavip_ativo = 1"
);
$stmtCase->execute([$caseId, $clienteId]);
$caso = $stmtCase->fetch();

if (!$caso) {
    sv_flash('error', 'Processo n&atilde;o encontrado.');
    sv_redirect('pages/meus_processos.php');
}

// --- Partes ---
$partes = [];
try {
    $stmtPartes = $pdo->prepare("SELECT * FROM case_partes WHERE case_id = ?");
    $stmtPartes->execute([$caseId]);
    $partes = $stmtPartes->fetchAll();
} catch (Exception $e) {
    $partes = [];
}

// --- Andamentos (últimos 20, filtrados por palavras bloqueadas) ---
$stmtAnd = $pdo->prepare(
    "SELECT * FROM case_andamentos WHERE case_id = ? AND visivel_cliente = 1 ORDER BY data_andamento DESC LIMIT 100"
);
$stmtAnd->execute([$caseId]);
$andamentosRaw = $stmtAnd->fetchAll();

$andamentos = [];
foreach ($andamentosRaw as $a) {
    if (sv_andamento_visivel($a['descricao'], $palavrasBloqueio)) {
        $andamentos[] = $a;
        if (count($andamentos) >= 20) break;
    }
}

// --- Documentos pendentes ---
$stmtDocs = $pdo->prepare(
    "SELECT * FROM documentos_pendentes WHERE case_id = ? AND visivel_cliente = 1 ORDER BY solicitado_em DESC"
);
$stmtDocs->execute([$caseId]);
$documentos = $stmtDocs->fetchAll();

// --- Próximos compromissos ---
$stmtEv = $pdo->prepare(
    "SELECT * FROM agenda_eventos WHERE case_id = ? AND visivel_cliente = 1 AND data_inicio >= CURDATE() AND status NOT IN ('cancelado','remarcado','realizado') ORDER BY data_inicio ASC"
);
$stmtEv->execute([$caseId]);
$eventos = $stmtEv->fetchAll();

$pageTitle = $caso['title'];
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Dados do Processo -->
<div class="sv-card">
    <h3 style="margin-top:0;color:#e2e8f0;"><?= sv_e($caso['title']) ?></h3>

    <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.75rem;">
        <?php if (!empty($caso['case_number'])): ?>
            <div><strong style="color:#94a3b8;">N&ordm; do Processo:</strong> <span style="color:#cbd5e1;"><?= sv_e($caso['case_number']) ?></span></div>
        <?php endif; ?>

        <?php if (!empty($caso['case_type'])): ?>
            <div>
                <strong style="color:#94a3b8;">Tipo:</strong>
                <span style="background:#1e293b;color:#c9a94e;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                    <?= sv_e(ucfirst($caso['case_type'])) ?>
                </span>
            </div>
        <?php endif; ?>

        <div>
            <strong style="color:#94a3b8;">Status:</strong>
            <?= sv_badge_status_processo($caso['status'] ?? '') ?>
        </div>

        <?php if (!empty($caso['court'])): ?>
            <div><strong style="color:#94a3b8;">Vara/Tribunal:</strong> <span style="color:#cbd5e1;"><?= sv_e($caso['court']) ?></span></div>
        <?php endif; ?>

        <?php if (!empty($caso['comarca'])): ?>
            <div><strong style="color:#94a3b8;">Comarca:</strong> <span style="color:#cbd5e1;"><?= sv_e($caso['comarca']) ?></span></div>
        <?php endif; ?>

        <?php if (!empty($caso['opened_at'])): ?>
            <div><strong style="color:#94a3b8;">Data de distribui&ccedil;&atilde;o:</strong> <span style="color:#cbd5e1;"><?= sv_formatar_data($caso['opened_at']) ?></span></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($partes)): ?>
        <h4 style="margin-top:1.25rem;color:#c9a94e;">Partes</h4>
        <div style="display:flex;flex-direction:column;gap:.35rem;">
            <?php foreach ($partes as $parte): ?>
                <div style="color:#cbd5e1;">
                    <strong style="color:#94a3b8;"><?= sv_e(ucfirst($parte['papel'] ?? 'Parte')) ?>:</strong>
                    <?= sv_e($parte['nome'] ?? '') ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Andamentos -->
<div class="sv-card" style="margin-top:1.5rem;">
    <h3>Andamentos</h3>
    <?php if (empty($andamentos)): ?>
        <p class="sv-empty">Nenhum andamento dispon&iacute;vel.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($andamentos as $and): ?>
                <div style="border-bottom:1px solid rgba(201,169,78,.1);padding-bottom:.75rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <strong style="color:#c9a94e;"><?= sv_formatar_data($and['data_andamento']) ?></strong>
                        <?php if (!empty($and['tipo'])): ?>
                            <span style="background:#1e293b;color:#c9a94e;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                                <?= sv_e(ucfirst($and['tipo'])) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="color:#cbd5e1;margin-top:.25rem;">
                        <?= sv_e(sv_traduzir_andamento($and['descricao'])) ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Documentos Pendentes -->
<div class="sv-card" style="margin-top:1.5rem;">
    <h3>Documentos Pendentes</h3>
    <?php if (empty($documentos)): ?>
        <p class="sv-empty">Nenhum documento pendente.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($documentos as $doc): ?>
                <div style="border-bottom:1px solid rgba(201,169,78,.1);padding-bottom:.75rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <span style="color:#cbd5e1;"><?= sv_e($doc['descricao']) ?></span>
                        <?php
                        $docStatusCor = $doc['status'] === 'recebido' ? '#059669' : '#f59e0b';
                        $docStatusLabel = $doc['status'] === 'recebido' ? 'Recebido' : 'Pendente';
                        ?>
                        <span style="background:<?= $docStatusCor ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                            <?= $docStatusLabel ?>
                        </span>
                    </div>
                    <?php if (!empty($doc['solicitado_em'])): ?>
                        <div style="color:#64748b;font-size:.75rem;margin-top:.25rem;">
                            Solicitado em <?= sv_formatar_data($doc['solicitado_em']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Próximos Compromissos -->
<div class="sv-card" style="margin-top:1.5rem;">
    <h3>Pr&oacute;ximos Compromissos</h3>
    <?php if (empty($eventos)): ?>
        <p class="sv-empty">Nenhum compromisso agendado.</p>
    <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:.75rem;">
            <?php foreach ($eventos as $ev): ?>
                <div style="border-bottom:1px solid rgba(201,169,78,.1);padding-bottom:.75rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                        <strong style="color:#c9a94e;"><?= sv_formatar_data_hora($ev['data_inicio']) ?></strong>
                        <span style="color:#cbd5e1;"><?= sv_e($ev['titulo']) ?></span>
                        <span style="background:#1e293b;color:#c9a94e;padding:2px 8px;border-radius:9999px;font-size:.7rem;font-weight:600;">
                            <?= sv_e(sv_nome_tipo_evento($ev['tipo'])) ?>
                        </span>
                    </div>
                    <?php if (!empty($ev['local'])): ?>
                        <div style="color:#94a3b8;font-size:.85rem;margin-top:.25rem;">
                            Local: <?= sv_e($ev['local']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Voltar -->
<div style="margin-top:1.5rem;">
    <a href="<?= sv_url('pages/meus_processos.php') ?>" style="color:#c9a94e;font-weight:600;">&larr; Voltar para Meus Processos</a>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
