<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$dryrun = !empty($_GET['dryrun']);

echo "=== Fix SP/SE invertidos ===\n\n";
echo "Cases onde comarca_uf discorda do CNJ real:\n";
echo "  .8.26. -> SP oficial (nao SE como estava no parser antigo)\n";
echo "  .8.25. -> SE oficial (nao SP como estava no parser antigo)\n\n";

// Casos onde comarca_uf='SE' e CNJ .8.26. — precisa virar SP
$stSE = $pdo->query("
    SELECT id, case_number, comarca_uf FROM cases
    WHERE comarca_uf='SE'
      AND SUBSTRING(REPLACE(REPLACE(case_number,'-',''),'.',''), 14, 3) = '826'
      AND status IN ('em_andamento','ativo','arquivado')
")->fetchAll(PDO::FETCH_ASSOC);
echo "Cases marcados como SE mas na verdade sao SP (.8.26.): " . count($stSE) . "\n";

// Casos onde comarca_uf='SP' e CNJ .8.25. — precisa virar SE
$stSP = $pdo->query("
    SELECT id, case_number, comarca_uf FROM cases
    WHERE comarca_uf='SP'
      AND SUBSTRING(REPLACE(REPLACE(case_number,'-',''),'.',''), 14, 3) = '825'
      AND status IN ('em_andamento','ativo','arquivado')
")->fetchAll(PDO::FETCH_ASSOC);
echo "Cases marcados como SP mas na verdade sao SE (.8.25.): " . count($stSP) . "\n\n";

if (!$dryrun) {
    $upd = $pdo->prepare("UPDATE cases SET comarca_uf = ? WHERE id = ?");
    foreach ($stSE as $r) $upd->execute(['SP', $r['id']]);
    foreach ($stSP as $r) $upd->execute(['SE', $r['id']]);
    echo "*** APLICADO ***\n";
    echo "  " . count($stSE) . " cases: SE -> SP\n";
    echo "  " . count($stSP) . " cases: SP -> SE\n";
} else {
    echo "*** DRY RUN — rode com &limpar=1 pra aplicar ***\n";
}
