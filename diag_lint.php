<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('no');
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/modules/financeiro/' . basename($_GET['f'] ?? 'cliente.php');
echo "Lintando: $f\n\n";
$php = trim((string)@shell_exec('which php')) ?: 'php';
$out = @shell_exec($php . ' -l ' . escapeshellarg($f) . ' 2>&1');
if ($out) { echo "[php -l]\n$out\n"; }
else {
    echo "[shell_exec indisponivel — tentando token_get_all]\n";
    $src = file_get_contents($f);
    try { token_get_all($src, TOKEN_PARSE); echo "token_get_all: SEM erro de parse detectado\n"; }
    catch (Throwable $e) { echo "PARSE ERROR: " . $e->getMessage() . "\n"; }
}
