<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Fix: Criar lead para Bruna Sena e espelhar doc_faltante ===\n\n";

$caseId = 620;
$clientId = 368;

// Verificar se já tem lead
$stmt = $pdo->prepare("SELECT id FROM pipeline_leads WHERE client_id = ? OR linked_case_id = ?");
$stmt->execute(array($clientId, $caseId));
$existingLead = $stmt->fetch();

if ($existingLead) {
    echo "Ja tem lead #" . $existingLead['id'] . " — atualizando para doc_faltante\n";
    $leadId = (int)$existingLead['id'];
} else {
    // Criar lead vinculado ao caso
    $pdo->prepare(
        "INSERT INTO pipeline_leads (client_id, linked_case_id, name, stage, case_type, doc_faltante_motivo, stage_antes_doc_faltante, created_at, updated_at)
         VALUES (?, ?, 'BRUNA SENA OLIVEIRA', 'doc_faltante', 'Investigação de Paternidade', 'Falta informações do pai', 'contrato_assinado', NOW(), NOW())"
    )->execute(array($clientId, $caseId));
    $leadId = (int)$pdo->lastInsertId();
    echo "Lead #$leadId criado\n";
}

// Espelhar doc_faltante no lead
$pdo->prepare("UPDATE pipeline_leads SET stage = 'doc_faltante', doc_faltante_motivo = 'Falta informações do pai (nome, CPF, endereço)', stage_antes_doc_faltante = COALESCE(stage_antes_doc_faltante, 'contrato_assinado'), linked_case_id = ?, updated_at = NOW() WHERE id = ?")
    ->execute(array($caseId, $leadId));
echo "Lead #$leadId atualizado para doc_faltante\n";

// Atualizar doc pendente com lead_id
$pdo->prepare("UPDATE documentos_pendentes SET lead_id = ? WHERE case_id = ? AND lead_id IS NULL")
    ->execute(array($leadId, $caseId));
echo "Documentos pendentes atualizados\n";

// Registrar no histórico do pipeline
$pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?, 'auto', 'doc_faltante', 1, 'Fix: caso criado sem lead, espelhamento manual')")
    ->execute(array($leadId));
echo "Historico registrado\n";

echo "\n=== FIM ===\n";
