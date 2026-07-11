<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_cnj_parser.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== SNAPSHOT: dados que o mapa do Brasil mostra AGORA ===\n\n";

$trUfEst = array(
    '01'=>'AC','02'=>'AL','03'=>'AP','04'=>'AM','05'=>'BA','06'=>'CE','07'=>'DF','08'=>'ES',
    '09'=>'GO','10'=>'MA','11'=>'MT','12'=>'MS','13'=>'MG','14'=>'PA','15'=>'PB','16'=>'PR',
    '17'=>'PE','18'=>'PI','19'=>'RJ','20'=>'RN','21'=>'RS','22'=>'RO','23'=>'RR','24'=>'SC',
    '25'=>'SP','26'=>'SE','27'=>'TO',
);

// Mesma logica do dashboard (aba Geral)
$stMapa = $pdo->query("
    SELECT comarca_uf, case_number
    FROM cases
    WHERE status IN ('em_andamento','ativo','arquivado')
");
$porUf = array();
foreach ($stMapa as $r) {
    $uf = strtoupper(trim((string)$r['comarca_uf']));
    if (strlen($uf) !== 2) {
        $digits = preg_replace('/\D/', '', (string)$r['case_number']);
        if (strlen($digits) >= 20 && substr($digits, 13, 1) === '8') {
            $tr = substr($digits, 14, 2);
            $uf = isset($trUfEst[$tr]) ? $trUfEst[$tr] : '';
        } else { $uf = ''; }
    }
    if ($uf !== '') { $porUf[$uf] = ($porUf[$uf] ?? 0) + 1; }
}
arsort($porUf);
$total = array_sum($porUf);

echo "Total processos com UF identificada: $total\n";
echo "Estados cobertos: " . count($porUf) . " / 27\n\n";
foreach ($porUf as $uf => $q) {
    $bar = str_repeat('█', min(60, (int)ceil($q * 60 / max($porUf))));
    printf("  %-3s | %4d | %s\n", $uf, $q, $bar);
}
