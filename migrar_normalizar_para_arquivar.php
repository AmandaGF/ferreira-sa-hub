<?php
/**
 * Normaliza qualquer case/lead com status/stage='para_arquivar' pro status real
 * + flag marcado_para_arquivar=1. "Para Arquivar" é só visual no Kanban.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();
@ini_set('display_errors', '1'); error_reporting(E_ALL);

try { $pdo->exec("ALTER TABLE cases ADD COLUMN marcado_para_arquivar TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE pipeline_leads ADD COLUMN marcado_para_arquivar TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE pipeline_leads ADD COLUMN kanban_oculto TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}

echo "=== Normalizando status='para_arquivar' / stage='para_arquivar' ===\n\n";

// CASES com status='para_arquivar' → restaura status real (de stage_antes_para_arquivar) + marca flag
$cs = $pdo->query("SELECT id, title, status, stage_antes_para_arquivar FROM cases WHERE status = 'para_arquivar'")->fetchAll();
echo "CASES: " . count($cs) . "\n";
foreach ($cs as $c) {
    $statusReal = $c['stage_antes_para_arquivar'] ?: 'em_andamento';
    $pdo->prepare("UPDATE cases SET status = ?, marcado_para_arquivar = 1, updated_at = NOW() WHERE id = ?")
        ->execute(array($statusReal, (int)$c['id']));
    echo "  case#{$c['id']} | {$c['title']} | status para_arquivar -> {$statusReal} (flag=1) ✓\n";
}

echo "\n";

// LEADS com stage='para_arquivar' → restaura stage real (impossível saber qual era... mas se há histórico no pipeline_history, usa)
$ls = $pdo->query("SELECT id, name, stage FROM pipeline_leads WHERE stage = 'para_arquivar'")->fetchAll();
echo "LEADS: " . count($ls) . "\n";
foreach ($ls as $l) {
    $h = $pdo->prepare("SELECT from_stage FROM pipeline_history WHERE lead_id = ? AND to_stage = 'para_arquivar' ORDER BY id DESC LIMIT 1");
    $h->execute(array($l['id']));
    $fromStage = $h->fetchColumn() ?: 'pasta_apta';
    $pdo->prepare("UPDATE pipeline_leads SET stage = ?, marcado_para_arquivar = 1, updated_at = NOW() WHERE id = ?")
        ->execute(array($fromStage, (int)$l['id']));
    echo "  lead#{$l['id']} | {$l['name']} | stage para_arquivar -> {$fromStage} (flag=1) ✓\n";
}

echo "\n=== FIM ===\n";
