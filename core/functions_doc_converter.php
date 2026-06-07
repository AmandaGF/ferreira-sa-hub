<?php
/**
 * Conversor de imagem para PDF (PHP puro, sem libs externas)
 *
 * Cobre JPG, PNG, GIF, WEBP (com fallback via GD pra normalizar pra JPG).
 * Saida: PDF single-page com a imagem ocupando A4 com margem proporcional.
 *
 * Por que nao usar FPDF/TCPDF: evitar 80KB-5MB de dependencia pra essa unica
 * funcionalidade. PDF basico de 1 pagina com imagem inline e' construivel
 * manualmente em ~150 linhas.
 *
 * Usado por: modules/whatsapp/api.php (action salvar_drive) quando cliente
 * manda foto de RG/comprovante e equipe quer salvar como PDF padronizado.
 *
 * Criado 07/06/2026 - Amanda pediu salvar docs do WA na pasta do case.
 */

if (!defined('APP_ROOT')) { require_once __DIR__ . '/config.php'; }

/**
 * Converte imagem (caminho local) em PDF (caminho local).
 *
 * @param string $caminhoImagem Caminho do arquivo de entrada (jpg/png/gif/webp).
 * @param string $caminhoPdfSaida Caminho onde gravar o PDF resultante.
 * @return array ['success' => bool, 'error' => ?, 'paginas' => 1]
 */
function imagem_para_pdf($caminhoImagem, $caminhoPdfSaida) {
    if (!is_file($caminhoImagem)) {
        return array('success' => false, 'error' => 'Imagem nao encontrada: ' . $caminhoImagem);
    }
    if (!function_exists('imagecreatefromjpeg')) {
        return array('success' => false, 'error' => 'Extensao GD nao disponivel no servidor');
    }

    // Normaliza pra JPEG temporario (FPDF/PDF inline suporta JPG nativo facil).
    // Pra PNG/GIF/WEBP, decoda via GD e re-encoda como JPG qualidade 88.
    $info = @getimagesize($caminhoImagem);
    if (!$info) {
        return array('success' => false, 'error' => 'Imagem corrompida ou formato nao reconhecido');
    }
    $largura = (int)$info[0];
    $altura  = (int)$info[1];
    $tipo    = (int)$info[2]; // IMAGETYPE_JPEG=2, PNG=3, GIF=1, WEBP=18

    $tmpJpg = $caminhoImagem;
    $criouTmp = false;

    if ($tipo !== IMAGETYPE_JPEG) {
        $img = null;
        switch ($tipo) {
            case IMAGETYPE_PNG:  $img = @imagecreatefrompng($caminhoImagem);  break;
            case IMAGETYPE_GIF:  $img = @imagecreatefromgif($caminhoImagem);  break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) $img = @imagecreatefromwebp($caminhoImagem);
                break;
            case IMAGETYPE_BMP:
                if (function_exists('imagecreatefrombmp')) $img = @imagecreatefrombmp($caminhoImagem);
                break;
        }
        if (!$img) {
            return array('success' => false, 'error' => 'Falha ao decodificar imagem (tipo ' . $tipo . ')');
        }
        // Achata PNG transparente sobre fundo branco
        if ($tipo === IMAGETYPE_PNG || $tipo === IMAGETYPE_GIF || $tipo === IMAGETYPE_WEBP) {
            $base = imagecreatetruecolor($largura, $altura);
            $branco = imagecolorallocate($base, 255, 255, 255);
            imagefilledrectangle($base, 0, 0, $largura, $altura, $branco);
            imagecopy($base, $img, 0, 0, 0, 0, $largura, $altura);
            imagedestroy($img);
            $img = $base;
        }
        $tmpJpg = sys_get_temp_dir() . '/conv_' . uniqid('', true) . '.jpg';
        imagejpeg($img, $tmpJpg, 88);
        imagedestroy($img);
        $criouTmp = true;
    }

    // Monta PDF manualmente com pagina A4 retrato (595 x 842 pontos).
    // Imagem centrada com margem 36pt e respeitando aspect-ratio original.
    $paginaW = 595.0;
    $paginaH = 842.0;
    $margem  = 36.0;
    $maxW    = $paginaW - 2 * $margem;
    $maxH    = $paginaH - 2 * $margem;

    $ratio = min($maxW / $largura, $maxH / $altura);
    $imgW  = $largura * $ratio;
    $imgH  = $altura  * $ratio;
    $imgX  = ($paginaW - $imgW) / 2;
    $imgY  = ($paginaH - $imgH) / 2;
    // PDF tem origem no canto inferior-esquerdo
    $imgYPdf = $paginaH - $imgY - $imgH;

    $jpgBytes = file_get_contents($tmpJpg);
    if ($criouTmp) { @unlink($tmpJpg); }

    if (!$jpgBytes) {
        return array('success' => false, 'error' => 'Falha ao ler JPG intermediario');
    }

    // Objetos PDF: 1=Catalog, 2=Pages, 3=Page, 4=Resources/XObject, 5=Image, 6=Content stream
    $contentStream = sprintf("q\n%.2f 0 0 %.2f %.2f %.2f cm\n/Im1 Do\nQ\n", $imgW, $imgH, $imgX, $imgYPdf);

    $objetos = array();
    $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objetos[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objetos[3] = sprintf("<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Resources 4 0 R /Contents 6 0 R >>", $paginaW, $paginaH);
    $objetos[4] = "<< /XObject << /Im1 5 0 R >> /ProcSet [/PDF /ImageC] >>";
    $objetos[5] = "<< /Type /XObject /Subtype /Image /Width " . $largura . " /Height " . $altura . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpgBytes) . " >>\nstream\n" . $jpgBytes . "\nendstream";
    $objetos[6] = "<< /Length " . strlen($contentStream) . " >>\nstream\n" . $contentStream . "endstream";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $xref = array();
    foreach ($objetos as $num => $body) {
        $xref[$num] = strlen($pdf);
        $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
    }
    $startXref = strlen($pdf);
    $pdf .= "xref\n0 7\n0000000000 65535 f \n";
    for ($i = 1; $i <= 6; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $xref[$i]);
    }
    $pdf .= "trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n" . $startXref . "\n%%EOF\n";

    $bytes = file_put_contents($caminhoPdfSaida, $pdf);
    if ($bytes === false || $bytes < 100) {
        return array('success' => false, 'error' => 'Falha ao gravar PDF de saida');
    }

    return array('success' => true, 'paginas' => 1, 'bytes' => $bytes);
}

/**
 * Baixa imagem de URL publica + converte pra PDF + retorna caminho do PDF temporario.
 * Caller e' responsavel por dar unlink() depois de usar.
 *
 * @return array ['success' => bool, 'caminho_pdf' => ?, 'error' => ?]
 */
function baixar_e_converter_imagem_para_pdf($urlImagem) {
    if (!filter_var($urlImagem, FILTER_VALIDATE_URL)) {
        return array('success' => false, 'error' => 'URL invalida');
    }
    $tmpImg = sys_get_temp_dir() . '/dl_' . uniqid('', true);
    $ch = curl_init($urlImagem);
    $fp = fopen($tmpImg, 'wb');
    curl_setopt_array($ch, array(
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $ok = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $http !== 200 || !filesize($tmpImg)) {
        @unlink($tmpImg);
        return array('success' => false, 'error' => 'Download falhou (HTTP ' . $http . ')');
    }

    $tmpPdf = sys_get_temp_dir() . '/conv_' . uniqid('', true) . '.pdf';
    $resultado = imagem_para_pdf($tmpImg, $tmpPdf);
    @unlink($tmpImg);

    if (!$resultado['success']) {
        @unlink($tmpPdf);
        return array('success' => false, 'error' => $resultado['error']);
    }

    return array('success' => true, 'caminho_pdf' => $tmpPdf);
}
