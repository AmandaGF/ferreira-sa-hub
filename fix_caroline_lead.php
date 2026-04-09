<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Estado atual lead
$lead = $pdo->query("SELECT * FROM pipeline_leads WHERE id=1234")->fetch();
echo "Lead atual: stage=" . ($lead['stage'] ?? 'N/A') . " linked_case=" . ($lead['linked_case_id'] ?? 'N/A') . "\n";

// Audit log
echo "\n=== Audit log ===\n";
try {
    $a = $pdo->query("SELECT id, action, entity_type, entity_id, details, created_at FROM audit_log WHERE (entity_type='lead' AND entity_id=1234) OR (entity_type='case' AND entity_id=614) ORDER BY id DESC LIMIT 20")->fetchAll();
    foreach ($a as $r) echo "[{$r['created_at']}] {$r['action']} {$r['entity_type']}#{$r['entity_id']} :: {$r['details']}\n";
} catch (Exception $e) { echo "ERR: " . $e->getMessage() . "\n"; }

// Corrigir lead 1234 → pasta_apta
echo "\n=== Corrigindo Lead #1234 → pasta_apta ===\n";
$pdo->prepare("UPDATE pipeline_leads SET stage='pasta_apta', doc_faltante_motivo=NULL, stage_antes_doc_faltante=NULL, updated_at=NOW() WHERE id=1234")->execute();
$pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
    ->execute(array(1234, 'cancelado', 'pasta_apta', 0, 'Manual: corrigir mapeamento bugado em_andamento→finalizado/cancelado'));
echo "OK\n";

echo "\n=== FIM ===\n";
