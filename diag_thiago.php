<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== Tipo da coluna notificacoes_cliente.mensagem ==\n";
foreach ($pdo->query("SHOW COLUMNS FROM notificacoes_cliente LIKE 'mensagem'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    foreach ($c as $k => $v) echo "  $k = " . var_export($v, true) . "\n";
}

echo "\n== Notificacao boas_vindas mais recente (Thiago Cassiano) ==\n";
$st = $pdo->query("SELECT n.id, n.client_id, c.name client_name, n.tipo, n.canal, n.created_at, LENGTH(n.mensagem) AS len, n.mensagem
                   FROM notificacoes_cliente n
                   LEFT JOIN clients c ON c.id = n.client_id
                   WHERE n.tipo='boas_vindas' AND (c.name LIKE '%Thiago Cassiano%' OR c.name LIKE '%Cassiano%')
                   ORDER BY n.id DESC LIMIT 3");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  notif#{$r['id']} client#{$r['client_id']} ({$r['client_name']}) canal={$r['canal']} em {$r['created_at']}\n";
    echo "  len: {$r['len']}\n";
    echo "  ===INICIO===\n{$r['mensagem']}\n  ===FIM===\n\n";
}

echo "== Ultimas notificacoes boas_vindas WhatsApp (qualquer cliente) ==\n";
$st = $pdo->query("SELECT n.id, c.name client_name, LENGTH(n.mensagem) AS len FROM notificacoes_cliente n LEFT JOIN clients c ON c.id=n.client_id WHERE n.tipo='boas_vindas' AND n.canal='whatsapp' ORDER BY n.id DESC LIMIT 8");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  notif#{$r['id']} cliente={$r['client_name']} len={$r['len']}\n";
}
