<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== SP (agora corrigido) — agrupado por cliente ===\n\n";
$stmt = $pdo->query("
    SELECT c.name AS cliente, cs.id, cs.case_number, cs.title, cs.status
    FROM cases cs
    LEFT JOIN clients c ON c.id = cs.client_id
    WHERE cs.comarca_uf = 'SP'
      AND cs.status IN ('em_andamento','ativo','arquivado')
    ORDER BY c.name, cs.case_number
");
$porCli = array();
foreach ($stmt as $r) {
    $porCli[$r['cliente'] ?? '(sem cliente)'][] = $r;
}
$totalCli = count($porCli); $totalCases = 0;
foreach ($porCli as $c => $lista) {
    echo "── $c (" . count($lista) . " cases) ──\n";
    foreach ($lista as $r) {
        printf("  #%-5d | %s | %s | %s\n", $r['id'], $r['case_number'], $r['status'], mb_substr($r['title'], 0, 55));
        $totalCases++;
    }
    echo "\n";
}
echo "TOTAL: $totalCases cases em $totalCli clientes\n";

echo "\n=== PROCURA PB (.8.15.) e PE (.8.17.) — via CNJ ===\n";
foreach (array('15'=>'PB', '17'=>'PE') as $tr => $uf) {
    echo "\n--- $uf (TR=$tr) ---\n";
    $stmt = $pdo->query("
        SELECT id, case_number, comarca_uf, comarca, title, status
        FROM cases
        WHERE status IN ('em_andamento','ativo','arquivado','concluido')
          AND case_number IS NOT NULL AND case_number <> ''
    ");
    $found = 0;
    foreach ($stmt as $r) {
        $d = preg_replace('/\D/', '', (string)$r['case_number']);
        if (strlen($d) !== 20) continue;
        if (substr($d, 13, 3) !== '8' . $tr) continue;
        $found++;
        printf("  #%-5d | %s | banco_uf=%-3s | status=%s | %s\n",
            $r['id'], $r['case_number'], $r['comarca_uf'] ?: '-', $r['status'], mb_substr($r['title'], 0, 45));
    }
    if ($found === 0) echo "  Nenhum processo com CNJ .8.$tr.\n";
}
