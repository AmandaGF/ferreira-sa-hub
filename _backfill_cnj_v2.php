<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_cnj_parser.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$dryrun = !empty($_GET['dryrun']);
echo "=== Backfill CNJ v2 " . ($dryrun ? '[DRY RUN]' : '[APLICANDO]') . " ===\n";
echo "Agora com tabela de comarcas dos 26 UFs (nao so RJ).\n\n";

$rows = $pdo->query("
    SELECT id, case_number, comarca_uf, comarca, regional
    FROM cases
    WHERE case_number IS NOT NULL AND case_number <> ''
")->fetchAll(PDO::FETCH_ASSOC);

$stUpd = $pdo->prepare("UPDATE cases SET comarca_uf = COALESCE(comarca_uf, ?),
                                          comarca    = COALESCE(NULLIF(comarca,''), ?),
                                          regional   = COALESCE(NULLIF(regional,''), ?)
                        WHERE id = ?");

$total = count($rows);
$tocados = 0; $cntUf = 0; $cntCom = 0; $cntReg = 0;
$comarcasPorUf = array();

foreach ($rows as $r) {
    $p = parse_cnj($r['case_number']);
    if (!$p['ok']) continue;

    $novoUf = null; $novoCom = null; $novoReg = null;

    if (empty($r['comarca_uf']) && !empty($p['uf'])) {
        $novoUf = $p['uf']; $cntUf++;
    }
    if (empty($r['comarca']) && !empty($p['comarca'])) {
        $novoCom = trim(preg_replace('/\s*\(.*?\)\s*/', '', $p['comarca']));
        if ($novoCom !== '') { $cntCom++; $comarcasPorUf[$p['uf']] = ($comarcasPorUf[$p['uf']] ?? 0) + 1; }
        else $novoCom = null;
    }
    if (empty($r['regional']) && !empty($p['comarca'])) {
        if (preg_match('/\((?:Regional (?:de |da |do ))?(Copacabana|Lagoa|Tijuca|Vila Isabel|Madureira|Jacarepaguá|Bangu|Campo Grande|Santa Cruz|Ilha do Governador|Méier|Barra da Tijuca|Leopoldina|Pavuna|Região Oceânica|Inhomirim|Itaipava|Alcântara)\)/u', $p['comarca'], $m)) {
            $novoReg = $m[1]; $cntReg++;
        }
    }

    if ($novoUf !== null || $novoCom !== null || $novoReg !== null) {
        $tocados++;
        if (!$dryrun) $stUpd->execute([$novoUf, $novoCom, $novoReg, $r['id']]);
    }
}

echo "Total analisado: $total\n";
echo "Cases tocados: $tocados\n";
echo "  UF preenchida:       $cntUf\n";
echo "  Comarca preenchida:  $cntCom\n";
echo "  Regional preenchida: $cntReg\n\n";

echo "Comarcas preenchidas por UF (delta desta rodada):\n";
arsort($comarcasPorUf);
foreach ($comarcasPorUf as $uf => $q) echo "  $uf -> $q\n";

echo "\n" . ($dryrun ? '*** DRY RUN ***' : '*** APLICADO ***') . "\n";
