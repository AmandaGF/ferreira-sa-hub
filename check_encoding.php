<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Buscar andamentos/publicações com caracteres corrompidos (contém '�' ou bytes inválidos)
echo "=== Andamentos com encoding quebrado ===\n";
$rows = $pdo->query("SELECT id, case_id, tipo, LEFT(descricao, 200) as trecho FROM case_andamentos WHERE descricao LIKE '%�%' OR descricao LIKE '%Ã%' OR descricao LIKE '%Ã§%' ORDER BY id DESC LIMIT 10")->fetchAll();
echo "Encontrados: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "#" . $r['id'] . " case=" . $r['case_id'] . " tipo=" . $r['tipo'] . "\n  " . $r['trecho'] . "\n\n";
}

echo "\n=== Publicações com encoding quebrado ===\n";
try {
    $rows2 = $pdo->query("SELECT id, case_id, tipo_publicacao, LEFT(conteudo, 200) as trecho FROM case_publicacoes WHERE conteudo LIKE '%�%' OR conteudo LIKE '%Ã%' OR conteudo LIKE '%Ã§%' ORDER BY id DESC LIMIT 10")->fetchAll();
    echo "Encontrados: " . count($rows2) . "\n";
    foreach ($rows2 as $r) {
        echo "#" . $r['id'] . " case=" . $r['case_id'] . " tipo=" . $r['tipo_publicacao'] . "\n  " . $r['trecho'] . "\n\n";
    }
} catch (Exception $e) { echo "Tabela não existe: " . $e->getMessage() . "\n"; }

// Verificar collation das tabelas
echo "\n=== Collation tabelas ===\n";
$tables = $pdo->query("SHOW TABLE STATUS")->fetchAll();
foreach ($tables as $t) {
    if (strpos($t['Collation'] ?? '', 'utf8mb4') === false) {
        echo $t['Name'] . ": " . ($t['Collation'] ?? 'NULL') . " !!!\n";
    }
}

echo "\n=== Connection charset ===\n";
$r = $pdo->query("SHOW VARIABLES LIKE 'character_set%'")->fetchAll();
foreach ($r as $v) echo $v['Variable_name'] . " = " . $v['Value'] . "\n";
