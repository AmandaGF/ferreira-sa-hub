<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== 1) ALTER notifications.link -> TEXT ==\n";
try {
    $pdo->exec("ALTER TABLE notifications MODIFY link TEXT");
    echo "  OK\n";
} catch (Exception $e) {
    echo "  Erro: " . $e->getMessage() . "\n";
}

echo "\n== 2) Backfill: reconstruir links truncados (length=500) ==\n";
// Pega notifications truncadas que sao links wa.me
$st = $pdo->prepare("SELECT n.id, n.link, n.title FROM notifications n WHERE LENGTH(n.link) >= 500 AND n.link LIKE 'https://wa.me/%' ORDER BY n.id DESC");
$st->execute();
$truncadas = $st->fetchAll(PDO::FETCH_ASSOC);
echo "  Encontradas: " . count($truncadas) . "\n";

$consertadas = 0;
foreach ($truncadas as $n) {
    // Extrai phone do inicio do link (entre wa.me/ e ?)
    if (!preg_match('#wa\.me/(\d+)\?text=#', $n['link'], $m)) continue;
    $phone = $m[1];
    // Busca a notificacoes_cliente mais recente com esse phone (canal whatsapp)
    $stNc = $pdo->prepare("SELECT mensagem FROM notificacoes_cliente WHERE canal='whatsapp' AND REPLACE(REPLACE(destinatario,' ',''),'-','') LIKE ? ORDER BY id DESC LIMIT 1");
    $phoneSimple = substr($phone, -11); // pega ultimos 11 digitos (DDD+numero)
    $stNc->execute(array('%' . $phoneSimple . '%'));
    $msg = $stNc->fetchColumn();
    if (!$msg) { continue; }
    // Reconstroi link
    $novoLink = 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
    $pdo->prepare("UPDATE notifications SET link = ? WHERE id = ?")->execute(array($novoLink, $n['id']));
    $consertadas++;
}
echo "  Consertadas: $consertadas\n";

echo "\n== 3) Sample verificacao ==\n";
$st = $pdo->query("SELECT id, title, LENGTH(link) AS llen FROM notifications WHERE title LIKE '%Thiago Cassiano%' OR title LIKE '%Camila Bosi%' ORDER BY id DESC LIMIT 5");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  notif#{$r['id']} llen={$r['llen']} | {$r['title']}\n";
}

echo "\nFIM\n";
