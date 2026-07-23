<?php
/**
 * Backfill de case_type quando esta generico (outro/outros/NULL/vazio) mas o
 * TITULO revela o tipo ("Nome x <Tipo>"). So grava quando o tipo derivado
 * classifica numa area != OUT (senao mantem como esta). Depois propaga pros
 * leads vinculados. Dry-run por padrao; aplicar com &apply=1.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_areas.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$apply = ($_GET['apply'] ?? '') === '1';

function tipo_do_titulo($title) {
    if (preg_match('/\sx\s+(.+)$/iu', $title, $m)) return trim($m[1]);
    return '';
}
function eh_generico($ct) {
    $n = mb_strtolower(trim((string)$ct), 'UTF-8');
    return ($n === '' || $n === 'outro' || $n === 'outros');
}

echo "=== BACKFILL case_type (a partir do titulo) ===\n";
echo "Modo: " . ($apply ? "APLICAR" : "DRY-RUN (use &apply=1)") . "\n\n";

// ---- PASSO 1: cases ----
$cases = $pdo->query("SELECT id, title, case_type FROM cases WHERE status NOT IN ('arquivado','cancelado')")->fetchAll();
$upC = $pdo->prepare("UPDATE cases SET case_type = ? WHERE id = ?");
$nC = 0;
echo "--- CASES ---\n";
foreach ($cases as $c) {
    if (!eh_generico($c['case_type'])) continue;         // so mexe no generico
    $sug = tipo_do_titulo($c['title']);
    if ($sug === '' || eh_generico($sug)) continue;      // titulo nao ajuda
    $area = fsa_area_from_case_type($sug);
    if ($area['code'] === 'OUT') continue;               // so grava se melhora
    $nC++;
    echo "   #{$c['id']} [" . ($c['case_type'] ?: 'NULL') . "] -> \"$sug\" ({$area['code']}) | {$c['title']}\n";
    if ($apply) $upC->execute(array($sug, $c['id']));
}
echo "   >>> cases " . ($apply ? "corrigidos" : "a corrigir") . ": $nC\n\n";

// ---- PASSO 2: leads vinculados a caso (herda o tipo do caso ja corrigido) ----
// So roda de verdade no apply (depende dos cases atualizados).
$leads = $pdo->query("
    SELECT pl.id, pl.case_type, pl.name, c.case_type AS case_ct, c.title
    FROM pipeline_leads pl
    JOIN cases c ON c.id = pl.linked_case_id
    WHERE pl.stage NOT IN ('finalizado','perdido','arquivado')
")->fetchAll();
$upL = $pdo->prepare("UPDATE pipeline_leads SET case_type = ? WHERE id = ?");
$nL = 0;
echo "--- LEADS (herda do caso vinculado) ---\n";
foreach ($leads as $l) {
    if (!eh_generico($l['case_type'])) continue;
    // usa o case_type do caso (se ja aplicamos, reflete o novo; em dry-run,
    // simula pegando do titulo do caso)
    $novo = $l['case_ct'];
    if (eh_generico($novo)) $novo = tipo_do_titulo($l['title']);
    if ($novo === '' || eh_generico($novo)) continue;
    $area = fsa_area_from_case_type($novo);
    if ($area['code'] === 'OUT') continue;
    $nL++;
    echo "   lead #{$l['id']} [" . ($l['case_type'] ?: 'NULL') . "] -> \"$novo\" ({$area['code']}) | {$l['name']}\n";
    if ($apply) $upL->execute(array($novo, $l['id']));
}
echo "   >>> leads " . ($apply ? "corrigidos" : "a corrigir") . ": $nL\n\n";

echo "=== FIM ===\n";
