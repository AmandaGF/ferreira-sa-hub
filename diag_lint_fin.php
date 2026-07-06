<?php
// Lint temporário do Setor Financeiro + OFX. Remove depois.
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('nope'); }
header('Content-Type: text/plain; charset=utf-8');
$arquivos = array(
    'core/functions_financeiro_interno.php',
    'modules/financeiro_interno/index.php',
    'modules/financeiro_interno/api.php',
    'modules/financeiro_interno/importar_ofx.php',
);
foreach ($arquivos as $f) {
    $path = __DIR__ . '/' . $f;
    if (!is_file($path)) { echo "[FALTA] $f\n"; continue; }
    try {
        token_get_all(file_get_contents($path), TOKEN_PARSE);
        echo "[OK]   $f\n";
    } catch (ParseError $e) {
        echo "[ERRO] $f  ->  linha " . $e->getLine() . ": " . $e->getMessage() . "\n";
    }
}
echo "\n--- fim ---\n";
