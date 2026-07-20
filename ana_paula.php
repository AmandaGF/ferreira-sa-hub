<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== BUSCA CLIENTE Maria Ana Paula ===\n";
$cli = $pdo->query("SELECT id, name, cpf, phone, created_at FROM clients
                    WHERE name LIKE '%Ana Paula%' AND name LIKE '%Maria%'
                    ORDER BY id DESC LIMIT 3")->fetchAll();
foreach ($cli as $c) printf("  client#%d %s cpf=%s tel=%s criado=%s\n",
    $c['id'], $c['name'], $c['cpf']?:'-', $c['phone']?:'-', $c['created_at']);

if (!$cli) { echo "  (nao achou)\n"; exit; }

echo "\n=== ATIVIDADE — pipeline_leads + cases + audit_log ===\n";
foreach ($cli as $c) {
    $cid = (int)$c['id'];
    echo "\n--- CLIENTE #$cid: {$c['name']} ---\n";

    // Leads do pipeline
    echo "LEADS (pipeline_leads):\n";
    $st = $pdo->prepare("SELECT id, name, stage, created_at, updated_at, linked_case_id, assigned_to
                         FROM pipeline_leads WHERE client_id = ? ORDER BY id");
    $st->execute(array($cid));
    foreach ($st as $l) printf("  lead#%d '%s' stage=%s linked_case=%s criado=%s atualizado=%s\n",
        $l['id'], $l['name'], $l['stage'], $l['linked_case_id']?:'-', $l['created_at'], $l['updated_at']);

    // Cases
    echo "CASES:\n";
    $st = $pdo->prepare("SELECT id, title, case_type, status, kanban_prev, drive_folder_url,
                                created_at, updated_at, created_by, responsible_user_id
                         FROM cases WHERE client_id = ? ORDER BY id");
    $st->execute(array($cid));
    foreach ($st as $cs) {
        printf("  case#%d '%s' status=%s prev=%d criado=%s por=%s\n",
            $cs['id'], $cs['title'], $cs['status'], (int)$cs['kanban_prev'],
            $cs['created_at'], $cs['created_by']?:'-');
        printf("    drive: %s\n", $cs['drive_folder_url'] ? substr($cs['drive_folder_url'], 0, 80) : '(sem)');
    }

    // Audit log relevante
    echo "AUDIT (last 20 events do client + lead + case):\n";
    $ids = array_map('intval', array_column($pdo->query("SELECT id FROM pipeline_leads WHERE client_id=$cid")->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $cids = array_map('intval', array_column($pdo->query("SELECT id FROM cases WHERE client_id=$cid")->fetchAll(PDO::FETCH_ASSOC), 'id'));
    $inLeads = $ids ? "OR (entity_type='lead' AND entity_id IN (" . implode(',', $ids) . "))" : '';
    $inCases = $cids ? "OR (entity_type='case' AND entity_id IN (" . implode(',', $cids) . "))" : '';
    $q = "SELECT id, created_at, action, entity_type, entity_id, user_id, LEFT(details,120) det
          FROM audit_log
          WHERE (entity_type='client' AND entity_id=$cid) $inLeads $inCases
          ORDER BY id DESC LIMIT 25";
    foreach ($pdo->query($q) as $a) {
        printf("  %s user=%s %s.%s → %s | %s\n",
            $a['created_at'], $a['user_id']?:'-', $a['entity_type'], $a['entity_id'],
            $a['action'], preg_replace('/\s+/', ' ', (string)$a['det']));
    }
}
