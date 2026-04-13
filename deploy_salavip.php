<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$source = __DIR__ . '/salavip_diagnostico_src.php';
$dest = dirname(__DIR__) . '/salavip_diagnostico.php';

if (!file_exists($source)) {
    die("Fonte não encontrada: $source");
}

$ok = copy($source, $dest);
echo $ok ? "OK: copiado para $dest\n" : "ERRO ao copiar\n";
echo "Tamanho: " . filesize($dest) . " bytes\n";
