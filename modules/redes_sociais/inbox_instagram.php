<?php
/**
 * Redes Sociais — Inbox Instagram (Amanda 04/06/2026 - Fase A placeholder)
 *
 * Quando o App Review da Meta aprovar e o webhook estiver recebendo eventos,
 * essa pagina vira uma UI espelhada do modules/whatsapp/index.php - lista de
 * conversas a esquerda + chat aberto a direita. Por enquanto mostra o estado
 * do setup e os dados ja recebidos (se houver).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('redes_sociais_instagram');

$pdo = db();
$pageTitle = 'Inbox Instagram';

$paginas = $totalConvs = 0;
try {
    $paginas = (int)$pdo->query("SELECT COUNT(*) FROM meta_pages WHERE ativa = 1 AND ig_business_id IS NOT NULL")->fetchColumn();
    $totalConvs = (int)$pdo->query("SELECT COUNT(*) FROM meta_inbox_conversas WHERE tipo='instagram'")->fetchColumn();
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width:1100px;margin:0 auto;padding:1rem;">
    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1.4rem;">
        <a href="<?= module_url('redes_sociais') ?>" style="font-size:.78rem;color:#6b7280;text-decoration:none;">← Redes Sociais</a>
    </div>
    <h2 style="margin:0 0 1.2rem;color:var(--petrol-900);display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1.4rem;">📷</span> Inbox Instagram
    </h2>

    <?php if ($paginas === 0): ?>
    <div style="background:#fff;border:2px dashed #d1d5db;border-radius:14px;padding:2.5rem 1.5rem;text-align:center;color:#6b7280;">
        <div style="font-size:3rem;margin-bottom:.6rem;opacity:.6;">📷</div>
        <h3 style="margin:0 0 .4rem;color:#374151;font-size:1.05rem;">Inbox ainda não conectada</h3>
        <p style="font-size:.85rem;max-width:480px;margin:0 auto 1rem;line-height:1.6;">
            Pra começar a receber DMs do Instagram aqui no Hub, é preciso vincular a Página do Facebook (com a conta IG Business) via Meta Business Suite e finalizar o App Review da Meta.
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
