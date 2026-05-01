<?php
/**
 * Reverte todos os cases que foram arquivados HOJE (01/Mai/2026) por causa do
 * bug do botão "Arquivar TODOS" antigo (setava status='arquivado'). Move pra
 * status='para_arquivar' (volta na coluna pra Amanda revisar).
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
$hoje = date('Y-m-d');

echo "=== " . ($executar ? "REVERTENDO" : "PREVIEW") . ": cases arquivados HOJE ({$hoje}) ===\n\n";

// 1) CASES com status='arquivado' E updated_at de hoje
$rows = $pdo->prepare("SELECT id, client_id, title, status, kanban_oculto, closed_at, updated_at,
                              stage_antes_para_arquivar
                       FROM cases
                       WHERE status = 'arquivado'
                         AND DATE(updated_at) = ?
                       ORDER BY updated_at DESC");
$rows->execute(array($hoje));
$cases = $rows->fetchAll();
echo "CASES arquivados hoje: " . count($cases) . "\n\n";

foreach ($cases as $c) {
    $cid = (int)$c['id'];
    echo "case#{$cid} | {$c['title']} | closed={$c['closed_at']} | updated={$c['updated_at']}";
    if ($executar) {
        try {
            // Volta pra para_arquivar (coluna no Kanban) + remove kanban_oculto + tira closed_at
            $pdo->prepare("UPDATE cases SET status = 'para_arquivar', kanban_oculto = 0, closed_at = NULL, updated_at = NOW() WHERE id = ?")
                ->execute(array($cid));
            try { audit_log('case_unarchive_para_arquivar', 'case', $cid, "arquivado -> para_arquivar (reversão bug)"); } catch (Exception $ea) {}
            echo " ✓\n";
        } catch (Exception $e) {
            echo " ✗ ERRO: " . $e->getMessage() . "\n";
        }
    } else {
        echo " (preview)\n";
    }
}

// 2) LEADS com stage='arquivado' E updated_at de hoje (caso o pipeline tenha sido afetado também)
echo "\n";
$lrows = $pdo->prepare("SELECT id, name, stage, linked_case_id, updated_at, stage_antes_para_arquivar
                        FROM pipeline_leads
                        WHERE stage = 'arquivado'
                          AND DATE(updated_at) = ?
                        ORDER BY updated_at DESC");
$lrows->execute(array($hoje));
$leads = $lrows->fetchAll();
echo "LEADS arquivados hoje: " . count($leads) . "\n\n";

foreach ($leads as $l) {
    $lid = (int)$l['id'];
    echo "lead#{$lid} | {$l['name']} | linked_case_id={$l['linked_case_id']} | updated={$l['updated_at']}";
    if ($executar) {
        try {
            $pdo->prepare("UPDATE pipeline_leads SET stage = 'para_arquivar', kanban_oculto = 0, arquivado_em = NULL, arquivado_por = NULL, updated_at = NOW() WHERE id = ?")
                ->execute(array($lid));
            try { audit_log('lead_unarchive_para_arquivar', 'lead', $lid, "arquivado -> para_arquivar (reversão bug)"); } catch (Exception $ea) {}
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
