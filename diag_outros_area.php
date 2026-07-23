<?php
/**
 * Audit: quem AINDA aparece com badge OUT (area 'Outros') no Kanban/Operacional,
 * usando o classificador real do sistema. Para cases, sugere o tipo correto
 * derivado do titulo ("... x <Tipo>"). Somente leitura.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_areas.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

function tipo_do_titulo($title) {
    // pega o texto depois do ultimo " x " (padrao "Nome x Tipo")
    if (preg_match('/\sx\s+(.+)$/iu', $title, $m)) return trim($m[1]);
    return '';
}

echo "=== AUDIT: badge OUT (area Outros) ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

// ---- LEADS ativos ----
echo "1. pipeline_leads ativos que mostram OUT:\n";
$leads = $pdo->query("
    SELECT id, name, case_type, stage, linked_case_id
    FROM pipeline_leads
    WHERE stage NOT IN ('finalizado','perdido','arquivado')
")->fetchAll();
$nLeadOut = 0;
foreach ($leads as $l) {
    $a = fsa_area_from_case_type($l['case_type']);
    if ($a['code'] !== 'OUT') continue;
    $nLeadOut++;
    echo "   #{$l['id']} | case_type=[" . ($l['case_type'] ?: 'NULL') . "] | stage={$l['stage']} | caso={$l['linked_case_id']} | {$l['name']}\n";
}
echo "   >>> total leads com OUT: $nLeadOut\n\n";

// ---- CASES ativos ----
echo "2. cases ativos que mostram OUT (com sugestao do titulo):\n";
$cases = $pdo->query("
    SELECT id, title, case_type
    FROM cases
    WHERE status NOT IN ('arquivado','cancelado')
")->fetchAll();
$nCaseOut = 0; $nSugerivel = 0;
foreach ($cases as $c) {
    $a = fsa_area_from_case_type($c['case_type']);
    if ($a['code'] !== 'OUT') continue;
    $nCaseOut++;
    $sug = tipo_do_titulo($c['title']);
    $sugArea = $sug ? fsa_area_from_case_type($sug) : null;
    $ok = ($sugArea && $sugArea['code'] !== 'OUT');
    if ($ok) $nSugerivel++;
    echo "   #{$c['id']} | case_type=[" . ($c['case_type'] ?: 'NULL') . "] | {$c['title']}\n";
    echo "        titulo sugere tipo: [" . ($sug ?: '(nada)') . "]"
       . ($sugArea ? " -> area " . $sugArea['code'] : "")
       . ($ok ? "  ✔ corrigivel" : "  — mantem OUT") . "\n";
}
echo "   >>> total cases com OUT: $nCaseOut  (corrigiveis pelo titulo: $nSugerivel)\n\n";

echo "=== FIM ===\n";
