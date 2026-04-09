<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Histórico do lead 1234
echo "=== Histórico Lead #1234 ===\n";
$h = $pdo->query("SELECT id, from_stage, to_stage, changed_by, notes, changed_at FROM pipeline_history WHERE lead_id = 1234 ORDER BY id")->fetchAll();
foreach ($h as $r) {
    echo "#{$r['id']} [{$r['changed_at']}] {$r['from_stage']} → {$r['to_stage']} by user {$r['changed_by']} :: {$r['notes']}\n";
}

// Audit log relacionado
echo "\n=== Audit log lead 1234 ou case 614 ===\n";
$a = $pdo->query("SELECT id, action, entity_type, entity_id, details, created_at FROM audit_log WHERE (entity_type='lead' AND entity_id=1234) OR (entity_type='case' AND entity_id=614) ORDER BY id DESC LIMIT 30")->fetchAll();
foreach ($a as $r) {
    echo "#{$r['id']} [{$r['created_at']}] {$r['action']} {$r['entity_type']}#{$r['entity_id']} :: {$r['details']}\n";
}

// Corrigir lead 1234 → pasta_apta
echo "\n=== Corrigindo Lead #1234 → pasta_apta ===\n";
$pdo->prepare("UPDATE pipeline_leads SET stage='pasta_apta', doc_faltante_motivo=NULL, stage_antes_doc_faltante=NULL, updated_at=NOW() WHERE id=1234")->execute();
$pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
    ->execute(array(1234, 'cancelado', 'pasta_apta', 0, 'Manual: corrigir mapeamento bugado em_andamento→finalizado/cancelado'));
echo "OK\n";

echo "\n=== FIM ===\n";
