<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

// BACKFILL
echo "== BACKFILL ==\n";
$backfillMap = array(1281 => 923, 1204 => 705, 1167 => 1087);
foreach ($backfillMap as $leadId => $caseId) {
    try {
        $r = $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = ? WHERE id = ? AND (linked_case_id IS NULL OR linked_case_id = 0)");
        $r->execute(array($caseId, $leadId));
        echo "  lead#$leadId -> case#$caseId: " . $r->rowCount() . " row(s) atualizadas\n";
    } catch (Throwable $e) { echo "  erro lead#$leadId: " . $e->getMessage() . "\n"; }
}

// DOSSIE simplificado dos 6
echo "\n== DOSSIE simplificado ==\n";
$investigar = array(1252, 1246, 1198, 1181, 1191, 1189);
foreach ($investigar as $leadId) {
    echo "\n--- lead#$leadId ---\n";
    try {
        $st = $pdo->prepare("SELECT l.id, l.name, l.stage, l.client_id, l.linked_case_id, l.converted_at, l.updated_at, c.name AS client_name
                             FROM pipeline_leads l LEFT JOIN clients c ON c.id = l.client_id WHERE l.id = ?");
        $st->execute(array($leadId));
        $lead = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lead) { echo "  lead nao existe\n"; continue; }
        echo "  '{$lead['name']}' stage={$lead['stage']} client#{$lead['client_id']} '{$lead['client_name']}'\n";
        echo "  linked_case_id={$lead['linked_case_id']} converted={$lead['converted_at']} updated={$lead['updated_at']}\n";

        // Cases relacionados
        $stC = $pdo->prepare("SELECT id, title, status, case_number, closed_at, created_at, updated_at FROM cases WHERE id = ? OR client_id = ? ORDER BY id DESC LIMIT 5");
        $stC->execute(array((int)$lead['linked_case_id'], (int)$lead['client_id']));
        foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $cs) {
            $tag = ((int)$cs['id'] === (int)$lead['linked_case_id']) ? ' [LINKED]' : '';
            echo "    case#{$cs['id']} status={$cs['status']} '{$cs['title']}'$tag\n";
            echo "      proc={$cs['case_number']} closed_at={$cs['closed_at']} updated={$cs['updated_at']}\n";
            // Ultimo andamento
            try {
                $stA = $pdo->prepare("SELECT data_andamento, descricao FROM case_andamentos WHERE case_id = ? ORDER BY id DESC LIMIT 1");
                $stA->execute(array($cs['id']));
                $a = $stA->fetch(PDO::FETCH_ASSOC);
                if ($a) {
                    $desc = preg_replace('/\s+/', ' ', $a['descricao']);
                    echo "      ULTIMO ({$a['data_andamento']}): " . substr($desc, 0, 180) . "\n";
                }
            } catch (Exception $e) {}
        }
    } catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
}
