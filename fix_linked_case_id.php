<?php
/**
 * Fix: Preencher linked_case_id em pipeline_leads que não têm
 * Acesso: ?key=fsa-hub-deploy-2026
 *
 * Roda uma vez para corrigir dados legados.
 */

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida');
}

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Fix linked_case_id ===\n\n";

// 1. Diagnóstico ANTES
echo "--- ANTES ---\n";
$leads = $pdo->query("
    SELECT pl.id, pl.name, pl.stage, pl.client_id, pl.linked_case_id
    FROM pipeline_leads pl
    WHERE pl.linked_case_id IS NULL
    AND pl.stage NOT IN ('perdido','cancelado','finalizado')
    ORDER BY pl.id DESC
")->fetchAll();
echo "Leads sem linked_case_id (ativos): " . count($leads) . "\n";
foreach ($leads as $l) {
    echo "  Lead #{$l['id']} — {$l['name']} (stage={$l['stage']}, client_id={$l['client_id']})\n";
}

// 2. Corrigir: vincular ao caso mais recente do mesmo client_id
$fixed = 0;
$stmt = $pdo->prepare("
    SELECT pl.id as lead_id, pl.name, pl.client_id, c.id as case_id, c.title as case_title, c.status as case_status
    FROM pipeline_leads pl
    JOIN cases c ON c.client_id = pl.client_id
    WHERE pl.linked_case_id IS NULL
    AND pl.stage NOT IN ('perdido','cancelado','finalizado')
    AND pl.client_id IS NOT NULL
    AND c.status NOT IN ('cancelado')
    ORDER BY pl.id DESC, c.created_at DESC
");
$stmt->execute();
$matches = $stmt->fetchAll();

// Agrupar: um lead pode ter múltiplos casos — pegar o mais recente (primeiro no resultado)
$leadFixed = array();
$update = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = ? WHERE id = ?");
foreach ($matches as $m) {
    if (isset($leadFixed[$m['lead_id']])) continue; // já fixou esse lead
    $update->execute(array($m['case_id'], $m['lead_id']));
    $leadFixed[$m['lead_id']] = true;
    $fixed++;
    echo "  FIXED: Lead #{$m['lead_id']} ({$m['name']}) → Case #{$m['case_id']} ({$m['case_title']}, status={$m['case_status']})\n";
}

echo "\nTotal corrigidos: $fixed\n\n";

// 3. Diagnóstico DEPOIS
echo "--- DEPOIS ---\n";
$leads2 = $pdo->query("
    SELECT pl.id, pl.name, pl.stage, pl.client_id, pl.linked_case_id
    FROM pipeline_leads pl
    WHERE pl.linked_case_id IS NULL
    AND pl.stage NOT IN ('perdido','cancelado','finalizado')
    ORDER BY pl.id DESC
")->fetchAll();
echo "Leads sem linked_case_id (ativos): " . count($leads2) . "\n";
foreach ($leads2 as $l) {
    echo "  Lead #{$l['id']} — {$l['name']} (stage={$l['stage']}, client_id=" . ($l['client_id'] ?: 'NULL') . ")\n";
}

echo "\n=== FIM ===\n";
