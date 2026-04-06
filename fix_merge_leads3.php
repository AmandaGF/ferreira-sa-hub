<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Fix: Arquivar leads duplicados da Suelen ===\n\n";

// Lead #1190 é duplicata (mesmo linked_case_id=643 que #1191)
// Arquivar #1190
$pdo->prepare("UPDATE pipeline_leads SET stage = 'arquivado', arquivado_em = NOW(), updated_at = NOW() WHERE id = ?")
    ->execute(array(1190));
$pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
    ->execute(array(1190, 'pasta_apta', 'arquivado', 1, 'Fix: lead duplicado apos merge'));
echo "Lead #1190 arquivado (duplicata do #1191)\n";

echo "\n=== FEITO ===\n";
