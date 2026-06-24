<?php
/** Diag temp: render novo.php (express) + compile-check api.php. Remover. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
$GLOBALS['_fatal'] = '';
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true)) {
        echo "\n\n!!! FATAL: " . $e['message'] . " em " . $e['file'] . ":" . $e['line'] . "\n";
    } else {
        echo "\n(sem fatal — arquivos compilaram)\n";
    }
});
@session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Amanda', 'email' => 'a@a.com', 'role' => 'admin');
header('Content-Type: text/plain; charset=utf-8');

// 1) render do form express
$_GET = array(); $_SERVER['REQUEST_METHOD'] = 'GET';
ob_start(); require __DIR__ . '/modules/helpdesk/novo.php'; $h = ob_get_clean();
echo "novo.php RENDER OK — bytes: " . strlen($h) . "\n";
echo "tem 'Mais detalhes (opcional)': " . (strpos($h, 'Mais detalhes (opcional)') !== false ? 'sim' : 'NAO') . "\n";
echo "tem Título e Descrição: " . (strpos($h, 'name="title"') !== false && strpos($h, 'name="description"') !== false ? 'sim' : 'NAO') . "\n";
echo "erro PHP no html: " . (strpos($h, 'Fatal error') !== false || strpos($h, 'Parse error') !== false ? 'SIM' : 'nao') . "\n";

// 2) compile-check api.php (GET sem action -> default -> redirect/exit; shutdown confirma se compilou)
echo "\nchecando api.php (vai redirecionar no fim)...\n";
$_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = array();
require __DIR__ . '/modules/helpdesk/api.php';
echo "api.php compilou e retornou normalmente\n";
