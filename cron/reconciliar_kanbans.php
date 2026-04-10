<?php
/**
 * Cron Job — Reconciliador Pipeline ↔ Operacional
 * Rodar 1x por dia (de madrugada) via cron do cPanel
 * Comando: php /home/ferre315/public_html/conecta/cron/reconciliar_kanbans.php
 *
 * Detecta divergências entre cases.status e pipeline_leads.stage e corrige
 * apenas onde a regra é absolutamente clara (ver mapear_*_para_* no script web).
 */
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';

$pdo = db();
echo "=== Reconciliador Kanbans — " . date('d/m/Y H:i') . " ===\n";

function mapear_case_para_lead($s) {
    switch ($s) {
        case 'cancelado':    return 'cancelado';
        case 'doc_faltante': return 'doc_faltante';
        case 'suspenso':     return 'suspenso';
        default: return null;
    }
}
function mapear_lead_para_case($s) {
    switch ($s) {
        case 'cancelado': return 'cancelado';
        case 'perdido':   return 'cancelado';
        case 'suspenso':  return 'suspenso';
        default: return null;
    }
}

$rows = $pdo->query("
    SELECT l.id AS lead_id, l.stage AS lead_stage, l.linked_case_id, l.name AS lead_name,
           c.id AS case_id, c.status AS case_status
    FROM pipeline_leads l
    INNER JOIN cases c ON c.id = l.linked_case_id
    WHERE l.linked_case_id IS NOT NULL AND l.linked_case_id > 0
")->fetchAll();

$corrigidos = 0;
foreach ($rows as $r) {
    $leadCanon = mapear_lead_para_case($r['lead_stage']);
    $caseCanon = mapear_case_para_lead($r['case_status']);

    if ($leadCanon !== null && $r['case_status'] !== $leadCanon) {
        $pdo->prepare("UPDATE cases SET status = ?, closed_at = COALESCE(closed_at, CURDATE()), updated_at = NOW() WHERE id = ?")
            ->execute(array($leadCanon, $r['case_id']));
        audit_log('reconcile_case_cron', 'case', $r['case_id'], $r['case_status'] . " → $leadCanon (espelho lead " . $r['lead_stage'] . ")");
        echo "[CASO] #" . $r['case_id'] . " " . $r['lead_name'] . ": " . $r['case_status'] . " → $leadCanon\n";
        $corrigidos++;
        continue;
    }
    if ($caseCanon !== null && $r['lead_stage'] !== $caseCanon) {
        $pdo->prepare("UPDATE pipeline_leads SET stage = ?, updated_at = NOW() WHERE id = ?")
            ->execute(array($caseCanon, $r['lead_id']));
        audit_log('reconcile_lead_cron', 'lead', $r['lead_id'], $r['lead_stage'] . " → $caseCanon (espelho caso " . $r['case_status'] . ")");
        echo "[LEAD] #" . $r['lead_id'] . " " . $r['lead_name'] . ": " . $r['lead_stage'] . " → $caseCanon\n";
        $corrigidos++;
    }
}

echo "Total corrigido: $corrigidos\n";
echo "=== FIM ===\n";
