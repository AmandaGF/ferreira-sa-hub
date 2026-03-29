<?php
/**
 * Migração: Adicionar colunas extras na tabela clients
 * Acesse: ferreiraesa.com.br/conecta/migrar_clientes_extra.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

$columns = array(
    'gender' => "VARCHAR(20) DEFAULT NULL COMMENT 'Masculino, Feminino' AFTER marital_status",
    'has_children' => "TINYINT(1) DEFAULT NULL COMMENT '1=sim, 0=nao' AFTER gender",
    'children_names' => "TEXT DEFAULT NULL COMMENT 'Nomes dos filhos' AFTER has_children",
    'pix_key' => "VARCHAR(255) DEFAULT NULL COMMENT 'Chave PIX' AFTER children_names",
);

foreach ($columns as $col => $def) {
    try {
        $exists = $pdo->query("SHOW COLUMNS FROM clients LIKE '$col'")->fetchAll();
        if (empty($exists)) {
            $pdo->exec("ALTER TABLE clients ADD COLUMN $col $def");
            echo "Coluna '$col' criada!\n";
        } else {
            echo "Coluna '$col' ja existe.\n";
        }
    } catch (Exception $e) {
        echo "ERRO $col: " . $e->getMessage() . "\n";
    }
}

echo "\nPronto!\n";
