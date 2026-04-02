<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

// Tentar ler o config.php do convivencia_form
$paths = array(
    '/home/ferre315/public_html/convivencia_form/config.php',
    '/home/ferre3151357/public_html/convivencia_form/config.php',
    __DIR__ . '/../convivencia_form/config.php',
    '/home/ferre315/public_html/gastos_pensao/config.php',
    '/home/ferre3151357/public_html/gastos_pensao/config.php',
    __DIR__ . '/../gastos_pensao/config.php',
);

echo "=== Buscando config.php dos formulários ===\n\n";

foreach ($paths as $p) {
    echo "Tentando: $p\n";
    if (file_exists($p)) {
        echo ">>> ENCONTRADO! <<<\n\n";
        echo file_get_contents($p);
        echo "\n\n";
    } else {
        echo "  Não encontrado.\n";
    }
}

// Listar diretórios em public_html
echo "\n=== Diretórios em public_html ===\n";
$publicHtml = dirname(__DIR__);
echo "Base: $publicHtml\n\n";
$dirs = glob($publicHtml . '/*', GLOB_ONLYDIR);
if ($dirs) {
    foreach ($dirs as $d) {
        echo "  " . basename($d) . "/\n";
    }
} else {
    echo "  Nenhum encontrado ou sem permissão\n";
}

echo "\n=== FIM ===\n";
