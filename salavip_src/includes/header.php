<?php
/**
 * Sala VIP F&S — Header (layout autenticado)
 *
 * Uso: defina $pageTitle antes de incluir este arquivo.
 *   $pageTitle = 'Painel';
 *   require_once __DIR__ . '/../includes/header.php';
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

salavip_require_login();

$_svUser = salavip_current_user();

// Migration: add foto_path column if not exists
$pdo = sv_db();
try { $pdo->query("SELECT foto_path FROM clients LIMIT 0"); } catch (Exception $e) {
    $pdo->exec("ALTER TABLE clients ADD COLUMN foto_path VARCHAR(500) DEFAULT NULL");
}

// Fetch client photo
$_svClienteFoto = null;
try {
    $stmtF = $pdo->prepare("SELECT foto_path FROM clients WHERE id = ?");
    $stmtF->execute([$_svUser['cliente_id']]);
    $_svClienteFoto = $stmtF->fetchColumn();
} catch(Exception $e) {}

// Contar mensagens nao lidas
$_svUnread = 0;
try {
    $stmtUnread = sv_db()->prepare(
        'SELECT COUNT(*) FROM salavip_mensagens WHERE cliente_id = ? AND origem = \'conecta\' AND lida_cliente = 0'
    );
    $stmtUnread->execute([$_svUser['cliente_id']]);
    $_svUnread = (int) $stmtUnread->fetchColumn();
} catch (Exception $e) {
    $_svUnread = 0;
}

// Pagina atual para marcar menu ativo
$_svCurrentPage = basename($_SERVER['SCRIPT_FILENAME'] ?? '');

// Flash message
$_svFlash = sv_flash_get();

if (!isset($pageTitle)) {
    $pageTitle = 'Sala VIP';
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sv_e($pageTitle) ?> — Sala VIP</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Lato:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= sv_e(SALAVIP_BASE_URL) ?>/assets/css/style.css">
</head>
<body>

<!-- Mobile Header -->
<div class="sv-mobile-header">
    <button type="button" id="btn-menu-open" aria-label="Abrir menu" style="background:none;border:none;color:#c9a94e;font-size:1.5rem;cursor:pointer;">&#9776;</button>
    <span style="font-family:'Playfair Display',serif;color:#c9a94e;font-size:1rem;">Sala VIP</span>
    <span style="display:inline-flex;align-items:center;gap:6px;">
        <?php if ($_svClienteFoto): ?>
            <img src="<?= sv_url('uploads/' . $_svClienteFoto) ?>" alt="Foto" class="sv-avatar sv-avatar-small">
        <?php else: ?>
            <div class="sv-avatar-placeholder sv-avatar-small">&#x1F464;</div>
        <?php endif; ?>
        <span style="color:#94a3b8;font-size:.8rem;"><?= sv_e($_svUser['nome_exibicao']) ?></span>
    </span>
</div>

<!-- Mobile Overlay -->
<div class="sv-overlay" id="sv-overlay"></div>

<!-- Mobile Menu -->
<div class="sv-mobile-menu" id="sv-mobile-menu">
    <div style="text-align:right;padding:0 1rem 1rem;">
        <button type="button" id="btn-menu-close" aria-label="Fechar menu" style="background:none;border:none;color:#94a3b8;font-size:1.5rem;cursor:pointer;">&times;</button>
    </div>
    <div class="sv-sidebar-logo">
        <img src="<?= sv_e(SALAVIP_BASE_URL) ?>/assets/img/logo.png" alt="Logo" onerror="this.style.display='none'">
        <h2>Sala VIP</h2>
    </div>
    <?php if ($_svClienteFoto): ?>
        <img src="<?= sv_url('uploads/' . $_svClienteFoto) ?>" alt="Foto" class="sv-avatar" style="margin:0 auto .5rem;display:block;">
    <?php else: ?>
        <div class="sv-avatar-placeholder" style="margin:0 auto .5rem;">&#x1F464;</div>
    <?php endif; ?>
    <div style="color:var(--sv-text);font-size:.82rem;text-align:center;margin-bottom:.5rem;"><?= sv_e($_svUser['nome_exibicao']) ?></div>
    <ul class="sv-nav">
        <li><a href="<?= sv_url('pages/dashboard.php') ?>"<?= $_svCurrentPage === 'dashboard.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4CA;</span> Painel</a></li>
        <li><a href="<?= sv_url('pages/meus_processos.php') ?>"<?= $_svCurrentPage === 'meus_processos.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4C2;</span> Meus Processos</a></li>
        <li><a href="<?= sv_url('pages/documentos.php') ?>"<?= $_svCurrentPage === 'documentos.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4C4;</span> Documentos</a></li>
        <li><a href="<?= sv_url('pages/mensagens.php') ?>"<?= $_svCurrentPage === 'mensagens.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4AC;</span> Mensagens<?php if ($_svUnread > 0): ?> <span class="sv-badge" style="background:#dc2626;color:#fff;margin-left:4px;"><?= $_svUnread ?></span><?php endif; ?></a></li>
        <li><a href="<?= sv_url('pages/compromissos.php') ?>"<?= $_svCurrentPage === 'compromissos.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4C5;</span> Compromissos</a></li>
        <li><a href="<?= sv_url('pages/financeiro.php') ?>"<?= $_svCurrentPage === 'financeiro.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4B0;</span> Financeiro</a></li>
        <li><a href="<?= sv_url('pages/meus_dados.php') ?>"<?= $_svCurrentPage === 'meus_dados.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F464;</span> Meus Dados</a></li>
        <li><a href="<?= sv_url('pages/faq.php') ?>"<?= $_svCurrentPage === 'faq.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x2753;</span> FAQ</a></li>
        <li><a href="<?= sv_url('pages/sobre.php') ?>"<?= $_svCurrentPage === 'sobre.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x2696;&#xFE0F;</span> Ferreira &amp; S&aacute;</a></li>
        <li><a href="<?= sv_url('logout.php') ?>"><span class="nav-icon">&#x1F6AA;</span> Sair</a></li>
    </ul>
</div>

<!-- Layout -->
<div class="sv-layout">

    <!-- Sidebar (desktop) -->
    <aside class="sv-sidebar">
        <div class="sv-sidebar-logo">
            <img src="<?= sv_e(SALAVIP_BASE_URL) ?>/assets/img/logo.png" alt="Logo" onerror="this.style.display='none'">
            <h2>Sala VIP</h2>
        </div>
        <?php if ($_svClienteFoto): ?>
            <img src="<?= sv_url('uploads/' . $_svClienteFoto) ?>" alt="Foto" class="sv-avatar" style="margin:0 auto .5rem;display:block;">
        <?php else: ?>
            <div class="sv-avatar-placeholder" style="margin:0 auto .5rem;">&#x1F464;</div>
        <?php endif; ?>
        <div style="color:var(--sv-text);font-size:.82rem;text-align:center;margin-bottom:.5rem;"><?= sv_e($_svUser['nome_exibicao']) ?></div>
        <ul class="sv-nav">
            <li><a href="<?= sv_url('pages/dashboard.php') ?>"<?= $_svCurrentPage === 'dashboard.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4CA;</span> Painel</a></li>
            <li><a href="<?= sv_url('pages/meus_processos.php') ?>"<?= $_svCurrentPage === 'meus_processos.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4C2;</span> Meus Processos</a></li>
            <li><a href="<?= sv_url('pages/documentos.php') ?>"<?= $_svCurrentPage === 'documentos.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4C4;</span> Documentos</a></li>
            <li><a href="<?= sv_url('pages/mensagens.php') ?>"<?= $_svCurrentPage === 'mensagens.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4AC;</span> Mensagens<?php if ($_svUnread > 0): ?> <span class="sv-badge" style="background:#dc2626;color:#fff;margin-left:4px;"><?= $_svUnread ?></span><?php endif; ?></a></li>
            <li><a href="<?= sv_url('pages/compromissos.php') ?>"<?= $_svCurrentPage === 'compromissos.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4C5;</span> Compromissos</a></li>
            <li><a href="<?= sv_url('pages/financeiro.php') ?>"<?= $_svCurrentPage === 'financeiro.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F4B0;</span> Financeiro</a></li>
            <li><a href="<?= sv_url('pages/meus_dados.php') ?>"<?= $_svCurrentPage === 'meus_dados.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x1F464;</span> Meus Dados</a></li>
            <li><a href="<?= sv_url('pages/faq.php') ?>"<?= $_svCurrentPage === 'faq.php' ? ' class="active"' : '' ?>><span class="nav-icon">&#x2753;</span> FAQ</a></li>
            <li><a href="<?= sv_url('logout.php') ?>"><span class="nav-icon">&#x1F6AA;</span> Sair</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="sv-main">
        <div class="sv-header">
            <h1><?= sv_e($pageTitle) ?></h1>
            <div class="user-info"><?= sv_e($_svUser['nome_exibicao']) ?></div>
        </div>

        <?php if ($_svFlash): ?>
            <div class="<?= $_svFlash['type'] === 'success' ? 'success-msg' : 'error-msg' ?>" style="margin-bottom:1.5rem;">
                <?= sv_e($_svFlash['msg']) ?>
            </div>
        <?php endif; ?>
