<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
echo "=== Buscando 'Cosme' ===\n\n";

// 1) Clientes
echo "── CLIENTES com 'Cosme' no nome ──\n";
$st = $pdo->prepare("SELECT id, name, cpf, phone, email, source, client_status, created_at FROM clients WHERE name LIKE ? ORDER BY id DESC");
$st->execute(array('%Cosme%'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "id={$c['id']} | {$c['name']} | CPF: " . ($c['cpf'] ?: '-') . " | tel: " . ($c['phone'] ?: '-') . "\n";
    echo "  email: " . ($c['email'] ?: '-') . " | origem: " . ($c['source'] ?: '-') . " | status: " . ($c['client_status'] ?: '-') . " | criado: {$c['created_at']}\n";
}

// 2) Leads no pipeline
echo "\n── LEADS no pipeline com 'Cosme' ──\n";
try {
    $st = $pdo->prepare("SELECT id, name, phone, email, stage, case_type, client_id, created_at FROM pipeline_leads WHERE name LIKE ? ORDER BY id DESC");
    $st->execute(array('%Cosme%'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        echo "id={$l['id']} | {$l['name']} | tel: " . ($l['phone'] ?: '-') . " | stage: {$l['stage']} | tipo: " . ($l['case_type'] ?: '-') . " | criado: {$l['created_at']}\n";
        echo "  client_id vinculado: " . ($l['client_id'] ?: '(nenhum)') . "\n";
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }

// 3) Form submissions
echo "\n── FORMULÁRIOS preenchidos com 'Cosme' ──\n";
try {
    $st = $pdo->prepare(
        "SELECT id, form_type, linked_client_id, linked_lead_id, payload_json, created_at
         FROM form_submissions
         WHERE payload_json LIKE ?
         ORDER BY id DESC LIMIT 20"
    );
    $st->execute(array('%Cosme%'));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        echo "  Nenhum formulário com 'Cosme' encontrado em form_submissions.\n";
    } else {
        foreach ($rows as $f) {
            $p = json_decode($f['payload_json'], true) ?: array();
            $nome = $p['nome'] ?? $p['name'] ?? '(sem nome)';
            $tel  = $p['telefone'] ?? $p['phone'] ?? '-';
            $cpf  = $p['cpf'] ?? '-';
            echo "id={$f['id']} | tipo: {$f['form_type']} | nome: $nome | tel: $tel | CPF: $cpf\n";
            echo "  criado em: {$f['created_at']} | client_id: " . ($f['linked_client_id'] ?: '-') . " | lead_id: " . ($f['linked_lead_id'] ?: '-') . "\n";
        }
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }

// 4) Conversas WhatsApp
echo "\n── CONVERSAS WhatsApp com 'Cosme' no nome de contato ──\n";
try {
    $st = $pdo->prepare("SELECT id, telefone, nome_contato, canal, created_at FROM zapi_conversas WHERE nome_contato LIKE ? ORDER BY id DESC LIMIT 10");
    $st->execute(array('%Cosme%'));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        echo "id={$c['id']} | {$c['nome_contato']} | canal {$c['canal']} | tel {$c['telefone']} | desde {$c['created_at']}\n";
    }
} catch (Exception $e) { echo "  (erro: " . $e->getMessage() . ")\n"; }
