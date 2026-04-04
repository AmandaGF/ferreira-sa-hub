<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Honorários + Êxito ===\n\n";

// 1. Adicionar coluna honorarios_cents (INT) e exito_percentual (DECIMAL)
$queries = array(
    "ALTER TABLE pipeline_leads ADD COLUMN honorarios_cents INT DEFAULT NULL COMMENT 'Honorários fixos em centavos' AFTER estimated_value_cents",
    "ALTER TABLE pipeline_leads ADD COLUMN exito_percentual DECIMAL(5,2) DEFAULT NULL COMMENT 'Percentual de êxito' AFTER honorarios_cents",
);

foreach ($queries as $q) {
    try {
        $pdo->exec($q);
        echo "[OK] $q\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "[SKIP] Coluna já existe\n";
        } else {
            echo "[ERRO] " . $e->getMessage() . "\n";
        }
    }
}

// 2. Migrar dados: parsear valor_acao existente → honorarios_cents + exito_percentual
echo "\n--- Migrando dados existentes ---\n";
$rows = $pdo->query("SELECT id, valor_acao FROM pipeline_leads WHERE valor_acao IS NOT NULL AND valor_acao != ''")->fetchAll();

$updated = 0;
$skipped = 0;

foreach ($rows as $r) {
    $texto = trim($r['valor_acao']);
    $honorarios = parse_valor_reais($texto);
    $exito = null;

    // Extrair percentual de êxito: "500+30%", "R$ 1.000 + 20%", etc.
    if (preg_match('/\+\s*(\d{1,3})\s*%/', $texto, $m)) {
        $exito = (float)$m[1];
    }

    if ($honorarios === null && $exito === null) {
        echo "[SKIP] Lead #{$r['id']} — '{$texto}' (não numérico)\n";
        $skipped++;
        continue;
    }

    $pdo->prepare("UPDATE pipeline_leads SET honorarios_cents = ?, exito_percentual = ?, estimated_value_cents = ? WHERE id = ?")
        ->execute(array($honorarios, $exito, $honorarios, $r['id']));

    $reais = $honorarios ? 'R$ ' . number_format($honorarios / 100, 2, ',', '.') : 'NULL';
    $exitoStr = $exito !== null ? $exito . '%' : 'NULL';
    echo "[OK] Lead #{$r['id']} — '{$texto}' → Hon: {$reais} | Êxito: {$exitoStr}\n";
    $updated++;
}

echo "\n=== RESULTADO ===\n";
echo "Atualizados: $updated\n";
echo "Ignorados: $skipped\n";
echo "Total: " . count($rows) . "\n";
echo "\n=== FIM ===\n";
