<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');

$svdir = dirname(__DIR__) . '/salavip';

echo "=== ARQUIVO ATUAL mensagem_ver.php ===\n";
$f = $svdir . '/pages/mensagem_ver.php';
echo "Mtime: " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
echo "Size: " . filesize($f) . "\n\n";
$c = file_get_contents($f);
echo $c;
