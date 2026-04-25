<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');

$lockFile = sys_get_temp_dir() . '/email_monitor.lock';
echo "Lock file path: {$lockFile}\n";
echo "Existe? " . (file_exists($lockFile) ? "SIM" : "NAO") . "\n";
if (file_exists($lockFile)) {
    echo "Tamanho: " . filesize($lockFile) . " bytes\n";
    echo "Modificado em: " . date('Y-m-d H:i:s', filemtime($lockFile)) . "\n";
    echo "Agora: " . date('Y-m-d H:i:s') . "\n";
    echo "Idade: " . (time() - filemtime($lockFile)) . " segundos\n";

    if (!empty($_GET['limpar'])) {
        $ok = @unlink($lockFile);
        echo "\n[LIMPAR] unlink: " . ($ok ? "OK" : "FALHOU") . "\n";
    } else {
        echo "\nPra limpar, acesse: ?key=fsa-hub-deploy-2026&limpar=1\n";
    }
}
