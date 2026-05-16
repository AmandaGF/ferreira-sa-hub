<?php
/**
 * One-shot: otimiza imagens pesadas do site (GD). Idempotente.
 * Mantém .orig de backup na 1ª vez. ?key=fsa-hub-deploy-2026
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$base = __DIR__ . '/assets/img/site/';
$alvos = array(
    'luiz.png'        => array('max' => 420, 'tipo' => 'png'),
    'mapa-brasil.png' => array('max' => 820, 'tipo' => 'png'),
);

foreach ($alvos as $arq => $cfg) {
    $path = $base . $arq;
    if (!is_file($path)) { echo "SKIP {$arq} (não existe)\n"; continue; }
    $antes = filesize($path);
    $orig  = $path . '.orig';
    if (!is_file($orig)) @copy($path, $orig); // backup 1x

    $src = @imagecreatefrompng($path);
    if (!$src) { echo "ERRO {$arq} (não abriu)\n"; continue; }
    $w = imagesx($src); $h = imagesy($src);
    $max = $cfg['max'];
    if (max($w, $h) <= $max) { imagedestroy($src); echo "OK {$arq} já pequeno ({$w}x{$h}, " . round($antes/1024) . "KB)\n"; continue; }

    $r = $w >= $h ? $max / $w : $max / $h;
    $nw = (int)round($w * $r); $nh = (int)round($h * $r);
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagepng($dst, $path, 9);
    imagedestroy($src); imagedestroy($dst);

    clearstatcache();
    $depois = filesize($path);
    echo sprintf("✓ %s: %dx%d → %dx%d | %dKB → %dKB (-%d%%)\n",
        $arq, $w, $h, $nw, $nh, round($antes/1024), round($depois/1024),
        round(100 * (1 - $depois / max(1, $antes))));
}
echo "\nGD: " . (function_exists('imagepng') ? 'disponível' : 'INDISPONÍVEL') . "\n";
echo "Pronto. (backups .orig guardados na 1ª execução)\n";
