<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$htPath = dirname(__DIR__) . '/salavip/uploads/.htaccess';
$content = '# Allow only image files
<FilesMatch "\.(jpg|jpeg|png|webp|gif)$">
    Require all granted
</FilesMatch>

# Block everything else
<FilesMatch "\.php$">
    Require all denied
</FilesMatch>
';

file_put_contents($htPath, $content);
echo "Written to: $htPath\n";
echo "Content:\n$content\n";

// Test file exists
$foto = dirname(__DIR__) . '/salavip/uploads/foto_447_1776080807.jpg';
echo "Photo exists: " . (file_exists($foto) ? 'YES (' . filesize($foto) . ' bytes)' : 'NO') . "\n";
echo "Photo readable: " . (is_readable($foto) ? 'YES' : 'NO') . "\n";
echo "Dir perms: " . decoct(fileperms(dirname($foto)) & 0777) . "\n";
echo "File perms: " . (file_exists($foto) ? decoct(fileperms($foto) & 0777) : 'N/A') . "\n";
