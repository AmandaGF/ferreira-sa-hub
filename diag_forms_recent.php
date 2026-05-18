<?php
/** READ-ONLY: últimos envios de TODOS os tipos + busca por nome. ?key=fsa-hub-deploy-2026 [&q=erika] */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$vid = (int)($_GET['id'] ?? 0);
if ($vid) {
    $s = $pdo->prepare("SELECT * FROM form_submissions WHERE id = ?"); $s->execute(array($vid));
    $r = $s->fetch();
    if (!$r) exit("id $vid nao encontrado\n");
    echo "#{$r['id']} {$r['form_type']} {$r['protocol']} {$r['client_name']} {$r['client_phone']} {$r['created_at']} status={$r['status']}\n" . str_repeat('-',60) . "\n";
    $p = json_decode($r['payload_json'], true);
    if (!is_array($p)) { echo "payload_json invalido. Cru:\n".substr($r['payload_json'],0,3000); exit; }
    echo "CHAVES (".count($p)."):\n";
    foreach ($p as $k=>$v){ echo "  {$k} = ".(is_scalar($v)?(is_string($v)?'"'.mb_substr($v,0,90).'"':$v):'['.gettype($v).']')."\n"; }
    exit;
}
echo "=== CONTAGEM POR TIPO ===\n";
foreach ($pdo->query("SELECT form_type, COUNT(*) c, MAX(created_at) ult FROM form_submissions GROUP BY form_type ORDER BY ult DESC")->fetchAll() as $t)
    echo sprintf("  %-22s %4d  último: %s\n", $t['form_type'], $t['c'], $t['ult']);
echo "\n=== ÚLTIMOS 20 (qualquer tipo) ===\n";
foreach ($pdo->query("SELECT id, form_type, protocol, client_name, created_at FROM form_submissions ORDER BY id DESC LIMIT 20")->fetchAll() as $r)
    echo "  #{$r['id']} | {$r['created_at']} | {$r['form_type']} | {$r['protocol']} | {$r['client_name']}\n";
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    echo "\n=== BUSCA '{$q}' (nome/telefone/payload) ===\n";
    $like = '%' . $q . '%';
    $s = $pdo->prepare("SELECT id, form_type, protocol, client_name, client_phone, created_at
                        FROM form_submissions
                        WHERE client_name LIKE ? OR client_phone LIKE ? OR payload_json LIKE ?
                        ORDER BY id DESC LIMIT 25");
    $s->execute(array($like, $like, $like));
    $rs = $s->fetchAll();
    if (!$rs) echo "  (nada)\n";
    foreach ($rs as $r) echo "  #{$r['id']} | {$r['created_at']} | {$r['form_type']} | {$r['protocol']} | {$r['client_name']} | {$r['client_phone']}\n";
}
