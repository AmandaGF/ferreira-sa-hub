<?php
require_once __DIR__ . '/../../core/middleware.php';
require_login();
$pdo = db();
$docId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT cd.*, u.name as user_name FROM case_documents cd LEFT JOIN users u ON u.id = cd.gerado_por WHERE cd.id = ?");
$stmt->execute(array($docId));
$doc = $stmt->fetch();
if (!$doc) { die('Documento não encontrado.'); }
$pageTitle = $doc['titulo'];
require_once APP_ROOT . '/templates/layout_start.php';
?>
<div style="max-width:800px;">
    <a href="<?= module_url('peticoes', 'index.php?case_id=' . $doc['case_id']) ?>" class="btn btn-outline btn-sm" style="margin-bottom:.75rem;">← Voltar</a>
    <div class="card">
        <div class="card-header">
            <h3 style="font-size:.95rem;"><?= e($doc['titulo']) ?></h3>
            <span style="font-size:.72rem;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($doc['created_at'])) ?> — <?= e($doc['user_name'] ?? '') ?></span>
        </div>
        <div class="card-body" style="font-family:'Times New Roman',serif;font-size:14px;line-height:1.8;">
            <?= $doc['conteudo_html'] ?>
        </div>
    </div>
</div>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
