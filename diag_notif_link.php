<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== Tipo da coluna notifications.link ==\n";
foreach ($pdo->query("SHOW COLUMNS FROM notifications LIKE 'link'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    foreach ($c as $k => $v) echo "  $k = " . var_export($v, true) . "\n";
}

echo "\n== Ultima notif Thiago - link armazenado ==\n";
$st = $pdo->query("SELECT n.id, n.title, LENGTH(n.link) AS link_len, n.link FROM notifications n WHERE n.title LIKE '%Thiago Cassiano%' ORDER BY n.id DESC LIMIT 3");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  notif#{$r['id']} link_len={$r['link_len']}\n";
    echo "  title: {$r['title']}\n";
    echo "  link: " . substr($r['link'], 0, 200) . (strlen($r['link']) > 200 ? '...(CONT)' : '') . "\n";
    echo "  link FIM (ultimos 100): " . substr($r['link'], -100) . "\n\n";
}
