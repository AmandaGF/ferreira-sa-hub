<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diagnóstico: cálculos salvos ===\n\n";

// 1. prazos_calculos
echo "--- prazos_calculos ---\n";
try {
    $rows = $pdo->query("SELECT * FROM prazos_calculos ORDER BY created_at DESC")->fetchAll();
    echo "Total: " . count($rows) . "\n";
    foreach ($rows as $r) {
        echo "#{$r['id']} | case_id=" . ($r['case_id'] ?: 'NULL') . " | tipo={$r['tipo_prazo']} | disp={$r['data_disponibilizacao']} | fatal={$r['data_fatal']} | comarca={$r['comarca']} | por={$r['calculado_por']}\n";
    }
    // Apagar todos sem case_id
    $pdo->exec("DELETE FROM prazos_calculos WHERE case_id IS NULL OR case_id = 0");
    echo "\n[OK] Cálculos sem processo apagados\n";
} catch (Exception $e) {
    echo "Tabela não existe ou erro: " . $e->getMessage() . "\n";
}

// 2. prazos_processuais
echo "\n--- prazos_processuais ---\n";
try {
    $rows2 = $pdo->query("SELECT * FROM prazos_processuais ORDER BY id DESC LIMIT 10")->fetchAll();
    echo "Últimos 10: " . count($rows2) . "\n";
    foreach ($rows2 as $r) {
        echo "#{$r['id']} | case_id={$r['case_id']} | tipo={$r['tipo']} | fatal={$r['data_fatal']}\n";
    }
} catch (Exception $e) {
    echo "Tabela não existe: " . $e->getMessage() . "\n";
}

// 3. agenda_eventos com tipo=prazo recentes
echo "\n--- agenda_eventos tipo=prazo ---\n";
try {
    $rows3 = $pdo->query("SELECT * FROM agenda_eventos WHERE tipo = 'prazo' ORDER BY id DESC LIMIT 10")->fetchAll();
    echo "Últimos 10: " . count($rows3) . "\n";
    foreach ($rows3 as $r) {
        echo "#{$r['id']} | case_id=" . ($r['case_id'] ?: 'NULL') . " | titulo={$r['titulo']} | data={$r['data_inicio']}\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
