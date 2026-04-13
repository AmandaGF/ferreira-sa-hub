<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$apply = isset($_GET['apply']);

// 1. Converter database default charset
echo "=== Converter database + tabelas para utf8mb4 ===\n\n";

$dbName = DB_NAME;
if ($apply) {
    $pdo->exec("ALTER DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database convertido.\n";
}

// 2. Converter todas as tabelas
$tables = $pdo->query("SHOW TABLE STATUS")->fetchAll();
foreach ($tables as $t) {
    $tName = $t['Name'];
    $coll = $t['Collation'] ?? '';
    if (strpos($coll, 'utf8mb4') !== false) continue;
    echo "Tabela: $tName — collation atual: $coll\n";
    if ($apply) {
        try {
            $pdo->exec("ALTER TABLE `$tName` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "  → Convertida!\n";
        } catch (Exception $e) {
            echo "  → ERRO: " . $e->getMessage() . "\n";
        }
    }
}

// 3. Corrigir dados corrompidos no case 734 (latin1 gravado como utf8)
echo "\n=== Corrigir dados corrompidos (case 734) ===\n";
$broken = $pdo->query("SELECT id, descricao FROM case_andamentos WHERE case_id = 734 AND descricao LIKE '%�%'")->fetchAll();
echo "Andamentos corrompidos: " . count($broken) . "\n";

if ($apply && count($broken) > 0) {
    foreach ($broken as $b) {
        // Tentar converter: os bytes estão em latin1 mas foram marcados como utf8
        $fixed = @iconv('UTF-8', 'UTF-8//IGNORE', $b['descricao']);
        // Se ainda tem ?, tentar double-decode
        if (strpos($fixed, '�') !== false) {
            $fixed = mb_convert_encoding($b['descricao'], 'UTF-8', 'ISO-8859-1');
        }
        if ($fixed !== $b['descricao']) {
            $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?")->execute(array($fixed, $b['id']));
            echo "  #" . $b['id'] . " corrigido\n";
        } else {
            echo "  #" . $b['id'] . " sem mudança (dados irrecuperáveis?)\n";
        }
    }
}

if (!$apply) {
    echo "\n>>> Modo simulação. Para aplicar: &apply=1\n";
} else {
    echo "\n>>> APLICADO.\n";
}

// Verificar resultado
echo "\n=== Charset final ===\n";
$r = $pdo->query("SHOW VARIABLES LIKE 'character_set_database'")->fetch();
echo $r['Variable_name'] . " = " . $r['Value'] . "\n";
