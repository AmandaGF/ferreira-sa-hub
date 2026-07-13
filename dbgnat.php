<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== USUARIA Nativania ===\n";
$st = $pdo->query("SELECT id, name, email, role, is_active FROM users WHERE name LIKE '%ativan%' OR name LIKE '%Nativ%' OR email LIKE '%nativ%'");
foreach ($st as $r) print_r($r);

echo "\n=== Ultimas atividades dela (audit_log 4h) ===\n";
$st = $pdo->query("SELECT al.created_at, al.action, al.entity_type, al.entity_id, SUBSTRING(al.details,1,120) det, u.name
                   FROM audit_log al JOIN users u ON u.id = al.user_id
                   WHERE u.name LIKE '%ativan%' AND al.created_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
                   ORDER BY al.created_at DESC LIMIT 20");
foreach ($st as $r) printf("  %s %s (%s#%d) %s\n", $r['created_at'], $r['action'], $r['entity_type'], $r['entity_id'], $r['det']);

echo "\n=== Erros PHP recentes (mesmo tempo) ===\n";
$log = __DIR__ . '/error_log';
if (is_file($log)) {
    $c = file_get_contents($log);
    // Filtra últimas 4h
    $linhas = explode("\n", substr($c, -8000));
    foreach ($linhas as $l) {
        if (strpos($l, date('d-M-Y', strtotime('-4 hours'))) !== false || strpos($l, date('d-M-Y')) !== false) {
            if (stripos($l, 'ativan') !== false || strpos($l, 'Fatal') !== false || strpos($l, 'Warning') !== false) echo "  $l\n";
        }
    }
}
