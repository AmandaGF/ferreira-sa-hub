<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_cnj_parser.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diagnostico: quantos cases o backfill de CNJ vai tocar ===\n\n";

// Casos com CNJ preenchido (base do universo)
$comCnj = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE case_number IS NOT NULL AND case_number <> ''")->fetchColumn();
echo "Cases com case_number preenchido: $comCnj\n\n";

// Casos que TERIAM algo pra preencher (pelo menos um dos 3 campos vazio)
$stmt = $pdo->query("
    SELECT id, case_number, comarca_uf, comarca, regional
    FROM cases
    WHERE case_number IS NOT NULL AND case_number <> ''
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = count($rows);
$vaziosUf = 0; $vaziosComarca = 0; $vaziosRegional = 0;
$cnjInvalido = 0;
$parserFalhou = 0;
$tocariaAlgo = 0;
$exemplos = array();

foreach ($rows as $r) {
    $p = parse_cnj($r['case_number']);
    if (!$p['ok']) {
        // Ou CNJ invalido, ou parser nao conseguiu UF
        if (!empty($p['erro']) && strpos($p['erro'], 'digitos') !== false) $cnjInvalido++;
        else $parserFalhou++;
        continue;
    }
    $mudaUf = (empty($r['comarca_uf']) && !empty($p['uf']));
    $mudaCom = (empty($r['comarca']) && !empty($p['comarca']));
    // Regional: extrai o que tem entre parenteses na comarca (mesma logica do JS)
    $regParsed = '';
    if (!empty($p['comarca']) && preg_match('/\((?:Regional (?:de |da |do )|1ª Vara|2ª Vara)?(.+?)\)/', $p['comarca'], $m)) {
        $regParsed = trim($m[1]);
    }
    $mudaReg = (empty($r['regional']) && $regParsed !== '');

    if ($mudaUf) $vaziosUf++;
    if ($mudaCom) $vaziosComarca++;
    if ($mudaReg) $vaziosRegional++;

    if ($mudaUf || $mudaCom || $mudaReg) {
        $tocariaAlgo++;
        if (count($exemplos) < 10) {
            $exemplos[] = "id=$r[id] cnj=$r[case_number] -> UF={$p['uf']}, Comarca={$p['comarca']}, Regional=$regParsed";
        }
    }
}

echo "Total analisado: $total\n";
echo "CNJ invalido (nao tem 20 digitos): $cnjInvalido\n";
echo "Parser falhou (formato desconhecido): $parserFalhou\n\n";

echo "Se rodar o backfill, tocaria em $tocariaAlgo cases:\n";
echo "  - Preencheria UF em:       $vaziosUf\n";
echo "  - Preencheria Comarca em:  $vaziosComarca (so TJRJ)\n";
echo "  - Preencheria Regional em: $vaziosRegional (so TJRJ)\n\n";

echo "Primeiros 10 exemplos de casos que seriam afetados:\n";
foreach ($exemplos as $e) echo "  $e\n";
