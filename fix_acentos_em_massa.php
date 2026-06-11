<?php
/**
 * Amanda 11/06/2026: aplica corrigir_acentos_juridico() em TODOS os
 * andamentos. Sem IA, sem custo. Dry-run por default; &confirma=1 salva.
 * Pode rodar quantas vezes quiser — idempotente.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(600);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_acentos.php';

$pdo = db();
$dryRun = !isset($_GET['confirma']);
$limit  = (int)($_GET['limit'] ?? 0); // 0 = todos
$caseFilter = (int)($_GET['case_id'] ?? 0);

$where = "descricao IS NOT NULL AND descricao != ''";
$params = array();
if ($caseFilter) { $where .= " AND case_id = ?"; $params[] = $caseFilter; }

$sql = "SELECT id, descricao FROM case_andamentos WHERE $where ORDER BY id ASC";
if ($limit > 0) $sql .= " LIMIT $limit";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

echo "=== Correção determinística de acentos ===\n";
echo "MODO: " . ($dryRun ? 'DRY-RUN' : 'CONFIRMA (vai salvar)') . "\n";
echo "Filtro caso: " . ($caseFilter ?: '(todos)') . "\n";
echo "Total a varrer: " . count($rows) . "\n\n";

$alterados = 0;
$exemplos  = array();
$update = $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?");

foreach ($rows as $r) {
    $orig = $r['descricao'];
    $corr = corrigir_acentos_juridico($orig);
    if ($corr === $orig) continue;
    $alterados++;
    if (count($exemplos) < 8) {
        $exemplos[] = array('id'=>$r['id'], 'a'=>mb_substr($orig,0,180,'UTF-8'), 'b'=>mb_substr($corr,0,180,'UTF-8'));
    }
    if (!$dryRun) $update->execute(array($corr, $r['id']));
}

echo "Alterados: $alterados / " . count($rows) . "\n\n";

echo "=== Amostras (primeiros 8 alterados) ===\n";
foreach ($exemplos as $e) {
    echo "AND #{$e['id']}\n";
    echo "  ANTES:  {$e['a']}\n";
    echo "  DEPOIS: {$e['b']}\n\n";
}

if ($dryRun) {
    echo "[DRY-RUN] Nada salvo. Pra aplicar: &confirma=1\n";
} else {
    echo "✓ Tudo salvo.\n";
}
