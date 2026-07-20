<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CASES da Maria Ana Paula ===\n";
foreach ($pdo->query("SELECT id, title, case_type, status, drive_folder_url, created_at,
                             assigned_to, responsible_user_id
                      FROM cases WHERE client_id = 2479 ORDER BY id") as $cs) {
    printf("case#%d  '%s'\n  tipo=%s  status=%s\n  criado=%s  assigned=%s resp=%s\n  drive=%s\n\n",
        $cs['id'], $cs['title'], $cs['case_type']?:'-', $cs['status'],
        $cs['created_at'], $cs['assigned_to']?:'-', $cs['responsible_user_id']?:'-',
        $cs['drive_folder_url']?:'-');
}

echo "\n=== LEADS da Maria Ana Paula (detalhes) ===\n";
foreach ($pdo->query("SELECT id, name, case_type, stage, source, notes, honorarios_cents,
                             created_at, updated_at, assigned_to
                      FROM pipeline_leads WHERE client_id = 2479 ORDER BY id") as $l) {
    printf("lead#%d '%s'\n  case_type=%s  stage=%s  source=%s\n  criado=%s  atualizado=%s\n  honor=%s  assigned=%s\n  notes=%s\n\n",
        $l['id'], $l['name'], $l['case_type']?:'-', $l['stage'], $l['source']?:'-',
        $l['created_at'], $l['updated_at'],
        $l['honorarios_cents'] ? 'R$ ' . number_format($l['honorarios_cents']/100, 2, ',', '.') : '-',
        $l['assigned_to']?:'-',
        preg_replace('/\s+/', ' ', mb_substr((string)$l['notes'], 0, 200, 'UTF-8')));
}

echo "\n=== USER #12 quem eh? ===\n";
$u = $pdo->query("SELECT id, name, role FROM users WHERE id = 12")->fetch();
if ($u) printf("user#%d = %s (role=%s)\n", $u['id'], $u['name'], $u['role']);

echo "\n=== QUANTOS clientes NO TOTAL tem MAIS DE 1 case? (escala do problema) ===\n";
$q = $pdo->query("SELECT client_id, COUNT(*) c FROM cases WHERE client_id > 0 GROUP BY client_id HAVING c > 1 ORDER BY c DESC LIMIT 20");
$total = 0;
foreach ($q as $r) { $total++; printf("  client#%d tem %d cases\n", $r['client_id'], $r['c']); }
$totalPlus1 = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT client_id FROM cases WHERE client_id > 0 GROUP BY client_id HAVING COUNT(*) > 1) t")->fetchColumn();
echo "\n  Total de clientes com >1 case: $totalPlus1 (mostrei 20 primeiros)\n";
