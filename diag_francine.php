<?php
/** Diag: Francine Batista da Costa some do kanban + nao gera documentos.
 *  curl "https://ferreiraesa.com.br/conecta/diag_francine.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$nome = '%Francine%Batista%';

echo "=== CLIENTS ===\n";
$c = $pdo->prepare("SELECT id, name, phone, email, cpf, source, created_at, updated_at FROM clients WHERE name LIKE ? ORDER BY id");
$c->execute(array($nome));
$clientes = $c->fetchAll();
foreach ($clientes as $r) {
    echo "client #{$r['id']}: {$r['name']} | tel={$r['phone']} | cpf={$r['cpf']} | source={$r['source']} | criado={$r['created_at']}\n";
}
if (!$clientes) echo "(nenhum cliente)\n";
echo "\n";

echo "=== PIPELINE_LEADS ===\n";
$l = $pdo->prepare("SELECT id, name, phone, stage, client_id, source, case_type, linked_case_id, created_at, updated_at FROM pipeline_leads WHERE name LIKE ? ORDER BY id");
$l->execute(array($nome));
$leads = $l->fetchAll();
foreach ($leads as $r) {
    echo "lead #{$r['id']}: stage={$r['stage']} | client_id={$r['client_id']} | linked_case_id={$r['linked_case_id']} | source={$r['source']} | criado={$r['created_at']} | atualizado={$r['updated_at']}\n";
}
if (!$leads) echo "(nenhum lead)\n";
echo "\n";

echo "=== CASES ===\n";
$cs = $pdo->prepare("SELECT id, title, stage, client_id, created_at FROM cases WHERE title LIKE ? ORDER BY id");
$cs->execute(array($nome));
$casos = $cs->fetchAll();
foreach ($casos as $r) {
    echo "case #{$r['id']}: {$r['title']} | stage={$r['stage']} | client_id={$r['client_id']} | criado={$r['created_at']}\n";
}
if (!$casos) echo "(nenhum caso)\n";
echo "\n";

echo "=== FORM_SUBMISSIONS ===\n";
$f = $pdo->prepare("SELECT id, protocol, form_type, status, linked_client_id, created_at FROM form_submissions WHERE client_name LIKE ? ORDER BY id");
$f->execute(array($nome));
foreach ($f->fetchAll() as $r) {
    echo "form #{$r['id']}: {$r['protocol']} | tipo={$r['form_type']} | status={$r['status']} | linked_client_id={$r['linked_client_id']} | {$r['created_at']}\n";
}
echo "\n=== FIM ===\n";
