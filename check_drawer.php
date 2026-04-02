<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

// Testar inclusão do drawer
echo "Tentando incluir card_drawer.php...\n";
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();
try {
    require_once dirname(__FILE__) . '/core/config.php';
    require_once dirname(__FILE__) . '/core/database.php';
    require_once dirname(__FILE__) . '/core/functions.php';
    require_once dirname(__FILE__) . '/core/auth.php';
    session_start();
    $drawerOriginKanban = 'operacional';
    include dirname(__FILE__) . '/modules/shared/card_drawer.php';
    $output = ob_get_clean();
    echo "OK! Output: " . strlen($output) . " bytes\n";
    // Mostrar primeiros 200 chars
    echo "Primeiros 200: " . substr(strip_tags($output), 0, 200) . "\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "ERRO: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . " linha " . $e->getLine() . "\n";
}

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
