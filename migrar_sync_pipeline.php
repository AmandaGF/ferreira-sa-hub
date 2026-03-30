<?php
/**
 * Migração: criar leads no Pipeline para casos órfãos (sem lead vinculado)
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();

echo "=== Sincronizar Operacional → Pipeline ===\n\n";

// Buscar casos ativos que NÃO têm lead vinculado
$cases = $pdo->query(
    "SELECT cs.*, c.name as client_name, c.phone as client_phone, c.email as client_email, c.id as cid
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     WHERE cs.status NOT IN ('concluido','arquivado')
     AND cs.id NOT IN (SELECT linked_case_id FROM pipeline_leads WHERE linked_case_id IS NOT NULL)
     ORDER BY cs.created_at ASC"
)->fetchAll();

echo count($cases) . " caso(s) sem lead no Pipeline.\n\n";

foreach ($cases as $cs) {
    $clientName = $cs['client_name'] ?: $cs['title'];

    // Determinar estágio no Pipeline baseado no status do caso
    $stageMap = array(
        'aguardando_docs' => 'contrato_assinado',
        'em_elaboracao'   => 'pasta_apta',
        'em_andamento'    => 'pasta_apta', // vai ser finalizado quando sincronizar
        'doc_faltante'    => 'doc_faltante',
        'aguardando_prazo'=> 'pasta_apta',
        'distribuido'     => 'pasta_apta',
    );
    $pipelineStage = isset($stageMap[$cs['status']]) ? $stageMap[$cs['status']] : 'contrato_assinado';

    // Se já está em execução ou além, criar como finalizado (já passou do comercial)
    if (in_array($cs['status'], array('em_andamento', 'aguardando_prazo', 'distribuido'))) {
        $pipelineStage = 'pasta_apta';
    }

    // Criar lead
    $pdo->prepare(
        "INSERT INTO pipeline_leads (name, phone, email, source, stage, client_id, linked_case_id, case_type, created_at, updated_at)
         VALUES (?, ?, ?, 'outro', ?, ?, ?, ?, ?, NOW())"
    )->execute(array(
        $clientName,
        $cs['client_phone'] ?: null,
        $cs['client_email'] ?: null,
        $pipelineStage,
        $cs['cid'] ?: null,
        $cs['id'],
        $cs['case_type'] ?: null,
        $cs['created_at']
    ));
    $leadId = (int)$pdo->lastInsertId();

    // Registrar histórico
    $pdo->prepare("INSERT INTO pipeline_history (lead_id, to_stage, notes, created_at) VALUES (?, ?, ?, NOW())")
        ->execute(array($leadId, $pipelineStage, 'Auto: sincronizado do Operacional'));

    echo "  ✓ $clientName → Pipeline ($pipelineStage) [lead #$leadId]\n";
}

echo "\nPronto!\n";
