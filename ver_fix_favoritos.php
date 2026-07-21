<?php
/** Verifica se o fix dos favoritos chegou no servidor. Temporario. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . '/templates/sidebar.php';
$t = (string)@file_get_contents($f);

echo "templates/sidebar.php\n";
echo "  tamanho: " . strlen($t) . " bytes\n";
echo "  modificado: " . date('d/m/Y H:i:s', (int)@filemtime($f)) . "\n\n";

$checks = array(
    'funcao removerFavorito()'      => 'function removerFavorito(',
    'botao ✕ no chip (fav-bar-x)'   => 'fav-bar-x',
    'purga de modulos mortos'       => "_mortos = ['gerid']",
    'escape de HTML (_favEsc)'      => 'function _favEsc(',
);
$ok = true;
foreach ($checks as $nome => $agulha) {
    $tem = (strpos($t, $agulha) !== false);
    if (!$tem) $ok = false;
    echo ($tem ? "  [OK]   " : "  [FALTA] ") . $nome . "\n";
}

echo "\n" . ($ok ? "RESULTADO: fix ESTA no servidor." : "RESULTADO: fix NAO propagou — rodar deploy de novo.") . "\n";
