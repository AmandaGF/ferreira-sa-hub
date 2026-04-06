<?php
/**
 * Fix: Arquivar leads de casos que foram absorvidos por merge
 * Rodar uma vez: /conecta/fix_merge_leads.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Fix: Arquivar leads de casos absorvidos por merge ===\n\n";

// Buscar casos arquivados com nota de unificacao
$stmt = $pdo->query("SELECT id, title, notes FROM cases WHERE status = 'arquivado' AND notes LIKE '%Unificado ao caso%'");
$casosAbsorvidos = $stmt->fetchAll();

echo "Casos absorvidos encontrados: " . count($casosAbsorvidos) . "\n\n";

$corrigidos = 0;
foreach ($casosAbsorvidos as $caso) {
    echo "Caso #{$caso['id']} — {$caso['title']}\n";
    echo "  Nota: {$caso['notes']}\n";

    // Buscar leads vinculados a este caso que nao estao arquivados
    $leads = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ? AND stage != 'arquivado'");
    $leads->execute(array($caso['id']));
    $leadsAtivos = $leads->fetchAll();

    if (empty($leadsAtivos)) {
        echo "  Nenhum lead ativo vinculado — OK\n\n";
        continue;
    }

    foreach ($leadsAtivos as $lead) {
        echo "  Lead #{$lead['id']} (stage: {$lead['stage']}) → Arquivando...\n";
        $pdo->prepare("UPDATE pipeline_leads SET stage = 'arquivado', arquivado_em = NOW(), updated_at = NOW() WHERE id = ?")
            ->execute(array($lead['id']));
        $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
            ->execute(array($lead['id'], $lead['stage'], 'arquivado', 1, 'Fix: caso absorvido por merge'));
        $corrigidos++;
    }
    echo "\n";
}

echo "=== CORRIGIDOS: $corrigidos leads arquivados ===\n";
