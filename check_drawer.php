<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

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
