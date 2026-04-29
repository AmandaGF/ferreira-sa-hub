<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');

echo "==== ffmpeg disponível no servidor? ====\n";
foreach (['ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/ffmpeg/bin/ffmpeg'] as $bin) {
    $out = @shell_exec("$bin -version 2>&1 | head -1");
    echo "  $bin -> " . trim($out ?: '(nao encontrado)') . "\n";
}

echo "\n==== which ffmpeg ====\n";
echo "  " . trim(@shell_exec("which ffmpeg 2>&1") ?: '(nada)') . "\n";

echo "\n==== avconv (alternativa) ====\n";
echo "  " . trim(@shell_exec("which avconv 2>&1") ?: '(nada)') . "\n";

echo "\n==== exec() habilitado? ====\n";
echo "  disable_functions = " . ini_get('disable_functions') . "\n";
echo "  shell_exec habilitado: " . (function_exists('shell_exec') ? 'sim' : 'nao') . "\n";
echo "  exec habilitado: " . (function_exists('exec') ? 'sim' : 'nao') . "\n";
