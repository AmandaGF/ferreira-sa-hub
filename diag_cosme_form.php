<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$execute = isset($_GET['executar']) && $_GET['executar'] === '1';

// Pega payload do form do Cosme (id=730)
$f = $pdo->prepare("SELECT payload_json FROM form_submissions WHERE id=730");
$f->execute();
$payload = json_decode($f->fetchColumn() ?: '{}', true) ?: array();

echo "=== Plano de correção ===\n\n";
echo "1) UPDATE clients id=2491:\n";
$campos = array(
    'name'              => $payload['nome'] ?? null,
    'cpf'               => $payload['cpf'] ?? null,
    'rg'                => $payload['rg'] ?? null,
    'birth_date'        => $payload['nascimento'] ?? null,
    'email'             => $payload['email'] ?? null,
    'phone'             => $payload['celular'] ?? $payload['phone'] ?? null,
    'profession'        => $payload['profissao'] ?? null,
    'marital_status'    => $payload['estado_civil'] ?? null,
);
foreach ($campos as $k => $v) {
    echo "   $k = " . (is_null($v) ? 'NULL' : "'$v'") . "\n";
}

echo "\n2) UPDATE form_submissions id=729 (Yasmim) SET status='arquivado'\n";

echo "\n3) Form id=730 (Cosme) já está OK, vinculado ao client 2491.\n\n";

if (!$execute) {
    echo "==================================================\n";
    echo "→ Adicionar &executar=1 na URL pra aplicar a correção.\n";
    echo "==================================================\n";
    exit;
}

echo "EXECUTANDO...\n";
try {
    $sql = "UPDATE clients SET name=?, cpf=?, rg=?, birth_date=?, email=?, phone=?, profession=?, marital_status=? WHERE id=2491";
    $st = $pdo->prepare($sql);
    $st->execute(array(
        $campos['name'] ?: 'Cosme Pereira dos Santos',
        $campos['cpf'], $campos['rg'], $campos['birth_date'],
        $campos['email'], $campos['phone'],
        $campos['profession'], $campos['marital_status']
    ));
    echo "  ✓ Cliente 2491 atualizado.\n";

    $pdo->prepare("UPDATE form_submissions SET status='arquivado' WHERE id=729")->execute();
    echo "  ✓ Form 729 (Yasmim) arquivado.\n";

    audit_log('cliente_corrigir_dup', 'client', 2491, 'Yasmim→Cosme (form 730)');
    echo "\n✅ Correção aplicada.\n";

    // Mostra estado final
    $st = $pdo->prepare("SELECT name, cpf, phone, email FROM clients WHERE id=2491");
    $st->execute();
    foreach ($st->fetch(PDO::FETCH_ASSOC) as $k => $v) echo "  $k: $v\n";
} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
}
