<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Diagnóstico Leidiane de Assis ===\n\n";

// 1. Buscar em form_submissions
$stmt = $pdo->query("SELECT id, form_type, protocol, linked_client_id, status, created_at, payload_json FROM form_submissions WHERE payload_json LIKE '%Leidiane%' OR payload_json LIKE '%leidiane%' ORDER BY created_at DESC");
$forms = $stmt->fetchAll();
echo "Form submissions: " . count($forms) . "\n";
foreach ($forms as $f) {
    echo "  ID={$f['id']} | Tipo={$f['form_type']} | Protocolo={$f['protocol']} | Client={$f['linked_client_id']} | Status={$f['status']} | Data={$f['created_at']}\n";
    $payload = json_decode($f['payload_json'], true);
    if ($payload) {
        echo "    Nome: " . ($payload['nome'] ?? $payload['name'] ?? '?') . "\n";
        echo "    CPF: " . ($payload['cpf'] ?? '?') . "\n";
        echo "    Email: " . ($payload['email'] ?? '?') . "\n";
        echo "    Telefone: " . ($payload['telefone'] ?? $payload['phone'] ?? '?') . "\n";
    }
}

// 2. Buscar em clients
$stmt2 = $pdo->query("SELECT id, name, cpf, phone, email, client_status, source, created_at FROM clients WHERE name LIKE '%Leidiane%' OR name LIKE '%leidiane%' ORDER BY created_at DESC");
$clients = $stmt2->fetchAll();
echo "\nClients: " . count($clients) . "\n";
foreach ($clients as $c) {
    echo "  ID={$c['id']} | {$c['name']} | CPF={$c['cpf']} | Phone={$c['phone']} | Status={$c['client_status']} | Source={$c['source']} | Data={$c['created_at']}\n";
}

// 3. Buscar em pipeline_leads
$stmt3 = $pdo->query("SELECT l.id, l.client_id, l.stage, l.created_at, c.name FROM pipeline_leads l LEFT JOIN clients c ON c.id = l.client_id WHERE c.name LIKE '%Leidiane%' ORDER BY l.created_at DESC");
$leads = $stmt3->fetchAll();
echo "\nPipeline leads: " . count($leads) . "\n";
foreach ($leads as $l) {
    echo "  Lead={$l['id']} | Client={$l['client_id']} ({$l['name']}) | Stage={$l['stage']} | Data={$l['created_at']}\n";
}

// 4. Quem é client 2309?
$stmt5 = $pdo->query("SELECT id, name, cpf, phone, email FROM clients WHERE id = 2309");
$c2309 = $stmt5->fetch();
echo "\nClient #2309: " . ($c2309 ? $c2309['name'] . " | CPF=" . $c2309['cpf'] . " | Phone=" . $c2309['phone'] : 'NÃO EXISTE') . "\n";

// 5. Buscar por CPF 165.911.327-08
$stmt6 = $pdo->query("SELECT id, name, cpf FROM clients WHERE REPLACE(REPLACE(cpf,'.',''),'-','') = '16591132708'");
$byCpf = $stmt6->fetchAll();
echo "\nClients com CPF 165.911.327-08: " . count($byCpf) . "\n";
foreach ($byCpf as $bc) echo "  ID={$bc['id']} | {$bc['name']} | CPF={$bc['cpf']}\n";

// 6. Verificar últimos form_submissions para ver se há erro
$stmt4 = $pdo->query("SELECT id, form_type, protocol, linked_client_id, status, created_at FROM form_submissions ORDER BY created_at DESC LIMIT 10");
echo "\nÚltimos 10 form_submissions:\n";
foreach ($stmt4->fetchAll() as $f) {
    echo "  ID={$f['id']} | {$f['form_type']} | {$f['protocol']} | Client={$f['linked_client_id']} | {$f['status']} | {$f['created_at']}\n";
}
