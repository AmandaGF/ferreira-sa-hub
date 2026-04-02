<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAGNÓSTICO 2 — " . date('Y-m-d H:i:s') . " ===\n\n";

// Query 1
echo "--- QUERY 1: Docs pendentes ativos e seus vínculos ---\n";
$rows = $pdo->query("
    SELECT dp.id, dp.case_id, dp.client_id, dp.lead_id, dp.descricao, dp.status,
           c.status as case_status, c.stage_antes_doc_faltante,
           pl.id as lead_id_encontrado, pl.stage as lead_stage, pl.linked_case_id
    FROM documentos_pendentes dp
    LEFT JOIN cases c ON c.id = dp.case_id
    LEFT JOIN pipeline_leads pl ON pl.id = dp.lead_id
    WHERE dp.status = 'pendente'
")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo sprintf(
        "  Doc #%d | case_id=%s (case_status=%s, stage_antes=%s) | client_id=%s | lead_id=%s (lead_stage=%s, linked_case=%s) | %s\n",
        $r['id'],
        $r['case_id'] ?: 'NULL', $r['case_status'] ?: 'NULL', $r['stage_antes_doc_faltante'] ?: 'NULL',
        $r['client_id'] ?: 'NULL',
        $r['lead_id'] ?: 'NULL', $r['lead_stage'] ?: 'NULL', $r['linked_case_id'] ?: 'NULL',
        $r['descricao']
    );
}

// Query 2
echo "\n--- QUERY 2: Cases em doc_faltante e seus leads vinculados ---\n";
$rows = $pdo->query("
    SELECT c.id as case_id, c.title, c.status, c.client_id,
           pl.id as lead_id, pl.stage, pl.linked_case_id,
           pl.name as lead_name
    FROM cases c
    LEFT JOIN pipeline_leads pl ON pl.linked_case_id = c.id
    WHERE c.status = 'doc_faltante'
")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo sprintf(
        "  Case #%d (%s) client_id=%s | Lead: %s\n",
        $r['case_id'], $r['title'], $r['client_id'],
        $r['lead_id'] ? "#" . $r['lead_id'] . " (" . $r['lead_name'] . ", stage=" . $r['stage'] . ", linked_case=" . $r['linked_case_id'] . ")" : "NENHUM LEAD VINCULADO"
    );
}

// Query 3
echo "\n--- QUERY 3: Cases em doc_faltante SEM lead vinculado ---\n";
$rows = $pdo->query("
    SELECT c.id, c.title, c.client_id, c.status
    FROM cases c
    WHERE c.status = 'doc_faltante'
    AND NOT EXISTS (
        SELECT 1 FROM pipeline_leads pl
        WHERE pl.linked_case_id = c.id
    )
")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo sprintf("  Case #%d — %s (client_id=%s)\n", $r['id'], $r['title'], $r['client_id']);
}

// Bonus: buscar se existe algum lead para esses clientes órfãos (por client_id)
if ($rows) {
    echo "\n--- BONUS: Leads desses clientes (por client_id) ---\n";
    foreach ($rows as $r) {
        $leads = $pdo->prepare("SELECT id, name, stage, linked_case_id, client_id FROM pipeline_leads WHERE client_id = ? ORDER BY id DESC");
        $leads->execute(array($r['client_id']));
        $ls = $leads->fetchAll();
        echo "  Client #" . $r['client_id'] . " (" . $r['title'] . "):\n";
        if (!$ls) {
            echo "    NENHUM LEAD ENCONTRADO\n";
        } else {
            foreach ($ls as $l) {
                echo sprintf("    Lead #%d — %s (stage=%s, linked_case=%s)\n",
                    $l['id'], $l['name'], $l['stage'], $l['linked_case_id'] ?: 'NULL');
            }
        }
    }
}

echo "\n=== FIM ===\n";
