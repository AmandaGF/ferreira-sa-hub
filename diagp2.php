<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();
$_SESSION['user_id'] = 1; $_SESSION['user_name'] = 'Admin diag'; $_SESSION['user_role'] = 'admin';
try {
    ob_start(); require __DIR__ . '/modules/painel/index.php'; $html = ob_get_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK renderizou " . strlen($html) . " bytes\n";
} catch (Throwable $e) {
    ob_end_clean(); header('Content-Type: text/plain; charset=utf-8');
    echo "ERRO: " . $e->getMessage() . "\nem " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTRACE:\n" . $e->getTraceAsString() . "\n";
}
