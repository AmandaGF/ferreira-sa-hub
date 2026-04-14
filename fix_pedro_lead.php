<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
try {
    $pdo = db();

    // Corrigir leads com stage='arquivado' (stage inválido no Pipeline)
    $stmt = $pdo->query("SELECT id, client_id, stage FROM pipeline_leads WHERE stage = 'arquivado'");
    $leads = $stmt->fetchAll();
    echo "Leads com stage='arquivado': " . count($leads) . "\n";

    foreach ($leads as $l) {
        echo "  Lead #{$l['id']} (client {$l['client_id']}) => finalizado\n";
        $pdo->prepare("UPDATE pipeline_leads SET stage = 'finalizado', updated_at = NOW() WHERE id = ?")
            ->execute(array($l['id']));
    }

    echo "\n=== Corrigido ===\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
