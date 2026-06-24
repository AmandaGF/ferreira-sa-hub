<?php
/** Diag temp: render-test audiencistas. Remover. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        echo "\n\n!!! FATAL: " . $e['message'] . " em " . $e['file'] . ":" . $e['line'] . "\n";
    }
});
@session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Amanda', 'email' => 'a@a.com', 'role' => 'admin');
ob_start();
require __DIR__ . '/modules/audiencistas/index.php';
$html = ob_get_clean();
header('Content-Type: text/plain; charset=utf-8');
echo "RENDER OK — bytes: " . strlen($html) . "\n";
echo "aba Audiências: " . (strpos($html, 'pane-audiencias') !== false ? 'sim' : 'NAO') . "\n";
echo "aba Audiencistas: " . (strpos($html, 'pane-audiencistas') !== false ? 'sim' : 'NAO') . "\n";
echo "form cadastro: " . (strpos($html, 'salvar_audiencista') !== false ? 'sim' : 'NAO') . "\n";
echo "erro PHP: " . (strpos($html, 'Fatal error') !== false ? 'SIM' : 'nao') . "\n";
