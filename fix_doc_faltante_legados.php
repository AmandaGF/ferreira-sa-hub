<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== FIX: Casos doc_faltante sem lead no Pipeline ===\n\n";

// 1. Buscar TODOS os casos com status doc_faltante
$cases = $pdo->query("
    SELECT c.id, c.client_id, c.title, c.case_type, c.status, c.stage_antes_doc_faltante,
           cl.name AS client_name
    FROM cases c
    LEFT JOIN clients cl ON cl.id = c.client_id
    WHERE c.status = 'doc_faltante'
    ORDER BY c.id
")->fetchAll();

echo "Total de casos em doc_faltante: " . count($cases) . "\n\n";

$criados = 0;
$jaTinha = 0;
$atualizados = 0;

foreach ($cases as $cs) {
    $caseId   = (int)$cs['id'];
    $clientId = (int)$cs['client_id'];
    $name     = $cs['client_name'] ?: $cs['title'];

    // Buscar último motivo de doc pendente
    $stmtDoc = $pdo->prepare("SELECT descricao FROM documentos_pendentes WHERE case_id = ? AND status = 'pendente' ORDER BY id DESC LIMIT 1");
    $stmtDoc->execute(array($caseId));
    $motivo = $stmtDoc->fetchColumn() ?: 'Documento(s) faltante(s)';

    // 1) Existe lead vinculado a este case?
    $stmtLead = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE linked_case_id = ? LIMIT 1");
    $stmtLead->execute(array($caseId));
    $lead = $stmtLead->fetch();

    if ($lead) {
        if ($lead['stage'] !== 'doc_faltante') {
            // Atualizar lead existente
            $pdo->prepare("UPDATE pipeline_leads SET stage = 'doc_faltante', stage_antes_doc_faltante = COALESCE(stage_antes_doc_faltante, ?), doc_faltante_motivo = ?, updated_at = NOW() WHERE id = ?")
                ->execute(array($lead['stage'], $motivo, $lead['id']));
            echo "ATUALIZADO Lead #" . $lead['id'] . " (case #$caseId / $name) — stage anterior: " . $lead['stage'] . "\n";
            $atualizados++;
        } else {
            $jaTinha++;
        }
        continue;
    }

    // 2) Existe lead órfão do mesmo cliente sem case vinculado?
    if ($clientId > 0) {
        $stmtLead2 = $pdo->prepare("SELECT id, stage FROM pipeline_leads WHERE client_id = ? AND (linked_case_id IS NULL OR linked_case_id = 0) ORDER BY id DESC LIMIT 1");
        $stmtLead2->execute(array($clientId));
        $lead2 = $stmtLead2->fetch();
        if ($lead2) {
            $pdo->prepare("UPDATE pipeline_leads SET stage = 'doc_faltante', stage_antes_doc_faltante = COALESCE(stage_antes_doc_faltante, ?), doc_faltante_motivo = ?, linked_case_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute(array($lead2['stage'], $motivo, $caseId, $lead2['id']));
            echo "VINCULADO Lead #" . $lead2['id'] . " ao case #$caseId ($name)\n";
            $atualizados++;
            continue;
        }
    }

    // 3) Criar novo lead
    $pdo->prepare("INSERT INTO pipeline_leads (client_id, linked_case_id, name, stage, case_type, doc_faltante_motivo, stage_antes_doc_faltante, created_at, updated_at)
                   VALUES (?, ?, ?, 'doc_faltante', ?, ?, ?, NOW(), NOW())")
        ->execute(array($clientId ?: null, $caseId, $name, $cs['case_type'] ?: 'outro', $motivo, $cs['stage_antes_doc_faltante'] ?: 'contrato_assinado'));
    $newId = (int)$pdo->lastInsertId();
    echo "CRIADO  Lead #$newId (case #$caseId / $name)\n";
    $criados++;
}

echo "\n=== RESULTADO ===\n";
echo "Leads criados: $criados\n";
echo "Leads atualizados/vinculados: $atualizados\n";
echo "Já estavam OK: $jaTinha\n";
echo "\n=== FIM ===\n";
