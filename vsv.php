<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$svdir = dirname(__DIR__) . '/salavip';

echo "=== config.php (mascarando senha) ===\n";
$c = file_get_contents($svdir . '/config.php');
$c = preg_replace("/(pass\w*|senha)\s*=\s*'[^']*'/i", "$1 = 'MASKED'", $c);
$c = preg_replace("/(Ar\w+@?)/i", "MASKED", $c);
echo substr($c, 0, 2500);

echo "\n\n=== error_log RECENTE (últimos 3KB) ===\n";
$log = $svdir . '/error_log';
if (is_file($log)) {
    $sz = filesize($log);
    echo "Tamanho: $sz bytes\n";
    echo "Mtime: " . date('Y-m-d H:i:s', filemtime($log)) . "\n\n";
    $content = file_get_contents($log);
    $content = preg_replace("/'Ar\d+@'/i", "'MASKED'", $content);
    echo substr($content, -3000);
}
