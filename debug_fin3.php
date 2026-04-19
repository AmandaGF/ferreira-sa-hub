<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Simular sessão da Amanda
session_start();
$_SESSION['user'] = array('id' => 1, 'role' => 'admin', 'name' => 'Amanda Guedes Ferreira', 'email' => 'amandaguedesferreira@gmail.com');

echo "=== Executando index.php do financeiro como Amanda logada ===\n\n";

// Capturar output e erros
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo "  ERRO [{$errno}] {$errstr} em {$errfile}:{$errline}\n";
    return false;
});

register_shutdown_function(function() {
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
        echo "\n❌ FATAL no shutdown: {$e['message']} em {$e['file']}:{$e['line']}\n";
    }
});

ob_start();
try {
    include __DIR__ . '/modules/financeiro/index.php';
    $out = ob_get_clean();
    echo "✅ Executou. Tamanho do output: " . strlen($out) . " bytes\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "❌ Exception: " . $e->getMessage() . "\n";
    echo "   Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Stack: " . $e->getTraceAsString() . "\n";
}
