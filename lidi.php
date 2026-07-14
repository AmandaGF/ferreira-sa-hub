<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CLIENTS 'Lidiane' / 'Liliane' / 'Leidiane' / 'Lydiane' ===\n";
$st = $pdo->query("SELECT id, name, cpf, phone, email FROM clients
                   WHERE name LIKE '%Lidian%' OR name LIKE '%Lilian%' OR name LIKE '%Leidian%' OR name LIKE '%Lydian%'
                   ORDER BY name");
foreach ($st as $r) printf("  #%d %-38s cpf=%s tel=%s\n", $r['id'], $r['name'], $r['cpf']?:'-', $r['phone']?:'-');

echo "\n=== CASES com titulo 'Trabalhista' + qualquer nome tipo Lidiane/Liliane/etc ===\n";
$st = $pdo->query("SELECT cs.id, cs.title, cs.case_type, cs.status, cs.client_id, c.name AS client_name
                   FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id
                   WHERE cs.title LIKE '%Trabalhista%'
                      OR cs.case_type LIKE '%rabalhist%'
                      OR cs.title LIKE '%Lidian%' OR cs.title LIKE '%Lilian%'
                      OR cs.title LIKE '%Leidian%' OR cs.title LIKE '%Lydian%'
                   ORDER BY cs.updated_at DESC LIMIT 30");
foreach ($st as $r) printf("  #%d [%s] '%s' client#%s (%s)\n", $r['id'], $r['status'], substr($r['title'],0,55), $r['client_id']?:'-', substr($r['client_name']??'',0,30));

echo "\n=== case_partes com nome tipo Lidiane/Liliane/etc ===\n";
$st = $pdo->query("SELECT cp.id parte_id, cp.case_id, cp.nome, cp.cpf, cp.papel, cp.client_id, cs.title, cs.case_type
                   FROM case_partes cp LEFT JOIN cases cs ON cs.id = cp.case_id
                   WHERE cp.nome LIKE '%Lidian%' OR cp.nome LIKE '%Lilian%'
                      OR cp.nome LIKE '%Leidian%' OR cp.nome LIKE '%Lydian%'
                   ORDER BY cp.id DESC LIMIT 20");
foreach ($st as $r) printf("  parte#%d case#%s '%s' papel=%s client#%s | case='%s' (tipo=%s)\n",
    $r['parte_id'], $r['case_id']?:'-', $r['nome'], $r['papel'], $r['client_id']?:'-',
    substr($r['title']??'',0,40), substr($r['case_type']??'',0,20));
