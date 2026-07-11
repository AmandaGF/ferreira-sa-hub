<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Distribuicao geografica dos PROCESSOS (cases) ===\n\n";

// Total geral
$total = (int)$pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn();
$emAndamento = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status IN ('em_andamento','ativo')")->fetchColumn();
$arquivados  = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'arquivado'")->fetchColumn();
$cancelados  = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status = 'cancelado'")->fetchColumn();
echo "Total cases:          $total\n";
echo "  em_andamento/ativo: $emAndamento\n";
echo "  arquivado:          $arquivados\n";
echo "  cancelado:          $cancelados\n\n";

// UF via comarca_uf (mais confiavel se preenchido)
$comUf = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status IN ('em_andamento','ativo','arquivado') AND comarca_uf IS NOT NULL AND comarca_uf <> ''")->fetchColumn();
$semUf = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status IN ('em_andamento','ativo','arquivado') AND (comarca_uf IS NULL OR comarca_uf = '')")->fetchColumn();
echo "Casos em_andamento+arquivado COM comarca_uf: $comUf\n";
echo "Casos em_andamento+arquivado SEM comarca_uf: $semUf (tentar extrair do case_number?)\n\n";

echo "Distribuicao por comarca_uf (em_andamento + arquivado):\n";
$stmt = $pdo->query("
    SELECT UPPER(TRIM(comarca_uf)) uf, COUNT(*) q
    FROM cases
    WHERE status IN ('em_andamento','ativo','arquivado')
      AND comarca_uf IS NOT NULL AND comarca_uf <> ''
    GROUP BY uf ORDER BY q DESC
");
foreach ($stmt as $r) echo "  " . str_pad($r['uf'], 4) . " -> " . $r['q'] . "\n";

echo "\nAgora tentando extrair UF do CNJ (case_number) pra casos SEM comarca_uf:\n";
echo "CNJ estadual formato NNNNNNN-DD.AAAA.8.TR.OOOO onde TR sao 2 digitos do TRIBUNAL.\n";
echo "Codigos TR->UF (segmento 8=estadual):\n";
$trUf = array(
    '01'=>'AC','02'=>'AL','03'=>'AP','04'=>'AM','05'=>'BA','06'=>'CE','07'=>'DF','08'=>'ES',
    '09'=>'GO','10'=>'MA','11'=>'MT','12'=>'MS','13'=>'MG','14'=>'PA','15'=>'PB','16'=>'PR',
    '17'=>'PE','18'=>'PI','19'=>'RJ','20'=>'RN','21'=>'RS','22'=>'RO','23'=>'RR','24'=>'SC',
    '25'=>'SP','26'=>'SE','27'=>'TO',
);

// Casos SEM comarca_uf mas com CNJ
$stmt = $pdo->query("
    SELECT case_number
    FROM cases
    WHERE status IN ('em_andamento','ativo','arquivado')
      AND (comarca_uf IS NULL OR comarca_uf = '')
      AND case_number IS NOT NULL AND case_number <> ''
");
$viaCnj = array();
foreach ($stmt as $r) {
    $digits = preg_replace('/\D/', '', $r['case_number']);
    if (strlen($digits) < 20) continue;
    // CNJ: NNNNNNN DD AAAA J TR OOOO -> 7+2+4+1+2+4 = 20
    // Segmento em posicao 13, tribunal posicoes 14-15
    $seg = substr($digits, 13, 1);
    $tr  = substr($digits, 14, 2);
    if ($seg === '8' && isset($trUf[$tr])) {
        $uf = $trUf[$tr];
        $viaCnj[$uf] = ($viaCnj[$uf] ?? 0) + 1;
    }
}
arsort($viaCnj);
foreach ($viaCnj as $uf => $q) echo "  " . str_pad($uf, 4) . " -> " . $q . " (extraido do CNJ)\n";
$totalViaCnj = array_sum($viaCnj);
echo "Total via CNJ: $totalViaCnj\n\n";

echo "=== TOTAL COMBINADO (comarca_uf + fallback CNJ) ===\n";
$combinado = array();
$stmt = $pdo->query("SELECT UPPER(TRIM(comarca_uf)) uf, COUNT(*) q FROM cases WHERE status IN ('em_andamento','ativo','arquivado') AND comarca_uf IS NOT NULL AND comarca_uf <> '' GROUP BY uf");
foreach ($stmt as $r) $combinado[$r['uf']] = (int)$r['q'];
foreach ($viaCnj as $uf => $q) $combinado[$uf] = ($combinado[$uf] ?? 0) + $q;
arsort($combinado);
foreach ($combinado as $uf => $q) echo "  " . str_pad($uf, 4) . " -> " . $q . "\n";
echo "Total combinado: " . array_sum($combinado) . "\n";
