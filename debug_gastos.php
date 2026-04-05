<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diagnóstico: Formulários Gastos Pensão ===\n\n";

// 1. Últimas submissões gastos_pensao no Conecta
echo "--- form_submissions (gastos_pensao) ---\n";
$rows = $pdo->query("SELECT id, protocol, client_name, client_phone, status, created_at, linked_client_id FROM form_submissions WHERE form_type = 'gastos_pensao' ORDER BY created_at DESC LIMIT 20")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "#{$r['id']} | {$r['protocol']} | {$r['client_name']} | {$r['client_phone']} | status={$r['status']} | {$r['created_at']} | client_id=" . ($r['linked_client_id'] ?: 'NULL') . "\n";
}

// 2. Total por form_type
echo "\n--- Totais por form_type ---\n";
$totais = $pdo->query("SELECT form_type, COUNT(*) as total, MAX(created_at) as ultimo FROM form_submissions GROUP BY form_type ORDER BY total DESC")->fetchAll();
foreach ($totais as $t) {
    echo "{$t['form_type']}: {$t['total']} (último: {$t['ultimo']})\n";
}

// 3. Últimos 5 de qualquer tipo
echo "\n--- Últimas 5 submissões (qualquer tipo) ---\n";
$ult = $pdo->query("SELECT id, form_type, client_name, status, created_at FROM form_submissions ORDER BY created_at DESC LIMIT 5")->fetchAll();
foreach ($ult as $u) {
    echo "#{$u['id']} | {$u['form_type']} | {$u['client_name']} | status={$u['status']} | {$u['created_at']}\n";
}

// 4. Verificar tabela legada pensao_respostas
echo "\n--- pensao_respostas (tabela legada) ---\n";
try {
    $leg = $pdo->query("SELECT id, protocolo, nome_responsavel, created_at FROM pensao_respostas ORDER BY created_at DESC LIMIT 10")->fetchAll();
    echo "Últimas 10: " . count($leg) . "\n";
    foreach ($leg as $l) {
        echo "#{$l['id']} | {$l['protocolo']} | {$l['nome_responsavel']} | {$l['created_at']}\n";
    }
} catch (Exception $e) {
    echo "Tabela não existe: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
