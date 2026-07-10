<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== ULTIMOS 30 toggles de salavip (audit_log) ===\n\n";
$stmt = $pdo->query("
    SELECT al.id, al.created_at, al.user_id, u.name AS user_name,
           al.entity_id AS case_id, cs.title AS case_title,
           al.description, cs.salavip_ativo AS estado_atual
    FROM audit_log al
    LEFT JOIN users u  ON u.id  = al.user_id
    LEFT JOIN cases cs ON cs.id = al.entity_id
    WHERE al.action = 'toggle_salavip'
    ORDER BY al.id DESC
    LIMIT 30
");
foreach ($stmt as $r) {
    echo sprintf("%s | user=%s | case=%s (%s) | acao=%s | estado_atual=%s\n",
        $r['created_at'], $r['user_name'] ?? '?',
        $r['case_id'], mb_substr($r['case_title'] ?? '', 0, 40),
        $r['description'], $r['estado_atual']);
}

echo "\n=== Toggles DUPLICADOS (mesmo case, mesmo user, < 30s de intervalo) ===\n";
$stmt = $pdo->query("
    SELECT a1.created_at AS ts1, a2.created_at AS ts2,
           a1.user_id, u.name AS user_name,
           a1.entity_id AS case_id, cs.title AS case_title,
           a1.description AS desc1, a2.description AS desc2,
           TIMESTAMPDIFF(SECOND, a1.created_at, a2.created_at) AS gap_segs
    FROM audit_log a1
    JOIN audit_log a2 ON a2.action='toggle_salavip'
                     AND a2.entity_id = a1.entity_id
                     AND a2.user_id   = a1.user_id
                     AND a2.id > a1.id
                     AND TIMESTAMPDIFF(SECOND, a1.created_at, a2.created_at) BETWEEN 0 AND 30
    LEFT JOIN users u  ON u.id  = a1.user_id
    LEFT JOIN cases cs ON cs.id = a1.entity_id
    WHERE a1.action = 'toggle_salavip'
    ORDER BY a1.id DESC
    LIMIT 20
");
$found = 0;
foreach ($stmt as $r) {
    $found++;
    echo sprintf("%s -> %s (gap %ds) | user=%s | case=%s (%s) | %s -> %s\n",
        $r['ts1'], $r['ts2'], $r['gap_segs'], $r['user_name'] ?? '?',
        $r['case_id'], mb_substr($r['case_title'] ?? '', 0, 30),
        $r['desc1'], $r['desc2']);
}
if (!$found) echo "(nenhum caso de duplo-toggle proximo detectado)\n";
