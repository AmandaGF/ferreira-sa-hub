<?php
// Diag rápido: verifica se ffmpeg está disponível no servidor
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');

echo "=== ffmpeg ===\n";
$paths = array('ffmpeg', '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/cpanel/ea-php74/root/usr/bin/ffmpeg');
foreach ($paths as $p) {
    $out = @shell_exec("$p -version 2>&1 | head -1");
    echo "  $p → " . ($out ? trim($out) : '(não encontrado)') . "\n";
}

echo "\n=== which ffmpeg ===\n";
echo @shell_exec('which ffmpeg 2>&1') ?: '(não tem `which` ou ffmpeg não está no PATH)';

echo "\n=== shell_exec disponível? ===\n";
echo function_exists('shell_exec') ? 'SIM' : 'NÃO';

echo "\n\n=== exec disponível? ===\n";
echo function_exists('exec') ? 'SIM' : 'NÃO';

echo "\n\n=== disable_functions ===\n";
echo ini_get('disable_functions') ?: '(nada bloqueado)';
