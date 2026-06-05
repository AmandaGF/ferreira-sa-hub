<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

// ── BACKFILL dos 3 leads com link quebrado ──
echo "== BACKFILL linked_case_id ==\n";
$backfillMap = array(
    1281 => 923,  // Wendel Magno
    1204 => 705,  // Jhonatan
    1167 => 1087, // Marcus Vinicius
);
foreach ($backfillMap as $leadId => $caseId) {
    // Confirma que o lead esta com linked_case_id vazio E o case existe e é do mesmo client_id
    $st = $pdo->prepare("SELECT l.id, l.name AS lead_name, l.client_id, c.title AS case_title, c.client_id AS case_client
                         FROM pipeline_leads l, cases c
                         WHERE l.id = ? AND c.id = ? AND (l.linked_case_id IS NULL OR l.linked_case_id = 0)");
    $st->execute(array($leadId, $caseId));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo "  lead#$leadId case#$caseId: nao satisfeito (talvez ja preenchido)\n"; continue; }
    if ((int)$row['client_id'] !== (int)$row['case_client']) {
        echo "  lead#$leadId case#$caseId: client_id divergente (lead={$row['client_id']} case={$row['case_client']}) - PULO\n";
        continue;
    }
    $pdo->prepare("UPDATE pipeline_leads SET linked_case_id = ? WHERE id = ?")->execute(array($caseId, $leadId));
    audit_log('pipeline_lead_linked_case_backfill', 'pipeline_leads', $leadId, "case_id=$caseId");
    echo "  ✓ lead#$leadId '{$row['lead_name']}' -> linked_case_id = $caseId '{$row['case_title']}'\n";
}

// ── DOSSIÊ dos 6 pra investigar ──
echo "\n== DOSSIE dos leads pra investigar ==\n";
$investigar = array(1252, 1246, 1198, 1181, 1191, 1189);
foreach ($investigar as $leadId) {
    echo "\n────────────────────────────────────────────\n";
    $st = $pdo->prepare("SELECT l.*, c.name AS client_name, c.phone AS client_phone, u.name AS atendente_name
                         FROM pipeline_leads l
                         LEFT JOIN clients c ON c.id = l.client_id
                         LEFT JOIN users u ON u.id = l.assigned_to
                         WHERE l.id = ?");
    $st->execute(array($leadId));
    $lead = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lead) { echo "lead#$leadId nao existe\n"; continue; }

    echo "📌 LEAD #{$lead['id']} '{$lead['name']}'\n";
    echo "   Cliente#{$lead['client_id']} '{$lead['client_name']}' tel={$lead['client_phone']}\n";
    echo "   stage='{$lead['stage']}' atendente='{$lead['atendente_name']}'\n";
    echo "   created_at={$lead['created_at']} converted_at={$lead['converted_at']}\n";
    echo "   updated_at={$lead['updated_at']} linked_case_id={$lead['linked_case_id']}\n";
    if (!empty($lead['notes'])) echo "   notas: " . substr($lead['notes'], 0, 200) . "\n";
    if (!empty($lead['valor_acao'])) echo "   valor_acao={$lead['valor_acao']}\n";

    // Cases vinculados (por linked OU por client_id)
    $stC = $pdo->prepare("SELECT cs.*, u.name AS responsavel_name
                          FROM cases cs LEFT JOIN users u ON u.id = cs.responsible_user_id
                          WHERE cs.id = ? OR cs.client_id = ?
                          ORDER BY cs.id DESC");
    $stC->execute(array((int)$lead['linked_case_id'], (int)$lead['client_id']));
    foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $cs) {
        $tag = ((int)$cs['id'] === (int)$lead['linked_case_id']) ? ' ← LINKED' : '';
        echo "   ⚖ CASE #{$cs['id']} status='{$cs['status']}' '{$cs['title']}'$tag\n";
        echo "     responsavel='{$cs['responsavel_name']}' created_at={$cs['created_at']} updated_at={$cs['updated_at']}\n";
        if (!empty($cs['case_number'])) echo "     case_number={$cs['case_number']}\n";
        if (!empty($cs['closed_at'])) echo "     closed_at={$cs['closed_at']}\n";
        if (!empty($cs['notes'])) echo "     notas: " . substr($cs['notes'], 0, 150) . "\n";
        // Ultimo andamento
        $stA = $pdo->prepare("SELECT data_andamento, descricao, created_at FROM case_andamentos WHERE case_id = ? ORDER BY id DESC LIMIT 1");
        $stA->execute(array($cs['id']));
        $a = $stA->fetch(PDO::FETCH_ASSOC);
        if ($a) {
            echo "     ULTIMO ANDAMENTO ({$a['data_andamento']}): " . substr(preg_replace('/\s+/', ' ', $a['descricao']), 0, 200) . "\n";
        }
        // Total andamentos
        $totAnd = (int)$pdo->prepare("SELECT COUNT(*) FROM case_andamentos WHERE case_id = ?")->execute(array($cs['id'])) ? $pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = " . (int)$cs['id'])->fetchColumn() : 0;
        echo "     total andamentos: $totAnd\n";
    }
}
echo "\n────────────────────────────────────────────\n";
