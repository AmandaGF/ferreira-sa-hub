<?php
/** Diag temp: render-test conversas_novas só 21. Remover após uso. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        echo "\n\n!!! FATAL: " . $e['message'] . " em " . $e['file'] . ":" . $e['line'] . "\n";
    }
});
@session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Amanda', 'email' => 'a@a.com', 'role' => 'admin');
$_GET['dia'] = date('Y-m-d', strtotime('-1 day'));
ob_start();
require __DIR__ . '/modules/whatsapp/conversas_novas.php';
$html = ob_get_clean();
header('Content-Type: text/plain; charset=utf-8');
echo "RENDER OK — bytes: " . strlen($html) . "\n";
echo "tem 'CX/Operac' visível: " . (strpos($html, 'CX/Operac') !== false ? 'SIM (revisar)' : 'nao') . "\n";
echo "tem '(24)' visível: "      . (strpos($html, '(24)') !== false ? 'SIM (revisar)' : 'nao') . "\n";
echo "tem 'Conversas novas (21)': " . (strpos($html, 'Conversas novas (21)') !== false ? 'sim' : 'NAO') . "\n";
echo "erro PHP no html: " . (strpos($html, 'Fatal error') !== false || strpos($html, 'Warning:') !== false ? 'SIM' : 'nao') . "\n";
