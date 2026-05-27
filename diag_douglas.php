<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

$nome = trim($_GET['nome'] ?? 'Douglas Silva');

echo "=== Investigando: $nome ===\n\n";

echo "--- 1) Clientes com esse nome em 'clients' ---\n";
$st = $pdo->prepare("SELECT id, name, cpf, phone, phone2, email, created_at FROM clients WHERE name LIKE ? ORDER BY id");
$st->execute(array('%' . $nome . '%'));
$cls = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$cls) { echo "  (nenhum)\n"; exit; }
foreach ($cls as $c) {
    echo "  #{$c['id']}  '{$c['name']}'\n";
    echo "       cpf='{$c['cpf']}'  phone='{$c['phone']}'  phone2='" . ($c['phone2'] ?? '') . "'  email='" . ($c['email'] ?? '') . "'\n";
    echo "       criado em " . $c['created_at'] . "\n";
}

echo "\n--- 2) Casos vinculados a cada client_id ---\n";
foreach ($cls as $c) {
    $st = $pdo->prepare("SELECT id, title, case_number, status, created_at FROM cases WHERE client_id = ? ORDER BY id DESC");
    $st->execute(array($c['id']));
    $r = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "  client#{$c['id']} '{$c['name']}': " . count($r) . " caso(s)\n";
    foreach ($r as $cs) echo "       case#{$cs['id']}  '{$cs['title']}'  status={$cs['status']}  cnj=" . ($cs['case_number'] ?: '-') . "\n";
}

echo "\n--- 3) Conversas WhatsApp vinculadas ---\n";
foreach ($cls as $c) {
    $st = $pdo->prepare("SELECT id, canal, telefone, nome_contato, atualizado_em FROM zapi_conversas WHERE client_id = ? ORDER BY atualizado_em DESC");
    $st->execute(array($c['id']));
    $r = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "  client#{$c['id']} '{$c['name']}': " . count($r) . " conversa(s)\n";
    foreach ($r as $cv) echo "       conv#{$cv['id']}  canal={$cv['canal']}  tel='{$cv['telefone']}'  nome='{$cv['nome_contato']}'  ult.=" . $cv['atualizado_em'] . "\n";
}

echo "\n--- 4) Leads no Pipeline ---\n";
foreach ($cls as $c) {
    $st = $pdo->prepare("SELECT id, name, phone, stage, linked_case_id FROM pipeline_leads WHERE client_id = ? ORDER BY id");
    $st->execute(array($c['id']));
    $r = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "  client#{$c['id']} '{$c['name']}': " . count($r) . " lead(s)\n";
    foreach ($r as $l) echo "       lead#{$l['id']}  '{$l['name']}'  phone='{$l['phone']}'  stage={$l['stage']}  linked_case=" . ($l['linked_case_id'] ?: '-') . "\n";
}

echo "\n=== FIM ===\n";
echo "\nDiagnostico:\n";
echo "- Se ha 2+ clients com mesmo nome -> duplicata, casos/conversas espalhados\n";
echo "- Quero o id do cliente 'oficial' (conversa WA atual + caso atual)\n";
echo "  pra eu rodar um merge: UPDATE cases SET client_id=oficial WHERE client_id=duplicado\n";
echo "                          UPDATE pipeline_leads SET client_id=oficial WHERE ...\n";
echo "                          DELETE duplicado\n";
