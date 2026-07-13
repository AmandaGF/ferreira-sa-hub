<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== TICKET #353 (helpdesk) ===\n";
try {
    $t = $pdo->query("SELECT id, title, description, category, priority, status, requester_id, client_id, case_id, created_at, updated_at FROM tickets WHERE id = 353")->fetch(PDO::FETCH_ASSOC);
    print_r($t);

    echo "\n-- Mensagens do ticket --\n";
    foreach ($pdo->query("SELECT tm.id, tm.user_id, u.name AS user_name, tm.message, tm.created_at FROM ticket_messages tm LEFT JOIN users u ON u.id=tm.user_id WHERE tm.ticket_id=353 ORDER BY tm.created_at") as $m) {
        printf("  %s [%s]:\n    %s\n\n", $m['created_at'], $m['user_name'] ?: '(nulo)', substr($m['message'],0,400));
    }
} catch (Exception $e) { echo "erro: " . $e->getMessage() . "\n"; }

echo "\n=== CONVERSAS RECENTES (2981-2984) ===\n";
foreach ($pdo->query("SELECT id, canal, telefone, nome_contato, client_id, atendente_id, status, created_at FROM zapi_conversas WHERE id BETWEEN 2981 AND 2984 ORDER BY id") as $r) {
    printf("  #%d canal=%s tel=%s nome=%s client=%s at=%s status=%s criada=%s\n",
        $r['id'], $r['canal'], $r['telefone'], substr($r['nome_contato'],0,25),
        $r['client_id']?:'-', $r['atendente_id']?:'-', $r['status'], $r['created_at']);
}

echo "\n=== Ultimas edicoes de conversa/nome/telefone dela (hoje) ===\n";
$st = $pdo->query("SELECT al.created_at, al.action, al.entity_type, al.entity_id, al.details
                   FROM audit_log al
                   WHERE al.user_id = 12 AND al.created_at >= CURDATE()
                     AND (al.action LIKE '%editar%' OR al.action LIKE '%update%' OR al.action LIKE '%numero%' OR al.action LIKE '%telefone%' OR al.action LIKE '%case_number%' OR al.action LIKE '%merge%')
                   ORDER BY al.created_at DESC LIMIT 30");
foreach ($st as $r) printf("  %s %s (%s#%d) %s\n", $r['created_at'], $r['action'], $r['entity_type'], $r['entity_id'], substr($r['details']??'',0,150));
