<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$cli = $pdo->query("SELECT id, name, cpf, email, phone FROM clients WHERE name LIKE '%Tamyris%Carvalho%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$c = $cli[0];
echo "Cliente #{$c['id']}: {$c['name']}\n  email={$c['email']}\n  tel={$c['phone']}\n\n";

$telDig = preg_replace('/\D/', '', (string)$c['phone']);

// Buscar match por email/telefone/nome (sem depender de linked_client_id)
echo "--- form_submissions com dados que batem com Tamyris ---\n";
$st = $pdo->prepare("SELECT id, form_type, status, client_name, client_email, client_phone, linked_client_id, linked_case_id, created_at
                     FROM form_submissions
                     WHERE (client_email <> '' AND client_email = ?)
                        OR REPLACE(REPLACE(REPLACE(REPLACE(client_phone,'(',''),')',''),'-',''),' ','') = ?
                        OR client_name LIKE ?
                     ORDER BY id DESC LIMIT 20");
$st->execute(array($c['email'], $telDig, '%Tamyris%'));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Achados: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "  #{$r['id']} tipo={$r['form_type']} status={$r['status']} em={$r['created_at']}\n";
    echo "    client_name: {$r['client_name']}\n";
    echo "    client_email: {$r['client_email']}\n";
    echo "    client_phone: {$r['client_phone']}\n";
    echo "    linked_client_id: " . ($r['linked_client_id'] ?: '(NULL)') . "\n";
    echo "    linked_case_id: " . ($r['linked_case_id'] ?: '(NULL)') . "\n\n";
}
