<?php
/**
 * Visualização admin do documento assinado pela colaboradora.
 *
 * Acesso: /modules/admin/onboarding_doc_view.php?id=DOCUMENTO_ID
 * Renderiza o snapshot HTML salvo no momento da assinatura,
 * com botões pra imprimir/salvar PDF.
 *
 * Acesso: SOMENTE admin
 */
require_once __DIR__ . '/../../core/middleware.php';
require_once __DIR__ . '/../../core/onboarding_docs_schema.php';
require_once __DIR__ . '/../../core/onboarding_docs_templates.php';
require_login();
require_role('admin');

$pdo = db();
$docId = (int)($_GET['id'] ?? 0);
if (!$docId) { redirect(module_url('admin', 'onboarding.php')); }

$st = $pdo->prepare("SELECT cd.*, co.nome_completo, co.token AS colab_token, co.id AS colab_id
                     FROM colaboradores_documentos cd
                     LEFT JOIN colaboradores_onboarding co ON co.id = cd.colaborador_id
                     WHERE cd.id = ?");
$st->execute(array($docId));
$doc = $st->fetch();
if (!$doc) {
    flash_set('error', 'Documento não encontrado.');
    redirect(module_url('admin', 'onboarding.php'));
}

$schema = onboarding_doc_schema($doc['tipo']);
if (!$schema) {
    flash_set('error', 'Tipo de documento desconhecido.');
    redirect(module_url('admin', 'onboarding.php?id=' . $doc['colab_id']));
}

$jaAssinado = !empty($doc['assinatura_estagiario_em']);

// Carrega o colaborador completo (precisa pra re-renderizar se nao tiver snapshot)
$stC = $pdo->prepare("SELECT * FROM colaboradores_onboarding WHERE id = ?");
$stC->execute(array($doc['colab_id']));
$reg = $stC->fetch();

$dadosAdmin = $doc['dados_admin_json'] ? json_decode($doc['dados_admin_json'], true) : array();
$dadosColab = $doc['dados_estagiario_json'] ? json_decode($doc['dados_estagiario_json'], true) : array();
if (!is_array($dadosAdmin)) $dadosAdmin = array();
if (!is_array($dadosColab)) $dadosColab = array();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($schema['icon']) ?> <?= htmlspecialchars($schema['label']) ?> — <?= htmlspecialchars($doc['nome_completo']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700;800&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --petrol-900: #052228;
    --petrol-700: #173d46;
    --cobre: #6a3c2c;
    --nude: #d7ab90;
    --nude-light: #fff7ed;
}
body { font-family: 'Open Sans', sans-serif; background: #f8f4ef; margin: 0; }
.toolbar {
    background: linear-gradient(135deg, var(--petrol-900), var(--petrol-700));
    color: #fff; padding: 1rem 1.5rem; display: flex; align-items: center;
    gap: 1rem; flex-wrap: wrap; justify-content: space-between;
    position: sticky; top: 0; z-index: 100; box-shadow: 0 4px 14px rgba(0,0,0,.15);
}
.toolbar h1 { color: #fff; font-size: 1.05rem; font-weight: 700; margin: 0; }
.toolbar a, .toolbar button {
    background: rgba(255,255,255,.15); color: #fff; padding: .5rem 1rem;
    border-radius: 8px; text-decoration: none; font-size: .85rem; font-weight: 600;
    border: 0; cursor: pointer; font-family: inherit;
}
.toolbar a:hover, .toolbar button:hover { background: rgba(255,255,255,.25); }
.toolbar .btn-print { background: var(--nude); color: var(--petrol-900); }
.toolbar .btn-print:hover { background: #e8c2a5; }
.subbar {
    background: #fff; padding: .8rem 1.5rem; border-bottom: 1px solid var(--nude);
    font-size: .85rem; color: var(--cobre); display: flex; gap: 1rem; flex-wrap: wrap;
}
.subbar strong { color: var(--petrol-900); }
.subbar .pill { background: #d1fae5; color: #065f46; padding: .15rem .6rem; border-radius: 12px; font-size: .72rem; font-weight: 700; }
.subbar .pill.warn { background: #fef3c7; color: #92400e; }
.doc-wrap { background: #f3f4f6; padding: 2rem 1rem; }

@media print {
    body { background: #fff; margin: 0; padding: 0; }
    .toolbar, .subbar, .no-print { display: none !important; }
    .doc-wrap { background: #fff; padding: 0; }
}

<?= onboarding_docs_css() ?>
</style>
</head>
<body>

<div class="toolbar no-print">
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <a href="<?= module_url('admin', 'onboarding.php?id=' . (int)$doc['colab_id']) ?>">← Voltar ao cadastro</a>
        <h1><?= htmlspecialchars($schema['icon']) ?> <?= htmlspecialchars($schema['label']) ?></h1>
    </div>
    <div style="display:flex;gap:.5rem;">
        <button class="btn-print" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    </div>
</div>

<div class="subbar no-print">
    <span>👤 <strong><?= e($doc['nome_completo']) ?></strong></span>
    <?php if ($jaAssinado): ?>
        <span class="pill">✓ Assinado</span>
        <span>em <strong><?= e(date('d/m/Y \à\s H:i', strtotime($doc['assinatura_estagiario_em']))) ?></strong></span>
        <?php if (!empty($doc['assinatura_estagiario_ip'])): ?>
            <span style="color:#6b7280;">IP: <?= e($doc['assinatura_estagiario_ip']) ?></span>
        <?php endif; ?>
    <?php else: ?>
        <span class="pill warn">⏳ Ainda não assinado</span>
        <span style="color:#6b7280;">A colaboradora ainda não preencheu/assinou. O preview abaixo é o estado atual com os campos que estão preenchidos no momento.</span>
    <?php endif; ?>
</div>

<div class="doc-wrap">
<?php
if (!empty($doc['pdf_html_snapshot'])) {
    // Snapshot salvo no momento da assinatura — sempre o "oficial"
    echo $doc['pdf_html_snapshot'];
} else {
    // Sem snapshot ainda: re-renderiza com os dados atuais
    $renderFn = $schema['render_function'];
    if (function_exists($renderFn)) {
        $assinaturas = $jaAssinado ? array(
            'estagiario_em' => $doc['assinatura_estagiario_em'],
            'estagiario_ip' => $doc['assinatura_estagiario_ip'],
        ) : array();
        try {
            echo $renderFn($reg, $dadosAdmin, $dadosColab, $assinaturas);
        } catch (Exception $e) {
            echo '<div class="doc-page"><p style="color:#991b1b;padding:2rem;">Erro ao renderizar: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
        }
    } else {
        echo '<div class="doc-page"><p style="color:#9a3412;padding:2rem;text-align:center;">⏳ Renderização ainda não disponível para este tipo de documento.</p></div>';
    }
}
?>
</div>

</body>
</html>
