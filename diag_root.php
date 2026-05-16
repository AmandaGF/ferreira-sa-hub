<?php
/**
 * Diag READ-ONLY: mapeia a raiz do domínio pra planejar a migração do
 * site novo (substituir o WordPress). Não altera NADA.
 * ?key=fsa-hub-deploy-2026
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$conecta = __DIR__;                 // .../public_html/conecta
$root    = dirname($conecta);       // .../public_html (provável docroot do www)

echo "=== CAMINHOS ===\n";
echo "Pasta /conecta : {$conecta}\n";
echo "Raiz (parent)  : {$root}\n";
echo "DOCUMENT_ROOT  : " . ($_SERVER['DOCUMENT_ROOT'] ?? '?') . "\n";
echo "HTTP_HOST      : " . ($_SERVER['HTTP_HOST'] ?? '?') . "\n";
echo "Usuário PHP    : " . (function_exists('posix_getpwuid') && function_exists('posix_geteuid')
        ? (posix_getpwuid(posix_geteuid())['name'] ?? get_current_user()) : get_current_user()) . "\n";
echo "PHP            : " . PHP_VERSION . " | SAPI " . PHP_SAPI . "\n";

echo "\n=== RAIZ GRAVÁVEL? ===\n";
echo "is_writable(raiz): " . (is_writable($root) ? 'SIM' : 'NÃO') . "\n";
$df = @disk_free_space($root);
echo "Espaço livre   : " . ($df ? round($df / 1048576) . ' MB' : '?') . "\n";

echo "\n=== CONTEÚDO DA RAIZ (" . $root . ") ===\n";
$it = @scandir($root);
if ($it) {
    foreach ($it as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $root . '/' . $f;
        $tipo = is_dir($p) ? 'DIR ' : 'file';
        $sz = is_file($p) ? ' (' . round(filesize($p) / 1024) . ' KB)' : '';
        echo "  [{$tipo}] {$f}{$sz}\n";
    }
}

echo "\n=== WORDPRESS? ===\n";
$wpCfg = $root . '/wp-config.php';
echo "wp-config.php  : " . (is_file($wpCfg) ? 'EXISTE' : 'não') . "\n";
echo "wp-content/    : " . (is_dir($root . '/wp-content') ? 'EXISTE' : 'não') . "\n";
echo "wp-admin/      : " . (is_dir($root . '/wp-admin') ? 'EXISTE' : 'não') . "\n";
if (is_file($wpCfg)) {
    $c = @file_get_contents($wpCfg);
    foreach (array('DB_NAME','DB_USER','DB_HOST','table_prefix') as $k) {
        if (preg_match("/(?:'" . $k . "'\\s*,\\s*'([^']*)'|\\\$table_prefix\\s*=\\s*'([^']*)')/", $c, $m)) {
            echo "  {$k} = " . ($m[1] !== '' ? $m[1] : ($m[2] ?? '')) . "\n";
        }
    }
}

echo "\n=== .htaccess da raiz ===\n";
$ht = $root . '/.htaccess';
if (is_file($ht)) {
    echo "(" . round(filesize($ht)) . " bytes)\n---\n" . substr(@file_get_contents($ht), 0, 2000) . "\n---\n";
} else { echo "não existe\n"; }

echo "\n=== index da raiz ===\n";
foreach (array('index.php','index.html') as $idx) {
    if (is_file($root . '/' . $idx)) echo "  {$idx} (" . round(filesize($root . '/' . $idx) / 1024) . " KB)\n";
}

echo "\n=== /conecta e /salavip ===\n";
echo "conecta/  : " . (is_dir($conecta) ? 'OK' : '?') . "\n";
echo "salavip/  : " . (is_dir($root . '/salavip') ? 'EXISTE' : 'não') . "\n";

echo "\n(diagnóstico read-only; nada foi alterado)\n";
