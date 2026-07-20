<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1'); error_reporting(E_ALL);
$pdo = db();

echo "=== CASES do client #2479 ===\n";
try {
    $st = $pdo->query("SELECT id, title, case_type, status, kanban_prev, created_at, created_by, responsible_user_id
                       FROM cases WHERE client_id = 2479 ORDER BY id");
    foreach ($st as $cs) {
        printf("  case#%d | %s\n    tipo=%s status=%s prev=%d\n    criado=%s por_user=%s resp=%s\n",
            $cs['id'], $cs['title'], $cs['case_type']?:'-', $cs['status'],
            (int)$cs['kanban_prev'], $cs['created_at'],
            $cs['created_by']?:'-', $cs['responsible_user_id']?:'-');
    }
} catch (Exception $e) { echo "err: " . $e->getMessage() . "\n"; }

echo "\n=== AUDIT LOG relacionado ===\n";
try {
    foreach ($pdo->query("SELECT created_at, action, entity_type, entity_id, user_id, LEFT(details,150) det
                          FROM audit_log
                          WHERE (entity_type='client' AND entity_id=2479)
                             OR (entity_type='lead' AND entity_id IN (1351, 1438))
                             OR (entity_type='case' AND entity_id IN (1560, 1639))
                          ORDER BY id ASC") as $a) {
        printf("  %s user#%s [%s.%s] %s | %s\n",
            $a['created_at'], $a['user_id']?:'-', $a['entity_type'], $a['entity_id'],
            $a['action'], preg_replace('/\s+/', ' ', (string)$a['det']));
    }
} catch (Exception $e) { echo "err: " . $e->getMessage() . "\n"; }
