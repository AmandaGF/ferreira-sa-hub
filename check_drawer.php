<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
echo "START\n";

// Testar inclusão do drawer com sessão simulada
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once dirname(__FILE__) . '/core/config.php';
require_once dirname(__FILE__) . '/core/database.php';
require_once dirname(__FILE__) . '/core/functions.php';

echo "Config OK\n";

// Simular sessão
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['user'] = array('id' => 1, 'name' => 'Test', 'email' => 'test@test.com', 'role' => 'admin');
$_SESSION[CSRF_TOKEN_NAME] = 'test_token';

require_once dirname(__FILE__) . '/core/auth.php';
echo "Auth OK\n";

echo "url() funciona: " . url('test') . "\n";
echo "csrf_token(): " . csrf_token() . "\n";

echo "\nIncluindo drawer...\n";
$drawerOriginKanban = 'operacional';
ob_start();
include dirname(__FILE__) . '/modules/shared/card_drawer.php';
$output = ob_get_clean();
echo "Drawer OK! " . strlen($output) . " bytes\n";

// Verificar se tem a tag <script>
echo "Tem <script>: " . (strpos($output, '<script>') !== false ? 'SIM' : 'NÃO') . "\n";
echo "Tem abrirDrawer: " . (strpos($output, 'abrirDrawer') !== false ? 'SIM' : 'NÃO') . "\n";
echo "Tem cardDrawer: " . (strpos($output, 'cardDrawer') !== false ? 'SIM' : 'NÃO') . "\n";

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
