<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');

// Verificar logs do salavip (subdir do domínio principal)
$logs = array(
    dirname(__DIR__) . '/salavip/error_log',
    dirname(__DIR__) . '/error_log',
    __DIR__ . '/error_log',
);
foreach ($logs as $l) {
    if (is_file($l)) {
        echo "\n\n=== $l (últimos 4KB) ===\n";
        echo substr(file_get_contents($l), -4000);
    }
}

echo "\n\n=== Existe pasta salavip? ===\n";
$sv = dirname(__DIR__) . '/salavip';
echo "path: $sv\n";
echo "exists: " . (is_dir($sv) ? 'SIM' : 'NAO') . "\n";
if (is_dir($sv)) {
    echo "\nArquivos:\n";
    foreach (glob($sv . '/*.php') as $f) echo "  " . basename($f) . "\n";
}
