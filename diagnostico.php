<?php
/**
 * Diagnóstico: docs pendentes + leads sem linked_case_id
 * Acesso: ?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAGNÓSTICO ESPELHAMENTO DOC_FALTANTE ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Docs pendentes
echo "--- 1. DOCUMENTOS PENDENTES ---\n";
$docs = $pdo->query("
    SELECT dp.id, dp.case_id, dp.client_id, dp.lead_id, dp.descricao, dp.status,
           c.status as case_status, c.stage_antes_doc_faltante, c.title as case_title,
           pl.stage as lead_stage, pl.name as lead_name
    FROM documentos_pendentes dp
    LEFT JOIN cases c ON c.id = dp.case_id
    LEFT JOIN pipeline_leads pl ON pl.id = dp.lead_id
    ORDER BY dp.solicitado_em DESC
    LIMIT 30
")->fetchAll();

echo "Total docs (últimos 30):\n";
foreach ($docs as $d) {
    echo sprintf("  Doc #%d [%s] — case_id=%s (status=%s, stage_antes=%s) | lead_id=%s (stage=%s) | client_id=%s | %s\n",
        $d['id'], $d['status'],
        $d['case_id'] ?: 'NULL', $d['case_status'] ?: 'NULL', $d['stage_antes_doc_faltante'] ?: 'NULL',
        $d['lead_id'] ?: 'NULL', $d['lead_stage'] ?: 'NULL',
        $d['client_id'] ?: 'NULL',
        $d['descricao']
    );
}

// 2. Leads sem linked_case_id
echo "\n--- 2. LEADS SEM LINKED_CASE_ID (ativos) ---\n";
$leads = $pdo->query("
    SELECT id, name, stage, client_id, linked_case_id
    FROM pipeline_leads
    WHERE linked_case_id IS NULL
    AND stage NOT IN ('perdido','cancelado','finalizado')
    ORDER BY id DESC
")->fetchAll();
echo "Total: " . count($leads) . "\n";
foreach ($leads as $l) {
    echo sprintf("  Lead #%d — %s (stage=%s, client_id=%s)\n",
        $l['id'], $l['name'], $l['stage'], $l['client_id'] ?: 'NULL');
}

// 3. Inconsistências: lead em doc_faltante mas caso NÃO em doc_faltante
echo "\n--- 3. INCONSISTÊNCIAS: lead em doc_faltante mas caso NÃO ---\n";
$inc1 = $pdo->query("
    SELECT pl.id as lead_id, pl.name, pl.stage, pl.linked_case_id,
           c.id as case_id, c.status as case_status, c.title
    FROM pipeline_leads pl
    LEFT JOIN cases c ON c.id = pl.linked_case_id
    WHERE pl.stage = 'doc_faltante'
    AND (c.id IS NULL OR c.status != 'doc_faltante')
")->fetchAll();
echo "Total: " . count($inc1) . "\n";
foreach ($inc1 as $i) {
    echo sprintf("  Lead #%d (%s) → Case #%s (status=%s)\n",
        $i['lead_id'], $i['name'],
        $i['case_id'] ?: 'NULL/sem linked', $i['case_status'] ?: 'NULL');
}

// 4. Inconsistências inversas: caso em doc_faltante mas lead NÃO
echo "\n--- 4. INCONSISTÊNCIAS: caso em doc_faltante mas lead NÃO ---\n";
$inc2 = $pdo->query("
    SELECT c.id as case_id, c.title, c.status, c.stage_antes_doc_faltante,
           cl.name as client_name,
           pl.id as lead_id, pl.stage as lead_stage
    FROM cases c
    LEFT JOIN clients cl ON cl.id = c.client_id
    LEFT JOIN pipeline_leads pl ON pl.linked_case_id = c.id
    WHERE c.status = 'doc_faltante'
")->fetchAll();
echo "Total casos em doc_faltante: " . count($inc2) . "\n";
foreach ($inc2 as $i) {
    $leadInfo = $i['lead_id'] ? "Lead #" . $i['lead_id'] . " (stage=" . $i['lead_stage'] . ")" : "SEM LEAD VINCULADO";
    echo sprintf("  Case #%d (%s) stage_antes=%s → %s\n",
        $i['case_id'], $i['client_name'] ?: $i['title'],
        $i['stage_antes_doc_faltante'] ?: 'NULL',
        $leadInfo);
}

// 5. Leads em doc_faltante (completo)
echo "\n--- 5. LEADS EM DOC_FALTANTE ---\n";
$docLeads = $pdo->query("
    SELECT pl.id, pl.name, pl.stage, pl.linked_case_id, pl.client_id,
           pl.doc_faltante_motivo, pl.stage_antes_doc_faltante
    FROM pipeline_leads pl
    WHERE pl.stage = 'doc_faltante'
")->fetchAll();
echo "Total: " . count($docLeads) . "\n";
foreach ($docLeads as $l) {
    echo sprintf("  Lead #%d — %s | linked_case=%s | client=%s | motivo=%s | stage_antes=%s\n",
        $l['id'], $l['name'],
        $l['linked_case_id'] ?: 'NULL', $l['client_id'] ?: 'NULL',
        $l['doc_faltante_motivo'] ?: 'NULL',
        $l['stage_antes_doc_faltante'] ?: 'NULL');
}

echo "\n=== FIM DIAGNÓSTICO ===\n";
