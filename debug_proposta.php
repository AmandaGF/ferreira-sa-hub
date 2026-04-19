<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
$_SESSION['user'] = array('id' => 1, 'role' => 'admin', 'name' => 'Amanda Guedes Ferreira', 'email' => 'amandaguedesferreira@gmail.com');

$_GET['id'] = 917;

echo "=== Executando proposta.php ===\n";
register_shutdown_function(function(){
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        echo "\nFATAL: {$e['message']} em {$e['file']}:{$e['line']}\n";
    }
});

try {
    ob_start();
    include __DIR__ . '/modules/financeiro/proposta.php';
    $out = ob_get_clean();
    echo "OK. Tamanho: " . strlen($out) . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "EXCEPTION: " . $e->getMessage() . "\n  at " . $e->getFile() . ":" . $e->getLine() . "\n";
}
