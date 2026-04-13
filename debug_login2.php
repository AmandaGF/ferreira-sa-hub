<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

// Forçar cópia do index.php corrigido
$src = __DIR__ . '/salavip_src/index.php';
$dst = dirname(__DIR__) . '/salavip/index.php';

echo "Src exists: " . (file_exists($src) ? 'YES' : 'NO') . "\n";
echo "Src has fix: " . (strpos(file_get_contents($src), 'OR cpf = ?') !== false ? 'YES' : 'NO') . "\n";

copy($src, $dst);
chmod($dst, 0644);

echo "Copied. Dst has fix: " . (strpos(file_get_contents($dst), 'OR cpf = ?') !== false ? 'YES' : 'NO') . "\n";
echo "DONE\n";
