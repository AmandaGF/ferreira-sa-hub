<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Conv WhatsApp do Jhonatan ===\n";
$st = $pdo->query("SELECT id, telefone, nome_contato, client_id FROM zapi_conversas WHERE nome_contato LIKE '%Jhonatan%' OR telefone LIKE '%99957-8792%' OR telefone LIKE '%99957879%'");
foreach ($st->fetchAll() as $c) {
    echo "  conv #{$c['id']} | tel={$c['telefone']} | nome={$c['nome_contato']} | client_id=" . ($c['client_id'] ?: 'NULL') . "\n";
}

echo "\n=== Cliente Jhonatan no banco ===\n";
$st = $pdo->query("SELECT id, name, phone FROM clients WHERE name LIKE '%Jhonatan%'");
foreach ($st->fetchAll() as $c) {
    echo "  client #{$c['id']} | name={$c['name']} | phone={$c['phone']}\n";
}

echo "\n=== Testa a query da proxima_audiencia (cliId conforme conv WhatsApp) ===\n";
$convsJhon = $pdo->query("SELECT client_id FROM zapi_conversas WHERE nome_contato LIKE '%Jhonatan%' LIMIT 1")->fetch();
$cliId = (int)($convsJhon['client_id'] ?? 0);
echo "client_id da conv: {$cliId}\n";

if ($cliId > 0) {
    $stAud = $pdo->prepare(
        "SELECT e.id, e.titulo, e.tipo, e.data_inicio, e.status, e.case_id
         FROM agenda_eventos e
         INNER JOIN cases cs ON cs.id = e.case_id
         WHERE e.tipo = 'audiencia'
           AND e.status NOT IN ('cancelado','realizado','nao_compareceu')
           AND e.data_inicio >= NOW()
           AND (cs.client_id = ? OR e.case_id IN (SELECT case_id FROM case_partes WHERE client_id = ?))
         ORDER BY e.data_inicio ASC LIMIT 5"
    );
    $stAud->execute(array($cliId, $cliId));
    foreach ($stAud->fetchAll() as $a) {
        echo "  Audiencia #{$a['id']} | {$a['titulo']} | {$a['data_inicio']} | status={$a['status']} | case={$a['case_id']}\n";
    }
}

echo "\nAgora: " . date('Y-m-d H:i:s') . "\n";
