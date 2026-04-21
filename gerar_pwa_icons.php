<?php
/**
 * Gera PNGs dedicados pro PWA install (192x192 e 512x512) usando GD.
 * Resolve o problema de ícones muito grandes (logo-sidebar.png 3368x3385)
 * travarem a instalação no Chrome Android.
 *
 * Uso: curl "https://ferreiraesa.com.br/conecta/gerar_pwa_icons.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
if (!extension_loaded('gd')) { exit('Extensão GD não disponível — abortando.'); }

header('Content-Type: text/plain; charset=utf-8');

$outDir = __DIR__ . '/assets/img';
$tamanhos = array(192, 512);

foreach ($tamanhos as $tam) {
    $img = imagecreatetruecolor($tam, $tam);
    imagealphablending($img, true);

    // Fundo petroleum
    $fundo = imagecolorallocate($img, 5, 34, 40); // #052228
    imagefilledrectangle($img, 0, 0, $tam, $tam, $fundo);

    // Cor do texto
    $rosa = imagecolorallocate($img, 215, 171, 144); // #d7ab90
    $branco = imagecolorallocate($img, 255, 255, 255);
    $dourado = imagecolorallocate($img, 184, 115, 51); // #B87333

    // Linha decorativa
    imagefilledrectangle($img, (int)($tam*0.3), (int)($tam*0.54), (int)($tam*0.7), (int)($tam*0.555), $dourado);

    // Texto "F&S" e "HUB" — escala por tamanho
    $escala = $tam / 512;

    // Usa font built-in + scale via imagestring truncado OU gd escalado manualmente
    // Como fallback simples sem TTF, usa imagestring (fonte 5 = máxima embutida) e escala
    // Mas pra boa aparência em 192/512, vamos desenhar via imagefilledpolygon/retângulos simulando letras

    // Alternativa: imagettftext se tiver fonte TTF — a maioria tem DejaVu
    $fontPaths = array(
        '/usr/share/fonts/truetype/dejavu/DejaVu-Sans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/dejavu-sans/DejaVuSans-Bold.ttf',
        'C:/Windows/Fonts/arialbd.ttf',
    );
    $font = null;
    foreach ($fontPaths as $fp) { if (file_exists($fp)) { $font = $fp; break; } }

    if ($font) {
        $fs1 = 150 * $escala;
        $fs2 = 110 * $escala;
        $bb1 = imagettfbbox($fs1, 0, $font, 'F&S');
        $w1 = $bb1[4] - $bb1[0];
        imagettftext($img, $fs1, 0, (int)(($tam - $w1) / 2), (int)($tam * 0.45), $rosa, $font, 'F&S');

        $bb2 = imagettfbbox($fs2, 0, $font, 'HUB');
        $w2 = $bb2[4] - $bb2[0];
        imagettftext($img, $fs2, 0, (int)(($tam - $w2) / 2), (int)($tam * 0.73), $branco, $font, 'HUB');
    } else {
        // Fallback sem TTF: letras grandes com imagestring escalado
        $tmp = imagecreatetruecolor(5, 9);
        // Placeholder simples — se não tem TTF, pelo menos fundo fica bonito
        $txt1Size = 5;
        $txtW1 = imagefontwidth($txt1Size) * 3;
        $txtH1 = imagefontheight($txt1Size);
        imagestring($img, $txt1Size, (int)(($tam - $txtW1) / 2), (int)($tam * 0.35), 'F&S', $rosa);
        imagestring($img, $txt1Size, (int)(($tam - imagefontwidth($txt1Size) * 3) / 2), (int)($tam * 0.60), 'HUB', $branco);
    }

    $outPath = $outDir . '/pwa-' . $tam . '.png';
    imagepng($img, $outPath, 9);
    imagedestroy($img);

    echo "[OK] $outPath (" . filesize($outPath) . " bytes)\n";
}

echo "\nPronto. Agora atualize o manifest.json pra apontar pra pwa-192.png e pwa-512.png.\n";
