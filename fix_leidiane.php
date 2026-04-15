<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors',1);
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// QUEM É CLIENT 2309?
$c = $pdo->query("SELECT * FROM clients WHERE id = 2309")->fetch();
echo "=== Client #2309 ===\n";
if ($c) { echo "Nome: {$c['name']} | CPF: {$c['cpf']} | Phone: {$c['phone']} | Email: {$c['email']}\n"; }
else { echo "NÃO EXISTE!\n"; }

// Buscar por CPF da Leidiane
$cpfBusca = $pdo->query("SELECT id, name, cpf FROM clients WHERE REPLACE(REPLACE(cpf,'.',''),'-','') = '16591132708'")->fetchAll();
echo "\nClients CPF 16591132708: " . count($cpfBusca) . "\n";
foreach ($cpfBusca as $cb) echo "  ID={$cb['id']} | {$cb['name']} | CPF={$cb['cpf']}\n";

// Payload completo do form 517
$f517 = $pdo->query("SELECT payload_json FROM form_submissions WHERE id = 517")->fetchColumn();
echo "\nPayload form 517:\n" . $f517 . "\n";

if (isset($_GET['fix'])) {
    // Criar cliente Leidiane
    $payload = json_decode($f517, true);
    if ($payload) {
        $nome = $payload['nome'] ?? 'Leidiane De Assis André';
        $cpf = $payload['cpf'] ?? '';
        $email = $payload['email'] ?? '';
        $phone = $payload['telefone'] ?? $payload['phone'] ?? '';

        // Verificar se já existe
        $existe = $pdo->query("SELECT id FROM clients WHERE name LIKE '%Leidiane%'")->fetchColumn();
        if ($existe) {
            echo "\nCliente Leidiane já existe (ID={$existe})\n";
            $clientId = $existe;
        } else {
            $pdo->prepare("INSERT INTO clients (name, cpf, email, phone, client_status, source, created_at) VALUES (?,?,?,?,'prospect','formulario',NOW())")
                ->execute(array($nome, $cpf, $email, $phone));
            $clientId = $pdo->lastInsertId();
            echo "\nCliente criado: ID={$clientId} | {$nome}\n";
        }

        // Atualizar form_submission
        $pdo->prepare("UPDATE form_submissions SET linked_client_id = ? WHERE id = 517")->execute(array($clientId));
        echo "Form 517 vinculado ao client {$clientId}\n";

        // Criar lead no Pipeline
        $existeLead = $pdo->query("SELECT id FROM pipeline_leads WHERE client_id = {$clientId}")->fetchColumn();
        if (!$existeLead) {
            $pdo->prepare("INSERT INTO pipeline_leads (client_id, stage, created_at, updated_at) VALUES (?, 'cadastro_preenchido', NOW(), NOW())")
                ->execute(array($clientId));
            $leadId = $pdo->lastInsertId();
            echo "Lead criado: ID={$leadId} | Stage=cadastro_preenchido\n";
        } else {
            echo "Lead já existe: ID={$existeLead}\n";
        }
        echo "\n=== CORRIGIDO ===\n";
    }
}
exit;

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
