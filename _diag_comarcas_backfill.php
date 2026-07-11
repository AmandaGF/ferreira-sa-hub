<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_cnj_parser.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diagnostico: cases nao-RJ com comarca preenchida ===\n";
echo "(pode ter vindo do backfill de ontem — Amanda pediu pra desconsiderar\n";
echo "porque a tabela ABJ pode ter erros como o Laguna/Balneario)\n\n";

$rows = $pdo->query("
    SELECT id, case_number, comarca_uf, comarca, title
    FROM cases
    WHERE comarca IS NOT NULL AND comarca <> ''
      AND comarca_uf IS NOT NULL AND comarca_uf <> 'RJ'
    ORDER BY comarca_uf, comarca
")->fetchAll(PDO::FETCH_ASSOC);

$total = count($rows);
echo "Total: $total cases nao-RJ com comarca preenchida\n\n";

// Agrupa por UF
$porUf = array();
foreach ($rows as $r) $porUf[$r['comarca_uf']][] = $r;
foreach ($porUf as $uf => $lista) {
    echo "─── $uf (" . count($lista) . " cases) ───\n";
    foreach ($lista as $r) {
        $cnj = $r['case_number'] ?: '(sem CNJ)';
        printf("  #%-5d | %s | %-25s | %s\n", $r['id'], $cnj, mb_substr($r['comarca'], 0, 25), mb_substr($r['title'], 0, 40));
    }
    echo "\n";
}

echo "─── DECISAO ───\n";
echo "Rode com &limpar=1 se quiser ZERAR a comarca desses $total cases\n";
echo "(UF fica preservada — so a comarca vai pra vazio).\n\n";

if (!empty($_GET['limpar'])) {
    $stmt = $pdo->prepare("UPDATE cases SET comarca = NULL WHERE id = ?");
    foreach ($rows as $r) $stmt->execute([$r['id']]);
    echo "*** LIMPADAS $total comarcas. UF preservada. ***\n";
} else {
    echo "*** DRY RUN — nada foi alterado. Adicione &limpar=1 se decidir zerar. ***\n";
}
