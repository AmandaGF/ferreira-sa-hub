<?php
/**
 * Migração: Adicionar coluna category em cases + internal_number
 * Acesse: ferreiraesa.com.br/conecta/migrar_categorias.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

// 1. Coluna category
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cases LIKE 'category'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN category VARCHAR(20) NOT NULL DEFAULT 'judicial' AFTER case_type");
        echo "Coluna 'category' criada!\n";
    } else {
        echo "Coluna 'category' ja existe.\n";
    }
} catch (Exception $e) {
    echo "ERRO category: " . $e->getMessage() . "\n";
}

// 2. Coluna internal_number
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cases LIKE 'internal_number'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN internal_number VARCHAR(30) DEFAULT NULL AFTER case_number");
        echo "Coluna 'internal_number' criada!\n";
    } else {
        echo "Coluna 'internal_number' ja existe.\n";
    }
} catch (Exception $e) {
    echo "ERRO internal_number: " . $e->getMessage() . "\n";
}

// 3. Classificar cases existentes
// Com nº processo = judicial
$pdo->exec("UPDATE cases SET category = 'judicial' WHERE case_number IS NOT NULL AND case_number != ''");
$jud = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
echo "Classificados como judicial: $jud\n";

// Sem nº processo = pre_processual (padrão, depois o admin ajusta manualmente os extrajudiciais)
$pdo->exec("UPDATE cases SET category = 'pre_processual' WHERE (case_number IS NULL OR case_number = '') AND category = 'judicial'");
$pre = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
echo "Classificados como pre_processual: $pre\n";

// 4. Gerar números internos para pré-processuais sem número
$sem = $pdo->query("SELECT id, created_at FROM cases WHERE category IN ('pre_processual','extrajudicial') AND (internal_number IS NULL OR internal_number = '') ORDER BY created_at")->fetchAll();
$count = 0;
foreach ($sem as $s) {
    $ano = date('Y', strtotime($s['created_at']));
    $count++;
    $prefix = 'PRE';
    $num = $prefix . '-' . $ano . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE cases SET internal_number = ? WHERE id = ?")->execute(array($num, $s['id']));
}
echo "Numeros internos gerados: $count\n";

echo "\nPronto!\n";
