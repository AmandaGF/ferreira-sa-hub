<?php
/**
 * Redes Sociais — landing da secao (Amanda 04/06/2026)
 * 3 cards (Inbox IG, Inbox FB, Comentarios FB) + status da configuracao.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('redes_sociais');

$pdo = db();
$pageTitle = 'Redes Sociais';

// Dispara o self-heal via api.php (idempotente, primeira vez cria tabelas)
@file_get_contents('php://memory'); // noop
try {
    $totalPaginas = (int)$pdo->query("SELECT COUNT(*) FROM meta_pages WHERE ativa = 1")->fetchColumn();
} catch (Exception $e) {
    // tabelas ainda nao criadas - api.php cria na primeira chamada
    $totalPaginas = 0;
}

require_once APP_ROOT . '/templates/layout_start.php';
?>

<div style="max-width:1100px;margin:0 auto;padding:1rem;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem;">
        <h2 style="margin:0;color:var(--petrol-900);">📲 Redes Sociais</h2>
        <?php if (has_min_role('gestao')): ?>
        <a href="<?= module_url('redes_sociais', 'setup.php') ?>" class="btn btn-outline btn-sm" style="font-size:.8rem;">⚙️ Configurar Meta</a>
        <?php endif; ?>
    </div>

    <?php if ($totalPaginas === 0): ?>
    <div style="background:#fef3c7;border:1.5px solid #f59e0b;border-radius:12px;padding:1rem 1.2rem;margin-bottom:1.4rem;font-size:.88rem;color:#92400e;">
        <strong>⚠️ Conexão com a Meta ainda não foi configurada.</strong>
        Pra começar a receber mensagens do Instagram e Facebook no Hub, é preciso vincular a Página oficial via Meta Business Suite e aprovar permissões no App Review da Meta.
        <?php if (has_min_role('gestao')): ?>
            <br><a href="<?= module_url('redes_sociais', 'setup.php') ?>" style="color:#7c2d12;font-weight:700;text-decoration:underline;">→ Ver passo a passo de configuração</a>
        <?php else: ?>
            <br>Peça pra Amanda ou Luiz Eduardo configurar.
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(260px, 1fr));gap:1rem;">
        <a href="<?= module_url('redes_sociais', 'inbox_instagram.php') ?>" style="background:#fff;border:2px solid #e5e7eb;border-radius:14px;padding:1.4rem 1.2rem;text-decoration:none;color:inherit;transition:all .2s;display:block;">
            <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.6rem;">
                <span style="font-size:1.6rem;">📷</span>
                <h3 style="margin:0;font-size:1rem;color:var(--petrol-900);">Inbox Instagram</h3>
            </div>
            <p style="font-size:.82rem;color:#6b7280;margin:0 0 .6rem;line-height:1.5;">Mensagens diretas (DMs) que os seguidores enviam pela conta do Instagram do escritório.</p>
            <span style="font-size:.7rem;font-weight:700;color:#7c3aed;text-transform:uppercase;letter-spacing:.05em;">Abrir Inbox →</span>
        </a>

        <a href="<?= module_url('redes_sociais', 'inbox_facebook.php') ?>" style="background:#fff;border:2px solid #e5e7eb;border-radius:14px;padding:1.4rem 1.2rem;text-decoration:none;color:inherit;transition:all .2s;display:block;">
            <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.6rem;">
                <span style="font-size:1.6rem;">📘</span>
                <h3 style="margin:0;font-size:1rem;color:var(--petrol-900);">Inbox Facebook</h3>
            </div>
            <p style="font-size:.82rem;color:#6b7280;margin:0 0 .6rem;line-height:1.5;">Mensagens do Messenger que chegam pela Página do Facebook.</p>
            <span style="font-size:.7rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:.05em;">Abrir Inbox →</span>
        </a>

        <a href="<?= module_url('redes_sociais', 'comentarios_facebook.php') ?>" style="background:#fff;border:2px solid #e5e7eb;border-radius:14px;padding:1.4rem 1.2rem;text-decoration:none;color:inherit;transition:all .2s;display:block;">
            <div style="display:flex;align-items:center;gap:.65rem;margin-bottom:.6rem;">
                <span style="font-size:1.6rem;">💬</span>
                <h3 style="margin:0;font-size:1rem;color:var(--petrol-900);">Comentários do Facebook</h3>
            </div>
            <p style="font-size:.82rem;color:#6b7280;margin:0 0 .6rem;line-height:1.5;">Comentários em posts da Página. Lista, responde e arquiva direto do Hub.</p>
            <span style="font-size:.7rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.05em;">Abrir Lista →</span>
        </a>
    </div>
</div>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
