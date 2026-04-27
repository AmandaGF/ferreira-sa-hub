<?php
/**
 * Gera DOCX real (OOXML) do documento renderizado, em vez do MHTML disfarçado
 * de .doc que causava perda de formatação (sem indent, sem espaçamento, sem
 * margem, fonte caía em Times default).
 *
 * Recebe via POST: html (conteúdo da .doc-body), titulo (nome do arquivo).
 * Devolve um .docx válido (ZIP + XMLs OOXML mínimos), com timbrado no topo.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('documentos');

$html = $_POST['html'] ?? '';
$titulo = trim($_POST['titulo'] ?? 'documento');
if ($html === '') { http_response_code(400); exit('html vazio'); }

// ─── Parser HTML → OOXML simplificado ────────────────────────────────
// Cobre: <p>, <h1-h3>, <strong>/<b>, <em>/<i>, <u>, <br>, alinhamento e
// text-indent inline, tabelas simples (<table>/<tr>/<td>), imagens.

libxml_use_internal_errors(true);
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->loadHTML('<?xml encoding="UTF-8"?><div>' . $html . '</div>');
libxml_clear_errors();

// Helper: escape XML
function xx($s) {
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// Detecta alinhamento e indent do style inline do node
function _ppr_from_style($style, $isTitle = false) {
    $jc = '';
    $indent = '';
    $spacing = '';
    if ($style) {
        if (preg_match('/text-align:\s*(left|right|center|justify)/i', $style, $m)) {
            $val = strtolower($m[1]);
            $jc = '<w:jc w:val="' . ($val === 'justify' ? 'both' : $val) . '"/>';
        }
        if (preg_match('/text-indent:\s*([\d.]+)\s*(em|px|pt|cm)/i', $style, $m)) {
            $val = floatval($m[1]);
            $unit = strtolower($m[2]);
            // Conversão pra twips (1 twip = 1/1440 polegada)
            // 1em ≈ 12pt = 240 twips; 1pt = 20 twips; 1cm ≈ 567 twips; 1px ≈ 15 twips
            $tw = 0;
            if ($unit === 'em') $tw = (int)($val * 240);
            elseif ($unit === 'pt') $tw = (int)($val * 20);
            elseif ($unit === 'cm') $tw = (int)($val * 567);
            elseif ($unit === 'px') $tw = (int)($val * 15);
            if ($tw > 0) $indent = '<w:ind w:firstLine="' . $tw . '"/>';
        }
    }
    // Default pra parágrafos: justify + line-spacing 1.5
    if (!$jc) $jc = '<w:jc w:val="both"/>';
    $spacing = '<w:spacing w:line="360" w:lineRule="auto" w:after="160"/>'; // 1.5 line

    if ($isTitle) {
        return '<w:pPr><w:jc w:val="center"/><w:spacing w:before="280" w:after="200"/></w:pPr>';
    }
    return '<w:pPr>' . $spacing . $indent . $jc . '</w:pPr>';
}

// Detecta marcações inline do estilo (font-weight, font-style, etc)
function _rpr_from_style($style, $extra = '') {
    $bits = '';
    if ($style) {
        if (preg_match('/font-weight:\s*(?:bold|700|800|900)/i', $style)) $bits .= '<w:b/>';
        if (preg_match('/font-style:\s*italic/i', $style)) $bits .= '<w:i/>';
        if (preg_match('/text-decoration:.*underline/i', $style)) $bits .= '<w:u w:val="single"/>';
        if (preg_match('/font-size:\s*([\d.]+)\s*(pt|px)/i', $style, $m)) {
            $sz = $m[2] === 'pt' ? floatval($m[1]) : floatval($m[1]) * 0.75;
            $bits .= '<w:sz w:val="' . (int)($sz * 2) . '"/>';
        }
    }
    return '<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="24"/>' . $bits . $extra . '</w:rPr>';
}

// Converte um node (recursivo) gerando uma lista de runs (<w:r>) ou parágrafos
function node_to_runs(DOMNode $node, $herdadoBold = false, $herdadoItal = false, $herdadoUnder = false) {
    $runs = '';
    foreach ($node->childNodes as $c) {
        if ($c->nodeType === XML_TEXT_NODE) {
            $txt = $c->nodeValue;
            if ($txt === '' || $txt === null) continue;
            $rPrInner = '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="24"/>';
            if ($herdadoBold) $rPrInner .= '<w:b/>';
            if ($herdadoItal) $rPrInner .= '<w:i/>';
            if ($herdadoUnder) $rPrInner .= '<w:u w:val="single"/>';
            $runs .= '<w:r><w:rPr>' . $rPrInner . '</w:rPr>'
                  .  '<w:t xml:space="preserve">' . xx($txt) . '</w:t></w:r>';
        } elseif ($c->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($c->nodeName);
            if ($tag === 'br') {
                $runs .= '<w:r><w:br/></w:r>';
            } elseif ($tag === 'b' || $tag === 'strong') {
                $runs .= node_to_runs($c, true, $herdadoItal, $herdadoUnder);
            } elseif ($tag === 'i' || $tag === 'em') {
                $runs .= node_to_runs($c, $herdadoBold, true, $herdadoUnder);
            } elseif ($tag === 'u') {
                $runs .= node_to_runs($c, $herdadoBold, $herdadoItal, true);
            } elseif ($tag === 'span' || $tag === 'a' || $tag === 'font') {
                // Inline style pode ter b/i/u embutido via CSS
                $st = $c->getAttribute('style');
                $b2 = $herdadoBold || preg_match('/font-weight:\s*(?:bold|700|800|900)/i', $st);
                $i2 = $herdadoItal || preg_match('/font-style:\s*italic/i', $st);
                $u2 = $herdadoUnder || preg_match('/text-decoration:.*underline/i', $st);
                $runs .= node_to_runs($c, $b2, $i2, $u2);
            } else {
                // Tag desconhecida inline — só recursar
                $runs .= node_to_runs($c, $herdadoBold, $herdadoItal, $herdadoUnder);
            }
        }
    }
    return $runs;
}

// Processa nodes de bloco (top-level): <p>, <div>, <h1-3>, <table>, <hr>
function block_to_xml(DOMNode $node) {
    $tag = strtolower($node->nodeName);
    $style = $node->getAttribute('style');

    if ($tag === 'p' || $tag === 'div') {
        $cls = $node->getAttribute('class');
        $isTitle = strpos($cls, 'doc-title') !== false || strpos($cls, 'titulo') !== false;
        $runs = node_to_runs($node);
        if (trim(strip_tags($node->textContent)) === '' && strpos($runs, '<w:br') === false) {
            return '<w:p>' . _ppr_from_style($style) . '</w:p>'; // p vazio = espaço
        }
        return '<w:p>' . _ppr_from_style($style, $isTitle) . $runs . '</w:p>';
    }
    if ($tag === 'h1' || $tag === 'h2' || $tag === 'h3') {
        $size = $tag === 'h1' ? 32 : ($tag === 'h2' ? 28 : 26);
        $runs = '';
        foreach ($node->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE) {
                $runs .= '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:sz w:val="' . $size . '"/></w:rPr>'
                      .  '<w:t xml:space="preserve">' . xx($c->nodeValue) . '</w:t></w:r>';
            } else {
                $runs .= node_to_runs($c, true, false, false);
            }
        }
        return '<w:p><w:pPr><w:jc w:val="center"/><w:spacing w:before="280" w:after="200"/></w:pPr>' . $runs . '</w:p>';
    }
    if ($tag === 'table') {
        $rowsXml = '';
        foreach ($node->getElementsByTagName('tr') as $tr) {
            $cellsXml = '';
            foreach ($tr->childNodes as $td) {
                if ($td->nodeType !== XML_ELEMENT_NODE) continue;
                $tdTag = strtolower($td->nodeName);
                if ($tdTag !== 'td' && $tdTag !== 'th') continue;
                $bold = ($tdTag === 'th');
                $tdContent = node_to_runs($td, $bold);
                if (!$tdContent) $tdContent = '<w:r><w:t></w:t></w:r>';
                $cellsXml .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr>'
                           . '<w:p>' . _ppr_from_style($td->getAttribute('style')) . $tdContent . '</w:p>'
                           . '</w:tc>';
            }
            if ($cellsXml) $rowsXml .= '<w:tr>' . $cellsXml . '</w:tr>';
        }
        return '<w:tbl><w:tblPr><w:tblW w:w="5000" w:type="pct"/></w:tblPr>' . $rowsXml . '</w:tbl>';
    }
    if ($tag === 'hr') {
        return '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="B87333"/></w:pBdr></w:pPr></w:p>';
    }
    // Outros: extrair texto puro
    $txt = trim($node->textContent);
    if ($txt !== '') {
        return '<w:p>' . _ppr_from_style($style) . '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="24"/></w:rPr><w:t xml:space="preserve">' . xx($txt) . '</w:t></w:r></w:p>';
    }
    return '';
}

// Itera direto pelos blocos top-level dentro do <div> wrapper
$root = $dom->getElementsByTagName('div')->item(0);
$bodyXml = '';
if ($root) {
    foreach ($root->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $bodyXml .= block_to_xml($child);
        } elseif ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) !== '') {
            $bodyXml .= '<w:p><w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="24"/></w:rPr><w:t xml:space="preserve">' . xx($child->nodeValue) . '</w:t></w:r></w:p>';
        }
    }
}
if (!$bodyXml) $bodyXml = '<w:p/>';

// ─── Cabeçalho (timbrado) ─────────────────────────────────────────────
$logoPath = APP_ROOT . '/assets/img/logo.png';
$temLogo = file_exists($logoPath);
$logoBin = $temLogo ? file_get_contents($logoPath) : '';

// XML do header com imagem (se houver) ou texto
if ($temLogo) {
    list($iw, $ih) = getimagesize($logoPath);
    $maxW_emu = 3800000; // ~10cm em EMU (1cm = 360000 EMU)
    $w_emu = $maxW_emu;
    $h_emu = (int)($ih * ($maxW_emu / $iw));
    $headerXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
        . '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
        . '<wp:extent cx="' . $w_emu . '" cy="' . $h_emu . '"/>'
        . '<wp:docPr id="1" name="Logo"/>'
        . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic><pic:nvPicPr><pic:cNvPr id="1" name="Logo"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="rId10"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $w_emu . '" cy="' . $h_emu . '"/></a:xfrm>'
        . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
        . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>'
        . '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="24" w:space="1" w:color="B87333"/></w:pBdr></w:pPr></w:p>'
        . '</w:hdr>';
} else {
    $headerXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
        . '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:sz w:val="32"/><w:color w:val="052228"/></w:rPr><w:t>FERREIRA &amp; SÁ ADVOCACIA</w:t></w:r></w:p>'
        . '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="24" w:space="1" w:color="B87333"/></w:pBdr></w:pPr></w:p>'
        . '</w:hdr>';
}

// Footer com endereços
$footerXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:p><w:pPr><w:pBdr><w:top w:val="single" w:sz="12" w:space="4" w:color="B87333"/></w:pBdr><w:jc w:val="center"/></w:pPr>'
    . '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:b/><w:sz w:val="18"/></w:rPr><w:t>Rio de Janeiro / RJ &#8195; Barra Mansa / RJ &#8195; Volta Redonda / RJ &#8195; Resende / RJ &#8195; São Paulo / SP</w:t></w:r></w:p>'
    . '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
    . '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="18"/></w:rPr><w:t>(24) 9.9205-0096 &#8195;|&#8195; (11) 2110-5438 &#8195;|&#8195; www.ferreiraesa.com.br &#8195;|&#8195; contato@ferreiraesa.com.br</w:t></w:r></w:p>'
    . '</w:ftr>';

// document.xml — corpo principal com referência ao header/footer
$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<w:body>'
    . $bodyXml
    . '<w:sectPr>'
    . '<w:headerReference w:type="default" r:id="rId4"/>'
    . '<w:footerReference w:type="default" r:id="rId5"/>'
    . '<w:pgSz w:w="11906" w:h="16838"/>' // A4 em twips
    . '<w:pgMar w:top="1417" w:right="1134" w:bottom="1417" w:left="1701" w:header="708" w:footer="708" w:gutter="0"/>'
    . '<w:cols w:space="708"/><w:docGrid w:linePitch="360"/>'
    . '</w:sectPr>'
    . '</w:body></w:document>';

// styles.xml — define defaults globais
$stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:docDefaults>'
    . '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/><w:sz w:val="24"/><w:szCs w:val="24"/><w:lang w:val="pt-BR" w:eastAsia="pt-BR" w:bidi="ar-SA"/></w:rPr></w:rPrDefault>'
    . '<w:pPrDefault><w:pPr><w:spacing w:after="160" w:line="360" w:lineRule="auto"/><w:jc w:val="both"/></w:pPr></w:pPrDefault>'
    . '</w:docDefaults>'
    . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
    . '</w:styles>';

// ─── Montar o ZIP ─────────────────────────────────────────────────────
$tmpFile = tempnam(sys_get_temp_dir(), 'docx_') . '.docx';
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
    http_response_code(500); exit('zip falhou');
}

$zip->addFromString('[Content_Types].xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Default Extension="png" ContentType="image/png"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
    . '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>'
    . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
    . '</Types>');

$zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '</Relationships>');

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>'
    . '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
if ($temLogo) {
    $rels .= '<Relationship Id="rId10" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/>';
}
$rels .= '</Relationships>';

// document.xml.rels precisa ter referencia ao header/footer
$zip->addFromString('word/_rels/document.xml.rels', $rels);

// header.xml.rels — precisa ter rId10 do logo
if ($temLogo) {
    $zip->addFromString('word/_rels/header1.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId10" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.png"/>'
        . '</Relationships>');
}

$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/styles.xml', $stylesXml);
$zip->addFromString('word/header1.xml', $headerXml);
$zip->addFromString('word/footer1.xml', $footerXml);
if ($temLogo) {
    $zip->addFromString('word/media/logo.png', $logoBin);
}

$zip->close();

// ─── Servir arquivo ───────────────────────────────────────────────────
$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $titulo) . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpFile));
header('Cache-Control: no-cache, must-revalidate');
readfile($tmpFile);
unlink($tmpFile);
exit;
