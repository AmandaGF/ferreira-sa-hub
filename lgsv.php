<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');

$svdir = dirname(__DIR__) . '/salavip';

echo "=== Arquivos salavip ===\n";
foreach (glob($svdir . '/*') as $f) echo "  " . basename($f) . (is_dir($f) ? '/' : '') . "\n";

echo "\n=== error_log da pasta salavip ===\n";
$log = $svdir . '/error_log';
if (is_file($log)) echo substr(file_get_contents($log), -6000);
else echo "(sem arquivo)\n";

// Lista pastas dentro do salavip pra achar arquivos como ver_mensagem, msg_ver etc
echo "\n=== Subdirs ===\n";
foreach (glob($svdir . '/*', GLOB_ONLYDIR) as $d) {
    echo "  " . basename($d) . "\n";
    foreach (glob($d . '/*.php') as $f) echo "    " . basename($f) . "\n";
}

echo "\n=== Procurar 'mensagem' ou 'ver_msg' ===\n";
$found = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($svdir));
foreach ($it as $f) {
    $n = $f->getFilename();
    if (preg_match('/mensa|msg|mensagem/i', $n)) echo "  " . $f->getPathname() . "\n";
}
