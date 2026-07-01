<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diag Tamyris Carvalho ===\n\n";

// Achar cliente
$cli = $pdo->query("SELECT id, name, cpf, email, phone FROM clients WHERE name LIKE '%Tamyris%Carvalho%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cli as $c) echo "  Cliente #{$c['id']}: {$c['name']} · CPF {$c['cpf']} · email {$c['email']} · tel {$c['phone']}\n";

echo "\n--- Schema form_submissions (campos relevantes) ---\n";
$cols = $pdo->query("DESCRIBE form_submissions")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    $n = $col['Field'];
    if (in_array($n, array('id','form_type','status','submitter_name','submitter_email','submitter_phone','submitter_cpf','client_id','linked_client_id','case_id','created_at','protocol'))) {
        echo "  {$n} ({$col['Type']})\n";
    }
}

foreach ($cli as $c) {
    $clientId = (int)$c['id'];
    echo "\n=== Cliente #{$clientId}: {$c['name']} ===\n";

    // Vínculo direto por linked_client_id
    echo "\n-- form_submissions com linked_client_id = {$clientId} --\n";
    try {
        $st = $pdo->prepare("SELECT id, form_type, status, submitter_name, submitter_email, submitter_phone, created_at FROM form_submissions WHERE linked_client_id = ? ORDER BY id DESC LIMIT 10");
        $st->execute(array($clientId));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  #{$r['id']} {$r['form_type']} {$r['status']} nome={$r['submitter_name']} em={$r['created_at']}\n";
    } catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

    // Vínculo por client_id (nome antigo?)
    echo "\n-- form_submissions com client_id = {$clientId} (caso schema use) --\n";
    try {
        $st = $pdo->prepare("SELECT id, form_type, status, submitter_name, created_at FROM form_submissions WHERE client_id = ? ORDER BY id DESC LIMIT 10");
        $st->execute(array($clientId));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  #{$r['id']} {$r['form_type']} {$r['status']} nome={$r['submitter_name']} em={$r['created_at']}\n";
    } catch (Exception $e) { echo "  (coluna client_id não existe)\n"; }

    // Match por CPF / email / phone (sem vínculo formal)
    echo "\n-- form_submissions com CPF/email/tel iguais aos do cliente --\n";
    $cpfDig = preg_replace('/\D/', '', (string)$c['cpf']);
    $telDig = preg_replace('/\D/', '', (string)$c['phone']);
    try {
        $st = $pdo->prepare("SELECT id, form_type, status, submitter_name, submitter_email, submitter_phone, linked_client_id, created_at
                             FROM form_submissions
                             WHERE (submitter_email = ? AND submitter_email <> '')
                                OR REPLACE(REPLACE(REPLACE(REPLACE(submitter_phone,'(',''),')',''),'-',''),' ','') = ?
                             ORDER BY id DESC LIMIT 10");
        $st->execute(array($c['email'], $telDig));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) echo "  #{$r['id']} {$r['form_type']} status={$r['status']} nome={$r['submitter_name']} email={$r['submitter_email']} tel={$r['submitter_phone']} linkedTo={$r['linked_client_id']} em={$r['created_at']}\n";
    } catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
}
