<?php
/**
 * Redes Sociais — Inbox Facebook Messenger (Amanda 04/06/2026 - Fase A placeholder)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('redes_sociais_facebook');

$pdo = db();
$pageTitle = 'Inbox Facebook';

$paginas = $totalConvs = 0;
try {
    $paginas = (int)$pdo->query("SELECT COUNT(*) FROM meta_pages WHERE ativa = 1")->fetchColumn();
    $totalConvs = (int)$pdo->query("SELECT COUNT(*) FROM meta_inbox_conversas WHERE tipo='messenger'")->fetchColumn();
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width:1100px;margin:0 auto;padding:1rem;">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.4rem;">
        <a href="<?= module_url('redes_sociais') ?>" style="font-size:.78rem;color:#6b7280;text-decoration:none;">← Redes Sociais</a>
    </div>
    <h2 style="margin:0 0 1.2rem;color:var(--petrol-900);display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.4rem;">📘</span> Inbox Facebook Messenger
    </h2>

    <?php if ($paginas === 0): ?>
    <div style="background:#fff;border:2px dashed #d1d5db;border-radius:14px;padding:2.5rem 1.5rem;text-align:center;color:#6b7280;">
        <div style="font-size:3rem;margin-bottom:.6rem;opacity:.6;">📘</div>
        <h3 style="margin:0 0 .4rem;color:#374151;font-size:1.05rem;">Inbox ainda não conectada</h3>
        <p style="font-size:.85rem;max-width:480px;margin:0 auto 1rem;line-height:1.6;">
            Pra começar a receber mensagens do Messenger da Página do escritório aqui no Hub, é preciso autorizar via Meta Business Suite e finalizar o App Review.
        </p>
        <?php if (has_min_role('gestao')): ?>
            <a href="<?= module_url('redes_sociais', 'setup.php') ?>" class="btn btn-primary btn-sm">⚙️ Ver passo a passo</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="background:#fff;border:1.5px solid #e5e7eb;border-radius:14px;padding:1.5rem;text-align:center;color:#6b7280;">
        <h3 style="margin:0 0 .3rem;color:#374151;">Conectado · <?= $totalConvs ?> conversa<?= $totalConvs === 1 ? '' : 's' ?></h3>
        <p style="font-size:.85rem;">UI de inbox em construção. Ativa quando o App Review aprovar.</p>
    </div>
    <?php endif; ?>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
