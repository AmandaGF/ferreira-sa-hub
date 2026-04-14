<?php
/**
 * Gerador: lê o HTML de endereços do Novajus e gera o script de migração completo.
 *
 * Executar localmente no terminal:
 *   php gerar_migracao_enderecos.php
 *
 * Isso irá gerar o arquivo migrar_enderecos_completo.php no mesmo diretório.
 *
 * O script gerado poderá ser acessado via:
 *   ?key=fsa-hub-deploy-2026         (modo simulação)
 *   ?key=fsa-hub-deploy-2026&fix=1   (aplica alterações)
 */

$htmlFile = 'C:/Users/User/Desktop/Endereços de contatos.html';
if (!file_exists($htmlFile)) {
    $alt = glob('C:/Users/User/Desktop/*ndere*contato*.html');
    if ($alt) $htmlFile = $alt[0];
    else die("Arquivo HTML não encontrado em: $htmlFile\n");
}

echo "Lendo arquivo: $htmlFile\n";
$html = file_get_contents($htmlFile);
echo "Tamanho: " . strlen($html) . " bytes\n";

// ---- Extrair textos de <p> e <td> tags ----
// Primeiro, normalizar: substituir </p> e </td> por delimitadores
$html2 = str_replace(array('</p>', '</td>'), array("|||BREAK|||", "|||BREAK|||"), $html);
$segments = explode("|||BREAK|||", $html2);

$lines = array();
foreach ($segments as $seg) {
    $text = strip_tags($seg);
    $text = str_replace(array("\xC2\xA0", "\r", "\n", "\t"), array(' ', '', '', ' '), $text);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    if ($text !== '' && $text !== ' ' && strlen($text) > 0) {
        $lines[] = $text;
    }
}

echo "Total linhas extraídas: " . count($lines) . "\n";

// ---- Agrupar em registros ----
// Headers de tabela a ignorar
$skipExact = array(
    'Nome / Razão social', 'Descrição', 'Logradouro', 'Número',
    'Complemento', 'Bairro', 'Cidade', 'UF', 'CEP', 'País',
    'Endereços de contatos'
);

$records = array();
$buffer = array();

foreach ($lines as $line) {
    // Pular headers de tabela
    if (in_array($line, $skipExact)) {
        continue;
    }

    // Detectar linha final: contém UF + CEP + Brasil (ou apenas UF + Brasil)
    // Padrões possíveis:
    //   "Cidade  UF  XXXXX-XXX  Brasil"
    //   "Bairro  Cidade  UF  XXXXX-XXX  Brasil"
    //   "Número  Bairro  Cidade  UF  XXXXX-XXX  Brasil"
    //   Também pode ter " Brasil Bairro" no final (dados corrompidos no PDF)
    if (preg_match('/([A-Z]{2})\s+(\d{5}-\d{3})\s+Brasil/', $line, $m) && count($buffer) > 0) {
        $buffer[] = $line;
        $records[] = $buffer;
        $buffer = array();
        continue;
    }
    // Sem CEP mas com UF + Brasil
    if (preg_match('/([A-Z]{2})\s+Brasil\s*$/', $line, $m) && count($buffer) > 1) {
        $buffer[] = $line;
        $records[] = $buffer;
        $buffer = array();
        continue;
    }
    // Caso especial: "RJ  XXXXX-XXX  Brasil" no início da linha (split de linha anterior)
    if (preg_match('/^([A-Z]{2})\s+(\d{5}-\d{3})\s+Brasil/', $line, $m) && count($buffer) > 1) {
        $buffer[] = $line;
        $records[] = $buffer;
        $buffer = array();
        continue;
    }

    $buffer[] = $line;
}

echo "Total registros encontrados: " . count($records) . "\n";

// ---- Parsear cada registro ----
$parsed = array();

foreach ($records as $idx => $rec) {
    // Encontrar a linha que contém Brasil (última ou penúltima)
    $brasilLineIdx = -1;
    for ($i = count($rec) - 1; $i >= 0; $i--) {
        if (preg_match('/Brasil/', $rec[$i])) {
            $brasilLineIdx = $i;
            break;
        }
    }
    if ($brasilLineIdx < 0) continue;

    $lastLine = $rec[$brasilLineIdx];

    // Extrair UF e CEP
    $uf = '';
    $cep = '';
    $city = '';
    $bairro_from_last = '';

    // Caso: "Número  Bairro  Cidade  UF  CEP  Brasil"
    // ou:   "Bairro  Cidade  UF  CEP  Brasil"
    // ou:   "Cidade  UF  CEP  Brasil"
    if (preg_match('/^(.+?)\s{2,}([A-Z]{2})\s+(\d{5}-\d{3})\s+Brasil/', $lastLine, $m)) {
        $beforeUF = trim($m[1]);
        $uf = $m[2];
        $cep = $m[3];
    } elseif (preg_match('/^(.+?)\s{2,}([A-Z]{2})\s+Brasil/', $lastLine, $m)) {
        $beforeUF = trim($m[1]);
        $uf = $m[2];
        $cep = '';
    } elseif (preg_match('/^([A-Z]{2})\s+(\d{5}-\d{3})\s+Brasil/', $lastLine, $m)) {
        // Linha que começa com UF (continuação da anterior)
        $uf = $m[1];
        $cep = $m[2];
        $beforeUF = '';
        // Tentar pegar cidade da linha anterior
        if ($brasilLineIdx > 0) {
            $prevLine = $rec[$brasilLineIdx - 1];
            $beforeUF = trim($prevLine);
        }
    } elseif (preg_match('/^([A-Z]{2})\s+Brasil/', $lastLine, $m)) {
        $uf = $m[1];
        $cep = '';
        $beforeUF = '';
        if ($brasilLineIdx > 0) {
            $beforeUF = trim($rec[$brasilLineIdx - 1]);
        }
    } else {
        continue; // Não conseguiu parsear
    }

    // Separar campos do "beforeUF"
    // Pode conter: número + bairro + cidade, ou bairro + cidade, ou só cidade
    if (!empty($beforeUF)) {
        // Primeiro, verificar se começa com número
        $hasNumber = false;
        $numberFromLast = '';
        if (preg_match('/^(\d+|[Ss]\/[Nn]|s\/n|[Ss]\/N|S\/N)\s{2,}(.+)$/', $beforeUF, $numM)) {
            $numberFromLast = trim($numM[1]);
            $beforeUF = trim($numM[2]);
            $hasNumber = true;
        }

        $parts = preg_split('/\s{2,}/', $beforeUF);
        $parts = array_values(array_filter($parts, function($p) { return trim($p) !== ''; }));

        if (count($parts) >= 2) {
            $city = trim(end($parts));
            array_pop($parts);
            $bairro_from_last = trim(implode(' ', $parts));
        } elseif (count($parts) == 1) {
            $city = trim($parts[0]);
        }
    }

    // Nome é a primeira linha
    $name = isset($rec[0]) ? trim($rec[0]) : '';
    if ($name === '' || $name === 'Brasil') continue;

    // Rua é a segunda linha
    $street = isset($rec[1]) ? trim($rec[1]) : '';

    // Linhas intermediárias
    $midLines = array();
    $lastContentLine = ($brasilLineIdx == count($rec) - 1) ? $brasilLineIdx : $brasilLineIdx;
    for ($i = 2; $i < $lastContentLine; $i++) {
        $ml = trim($rec[$i]);
        if ($ml !== '' && $ml !== 'Brasil' && !in_array($ml, $skipExact)) {
            $midLines[] = $ml;
        }
    }

    $number = isset($numberFromLast) && $numberFromLast ? $numberFromLast : '';
    $complement = '';
    $neighborhood = $bairro_from_last;

    if (count($midLines) > 0) {
        $firstMid = $midLines[0];

        // Formato: "número  texto" ou "número texto" ou "número"
        if (preg_match('/^(\d+|[Ss]\/[Nn]|s\/n|S\/N|00\d+)\s{2,}(.*)$/', $firstMid, $nm)) {
            if (!$number) $number = trim($nm[1]);
            $extra = trim($nm[2]);
            if ($extra !== '') {
                if (!$neighborhood) $neighborhood = $extra;
                else $complement = $extra;
            }
        } elseif (preg_match('/^(\d+|[Ss]\/[Nn]|s\/n|S\/N)\s+(.+)$/', $firstMid, $nm)) {
            if (!$number) $number = trim($nm[1]);
            $complement = trim($nm[2]);
        } elseif (preg_match('/^(\d+|[Ss]\/[Nn]|s\/n|S\/N|00\d+|Casa \d+)$/', $firstMid)) {
            if (!$number) $number = trim($firstMid);
        } elseif (preg_match('/^\d+/', $firstMid) && !$number) {
            // Começa com número seguido de algo
            if (preg_match('/^(\d+)\s+(.+)$/', $firstMid, $nm)) {
                $number = trim($nm[1]);
                $complement = trim($nm[2]);
            } else {
                $number = trim($firstMid);
            }
        } else {
            // Não é número - pode ser bairro continuação ou complemento
            if (strpos($firstMid, '(') === 0) {
                // Continuação de bairro: "(Cunhambebe)", "(Tamoios)"
                $neighborhood .= ' ' . $firstMid;
            } else {
                $complement = $firstMid;
            }
        }

        // Remaining mid lines
        for ($j = 1; $j < count($midLines); $j++) {
            $ml = trim($midLines[$j]);
            if ($ml === '' || $ml === 'Brasil') continue;

            // Continuação de bairro com parênteses
            if (strpos($ml, '(') === 0) {
                $neighborhood .= ' ' . $ml;
                continue;
            }

            if ($complement === '') {
                $complement = $ml;
            } else {
                $complement .= ', ' . $ml;
            }
        }
    }

    // Limpar número de zeros à esquerda (00447 -> 447) mas manter "0" e "02"
    if ($number !== '' && preg_match('/^0+(\d{2,})$/', $number, $zm)) {
        $number = $zm[1];
    }
    // Remover ponto do número (1.509 -> 1509)
    $number = str_replace('.', '', $number);

    // Montar logradouro completo: "Rua X, 123 - Complemento - Bairro"
    $fullStreet = $street;
    if ($number !== '') {
        $fullStreet .= ', ' . $number;
    }
    if ($complement !== '') {
        $fullStreet .= ' - ' . $complement;
    }
    if ($neighborhood !== '') {
        $fullStreet .= ' - ' . trim($neighborhood);
    }

    // Limpar CEP
    if ($cep === '00000-000') $cep = '';

    // Limpar cidade e bairro de restos
    $city = preg_replace('/\s+/', ' ', trim($city));
    $fullStreet = preg_replace('/\s+/', ' ', trim($fullStreet));

    // Pular registros vazios ou de empresa
    if ($name === '' || $street === '') continue;

    $parsed[] = array(
        'name' => $name,
        'street' => $fullStreet,
        'city' => $city,
        'uf' => $uf,
        'cep' => $cep
    );
}

echo "Total registros parseados: " . count($parsed) . "\n";

// ---- Gerar script PHP de migração ----
$outputFile = __DIR__ . '/migrar_enderecos_completo.php';
$fp = fopen($outputFile, 'w');

fwrite($fp, '<?php
if (($_GET[\'key\'] ?? \'\') !== \'fsa-hub-deploy-2026\') die(\'Acesso negado.\');
header(\'Content-Type: text/plain; charset=utf-8\');
require_once __DIR__ . \'/core/config.php\';
require_once __DIR__ . \'/core/database.php\';
try {
    $pdo = db();

    $enderecos = array(
');

foreach ($parsed as $p) {
    $n = str_replace("'", "\\'", $p['name']);
    $s = str_replace("'", "\\'", $p['street']);
    $c = str_replace("'", "\\'", $p['city']);
    $u = $p['uf'];
    $z = $p['cep'];
    fwrite($fp, "        array('$n', '$s', '$c', '$u', '$z'),\n");
}

fwrite($fp, '    );

    $total = count($enderecos);
    $encontrados = 0;
    $atualizados = 0;
    $jaTemEnd = 0;
    $naoEncontrados = array();

    foreach ($enderecos as $e) {
        $nome = $e[0];
        $street = $e[1];
        $city = $e[2];
        $uf = $e[3];
        $zip = $e[4];

        // Normalizar nome para busca
        $nomeNorm = mb_strtoupper(trim($nome), \'UTF-8\');

        // Buscar cliente por nome (case-insensitive)
        $stmt = $pdo->prepare("SELECT id, name, address_street, address_city, address_state, address_zip FROM clients WHERE UPPER(TRIM(name)) = ? LIMIT 1");
        $stmt->execute(array($nomeNorm));
        $client = $stmt->fetch();

        if (!$client) {
            // Tentativa 2: fuzzy com primeiro + último nome
            $words = explode(\' \', trim($nome));
            if (count($words) >= 2) {
                $first = $words[0];
                $last = end($words);
                $stmt2 = $pdo->prepare("SELECT id, name, address_street FROM clients WHERE UPPER(name) LIKE ? AND UPPER(name) LIKE ? LIMIT 1");
                $stmt2->execute(array(\'%\' . mb_strtoupper($first, \'UTF-8\') . \'%\', \'%\' . mb_strtoupper($last, \'UTF-8\') . \'%\'));
                $client = $stmt2->fetch();
            }
            if (!$client) {
                $naoEncontrados[] = $nome;
                continue;
            }
        }

        $encontrados++;

        // Só atualizar se address_street está vazio
        if (!empty(trim($client[\'address_street\'] ?? \'\'))) {
            $jaTemEnd++;
            continue;
        }

        if (isset($_GET[\'fix\'])) {
            $pdo->prepare("UPDATE clients SET address_street = ?, address_city = ?, address_state = ?, address_zip = ? WHERE id = ?")
                ->execute(array($street, $city, $uf, $zip, $client[\'id\']));
        }
        $atualizados++;
        echo "  OK: {$nome} => {$street}, {$city}/{$uf} CEP {$zip}\n";
    }

    echo "\n=== RESULTADO ===\n";
    echo "Total registros: {$total}\n";
    echo "Encontrados no banco: {$encontrados}\n";
    echo "Atualizados: {$atualizados}\n";
    echo "Já tinham endereço: {$jaTemEnd}\n";
    echo "Não encontrados: " . count($naoEncontrados) . "\n";

    if (!empty($naoEncontrados)) {
        echo "\nNão encontrados:\n";
        foreach ($naoEncontrados as $n) echo "  - {$n}\n";
    }

    if (!isset($_GET[\'fix\'])) {
        echo "\nAdicione &fix=1 para aplicar as alterações.\n";
    } else {
        echo "\n=== MIGRAÇÃO APLICADA ===\n";
    }
} catch (Exception $ex) {
    echo "ERRO: " . $ex->getMessage() . "\n";
}
');

fclose($fp);
echo "\nScript gerado com sucesso: $outputFile\n";
echo "Total de registros no script: " . count($parsed) . "\n";
echo "\nPróximos passos:\n";
echo "1. Revise o arquivo migrar_enderecos_completo.php\n";
echo "2. Faça deploy no servidor\n";
echo "3. Acesse: ?key=fsa-hub-deploy-2026 (simular)\n";
echo "4. Se tudo OK: ?key=fsa-hub-deploy-2026&fix=1 (aplicar)\n";
