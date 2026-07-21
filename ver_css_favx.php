<?php
/** Mostra a regra .fav-bar-x que esta REALMENTE no servidor. Temporario. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$f = __DIR__ . '/templates/sidebar.php';
echo "sidebar.php modificado em: " . date('d/m/Y H:i:s', (int)@filemtime($f)) . "\n\n";
echo "--- regras .fav-bar-x no arquivo do servidor ---\n";
foreach (explode("\n", (string)@file_get_contents($f)) as $i => $ln) {
    if (strpos($ln, 'fav-bar-x') !== false) echo ($i + 1) . ": " . trim($ln) . "\n";
}
echo "\n--- glifo usado no chip ---\n";
foreach (explode("\n", (string)@file_get_contents($f)) as $i => $ln) {
    if (strpos($ln, 'removerFavorito(\\\'') !== false || strpos($ln, 'Remover dos favoritos') !== false) {
        echo ($i + 1) . ": " . trim($ln) . "\n";
    }
}
