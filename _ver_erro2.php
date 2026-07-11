<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();
// Emular login como admin id=1
$_SESSION['user_id'] = 1;
$_SESSION['user_name'] = 'Admin diag';
$_SESSION['user_role'] = 'admin';
$_SESSION['csrf_token'] = 'abc';
try {
    ob_start();
    require __DIR__ . '/modules/painel/index.php';
    $html = ob_get_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK renderizou " . strlen($html) . " bytes\n";
    echo "Primeiros 500: " . substr(strip_tags($html), 0, 500) . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "em " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTRACE:\n" . $e->getTraceAsString() . "\n";
}
