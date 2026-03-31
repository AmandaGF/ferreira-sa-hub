<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/modules/planilha/importar.php';
echo "Arquivo: $file\n";
echo "Existe: " . (file_exists($file) ? 'SIM' : 'NAO') . "\n";
echo "Tamanho: " . filesize($file) . " bytes\n";
echo "Modificado: " . date('Y-m-d H:i:s', filemtime($file)) . "\n\n";

$content = file_get_contents($file);
echo "Contém csrf_field: " . (strpos($content, 'csrf_field') !== false ? 'SIM (BUG!)' : 'NAO (OK)') . "\n";
echo "Contém csrf_input: " . (strpos($content, 'csrf_input') !== false ? 'SIM (OK)' : 'NAO') . "\n";
echo "Contém error_reporting: " . (strpos($content, 'error_reporting') !== false ? 'SIM' : 'NAO') . "\n";
echo "Contém importar2: " . (file_exists(__DIR__ . '/modules/planilha/importar2.php') ? 'SIM' : 'NAO') . "\n\n";

// Testar se csrf_input funciona
require_once __DIR__ . '/core/middleware.php';
echo "csrf_input existe: " . (function_exists('csrf_input') ? 'SIM' : 'NAO') . "\n";
echo "csrf_field existe: " . (function_exists('csrf_field') ? 'SIM' : 'NAO') . "\n";
