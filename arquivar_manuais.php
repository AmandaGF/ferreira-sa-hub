<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Modo: preview (default) ou executar (?executar=1)
$executar = isset($_GET['executar']);

echo "=== " . ($executar ? "ARQUIVANDO" : "PREVIEW") . ": Processos sem lead vinculado ===\n\n";

// Processos que NÃO têm lead vinculado no pipeline (cadastrados manualmente)
// e que estão ativos no Kanban
$sql = "SELECT cs.id, cs.title, cs.case_type, cs.status, cs.case_number, c.name as client_name, cs.created_at
        FROM cases cs
        LEFT JOIN clients c ON c.id = cs.client_id
        WHERE cs.status NOT IN ('concluido','arquivado','cancelado')
        AND cs.id NOT IN (SELECT linked_case_id FROM pipeline_leads WHERE linked_case_id IS NOT NULL)
        ORDER BY cs.created_at DESC";

$rows = $pdo->query($sql)->fetchAll();

echo "Encontrados: " . count($rows) . " processos sem lead vinculado\n\n";

foreach ($rows as $r) {
    echo "Case #{$r['id']} — {$r['title']}\n";
    echo "  Cliente: " . ($r['client_name'] ?: 'N/A') . "\n";
    echo "  Tipo: " . ($r['case_type'] ?: 'N/A') . " | Status: {$r['status']} | Nr: " . ($r['case_number'] ?: 'N/A') . "\n";
    echo "  Criado: {$r['created_at']}\n";

    if ($executar) {
        $pdo->prepare("UPDATE cases SET status = 'arquivado', updated_at = NOW() WHERE id = ?")
            ->execute(array($r['id']));
        echo "  → ARQUIVADO\n";
    }
    echo "\n";
}

if (!$executar) {
    echo "---\nPara executar, adicione &executar=1 na URL\n";
    echo "ATENÇÃO: Revise a lista acima antes de executar!\n";
}

echo "\n=== FIM ===\n";
