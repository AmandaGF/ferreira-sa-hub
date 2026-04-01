<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$publicHtml = dirname(__DIR__);
echo "Base: $publicHtml\n\n";

// Listar pastas que contêm "calc"
$dirs = glob($publicHtml . '/*calc*', GLOB_ONLYDIR);
$dirs2 = glob($publicHtml . '/*Calc*', GLOB_ONLYDIR);
$all = array_merge($dirs ?: array(), $dirs2 ?: array());
echo "Pastas calc*:\n";
foreach ($all as $d) { echo "  " . basename($d) . "/\n"; }

// Tentar caminhos possíveis
$paths = array(
    $publicHtml . '/calculadora/index.html',
    $publicHtml . '/calculadora/index.php',
    $publicHtml . '/Calculadora/index.html',
);

foreach ($paths as $p) {
    echo "\nTentando: $p\n";
    if (file_exists($p)) {
        echo "ENCONTRADO! (" . filesize($p) . " bytes)\n\n";
        // Mostrar linhas que contêm "collection" ou "leads_calculadora" ou "pensionForm" ou "then"
        $lines = file($p);
        $total = count($lines);
        echo "Total linhas: $total\n\n";
        for ($i = 0; $i < $total; $i++) {
            $l = $lines[$i];
            if (stripos($l, 'collection') !== false || stripos($l, 'leads_calc') !== false
                || stripos($l, 'pensionForm') !== false || stripos($l, '.then') !== false
                || stripos($l, 'submit') !== false || stripos($l, 'dados') !== false
                || stripos($l, 'btnCalc') !== false || stripos($l, 'valorFinal') !== false
                || stripos($l, 'result') !== false) {
                echo ($i+1) . ": " . rtrim($l) . "\n";
            }
        }
        break;
    } else {
        echo "  Não encontrado\n";
    }
}
