<?php
/**
 * Parser: extrai enderecos do HTML Novajus e salva resultado em arquivo texto
 * Executar: php parse_html_enderecos.php
 * Resultado: parse_resultado.txt
 */

$html = file_get_contents('C:/Users/User/Desktop/Endereços de contatos.html');

// Split by </p> to get text blocks
$segments = preg_split('/<\/p>/', $html);
$lines = array();
foreach ($segments as $seg) {
    $text = strip_tags($seg);
    $text = str_replace(array("\xC2\xA0", "\r", "\n"), array(' ', '', ''), $text);
    $text = trim($text);
    if ($text !== '' && $text !== ' ') {
        $lines[] = $text;
    }
}

// Find blocks: each record starts with a name (all caps or title case)
// followed by street, then number+neighborhood, then city+UF+CEP
$records = array();
$current = array();
$i = 0;
$total = count($lines);

// Skip header lines (Nome / Razão social, Descrição, Logradouro, etc.)
$skipHeaders = array('Nome / Razão social', 'Descrição', 'Logradouro', 'Número', 'Complemento', 'Bairro', 'Cidade', 'UF', 'CEP', 'País', 'Endereços de contatos');

while ($i < $total) {
    $line = $lines[$i];

    // Skip known headers
    $skip = false;
    foreach ($skipHeaders as $h) {
        if (strpos($line, $h) !== false) {
            $skip = true;
            break;
        }
    }
    if ($skip) { $i++; continue; }

    // Check if this line contains "Brasil" with CEP pattern = end of a record
    if (preg_match('/([A-Z]{2})\s+(\d{5}-\d{3})\s+Brasil/', $line, $m)) {
        // This is the city/UF/CEP line
        $current['city_line'] = $line;
        $current['uf'] = $m[1];
        $current['cep'] = $m[2];
        $records[] = $current;
        $current = array();
        $i++;
        continue;
    }

    // Build current record
    if (empty($current)) {
        $current = array('name' => $line, 'middle_lines' => array());
    } else {
        $current['middle_lines'][] = $line;
    }

    $i++;
}

// Output as PHP array format
$out = fopen(__DIR__ . '/parse_resultado.txt', 'w');
fprintf($out, "Total records: %d\n\n", count($records));

foreach ($records as $idx => $rec) {
    $name = isset($rec['name']) ? $rec['name'] : '???';
    $uf = isset($rec['uf']) ? $rec['uf'] : '??';
    $cep = isset($rec['cep']) ? $rec['cep'] : '?????-???';
    $cityLine = isset($rec['city_line']) ? $rec['city_line'] : '';
    $middles = isset($rec['middle_lines']) ? $rec['middle_lines'] : array();

    // Extract city from city_line (everything before UF)
    $city = '';
    if (preg_match('/^(.+?)\s{2,}[A-Z]{2}\s+\d{5}-\d{3}/', $cityLine, $cm)) {
        $city = trim($cm[1]);
    }

    // Street is usually first middle line
    $street = isset($middles[0]) ? $middles[0] : '';

    // Number + neighborhood + complement from remaining middle lines
    $extra = array();
    for ($j = 1; $j < count($middles); $j++) {
        $extra[] = $middles[$j];
    }

    fprintf($out, "REC[%d]\n", $idx);
    fprintf($out, "  NAME: %s\n", $name);
    fprintf($out, "  STREET: %s\n", $street);
    foreach ($extra as $k => $e) {
        fprintf($out, "  EXTRA[%d]: %s\n", $k, $e);
    }
    fprintf($out, "  CITY_LINE: %s\n", $cityLine);
    fprintf($out, "  CITY: %s\n", $city);
    fprintf($out, "  UF: %s\n", $uf);
    fprintf($out, "  CEP: %s\n", $cep);
    fprintf($out, "\n");
}

fclose($out);
echo "Done. " . count($records) . " records written to parse_resultado.txt\n";
