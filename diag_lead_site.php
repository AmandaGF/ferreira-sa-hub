<?php
/**
 * Diag: leads vindos do site (form lp/v2.php). Read-only por padrão.
 *  ?key=fsa-hub-deploy-2026            → lista últimos 15 leads source='landing'
 *  &del=<lead_id>                      → apaga lead de teste (e o cliente se órfão)
 */
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$del = (int)($_GET['del'] ?? 0);
if ($del > 0) {
    $l = $pdo->prepare("SELECT id, name, client_id FROM pipeline_leads WHERE id = ?");
    $l->execute(array($del));
    $row = $l->fetch();
    if (!$row) { exit("Lead #{$del} não encontrado.\n"); }
    $pdo->prepare("DELETE FROM pipeline_history WHERE lead_id = ?")->execute(array($del));
    $pdo->prepare("DELETE FROM pipeline_leads WHERE id = ?")->execute(array($del));
    echo "✓ Lead #{$del} ({$row['name']}) apagado.\n";
    if (!empty($row['client_id'])) {
        $c = $pdo->prepare("SELECT COUNT(*) FROM pipeline_leads WHERE client_id = ?");
        $c->execute(array($row['client_id']));
        if ((int)$c->fetchColumn() === 0) {
            $pdo->prepare("DELETE FROM clients WHERE id = ? AND source IN ('formulario','landing')")
                ->execute(array($row['client_id']));
            echo "✓ Cliente #{$row['client_id']} (órfão, origem formulário) apagado.\n";
        }
    }
    exit;
}

echo "=== ÚLTIMOS LEADS source='landing' (site) ===\n";
$rows = $pdo->query(
    "SELECT id, name, phone, email, case_type, stage, client_id, created_at
     FROM pipeline_leads WHERE source = 'landing' ORDER BY id DESC LIMIT 15"
)->fetchAll();
foreach ($rows as $r) {
    echo sprintf("#%d | %s | %s | %s | área=%s | stage=%s | client=%s | %s\n",
        $r['id'], $r['name'], $r['phone'] ?: '-', $r['email'] ?: '-',
        $r['case_type'] ?: '-', $r['stage'], $r['client_id'] ?: '-', $r['created_at']);
}
echo "\n(use &del=<id> pra apagar lead de teste)\n";
