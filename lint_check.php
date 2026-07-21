<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$f = __DIR__ . '/modules/operacional/caso_ver.php';
$code = file_get_contents($f);
$len = strlen($code);
$linhas = substr_count($code, "\n") + 1;
echo "Arquivo: $f\nTamanho: $len bytes / $linhas linhas\n\n";

// Parse test (fatal parse err → aparece aqui)
$tokens = @token_get_all($code);
if ($tokens === false || !is_array($tokens)) {
    echo "❌ FALHA parsing (token_get_all retornou falso)\n";
    error_get_last() && print_r(error_get_last());
} else {
    echo "✓ Parsing OK — " . count($tokens) . " tokens\n";
}

// Contagem simples de <?php / ?" . ">
$open = substr_count($code, '<?php');
$close = substr_count($code, "?" . ">");
echo "<?php: $open · ?" . ">: $close\n";

// Check ultimas linhas
echo "\nUltimas 15 linhas:\n";
$linhas_arr = explode("\n", $code);
foreach (array_slice($linhas_arr, -15) as $i => $l) {
    printf("  %4d | %s\n", count($linhas_arr) - 15 + $i + 1, rtrim($l));
}
