<?php
/**
 * Rota /l/{codigo} — registra clique + redirect pra URL original.
 * Rewrite em .htaccess: RewriteRule ^l/([A-Za-z0-9]+)$ api/l.php?c=$1
 *
 * Público (sem login). NÃO expor informação do lead/case aqui.
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions_shortlinks.php';

$codigo = isset($_GET['c']) ? trim((string)$_GET['c']) : '';
if ($codigo === '' || !preg_match('/^[A-Za-z0-9]{4,20}$/', $codigo)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Link não encontrado.';
    exit;
}

$url = sl_registrar_clique($codigo);
if (!$url) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Link não encontrado ou expirado.';
    exit;
}

// 302 pra não gerar cache permanente (cada clique conta)
header('Location: ' . $url, true, 302);
header('Cache-Control: no-cache, no-store, must-revalidate');
exit;
