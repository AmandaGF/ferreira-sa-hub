<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
ini_set('display_errors','1'); error_reporting(E_ALL);
@session_start();
$_SESSION['user'] = array('id'=>1, 'name'=>'Amanda Guedes Ferreira', 'role'=>'admin');
$t0 = microtime(true);
register_shutdown_function(function() use ($t0){
    $e = error_get_last();
    if ($e && in_array($e['type'], array(E_ERROR,E_PARSE,E_COMPILE_ERROR,E_CORE_ERROR))) {
        echo "\n\n>>> FATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n";
    }
    echo "\n[diag] tempo: ".round(microtime(true)-$t0,2)."s\n";
});
header('Content-Type: text/plain; charset=utf-8');
ob_start();
try {
    require __DIR__.'/modules/dashboard/index.php';
    $html = ob_get_clean();
    echo "OK — render ".strlen($html)." bytes\n";
} catch (Throwable $e) {
    @ob_end_clean();
    echo ">>> EXCEPTION: ".$e->getMessage()." @ ".$e->getFile().":".$e->getLine()."\n";
}
