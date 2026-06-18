<?php
/** Diag: Francine/Alex Sandro — mesmo telefone, lead some do kanban.
 *  curl "https://ferreiraesa.com.br/conecta/diag_francine.php?key=fsa-hub-deploy-2026"
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// telefone do form #700 da Francine
$tel8 = '98192-6615'; $telDig = '981926615';

echo "=== FORM_SUBMISSIONS (Francine ou Alex) ===\n";
$f = $pdo->prepare("SELECT id, protocol, form_type, status, client_name, client_phone, linked_client_id, created_at
                    FROM form_submissions
                    WHERE client_name LIKE '%Francine%' OR client_name LIKE '%Alex%Sandro%' OR REPLACE(REPLACE(REPLACE(REPLACE(client_phone,'(',''),')',''),'-',''),' ','') LIKE ?
                    ORDER BY id");
$f->execute(array('%' . $telDig . '%'));
foreach ($f->fetchAll() as $r) {
    echo "form #{$r['id']}: {$r['protocol']} | {$r['client_name']} | tel={$r['client_phone']} | status={$r['status']} | linked_client_id={$r['linked_client_id']} | {$r['created_at']}\n";
}
echo "\n";

echo "=== CLIENTS (por telefone OU nome) ===\n";
$c = $pdo->prepare("SELECT id, name, phone, email, cpf, source, created_at FROM clients
                    WHERE name LIKE '%Francine%' OR name LIKE '%Alex%Sandro%'
                       OR REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ','') LIKE ? ORDER BY id");
$c->execute(array('%' . $telDig . '%'));
$clientes = $c->fetchAll();
foreach ($clientes as $r) {
    echo "client #{$r['id']}: {$r['name']} | tel={$r['phone']} | cpf={$r['cpf']} | source={$r['source']} | criado={$r['created_at']}\n";
}
if (!$clientes) echo "(nenhum)\n";
echo "\n";

echo "=== PIPELINE_LEADS (por telefone OU nome) ===\n";
$l = $pdo->prepare("SELECT id, name, phone, stage, client_id, source, linked_case_id, created_at, updated_at FROM pipeline_leads
                    WHERE name LIKE '%Francine%' OR name LIKE '%Alex%Sandro%'
                       OR REPLACE(REPLACE(REPLACE(REPLACE(phone,'(',''),')',''),'-',''),' ','') LIKE ? ORDER BY id");
$l->execute(array('%' . $telDig . '%'));
$leads = $l->fetchAll();
foreach ($leads as $r) {
    echo "lead #{$r['id']}: {$r['name']} | stage={$r['stage']} | client_id={$r['client_id']} | linked_case_id={$r['linked_case_id']} | criado={$r['created_at']} | atualizado={$r['updated_at']}\n";
}
if (!$leads) echo "(nenhum)\n";
echo "\n";

echo "=== CASES (por client_id dos achados) ===\n";
$ids = array();
foreach ($clientes as $r) $ids[] = (int)$r['id'];
if ($ids) {
    $in = implode(',', $ids);
    foreach ($pdo->query("SELECT id, title, stage, client_id, created_at FROM cases WHERE client_id IN ($in) ORDER BY id")->fetchAll() as $r) {
        echo "case #{$r['id']}: {$r['title']} | stage={$r['stage']} | client_id={$r['client_id']} | {$r['created_at']}\n";
    }
} else { echo "(sem client_id pra buscar)\n"; }

echo "\n=== PAYLOAD do form #700 (dados REAIS da Francine) ===\n";
$p = $pdo->query("SELECT payload_json FROM form_submissions WHERE id = 700")->fetchColumn();
$pa = json_decode($p, true);
if (is_array($pa)) { foreach ($pa as $k => $v) { if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE); echo "  $k = $v\n"; } }
echo "\n=== CLIENT #2473 (Alex — checar contaminacao pela Francine) ===\n";
$cli = $pdo->query("SELECT * FROM clients WHERE id = 2473")->fetch(PDO::FETCH_ASSOC);
if ($cli) { foreach ($cli as $k => $v) { if ($v !== null && $v !== '') echo "  $k = $v\n"; } }
echo "\n=== FIM ===\n";
