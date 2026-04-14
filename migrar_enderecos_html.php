<?php
/**
 * Migração de endereços do Novajus — parse HTML direto
 * Lê o arquivo HTML exportado do Novajus e atualiza endereços no banco
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

function extractTextFromNode($node, &$text) {
    if ($node->nodeType === XML_TEXT_NODE) {
        $t = trim($node->textContent);
        if ($t !== '') $text .= $t . "\n";
    }
    if ($node->hasChildNodes()) {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $bg = $child->getAttribute('bgcolor');
                $cls = $child->getAttribute('class');
                if ($bg === '#B8B8B8' || $cls === 's1') continue;
            }
            extractTextFromNode($child, $text);
        }
    }
}

function parseBlocoEndereco($lines) {
    if (count($lines) < 2) return null;
    $nome = $lines[0];
    if (preg_match('/^\d/', $nome) || preg_match('/^(Rua|Avenida|Estrada|Travessa|Beco|Servidão|Viela|Caminho|Rodovia|Largo|Praça|Alameda)/ui', $nome)) return null;
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 80) return null;

    $lastLine = $lines[count($lines) - 1];
    $cep = '';
    if (preg_match('/(\d{5}-\d{3})/', $lastLine, $m)) $cep = $m[1];
    $uf = '';
    if (preg_match('/\b([A-Z]{2})\s+\d{5}-\d{3}/', $lastLine, $m)) $uf = $m[1];
    if (!$uf && preg_match('/\b([A-Z]{2})\s+Brasil/', $lastLine, $m)) $uf = $m[1];

    $origLast = $lines[count($lines) - 1];
    $origLast = preg_replace('/\s*' . preg_quote($cep, '/') . '\s*/', '', $origLast);
    $origLast = preg_replace('/\s*Brasil\s*/', '', $origLast);
    if ($uf) $origLast = preg_replace('/\s*\b' . preg_quote($uf, '/') . '\b/', '', $origLast, 1);
    $origLast = trim($origLast);

    $parts = preg_split('/\s{2,}/', $origLast);
    $parts = array_values(array_filter(array_map('trim', $parts)));
    $cidade = '';
    $bairro = '';
    if (count($parts) >= 2) {
        $cidade = array_pop($parts);
        $bairro = implode(' ', $parts);
    } elseif (count($parts) === 1) {
        $cidade = $parts[0];
    }

    $logradouro = '';
    $numero = '';
    $complemento = '';

    if (count($lines) >= 3) {
        $logradouro = $lines[1];
        $midLines = array_slice($lines, 2, count($lines) - 3);
        foreach ($midLines as $ml) {
            $ml = trim($ml);
            if (!$numero && preg_match('/^(\d+\s*[A-Za-z]?)\s/', $ml, $nm)) {
                $numero = trim($nm[1]);
                $rest = trim(mb_substr($ml, mb_strlen($nm[0])));
                if ($rest) $complemento = $rest;
            } elseif (!$numero && preg_match('/^(s\/n|S\/N)\b/i', $ml)) {
                $numero = 's/n';
                $rest = trim(preg_replace('/^s\/n\s*/i', '', $ml));
                if ($rest) $complemento = $rest;
            } elseif (!$numero && preg_match('/^\d+$/', $ml)) {
                $numero = $ml;
            } else {
                if ($complemento) $complemento .= ' - ' . $ml;
                else $complemento = $ml;
            }
        }
    } elseif (count($lines) === 2) {
        if (preg_match('/^(\d+\S*)\s+/', $lines[1], $nm)) {
            $numero = $nm[1];
        }
    }

    $street = $logradouro;
    if ($numero && $numero !== '0' && $numero !== '0000') $street .= ', ' . $numero;
    if ($complemento) {
        $complemento = preg_replace('/\s+/', ' ', $complemento);
        $street .= ' - ' . $complemento;
    }
    $street = trim($street, ' ,-');
    if (!$street || !$nome) return null;

    return array('nome' => $nome, 'street' => $street, 'city' => $cidade, 'uf' => $uf, 'zip' => $cep);
}

try {
    $pdo = db();

    // Ler HTML — pode ser upload ou arquivo local
    $htmlFile = __DIR__ . '/enderecos_novajus.html';
    if (!file_exists($htmlFile)) {
        die("Arquivo enderecos_novajus.html não encontrado na pasta conecta/.\nFaça upload do arquivo HTML para o servidor.");
    }

    $html = file_get_contents($htmlFile);
    // Fix encoding: o HTML diz utf-8 mas pode ter BOM
    $html = preg_replace('/^\xEF\xBB\xBF/', '', $html);

    // Extrair texto puro das tags <p> com padding-left:9pt (dados dos contatos)
    // e das tabelas
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);

    // Estratégia: extrair todo o texto visível, separar por blocos
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) die("Não encontrou body no HTML");

    $allText = '';
    extractTextFromNode($body, $allText);

    // Separar em linhas e limpar
    $lines = array_values(array_filter(array_map('trim', explode("\n", $allText)), function($l) {
        return $l !== '' && $l !== 'Brasil' && !preg_match('/^(Nome|Descrição|Logradouro|Número|Complemento|Bairro|Cidade|UF|CEP|País)/u', $l);
    }));

    // Parsear registros: detectar padrão CEP (XXXXX-XXX) para encontrar fim de cada registro
    $registros = array();
    $current = array();

    foreach ($lines as $line) {
        $current[] = $line;

        // Se a linha contém um CEP, este é o fim do registro
        if (preg_match('/\d{5}-\d{3}/', $line)) {
            // Tentar parsear este bloco
            $reg = parseBlocoEndereco($current);
            if ($reg) $registros[] = $reg;
            $current = array();
        }
    }

    echo "=== Migração Endereços Novajus (HTML) ===\n\n";
    echo "Registros extraídos do HTML: " . count($registros) . "\n\n";

    $encontrados = 0;
    $atualizados = 0;
    $jaTemEnd = 0;
    $naoEncontrados = array();

    foreach ($registros as $r) {
        $nomeNorm = mb_strtoupper(trim($r['nome']), 'UTF-8');

        // Buscar por nome exato
        $stmt = $pdo->prepare("SELECT id, name, address_street FROM clients WHERE UPPER(TRIM(name)) = ? LIMIT 1");
        $stmt->execute(array($nomeNorm));
        $client = $stmt->fetch();

        // Fallback: busca parcial (primeiro + último nome)
        if (!$client) {
            $partes = explode(' ', $nomeNorm);
            if (count($partes) >= 2) {
                $primeiro = $partes[0];
                $ultimo = end($partes);
                $stmt2 = $pdo->prepare("SELECT id, name, address_street FROM clients WHERE UPPER(name) LIKE ? AND UPPER(name) LIKE ? LIMIT 1");
                $stmt2->execute(array($primeiro . '%', '%' . $ultimo));
                $client = $stmt2->fetch();
            }
        }

        if (!$client) {
            $naoEncontrados[] = $r['nome'];
            continue;
        }

        $encontrados++;

        if (!empty(trim($client['address_street'] ?? ''))) {
            $jaTemEnd++;
            continue;
        }

        if (isset($_GET['fix'])) {
            $pdo->prepare("UPDATE clients SET address_street = ?, address_city = ?, address_state = ?, address_zip = ? WHERE id = ?")
                ->execute(array($r['street'], $r['city'], $r['uf'], $r['zip'], $client['id']));
        }
        $atualizados++;
        echo "  OK: {$r['nome']} => {$r['street']}, {$r['city']}/{$r['uf']} CEP {$r['zip']}\n";
    }

    echo "\n=== RESULTADO ===\n";
    echo "Total registros HTML: " . count($registros) . "\n";
    echo "Encontrados no banco: {$encontrados}\n";
    echo "Atualizados: {$atualizados}\n";
    echo "Já tinham endereço: {$jaTemEnd}\n";
    echo "Não encontrados: " . count($naoEncontrados) . "\n";

    if (!empty($naoEncontrados) && count($naoEncontrados) <= 50) {
        echo "\nNão encontrados:\n";
        foreach ($naoEncontrados as $n) echo "  - {$n}\n";
    }

    if (!isset($_GET['fix'])) {
        echo "\nAdicione &fix=1 para aplicar.\n";
    } else {
        echo "\n=== MIGRAÇÃO APLICADA ===\n";
    }

} catch (Exception $ex) {
    echo "ERRO: " . $ex->getMessage() . "\n" . $ex->getTraceAsString();
}
