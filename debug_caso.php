<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/modules/operacional/caso_ver.php';
$lines = file($file);
echo "Total linhas: " . count($lines) . "\n\n";
echo "Linhas 400-410:\n";
for ($i = 399; $i < min(410, count($lines)); $i++) {
    echo ($i+1) . ": " . $lines[$i];
}
