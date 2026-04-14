<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
try {
    $pdo = db();

    // Buscar caso do Pedro David
    $stmt = $pdo->query("SELECT c.id, c.title, c.status, c.client_id, cl.name FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id WHERE cl.name LIKE '%Pedro David%'");
    $cases = $stmt->fetchAll();
    echo "Casos Pedro David:\n";
    foreach ($cases as $c) {
        echo "  Case #{$c['id']} | {$c['title']} | Status: {$c['status']} | Client: {$c['name']}\n";
    }

    // Buscar lead
    $stmt2 = $pdo->query("SELECT l.id, l.stage, l.client_id, l.linked_case_id, cl.name FROM pipeline_leads l LEFT JOIN clients cl ON cl.id = l.client_id WHERE cl.name LIKE '%Pedro David%'");
    $leads = $stmt2->fetchAll();
    echo "\nLeads Pedro David:\n";
    foreach ($leads as $l) {
        echo "  Lead #{$l['id']} | Stage: {$l['stage']} | Case: {$l['linked_case_id']} | Client: {$l['name']}\n";
    }

    // Buscar docs pendentes
    $stmt3 = $pdo->query("SELECT dp.id, dp.case_id, dp.descricao, dp.status FROM documentos_pendentes dp INNER JOIN cases c ON c.id = dp.case_id LEFT JOIN clients cl ON cl.id = c.client_id WHERE cl.name LIKE '%Pedro David%'");
    $docs = $stmt3->fetchAll();
    echo "\nDocs pendentes Pedro David:\n";
    foreach ($docs as $d) {
        echo "  Doc #{$d['id']} | Case: {$d['case_id']} | Status: {$d['status']} | {$d['descricao']}\n";
    }

    if (isset($_GET['fix'])) {
        // Arquivar o case
        foreach ($cases as $c) {
            if ($c['status'] !== 'arquivado') {
                $pdo->prepare("UPDATE cases SET status = 'arquivado', closed_at = CURDATE(), kanban_oculto = 1, updated_at = NOW() WHERE id = ?")
                    ->execute(array($c['id']));
                echo "\nCase #{$c['id']} => arquivado\n";
            }
        }
        // Finalizar lead
        foreach ($leads as $l) {
            if ($l['stage'] !== 'finalizado' && $l['stage'] !== 'cancelado') {
                $pdo->prepare("UPDATE pipeline_leads SET stage = 'finalizado', updated_at = NOW() WHERE id = ?")
                    ->execute(array($l['id']));
                echo "Lead #{$l['id']} => finalizado\n";
            }
        }
        // Resolver docs pendentes
        foreach ($docs as $d) {
            if ($d['status'] === 'pendente') {
                $pdo->prepare("UPDATE documentos_pendentes SET status = 'cancelado' WHERE id = ?")
                    ->execute(array($d['id']));
                echo "Doc #{$d['id']} => cancelado\n";
            }
        }
        echo "\n=== CORRIGIDO ===\n";
    } else {
        echo "\nAdicione &fix=1 para corrigir.\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
