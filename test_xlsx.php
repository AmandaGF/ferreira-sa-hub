<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

echo "PHP " . phpversion() . "\n";
echo "Extensions: " . implode(', ', get_loaded_extensions()) . "\n\n";
echo "ZipArchive: " . (class_exists('ZipArchive') ? 'SIM' : 'NAO') . "\n";
echo "GD: " . (extension_loaded('gd') ? 'SIM' : 'NAO') . "\n";
echo "DOM: " . (extension_loaded('dom') ? 'SIM' : 'NAO') . "\n";
echo "SimpleXML: " . (extension_loaded('SimpleXML') ? 'SIM' : 'NAO') . "\n";
echo "XMLWriter: " . (extension_loaded('xmlwriter') ? 'SIM' : 'NAO') . "\n";

// Test write permission
$testDir = __DIR__ . '/temp';
if (!is_dir($testDir)) @mkdir($testDir, 0755);
$canWrite = is_writable($testDir) ? 'SIM' : 'NAO';
echo "Writable temp: $canWrite\n";
@rmdir($testDir);

// Check if composer autoload exists
echo "Composer vendor: " . (file_exists(__DIR__ . '/vendor/autoload.php') ? 'SIM' : 'NAO') . "\n";
