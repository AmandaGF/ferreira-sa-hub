<?php
/**
 * Restaura ao Kanban Comercial os leads arquivados.
 * Volta cada um pro stage anterior conforme pipeline_history.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();
@ini_set('display_errors', '1'); error_reporting(E_ALL);

$executar = isset($_GET['executar']);
$uid = function_exists('current_user_id') && current_user_id() ? current_user_id() : 1;

echo "=== " . ($executar ? "RESTAURANDO" : "PREVIEW") . ": leads arquivados do Kanban Comercial ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . " | uid usado: {$uid}\n\n";

$rows = $pdo->query("SELECT id, name, stage FROM pipeline_leads WHERE stage = 'arquivado' ORDER BY id DESC")->fetchAll();
echo "Leads arquivados: " . count($rows) . "\n\n";

foreach ($rows as $l) {
    $lid = (int)$l['id'];
    $h = $pdo->prepare("SELECT from_stage FROM pipeline_history WHERE lead_id = ? AND to_stage = 'arquivado' ORDER BY id DESC LIMIT 1");
    $h->execute(array($lid));
    $from = $h->fetchColumn();
    if (!$from) {
        $a = $pdo->prepare("SELECT details FROM audit_log WHERE entity_type='lead' AND entity_id=? AND action='lead_moved' AND details LIKE '% -> arquivado' ORDER BY id DESC LIMIT 1");
        $a->execute(array($lid));
        $det = $a->fetchColumn();
        if ($det && preg_match('/^(\S+)\s+->\s+/', $det, $m)) $from = $m[1];
    }
    if (!$from) {
        echo "  ⚠ lead#{$lid} {$l['name']} — sem histórico, mantido\n";
        continue;
    }
    echo "  lead#{$lid} {$l['name']} -> '{$from}'";
    if ($executar) {
        try {
            $pdo->prepare("UPDATE pipeline_leads SET stage = ?, arquivado_em = NULL, arquivado_por = NULL, updated_at = NOW() WHERE id = ?")
                ->execute(array($from, $lid));
            try {
                $pdo->prepare("INSERT INTO pipeline_history (lead_id, from_stage, to_stage, changed_by, notes) VALUES (?,?,?,?,?)")
                    ->execute(array($lid, 'arquivado', $from, $uid, 'Reversao automatica'));
            } catch (Exception $eh) { echo " [hist err: " . $eh->getMessage() . "]"; }
            try { audit_log('lead_unarchive_massa', 'lead', $lid, "arquivado -> {$from}"); } catch (Exception $ea) {}
            echo " ✓\n";
        } catch (Exception $e) {
            echo " ✗ ERRO: " . $e->getMessage() . "\n";
        }
    } else {
        echo " (preview)\n";
    }
}

if (!$executar) echo "\nPara executar: adicione &executar=1\n";
echo "\n=== FIM ===\n";
