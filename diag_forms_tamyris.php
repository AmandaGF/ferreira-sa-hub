<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diag Tamyris Carvalho ===\n\n";

// Achar cliente
$cli = $pdo->query("SELECT id, name, cpf, email, phone FROM clients WHERE name LIKE '%Tamyris%Carvalho%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cli as $c) echo "  Cliente #{$c['id']}: {$c['name']} · CPF {$c['cpf']} · email {$c['email']} · tel {$c['phone']}\n";

echo "\n--- Schema form_submissions (TODOS os campos) ---\n";
$cols = $pdo->query("DESCRIBE form_submissions")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $col) {
    echo "  {$col['Field']} ({$col['Type']})\n";
}

foreach ($cli as $c) {
    $clientId = (int)$c['id'];
    echo "\n=== Cliente #{$clientId}: {$c['name']} ===\n";

    // Vínculo direto por linked_client_id (só campos que sabemos que existem)
    echo "\n-- form_submissions com linked_client_id = {$clientId} --\n";
    try {
        $st = $pdo->prepare("SELECT * FROM form_submissions WHERE linked_client_id = ? ORDER BY id DESC LIMIT 5");
        $st->execute(array($clientId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo "  Total: " . count($rows) . "\n";
        foreach ($rows as $r) {
            echo "  #{$r['id']} {$r['form_type']} status={$r['status']} em={$r['created_at']}\n";
            // Mostrar chaves que possam ter dados do submitter
            foreach ($r as $k => $v) {
                if (strpos($k, 'nome') !== false || strpos($k, 'name') !== false || strpos($k, 'email') !== false || strpos($k, 'phone') !== false || strpos($k, 'telefone') !== false) {
                    if ($v !== null && $v !== '') echo "    {$k} = {$v}\n";
                }
            }
        }
    } catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
}
