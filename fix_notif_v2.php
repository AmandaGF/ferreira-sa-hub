<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== Sample destinatario de notificacoes_cliente p/ Thiago ==\n";
$st = $pdo->query("SELECT id, client_id, canal, destinatario FROM notificacoes_cliente WHERE id IN (SELECT id FROM (SELECT id FROM notificacoes_cliente WHERE tipo='boas_vindas' ORDER BY id DESC LIMIT 4) tmp)");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  nc#{$r['id']} canal={$r['canal']} dest='{$r['destinatario']}'\n";
}

echo "\n== Backfill V2: usa client_id em vez de phone ==\n";
// Estrategia: title da notification eh 'X — NomeCliente'. Vou matchar via title vs client name
// E pegar a mensagem completa da notificacoes_cliente correspondente
$st = $pdo->prepare("SELECT n.id, n.title, n.link FROM notifications n WHERE LENGTH(n.link) = 500 AND n.link LIKE 'https://wa.me/%'");
$st->execute();
$truncadas = $st->fetchAll(PDO::FETCH_ASSOC);
echo "  Notif ainda truncadas (llen=500): " . count($truncadas) . "\n";

$ok = 0;
$fail = 0;
foreach ($truncadas as $n) {
    // Pega nome do cliente do title
    if (!preg_match('/[—-]\s*(.+)$/u', $n['title'], $m)) { $fail++; continue; }
    $nomeCli = trim($m[1]);
    // Pega phone do link
    if (!preg_match('#wa\.me/(\d+)\?#', $n['link'], $mp)) { $fail++; continue; }
    $phone = $mp[1];
    // Busca mensagem completa da notificacoes_cliente correspondente (por nome do cliente + canal)
    $stNc = $pdo->prepare("SELECT nc.mensagem FROM notificacoes_cliente nc INNER JOIN clients cl ON cl.id=nc.client_id WHERE cl.name = ? AND nc.canal='whatsapp' ORDER BY nc.id DESC LIMIT 1");
    $stNc->execute(array($nomeCli));
    $msg = $stNc->fetchColumn();
    if (!$msg) {
        // tenta LIKE
        $stNc = $pdo->prepare("SELECT nc.mensagem FROM notificacoes_cliente nc INNER JOIN clients cl ON cl.id=nc.client_id WHERE cl.name LIKE ? AND nc.canal='whatsapp' ORDER BY nc.id DESC LIMIT 1");
        $stNc->execute(array('%' . $nomeCli . '%'));
        $msg = $stNc->fetchColumn();
    }
    if (!$msg) { $fail++; continue; }
    $novoLink = 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
    $pdo->prepare("UPDATE notifications SET link=? WHERE id=?")->execute(array($novoLink, $n['id']));
    $ok++;
}
echo "  Consertadas V2: $ok | Falhas: $fail\n";

echo "\n== Sample apos V2 ==\n";
$st = $pdo->query("SELECT id, title, LENGTH(link) AS llen FROM notifications WHERE title LIKE '%Thiago Cassiano%' OR title LIKE '%Camila Bosi%' ORDER BY id DESC LIMIT 5");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  notif#{$r['id']} llen={$r['llen']} | {$r['title']}\n";
}
