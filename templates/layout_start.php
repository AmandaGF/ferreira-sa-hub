<?php
/**
 * Layout Start — inclui header + sidebar + abre main content
 *
 * Variáveis disponíveis (definir antes do require):
 *   $pageTitle  — título da página (obrigatório)
 *   $extraCss   — CSS inline adicional (opcional)
 */

require_once APP_ROOT . '/templates/header.php';
require_once APP_ROOT . '/templates/sidebar.php';
?>

<div class="app-layout">
    <main class="main-content">
        <div class="topbar">
            <div class="topbar-left">
                <button class="btn-sidebar-toggle" id="sidebarToggle">☰</button>
                <h1 class="topbar-title"><?= e($pageTitle ?? 'Painel') ?></h1>
            </div>
        </div>

        <div class="page-content">
            <?= flash_html() ?>
