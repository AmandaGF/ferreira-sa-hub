<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('no');
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/modules/financeiro/cliente.php';
echo "arquivo: $f\n";
echo "mtime: " . date('Y-m-d H:i:s', filemtime($f)) . " | tamanho: " . filesize($f) . " bytes\n\n";
$src = file_get_contents($f);
try {
    token_get_all($src, TOKEN_PARSE);
    echo "SINTAXE OK — sem parse error!\n";
} catch (Throwable $e) {
    $ln = $e->getLine();
    echo "PARSE ERROR (linha $ln): " . $e->getMessage() . "\n\n";
    $lines = explode("\n", $src);
    for ($i = max(1, $ln - 6); $i <= min(count($lines), $ln + 3); $i++) {
        echo ($i === $ln ? '>>> ' : '    ') . $i . ': ' . rtrim($lines[$i - 1]) . "\n";
    }
}
