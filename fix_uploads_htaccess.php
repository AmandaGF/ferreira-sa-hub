<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$dir = dirname(__DIR__) . '/salavip/uploads/';

// Tentar diferentes formatos de .htaccess
$htContent = "# Allow all access to images\n" .
    "Satisfy any\n" .
    "Allow from all\n" .
    "\n" .
    "<IfModule mod_authz_core.c>\n" .
    "    Require all granted\n" .
    "</IfModule>\n" .
    "\n" .
    "# Block PHP\n" .
    "AddHandler cgi-script .php .php5 .phtml\n" .
    "Options -ExecCGI\n";

file_put_contents($dir . '.htaccess', $htContent);
chmod($dir . '.htaccess', 0644);
echo ".htaccess written\n";
echo $htContent . "\n";

// Check parent .htaccess for denials
$parentHt = dirname(__DIR__) . '/salavip/.htaccess';
echo "Parent .htaccess:\n" . (file_exists($parentHt) ? file_get_contents($parentHt) : 'NOT FOUND') . "\n";

// Test
$testUrl = "https://www.ferreiraesa.com.br/salavip/uploads/foto_447_1776081067.jpg";
$ch = curl_init($testUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true]);
curl_exec($ch);
echo "Test: HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
curl_close($ch);
