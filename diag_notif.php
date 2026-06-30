<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Últimas notificações (todas) ===\n\n";
$ns = $pdo->query("SELECT id, user_id, type, title, link, created_at FROM notifications ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ns as $n) {
    echo "  #{$n['id']} user={$n['user_id']} tipo={$n['type']} em={$n['created_at']}\n";
    echo "    titulo: {$n['title']}\n";
    echo "    link:   {$n['link']}\n";
    echo "\n";
}

echo "\n=== Notificações com 'audien' OU 'agenda' OU 'amanhã' OU 'lembrete' ===\n\n";
$ns2 = $pdo->query("SELECT id, user_id, type, title, link, created_at FROM notifications WHERE LOWER(title) LIKE '%audien%' OR LOWER(title) LIKE '%amanh%' OR LOWER(title) LIKE '%lembrete%' OR LOWER(message) LIKE '%audien%' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach ($ns2 as $n) {
    echo "  #{$n['id']} user={$n['user_id']} {$n['type']} {$n['created_at']}\n";
    echo "    titulo: {$n['title']}\n";
    echo "    link:   {$n['link']}\n\n";
}
