<?php
/**
 * Sala VIP F&S — Documentos
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

$showUpload = isset($_GET['upload']) && $_GET['upload'] == '1';

// --- Documentos Solicitados ---
$stmtSolicitados = $pdo->prepare(
    "SELECT dp.*, c.title AS processo_titulo
     FROM documentos_pendentes dp
     LEFT JOIN cases c ON c.id = dp.case_id
     WHERE dp.client_id = ? AND dp.visivel_cliente = 1
     ORDER BY dp.solicitado_em DESC"
);
$stmtSolicitados->execute([$clienteId]);
$solicitados = $stmtSolicitados->fetchAll();

// --- Documentos Enviados pelo Cliente ---
$stmtEnviados = $pdo->prepare(
    "SELECT dc.*, c.title AS processo_titulo
     FROM salavip_documentos_cliente dc
     LEFT JOIN cases c ON c.id = dc.case_id
     WHERE dc.cliente_id = ?
     ORDER BY dc.criado_em DESC"
);
$stmtEnviados->execute([$clienteId]);
$enviados = $stmtEnviados->fetchAll();

// --- Processos para upload ---
$processos = [];
if ($showUpload) {
    $stmtProc = $pdo->prepare(
        "SELECT id, title FROM cases WHERE client_id = ? AND salavip_ativo = 1 ORDER BY title ASC"
    );
    $stmtProc->execute([$clienteId]);
    $processos = $stmtProc->fetchAll();
}

$pageTitle = 'Documentos';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if ($showUpload): ?>
    <!-- Formulário de Upload -->
    <div class="sv-card" style="margin-bottom:1.5rem;">
        <h3>Enviar Documento</h3>
        <form action="<?= sv_url('api/upload_doc.php') ?>" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= salavip_gerar_csrf() ?>">

            <div class="form-group">
                <label class="form-label">T&iacute;tulo do Documento *</label>
                <input type="text" name="titulo" class="form-input" required placeholder="Ex: RG, Comprovante de resid&ecirc;ncia...">
            </div>

            <div class="form-group">
                <label class="form-label">Processo</label>
                <select name="case_id" class="form-input">
                    <option value="">Nenhum (geral)</option>
                    <?php foreach ($processos as $proc): ?>
                        <option value="<?= (int)$proc['id'] ?>"><?= sv_e($proc['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Descri&ccedil;&atilde;o</label>
                <textarea name="descricao" class="form-input" rows="3" placeholder="Observa&ccedil;&otilde;es sobre o documento (opcional)"></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Arquivo * (PDF, JPG, PNG, DOC, DOCX - m&aacute;x 10MB)</label>
                <input type="file" name="arquivo" class="form-input" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
            </div>

            <div style="display:flex;gap:1rem;align-items:center;margin-top:1rem;">
                <button type="submit" class="sv-btn sv-btn-gold">Enviar Documento</button>
                <a href="<?= sv_url('pages/documentos.php') ?>" class="sv-btn sv-btn-outline">Cancelar</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<!-- Documentos Solicitados -->
<div class="sv-card" style="margin-bottom:1.5rem;">
    <h3>Documentos Solicitados</h3>
    <?php if (empty($solicitados)): ?>
        <p class="sv-empty">Nenhum documento solicitado no momento.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="sv-table">
                <thead>
                    <tr>
                        <th>Descri&ccedil;&atilde;o</th>
                        <th>Processo</th>
                        <th>Status</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($solicitados as $doc): ?>
                        <tr>
                            <td><?= sv_e($doc['descricao']) ?></td>
                            <td><?= sv_e($doc['processo_titulo'] ?? '-') ?></td>
                            <td>
                                <?php
                                $statusCor = $doc['status'] === 'recebido' ? '#059669' : '#f59e0b';
                                $statusLabel = $doc['status'] === 'recebido' ? 'Recebido' : 'Pendente';
                                ?>
                                <span style="background:<?= $statusCor ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:0.75rem;font-weight:600;">
                                    <?= $statusLabel ?>
                                </span>
                            </td>
                            <td><?= sv_formatar_data($doc['solicitado_em']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Documentos Enviados -->
<div class="sv-card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
        <h3 style="margin:0;">Documentos Enviados por Voc&ecirc;</h3>
        <?php if (!$showUpload): ?>
            <a href="<?= sv_url('pages/documentos.php?upload=1') ?>" class="sv-btn sv-btn-gold">Enviar Documento</a>
        <?php endif; ?>
    </div>
    <?php if (empty($enviados)): ?>
        <p class="sv-empty">Voc&ecirc; ainda n&atilde;o enviou nenhum documento.</p>
    <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="sv-table">
                <thead>
                    <tr>
                        <th>T&iacute;tulo</th>
                        <th>Processo</th>
                        <th>Status</th>
                        <th>Data</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($enviados as $doc): ?>
                        <tr>
                            <td><?= sv_e($doc['titulo']) ?></td>
                            <td><?= sv_e($doc['processo_titulo'] ?? '-') ?></td>
                            <td>
                                <?php
                                $statusMap = [
                                    'pendente'  => ['#f59e0b', 'Pendente'],
                                    'aceito'    => ['#059669', 'Aceito'],
                                    'rejeitado' => ['#dc2626', 'Rejeitado'],
                                ];
                                $s = $statusMap[$doc['status'] ?? 'pendente'] ?? ['#888', ucfirst($doc['status'] ?? '')];
                                ?>
                                <span style="background:<?= $s[0] ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:0.75rem;font-weight:600;">
                                    <?= sv_e($s[1]) ?>
                                </span>
                            </td>
                            <td><?= sv_formatar_data($doc['criado_em']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
