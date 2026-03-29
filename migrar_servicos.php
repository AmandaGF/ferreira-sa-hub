<?php
/**
 * Migração: Adicionar coluna internal_number em cases
 * Acesse: ferreiraesa.com.br/conecta/migrar_servicos.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

// Adicionar internal_number se não existir
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cases LIKE 'internal_number'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cases ADD COLUMN internal_number VARCHAR(30) DEFAULT NULL AFTER case_number");
        echo "Coluna 'internal_number' adicionada!\n";
    } else {
        echo "Coluna 'internal_number' já existe.\n";
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// Gerar números internos para serviços existentes sem número
try {
    $sem = $pdo->query("SELECT id, created_at FROM cases WHERE (case_number IS NULL OR case_number = '') AND (internal_number IS NULL OR internal_number = '') ORDER BY created_at")->fetchAll();
    $count = 0;
    foreach ($sem as $s) {
        $ano = date('Y', strtotime($s['created_at']));
        $count++;
        $num = 'ADM-' . $ano . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE cases SET internal_number = ? WHERE id = ?")->execute(array($num, $s['id']));
    }
    echo "$count serviços existentes receberam número interno.\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\nPronto!\n";
