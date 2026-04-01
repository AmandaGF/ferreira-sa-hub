<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

// Limpar cache opcode de TODOS os arquivos
if (function_exists('opcache_reset')) { opcache_reset(); echo "opcache_reset: OK\n"; }
$file = __DIR__ . '/modules/operacional/caso_ver.php';
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($file, true);
    echo "opcache_invalidate: OK\n";
}

// Touch para forçar recompilação
touch($file);
clearstatcache(true, $file);
echo "touch: OK\n";

echo "Tamanho: " . filesize($file) . " bytes\n";
echo "Modificado: " . date('Y-m-d H:i:s', filemtime($file)) . "\n";

// Sintaxe check via PHP
$output = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
echo "Syntax: " . trim($output) . "\n\n";

// Compilar manualmente
echo "Tentando compilar...\n";
try {
    $code = file_get_contents($file);
    // Procurar a string problemática
    $pos = strpos($code, 'unexpected');
    if ($pos !== false) {
        echo "ALERTA: encontrou 'unexpected' no arquivo!\n";
    }
    // Verificar se há aspas duplas conflitantes na linha do urlencode
    $lines = file($file);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'urlencode') !== false && strpos($line, 'target=') !== false) {
            echo "PROBLEMA linha " . ($i+1) . ": urlencode e target na mesma linha HTML!\n";
            echo ">>> " . trim($line) . "\n";
        }
    }
    echo "\nNenhum problema de aspas encontrado.\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
