<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Sincronizar estimated_value_cents com honorarios_cents ===\n\n";

// Antes
$antes = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN honorarios_cents > 0 AND (estimated_value_cents IS NULL OR estimated_value_cents = 0) THEN 1 ELSE 0 END) AS falta_est,
    SUM(CASE WHEN honorarios_cents > 0 AND estimated_value_cents != honorarios_cents THEN 1 ELSE 0 END) AS diferente
    FROM pipeline_leads WHERE arquivado_em IS NULL")->fetch();

echo "Antes:\n";
echo "  Total de leads: {$antes['total']}\n";
echo "  Com honorarios preenchido mas estimated_value zerado: {$antes['falta_est']}\n";
echo "  Com valores diferentes entre os dois: {$antes['diferente']}\n\n";

// Executar sync
$r = $pdo->exec("
    UPDATE pipeline_leads
    SET estimated_value_cents = honorarios_cents
    WHERE honorarios_cents > 0
      AND (estimated_value_cents IS NULL
           OR estimated_value_cents = 0
           OR estimated_value_cents != honorarios_cents)
");
echo "Linhas afetadas pelo sync: {$r}\n\n";

// Depois
$depois = $pdo->query("SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN honorarios_cents > 0 AND (estimated_value_cents IS NULL OR estimated_value_cents = 0) THEN 1 ELSE 0 END) AS falta_est,
    SUM(CASE WHEN honorarios_cents > 0 AND estimated_value_cents != honorarios_cents THEN 1 ELSE 0 END) AS diferente
    FROM pipeline_leads WHERE arquivado_em IS NULL")->fetch();

echo "Depois:\n";
echo "  Com honorarios mas estimated_value zerado: {$depois['falta_est']}\n";
echo "  Com valores diferentes: {$depois['diferente']}\n\n";

// Faturamento do mês atual (o que o dashboard vai mostrar)
$mes = date('Y-m');
$fat = $pdo->query("SELECT IFNULL(SUM(estimated_value_cents),0)/100 AS total
                    FROM pipeline_leads
                    WHERE converted_at IS NOT NULL
                      AND stage NOT IN ('cancelado','perdido')
                      AND DATE_FORMAT(converted_at,'%Y-%m') = '{$mes}'")->fetch();
$cnt = $pdo->query("SELECT COUNT(*) AS n FROM pipeline_leads
                    WHERE converted_at IS NOT NULL
                      AND stage NOT IN ('cancelado','perdido')
                      AND DATE_FORMAT(converted_at,'%Y-%m') = '{$mes}'")->fetch();

echo "=== Valores que o Dashboard agora deve mostrar ({$mes}) ===\n";
echo "Contratos em {$mes}: {$cnt['n']}\n";
echo "Faturamento em {$mes}: R$ " . number_format($fat['total'], 2, ',', '.') . "\n";
echo "\n=== CONCLUIDO ===\n";
