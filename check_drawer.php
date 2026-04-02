<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

// Testar syntax do drawer
echo "PHP lint card_drawer.php:\n";
$output = shell_exec('php -l ' . escapeshellarg(dirname(__FILE__) . '/modules/shared/card_drawer.php') . ' 2>&1');
echo $output . "\n";

echo "PHP lint card_api.php:\n";
$output2 = shell_exec('php -l ' . escapeshellarg(dirname(__FILE__) . '/modules/shared/card_api.php') . ' 2>&1');
echo $output2 . "\n";

$root = dirname(__FILE__);
echo "ROOT: $root\n\n";

$files = array(
    'modules/shared/card_drawer.php',
    'modules/shared/card_api.php',
    'modules/shared/card_actions.php',
);

foreach ($files as $f) {
    $path = $root . '/' . $f;
    echo "$f: " . (file_exists($path) ? 'EXISTE (' . filesize($path) . ' bytes)' : 'NÃO EXISTE') . "\n";
}

echo "\nDiretório modules/shared/:\n";
$dir = $root . '/modules/shared';
if (is_dir($dir)) {
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        echo "  $item (" . filesize($dir . '/' . $item) . " bytes)\n";
    }
} else {
    echo "  DIRETÓRIO NÃO EXISTE!\n";
}
