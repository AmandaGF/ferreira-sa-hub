<?php
/**
 * Restaura ao Kanban Comercial os 4 leads arquivados sem virem da Pasta Apta.
 * Volta cada um pro stage anterior conforme pipeline_history.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();

$executar = isset($_GET['executar']);

echo "=== " . ($executar ? "RESTAURANDO" : "PREVIEW") . ": leads arquivados do Kanban Comercial ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . "\n\n";

$rows = $pdo->query("SELECT id, name, stage, linked_case_id, arquivado_em
                     FROM pipeline_leads WHERE stage = 'arquivado' ORDER BY id DESC")->fetchAll();
echo "Leads arquivados: " . count($rows) . "\n\n";

$pdo->beginTransaction();
try {
    foreach ($rows as $l) {
        $lid = (int)$l['id'];
        // Achar último from_stage no pipeline_history apontando pra arquivado
        $h = $pdo->prepare("SELECT from_stage FROM pipeline_history WHERE lead_id = ? AND to_stage = 'arquivado' ORDER BY id DESC LIMIT 1");
        $h->execute(array($lid));
        $from = $h->fetchColumn();
        if (!$from) {
            // Fallback: audit_log
            $a = $pdo->prepare("SELECT details FROM audit_log WHERE entity_type='lead' AND entity_id=? AND action='lead_moved' AND details LIKE '% -> arquivado' ORDER BY id DESC LIMIT 1");
            $a->execute(array($lid));
            $det = $a->fetchColumn();
            if ($det && preg_match('/^(\S+)\s+->\s+/', $det, $m)) $from = $m[1];
        }
        if (!$from) {
            echo "  ⚠️ lead#{$lid} {$l['name']} — sem histórico, mantido em arquivado\n";
            continue;
        }
        echo "  lead#{$lid} {$l['name']} -> restaurando para '{$from}'";
        if ($executar) {
            $pdo->prepare("UPDATE pipeline_leads SET stage = ?, arquivado_em = NULL, arquivado_por = NULL, updated_at = NOW() WHERE id = ?")
                ->execute(array($from, $lid));
            $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                ->execute(array($lid, 'arquivado', $from, current_user_id(), 'Reversão: regra "arquivar só de pasta_apta" + arquivamento não pode arrastar pasta'));
            audit_log('lead_unarchive_massa', 'lead', $lid, "arquivado -> {$from}");
            echo " ✓\n";
        } else {
            echo " (preview)\n";
        }
    }
    if ($executar) $pdo->commit();
    else $pdo->rollBack();
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERRO: " . $e->getMessage() . "\n";
}

if (!$executar) echo "\nPara executar: adicione &executar=1\n";
echo "\n=== FIM ===\n";
