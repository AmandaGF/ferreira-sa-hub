<?php
/** READ-ONLY: últimos envios de TODOS os tipos + busca por nome. ?key=fsa-hub-deploy-2026 [&q=erika] */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
echo "=== CONTAGEM POR TIPO ===\n";
foreach ($pdo->query("SELECT form_type, COUNT(*) c, MAX(created_at) ult FROM form_submissions GROUP BY form_type ORDER BY ult DESC")->fetchAll() as $t)
    echo sprintf("  %-22s %4d  último: %s\n", $t['form_type'], $t['c'], $t['ult']);
echo "\n=== ÚLTIMOS 20 (qualquer tipo) ===\n";
foreach ($pdo->query("SELECT id, form_type, protocol, client_name, created_at FROM form_submissions ORDER BY id DESC LIMIT 20")->fetchAll() as $r)
    echo "  #{$r['id']} | {$r['created_at']} | {$r['form_type']} | {$r['protocol']} | {$r['client_name']}\n";
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    echo "\n=== BUSCA '{$q}' ===\n";
    $s = $pdo->prepare("SELECT id, form_type, protocol, client_name, created_at FROM form_submissions WHERE client_name LIKE ? ORDER BY id DESC LIMIT 20");
    $s->execute(array('%' . $q . '%'));
    foreach ($s->fetchAll() as $r) echo "  #{$r['id']} | {$r['created_at']} | {$r['form_type']} | {$r['protocol']} | {$r['client_name']}\n";
}
