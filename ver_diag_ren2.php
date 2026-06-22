<?php
/** Diag temp: render-test renuncias + cria arquivo público de teste. Remover. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        echo "\n\n!!! FATAL: " . $e['message'] . " em " . $e['file'] . ":" . $e['line'] . "\n";
    }
});
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';

// cria arquivo de teste pra checar se /files/renuncias é público
$dir = APP_ROOT . '/files/renuncias';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
@file_put_contents($dir . '/pubtest.txt', 'ok-publico');
@chmod($dir . '/pubtest.txt', 0644);
echo "URL pública de teste: " . url('files/renuncias/pubtest.txt') . "\n\n";

@session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Amanda', 'email' => 'a@a.com', 'role' => 'admin');
ob_start();
require __DIR__ . '/modules/processos/renuncias.php';
$html = ob_get_clean();
header('Content-Type: text/plain; charset=utf-8');
echo "RENDER OK — bytes: " . strlen($html) . "\n";
echo "tem checkbox list (rdCasosBox): " . (strpos($html, 'rdCasosBox') !== false ? 'sim' : 'NAO') . "\n";
echo "tem marcar todos (rdToggleTodos): " . (strpos($html, 'rdToggleTodos') !== false ? 'sim' : 'NAO') . "\n";
echo "erro PHP: " . (strpos($html, 'Fatal error') !== false ? 'SIM' : 'nao') . "\n";
