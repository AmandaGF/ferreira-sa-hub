<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$mesAtual = date('Y-m');
echo "=== Diagnóstico Faturamento $mesAtual ===\n\n";

$rows = $pdo->query("SELECT id, name, stage, converted_at, valor_acao, honorarios_cents, estimated_value_cents FROM pipeline_leads WHERE converted_at IS NOT NULL AND stage NOT IN ('cancelado','perdido') AND DATE_FORMAT(converted_at,'%Y-%m')='$mesAtual'")->fetchAll();

echo "Contratos no mês: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "Lead #{$r['id']} — {$r['name']}\n";
    echo "  Stage: {$r['stage']}\n";
    echo "  Convertido: {$r['converted_at']}\n";
    echo "  valor_acao: " . ($r['valor_acao'] ?: 'NULL') . "\n";
    echo "  honorarios_cents: " . ($r['honorarios_cents'] ?: 'NULL') . "\n";
    echo "  estimated_value_cents: " . ($r['estimated_value_cents'] ?: 'NULL') . "\n\n";
}

echo "--- Todos os leads com valor preenchido (qualquer mês) ---\n";
$total = $pdo->query("SELECT COUNT(*) FROM pipeline_leads WHERE estimated_value_cents > 0")->fetchColumn();
$totalLeads = $pdo->query("SELECT COUNT(*) FROM pipeline_leads")->fetchColumn();
echo "Com valor: $total / $totalLeads\n";
