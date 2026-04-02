<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
echo "OK1\n";

// Ler o arquivo como texto e procurar problemas
$file = __DIR__ . '/modules/shared/card_drawer.php';
$code = file_get_contents($file);
echo "Tamanho: " . strlen($code) . "\n";

// Verificar se tem PHP syntax válido usando token_get_all
try {
    $tokens = @token_get_all($code);
    echo "Tokens: " . count($tokens) . "\n";
    echo "OK - sem erro de parse\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// Verificar se o require_once do operacional existe
$opFile = __DIR__ . '/modules/operacional/index.php';
$opCode = file_get_contents($opFile);
$pos = strpos($opCode, 'card_drawer');
echo "\noperacional/index.php menciona card_drawer: " . ($pos !== false ? "SIM (pos $pos)" : "NÃO") . "\n";

// Últimas 3 linhas do operacional
$lines = explode("\n", $opCode);
$total = count($lines);
echo "Últimas 3 linhas do operacional:\n";
for ($i = max(0, $total - 3); $i < $total; $i++) {
    echo ($i+1) . ": " . trim($lines[$i]) . "\n";
}
