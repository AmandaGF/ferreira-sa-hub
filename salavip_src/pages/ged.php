<?php
/**
 * Central VIP F&S — Docs do Escritório (GED)
 * Documentos compartilhados pelo escritório com o cliente.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();

// Buscar documentos GED visíveis para o cliente
$stmtDocs = $pdo->prepare(
    "SELECT g.*, c.title AS processo_titulo
     FROM salavip_ged g
     LEFT JOIN cases c ON c.id = g.processo_id
     WHERE g.cliente_id = ? AND g.visivel_cliente = 1
     ORDER BY g.compartilhado_em DESC"
);
$stmtDocs->execute([$clienteId]);
$docs = $stmtDocs->fetchAll();

// Agrupar por processo
$porProcesso = [];
$gerais = [];
foreach ($docs as $doc) {
    if ($doc['processo_id']) {
        $key = (int)$doc['processo_id'];
        if (!isset($porProcesso[$key])) {
            $porProcesso[$key] = [
                'titulo' => $doc['processo_titulo'] ?: 'Processo #' . $key,
                'docs' => []
            ];
        }
        $porProcesso[$key]['docs'][] = $doc;
    } else {
        $gerais[] = $doc;
    }
}

$categoriaCores = [
    'procuracao' => '#6366f1',
    'contrato' => '#059669',
    'peticao' => '#B87333',
    'decisao' => '#dc2626',
    'sentenca' => '#7c3aed',
    'certidao' => '#0891b2',
    'comprovante' => '#65a30d',
    'acordo' => '#d97706',
    'parecer' => '#4f46e5',
    'outro' => '#94a3b8',
    'geral' => '#94a3b8',
];

$categoriaLabels = [
    'procuracao' => 'Procuração',
    'contrato' => 'Contrato',
    'peticao' => 'Petição',
    'decisao' => 'Decisão',
    'sentenca' => 'Sentença',
    'certidao' => 'Certidão',
    'comprovante' => 'Comprovante',
    'acordo' => 'Acordo',
    'parecer' => 'Parecer',
    'outro' => 'Outro',
    'geral' => 'Geral',
];

$pageTitle = 'Docs do Escritório';
require_once __DIR__ . '/../includes/header.php';
?>

<?php if (empty($docs)): ?>
    <div class="sv-card">
        <p class="sv-empty">Nenhum documento compartilhado pelo escritório no momento.</p>
    </div>
<?php else: ?>

    <?php if (!empty($gerais)): ?>
    <div class="sv-card" style="margin-bottom:1.5rem;">
        <h3>Documentos Gerais</h3>
        <div style="overflow-x:auto;">
            <table class="sv-table">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Categoria</th>
                        <th>Data</th>
                        <th style="text-align:center;">Download</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($gerais as $doc):
                        $catKey = strtolower(str_replace(['ã','á','é','ê','í','ó','ç'], ['a','a','e','e','i','o','c'], $doc['categoria'] ?? 'geral'));
                        $catCor = isset($categoriaCores[$catKey]) ? $categoriaCores[$catKey] : '#94a3b8';
                        $catLabel = isset($categoriaLabels[$catKey]) ? $categoriaLabels[$catKey] : ucfirst($doc['categoria'] ?? 'Geral');
                    ?>
                    <tr>
                        <td>
                            <strong><?= sv_e($doc['titulo']) ?></strong>
                            <?php if ($doc['descricao']): ?>
                                <div style="font-size:.78rem;color:var(--sv-text-muted,#64748b);margin-top:2px;"><?= sv_e($doc['descricao']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="display:inline-block;background:<?= $catCor ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.72rem;font-weight:600;">
                                <?= sv_e($catLabel) ?>
                            </span>
                        </td>
                        <td style="font-size:.82rem;white-space:nowrap;"><?= sv_formatar_data($doc['compartilhado_em']) ?></td>
                        <td style="text-align:center;">
                            <a href="<?= sv_url('api/download_ged.php?id=' . (int)$doc['id']) ?>" class="sv-btn sv-btn-outline" style="font-size:.78rem;padding:4px 12px;">&#x2B07; Baixar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php foreach ($porProcesso as $procId => $grupo): ?>
    <div class="sv-card" style="margin-bottom:1.5rem;">
        <h3><?= sv_e($grupo['titulo']) ?></h3>
        <div style="overflow-x:auto;">
            <table class="sv-table">
                <thead>
                    <tr>
                        <th>Documento</th>
                        <th>Categoria</th>
                        <th>Data</th>
                        <th style="text-align:center;">Download</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grupo['docs'] as $doc):
                        $catKey = strtolower(str_replace(['ã','á','é','ê','í','ó','ç'], ['a','a','e','e','i','o','c'], $doc['categoria'] ?? 'geral'));
                        $catCor = isset($categoriaCores[$catKey]) ? $categoriaCores[$catKey] : '#94a3b8';
                        $catLabel = isset($categoriaLabels[$catKey]) ? $categoriaLabels[$catKey] : ucfirst($doc['categoria'] ?? 'Geral');
                    ?>
                    <tr>
                        <td>
                            <strong><?= sv_e($doc['titulo']) ?></strong>
                            <?php if ($doc['descricao']): ?>
                                <div style="font-size:.78rem;color:var(--sv-text-muted,#64748b);margin-top:2px;"><?= sv_e($doc['descricao']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="display:inline-block;background:<?= $catCor ?>;color:#fff;padding:2px 8px;border-radius:9999px;font-size:.72rem;font-weight:600;">
                                <?= sv_e($catLabel) ?>
                            </span>
                        </td>
                        <td style="font-size:.82rem;white-space:nowrap;"><?= sv_formatar_data($doc['compartilhado_em']) ?></td>
                        <td style="text-align:center;">
                            <a href="<?= sv_url('api/download_ged.php?id=' . (int)$doc['id']) ?>" class="sv-btn sv-btn-outline" style="font-size:.78rem;padding:4px 12px;">&#x2B07; Baixar</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
