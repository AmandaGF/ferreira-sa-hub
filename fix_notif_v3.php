<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

// Map titulo da notif -> tipo em notificacao_config
$tipoMap = array(
    'Boas-vindas ao cliente' => 'boas_vindas',
    'Documentos recebidos' => 'docs_recebidos',
    'Processo distribuído' => 'processo_distribuido',
    'Documento faltante' => 'doc_faltante',
);

echo "== Backfill V3 - tipo + cliente normalizado ==\n";
$st = $pdo->prepare("SELECT n.id, n.title, n.link FROM notifications n WHERE LENGTH(n.link) = 500 AND n.link LIKE 'https://wa.me/%'");
$st->execute();
$truncadas = $st->fetchAll(PDO::FETCH_ASSOC);
echo "  Trunc ainda: " . count($truncadas) . "\n";

$ok = 0; $semCli = 0; $semNc = 0; $semTipo = 0;
foreach ($truncadas as $n) {
    // Extrai phone (ultimos 11 digitos)
    if (!preg_match('#wa\.me/(\d+)\?#', $n['link'], $mp)) continue;
    $phone = $mp[1];
    $phone11 = substr(preg_replace('/\D/', '', $phone), -11);
    if (strlen($phone11) < 10) continue;

    // Identifica tipo a partir do prefixo do title
    $tipo = null;
    foreach ($tipoMap as $prefix => $t) {
        if (strpos($n['title'], $prefix) === 0) { $tipo = $t; break; }
    }
    if (!$tipo) { $semTipo++; continue; }

    // Acha client por phone normalizado (apenas digitos)
    $stCli = $pdo->prepare("SELECT id, name FROM clients WHERE REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ? LIMIT 1");
    $stCli->execute(array('%' . $phone11));
    $cli = $stCli->fetch(PDO::FETCH_ASSOC);
    if (!$cli) { $semCli++; continue; }

    // Acha mensagem mais recente em notificacoes_cliente desse client + tipo + canal whatsapp
    $stMsg = $pdo->prepare("SELECT mensagem FROM notificacoes_cliente WHERE client_id = ? AND tipo = ? AND canal='whatsapp' ORDER BY id DESC LIMIT 1");
    $stMsg->execute(array($cli['id'], $tipo));
    $msg = $stMsg->fetchColumn();
    if (!$msg) { $semNc++; continue; }

    // Reconstrói link com mensagem completa
    $novoLink = 'https://wa.me/' . $phone . '?text=' . rawurlencode($msg);
    $pdo->prepare("UPDATE notifications SET link = ? WHERE id = ?")->execute(array($novoLink, $n['id']));
    $ok++;
}
echo "  OK: $ok | sem_tipo: $semTipo | sem_cliente: $semCli | sem_msg_nc: $semNc\n";

echo "\n== Sample apos V3 ==\n";
$st = $pdo->query("SELECT id, title, LENGTH(link) AS llen FROM notifications WHERE title LIKE '%Thiago Cassiano%' OR title LIKE '%Camila Bosi%' ORDER BY id DESC LIMIT 6");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  notif#{$r['id']} llen={$r['llen']} | {$r['title']}\n";
}

echo "\n== Restantes truncadas ==\n";
$tot = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE LENGTH(link) = 500 AND link LIKE 'https://wa.me/%'")->fetchColumn();
echo "  Ainda truncadas: $tot\n";
