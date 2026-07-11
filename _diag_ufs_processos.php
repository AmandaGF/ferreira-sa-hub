<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_cnj_parser.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$trUfEst = array(
    '01'=>'AC','02'=>'AL','03'=>'AP','04'=>'AM','05'=>'BA','06'=>'CE','07'=>'DF','08'=>'ES',
    '09'=>'GO','10'=>'MA','11'=>'MT','12'=>'MS','13'=>'MG','14'=>'PA','15'=>'PB','16'=>'PR',
    '17'=>'PE','18'=>'PI','19'=>'RJ','20'=>'RN','21'=>'RS','22'=>'RO','23'=>'RR','24'=>'SC',
    '25'=>'SP','26'=>'SE','27'=>'TO',
);

echo "=== Investigacao: dados do mapa por UF ===\n\n";
echo "Regra: comarca_uf preenchido no case OU fallback via CNJ (segmento 8=estadual)\n\n";

// Puxa todos os cases ativos
$rows = $pdo->query("
    SELECT id, case_number, comarca_uf, comarca, title, status
    FROM cases
    WHERE status IN ('em_andamento','ativo','arquivado')
    ORDER BY comarca_uf, case_number
")->fetchAll(PDO::FETCH_ASSOC);

$porUf = array();
$inconsistencias = array();
foreach ($rows as $r) {
    $ufBanco = strtoupper(trim((string)$r['comarca_uf']));
    $ufCnj = '';
    $digits = preg_replace('/\D/', '', (string)$r['case_number']);
    if (strlen($digits) === 20 && substr($digits, 13, 1) === '8') {
        $tr = substr($digits, 14, 2);
        $ufCnj = isset($trUfEst[$tr]) ? $trUfEst[$tr] : '';
    }
    // UF final: banco tem prioridade, fallback CNJ
    $ufFinal = strlen($ufBanco) === 2 ? $ufBanco : $ufCnj;
    if ($ufFinal !== '') $porUf[$ufFinal][] = $r;

    // Registra inconsistencia: banco != CNJ (ambos existem)
    if (strlen($ufBanco) === 2 && $ufCnj !== '' && $ufBanco !== $ufCnj) {
        $inconsistencias[] = array($r, $ufBanco, $ufCnj);
    }
}

echo "=== INCONSISTENCIAS (UF do banco != UF do CNJ) ===\n";
echo "Sao casos onde o comarca_uf foi preenchido MANUALMENTE ou por backfill anterior\n";
echo "mas nao bate com o segmento estadual do CNJ. Total: " . count($inconsistencias) . "\n\n";
foreach ($inconsistencias as $inc) {
    list($r, $ufB, $ufC) = $inc;
    printf("  #%-5d | CNJ=%s | banco=%s CNJ=%s | %s\n",
        $r['id'], $r['case_number'], $ufB, $ufC, mb_substr($r['title'], 0, 45));
}

echo "\n=== TODAS AS UFs (contagem final que aparece no mapa) ===\n";
$ordenado = $porUf;
uksort($ordenado, function($a, $b) use ($porUf) {
    return count($porUf[$b]) - count($porUf[$a]);
});
foreach ($ordenado as $uf => $lista) {
    printf("  %-3s | %4d cases\n", $uf, count($lista));
}

echo "\n=== DETALHE UF=SE (Amanda disse que nao tem 40 processos em SE) ===\n";
if (!empty($porUf['SE'])) {
    foreach ($porUf['SE'] as $r) {
        printf("  #%-5d | CNJ=%s | banco_uf=%s | %s\n",
            $r['id'], $r['case_number'] ?: '(sem)', $r['comarca_uf'], mb_substr($r['title'], 0, 50));
    }
}

echo "\n=== PROCURA POR PB e PE ===\n";
echo "PB no mapa: " . (isset($porUf['PB']) ? count($porUf['PB']) . " cases" : "NENHUM") . "\n";
echo "PE no mapa: " . (isset($porUf['PE']) ? count($porUf['PE']) . " cases" : "NENHUM") . "\n\n";
// Ver se tem CNJ de PB ou PE mas sem comarca_uf setado
$stmt = $pdo->query("
    SELECT id, case_number, comarca_uf, comarca, title, status
    FROM cases
    WHERE status IN ('em_andamento','ativo','arquivado')
      AND case_number LIKE '%.8.15.%' OR case_number LIKE '%.8.17.%'
");
foreach ($stmt as $r) {
    $digits = preg_replace('/\D/', '', (string)$r['case_number']);
    if (strlen($digits) !== 20) continue;
    if (substr($digits, 13, 1) !== '8') continue;
    $tr = substr($digits, 14, 2);
    if ($tr !== '15' && $tr !== '17') continue;
    printf("  Achado: #%d | CNJ=%s (TR=%s=%s) | banco_uf=%s | %s\n",
        $r['id'], $r['case_number'], $tr, $trUfEst[$tr], $r['comarca_uf'], mb_substr($r['title'], 0, 40));
}
