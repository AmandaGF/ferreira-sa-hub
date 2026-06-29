<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$CLIENT_ID = 2399; // Marcelo Cosme
$NOME = '%Cosme%';
$CPF = '07293230729';

echo "=== Formulários preenchidos pelo Cosme (client_id=$CLIENT_ID) ===\n\n";

// Schema da tabela
$cols = $pdo->query("SHOW COLUMNS FROM form_submissions")->fetchAll(PDO::FETCH_ASSOC);
echo "Colunas de form_submissions:\n";
foreach ($cols as $c) echo "  - {$c['Field']} ({$c['Type']})\n";
echo "\n";

// Busca por linked_client_id
echo "── POR client_id = $CLIENT_ID ──\n";
$st = $pdo->prepare("SELECT * FROM form_submissions WHERE linked_client_id = ? ORDER BY id DESC");
$st->execute(array($CLIENT_ID));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) echo "  Nenhum.\n";
foreach ($rows as $f) {
    echo "  id={$f['id']} | tipo: " . ($f['form_type'] ?? '?') . " | criado: {$f['created_at']}\n";
    $p = json_decode($f['payload_json'] ?? '{}', true) ?: array();
    echo "  Payload (chaves): " . implode(', ', array_keys($p)) . "\n\n";
}

// Busca por payload contendo nome ou CPF
echo "── POR payload contendo 'Cosme' ou CPF ──\n";
$st = $pdo->prepare("SELECT id, form_type, linked_client_id, payload_json, created_at FROM form_submissions WHERE payload_json LIKE ? OR payload_json LIKE ? OR payload_json LIKE ? ORDER BY id DESC LIMIT 30");
$st->execute(array($NOME, '%' . $CPF . '%', '%072.932.307-29%'));
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) {
    echo "  ❌ Nenhum formulário com 'Cosme' ou CPF dele encontrado.\n";
} else {
    foreach ($rows as $f) {
        $p = json_decode($f['payload_json'], true) ?: array();
        $nome = $p['nome'] ?? $p['name'] ?? $p['nome_completo'] ?? '(sem nome)';
        $cpf  = $p['cpf'] ?? '-';
        $tel  = $p['telefone'] ?? $p['phone'] ?? $p['whatsapp'] ?? '-';
        echo "  ✓ id={$f['id']} | tipo: {$f['form_type']} | em {$f['created_at']}\n";
        echo "    nome no form: $nome | CPF: $cpf | tel: $tel\n";
        echo "    vinculado ao client_id: " . ($f['linked_client_id'] ?: '(nenhum)') . "\n\n";
    }
}
