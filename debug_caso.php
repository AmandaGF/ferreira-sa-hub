<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

// Limpar cache opcode
if (function_exists('opcache_reset')) { opcache_reset(); echo "opcache_reset OK\n"; }
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__DIR__ . '/modules/operacional/caso_ver.php', true);
    echo "opcache_invalidate OK\n";
}

$file = __DIR__ . '/modules/operacional/caso_ver.php';
echo "Tamanho: " . filesize($file) . " bytes\n";
echo "Modificado: " . date('Y-m-d H:i:s', filemtime($file)) . "\n\n";

// Tentar compilar
$output = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
echo "Syntax check: $output\n";
