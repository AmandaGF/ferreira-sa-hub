<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "=== CLIENTES com nome contendo 'Rayane' ===\n";
$st = $pdo->query("SELECT id, name, phone, whatsapp_lid, birth_date FROM clients WHERE name LIKE '%Rayane%' OR name LIKE '%RAYANE%'");
foreach ($st->fetchAll() as $c) {
    echo "  id={$c['id']} | nome={$c['name']} | phone={$c['phone']} | lid={$c['whatsapp_lid']} | nasc={$c['birth_date']}\n";
}

echo "\n=== CLIENTES com nome contendo 'Joyce' ===\n";
$st = $pdo->query("SELECT id, name, phone, whatsapp_lid, birth_date FROM clients WHERE name LIKE '%Joyce%'");
foreach ($st->fetchAll() as $c) {
    echo "  id={$c['id']} | nome={$c['name']} | phone={$c['phone']} | lid={$c['whatsapp_lid']} | nasc={$c['birth_date']}\n";
}

echo "\n=== Aniversariantes de HOJE (ainda não parabenizados) ===\n";
$st = $pdo->query("SELECT c.id, c.name, c.phone, c.whatsapp_lid, c.birth_date,
                          (SELECT COUNT(*) FROM clients c2 WHERE c2.phone = c.phone AND c2.id != c.id) AS outros_com_mesmo_tel
                   FROM clients c
                   WHERE c.birth_date IS NOT NULL
                     AND MONTH(c.birth_date) = MONTH(CURDATE())
                     AND DAY(c.birth_date)   = DAY(CURDATE())
                     AND c.phone IS NOT NULL AND c.phone != ''");
foreach ($st->fetchAll() as $c) {
    echo "  id={$c['id']} | {$c['name']} | phone={$c['phone']} | lid={$c['whatsapp_lid']} | nasc={$c['birth_date']} | outros_com_mesmo_tel={$c['outros_com_mesmo_tel']}\n";
}

echo "\n=== Clientes com TELEFONES DUPLICADOS (mesmo phone, 2+ clients) ===\n";
$st = $pdo->query("SELECT phone, COUNT(*) as qtd, GROUP_CONCAT(CONCAT(id,':',name) SEPARATOR ' || ') as clientes
                   FROM clients
                   WHERE phone IS NOT NULL AND phone != ''
                   GROUP BY phone HAVING qtd > 1 ORDER BY qtd DESC LIMIT 30");
foreach ($st->fetchAll() as $r) {
    echo "  phone={$r['phone']} ({$r['qtd']}x): {$r['clientes']}\n";
}
