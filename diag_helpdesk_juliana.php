<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG helpdesk Juliana Assumpcao ===\n\n";

// Acha o case pelo numero mostrado no print
echo "-- Case Juliana Assumpcao x Alimentos --\n";
$st = $pdo->query("SELECT id, title, client_id, case_number FROM cases WHERE case_number = '0821633-31.2025.8.19.0203' OR title LIKE '%Juliana%Assump%' LIMIT 5");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  case #{$c['id']} client_id=$c[client_id] · {$c['title']} · {$c['case_number']}\n";
    $caseId = (int)$c['id'];
    $clientId = (int)$c['client_id'];

    // Cliente do case
    $stCl = $pdo->prepare("SELECT id, name, cpf, phone, email FROM clients WHERE id=?");
    $stCl->execute(array($clientId));
    $cli = $stCl->fetch(PDO::FETCH_ASSOC);
    if ($cli) echo "     cliente principal: #{$cli['id']} {$cli['name']} (CPF=$cli[cpf] phone=$cli[phone] email=$cli[email])\n";

    // Tickets vinculados a esse case ou client
    echo "     Tickets vinculados:\n";
    $stT = $pdo->prepare("SELECT id, title, origem, case_id, client_id, requester_id, status, created_at FROM tickets WHERE case_id=? OR client_id=? ORDER BY created_at DESC LIMIT 20");
    $stT->execute(array($caseId, $clientId));
    $ticks = $stT->fetchAll(PDO::FETCH_ASSOC);
    if (!$ticks) echo "       (nenhum ticket)\n";
    foreach ($ticks as $t) {
        echo "       ticket #$t[id] origem=$t[origem] case_id=" . ($t['case_id']?:'NULL') . " client_id=$t[client_id] req_id=" . ($t['requester_id']?:'NULL') . " status=$t[status] em=$t[created_at]\n";
        echo "          title: $t[title]\n";
    }
}

echo "\n-- Tickets com nome Juliana (busca ampla) --\n";
$st = $pdo->query("SELECT t.id, t.title, t.origem, t.case_id, t.client_id, t.status, t.created_at,
                          cl.name AS cli_nome
                   FROM tickets t
                   LEFT JOIN clients cl ON cl.id = t.client_id
                   WHERE cl.name LIKE '%Juliana%'
                   ORDER BY t.created_at DESC LIMIT 20");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ticket #$r[id] origem=$r[origem] case_id=" . ($r['case_id']?:'NULL') . " client_id=$r[client_id] ($r[cli_nome]) status=$r[status]\n";
    echo "     title: $r[title]\n";
}

// Tickets da Central VIP (todos)
echo "\n-- Ultimos 15 tickets origem=salavip (Central VIP) --\n";
$st = $pdo->query("SELECT t.id, t.title, t.case_id, t.client_id, t.status, t.created_at,
                          cl.name AS cli_nome
                   FROM tickets t
                   LEFT JOIN clients cl ON cl.id = t.client_id
                   WHERE t.origem = 'salavip'
                   ORDER BY t.created_at DESC LIMIT 15");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #$r[id] cli=#$r[client_id] ($r[cli_nome]) case=" . ($r['case_id']?:'NULL') . " status=$r[status] em=$r[created_at]\n";
    echo "     title: $r[title]\n";
}
