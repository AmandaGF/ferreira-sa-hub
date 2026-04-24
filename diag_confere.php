<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Busca em form_submissions por 'Suelen' / 'Wendel Magno' / 'Sayonara' ===\n";
$nomes = array('Suelen', 'Wendel Magno', 'Sayonara');
foreach ($nomes as $n) {
    echo "\n[Buscando '{$n}']\n";
    $q = $pdo->prepare("SELECT id, form_type, created_at, SUBSTR(payload_json,1,150) AS prev FROM form_submissions WHERE payload_json LIKE ? ORDER BY id DESC");
    $q->execute(array('%' . $n . '%'));
    $res = $q->fetchAll();
    if (!$res) echo "  NADA no form_submissions\n";
    foreach ($res as $r) echo "  #{$r['id']} [{$r['form_type']}] {$r['created_at']}\n";
}

echo "\n\n=== Busca em clients ===\n";
foreach ($nomes as $n) {
    $q = $pdo->prepare("SELECT id, name, phone, email, created_at FROM clients WHERE name LIKE ?");
    $q->execute(array('%' . $n . '%'));
    $res = $q->fetchAll();
    foreach ($res as $r) echo "  client #{$r['id']} {$r['name']} | tel={$r['phone']} | email={$r['email']} | criado {$r['created_at']}\n";
}

echo "\n\n=== Todas as 'convivencia' em form_submissions (13 antigas + testes) ===\n";
$q = $pdo->query("SELECT id, created_at, SUBSTR(payload_json,1,80) AS prev FROM form_submissions WHERE form_type='convivencia' ORDER BY id DESC");
foreach ($q->fetchAll() as $r) {
    echo "  #{$r['id']} {$r['created_at']}: {$r['prev']}\n";
}
