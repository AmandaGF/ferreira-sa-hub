<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
session_start();
$_SESSION['user_id'] = 1; $_SESSION['user_name'] = 'Admin'; $_SESSION['user_role'] = 'admin';
$_GET['id'] = (int)($_GET['case_id'] ?? 1195);
try {
    ob_start();
    require __DIR__ . '/modules/operacional/caso_ver.php';
    $html = ob_get_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK renderizou " . strlen($html) . " bytes\n";
    // Ver se tem erro embutido no HTML
    if (strpos($html, 'Fatal error') !== false || strpos($html, 'Parse error') !== false || strpos($html, 'Warning:') !== false) {
        $pos = strpos($html, 'error');
        echo "\n[erro no output]:\n" . substr($html, max(0, $pos - 200), 800) . "\n";
    }
} catch (Throwable $e) {
    @ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERRO: " . $e->getMessage() . "\nem " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nTRACE:\n" . $e->getTraceAsString() . "\n";
}
