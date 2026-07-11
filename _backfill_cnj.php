<?php
/**
 * Backfill de UF/comarca/regional a partir do CNJ (Amanda 10/07/2026).
 *
 * Preenche APENAS campos vazios. Se o admin ja tinha corrigido manualmente
 * (ex: declinio de competencia: distribuido em Volta Redonda mas foi pra
 * Resende), NAO sobrescreve. Idempotente — pode rodar quantas vezes quiser.
 *
 * Rode com ?dryrun=1 pra ver o que faria SEM gravar.
 * Depois rode sem dryrun pra aplicar.
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_cnj_parser.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$dryrun = !empty($_GET['dryrun']);
echo "=== Backfill CNJ " . ($dryrun ? '[DRY RUN]' : '[APLICANDO]') . " ===\n\n";

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
$tocados = 0;
$cntUf = 0; $cntCom = 0; $cntReg = 0;
$puloInvalido = 0;

foreach ($rows as $r) {
    $p = parse_cnj($r['case_number']);
    if (!$p['ok']) { $puloInvalido++; continue; }

    $novoUf = null;
    $novoCom = null;
    $novoReg = null;

    // UF: so preenche se vazio
    if (empty($r['comarca_uf']) && !empty($p['uf'])) {
        $novoUf = $p['uf'];
        $cntUf++;
    }

    // Comarca: extrai nome antes do parenteses ("Rio de Janeiro (Regional X)" -> "Rio de Janeiro")
    if (empty($r['comarca']) && !empty($p['comarca'])) {
        $novoCom = trim(preg_replace('/\s*\(.*?\)\s*/', '', $p['comarca']));
        if ($novoCom !== '') $cntCom++;
        else $novoCom = null;
    }

    // Regional: so extrai se tem "(Regional de X)" ou "(bairro da Capital)"
    // Nao pega "(Capital)" nem "(1a Vara ...)" — sao 2 casos que NAO sao regional.
    if (empty($r['regional']) && !empty($p['comarca'])) {
        if (preg_match('/\((?:Regional (?:de |da |do ))?(Copacabana|Lagoa|Tijuca|Vila Isabel|Madureira|Jacarepaguá|Bangu|Campo Grande|Santa Cruz|Ilha do Governador|Méier|Barra da Tijuca|Leopoldina|Pavuna|Região Oceânica|Inhomirim|Itaipava|Alcântara)\)/u', $p['comarca'], $m)) {
            $novoReg = $m[1];
            $cntReg++;
        }
    }

    // So dispara UPDATE se pelo menos um campo mudou
    if ($novoUf !== null || $novoCom !== null || $novoReg !== null) {
        $tocados++;
        if (!$dryrun) {
            $stUpd->execute([$novoUf, $novoCom, $novoReg, $r['id']]);
        }
    }
}

echo "Total analisado: $total\n";
echo "CNJ invalido/sem UF: $puloInvalido (pulados)\n";
echo "Cases tocados: $tocados\n";
echo "  UF preenchida:       $cntUf\n";
echo "  Comarca preenchida:  $cntCom\n";
echo "  Regional preenchida: $cntReg\n\n";

if ($dryrun) {
    echo "*** DRY RUN — nada foi gravado. Rode sem ?dryrun=1 pra aplicar. ***\n";
} else {
    echo "*** Backfill APLICADO. ***\n";
}
