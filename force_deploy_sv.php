<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$src = __DIR__ . '/salavip_src/';
$dst = dirname(__DIR__) . '/salavip/';

// Copiar TODOS os arquivos (exceto uploads)
function rcopy($s, $d) {
    $n = 0;
    $dir = opendir($s);
    if (!is_dir($d)) mkdir($d, 0755, true);
    while (($f = readdir($dir)) !== false) {
        if ($f === '.' || $f === '..' || $f === 'desktop.ini') continue;
        if ($f === 'uploads') continue;
        if (is_dir("$s/$f")) { $n += rcopy("$s/$f", "$d/$f"); }
        else { copy("$s/$f", "$d/$f"); chmod("$d/$f", 0644); $n++; }
    }
    closedir($dir);
    return $n;
}

$n = rcopy($src, $dst);
echo "Copiados: $n arquivos\n";

// Verificar processo_detalhe.php
$file = $dst . 'pages/processo_detalhe.php';
echo "processo_detalhe.php: " . (file_exists($file) ? filesize($file) . " bytes" : "NÃO EXISTE") . "\n";
echo "Contém 'TODOS visíveis': " . (strpos(file_get_contents($file), 'TODOS') !== false ? 'SIM' : 'NÃO') . "\n";
