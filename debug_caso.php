<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/modules/operacional/caso_ver.php';
$lines = file($file);
echo "Linhas com 'voltar_caso' ou 'Prazo' ou 'Compromisso':\n\n";
foreach ($lines as $i => $line) {
    if (stripos($line, 'voltar_caso') !== false || (stripos($line, 'Prazo') !== false && stripos($line, 'href') !== false) || (stripos($line, 'Compromisso') !== false && stripos($line, 'href') !== false)) {
        echo ($i+1) . ": " . trim($line) . "\n\n";
    }
}
