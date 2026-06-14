<?php
/**
 * Adiciona flag "Elaborado por IA" na tabela cases.
 * Marcado automaticamente quando a Fábrica de Petições (Minerva) gera uma minuta
 * vinculada ao caso, e manualmente via botão na ficha do caso. Badge no Kanban.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Migração: cases.elaborado_por_ia ===\n\n";
$queries = array(
    "ALTER TABLE cases ADD COLUMN elaborado_por_ia TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE cases ADD COLUMN elaborado_por_ia_em DATETIME NULL",
    "ALTER TABLE cases ADD COLUMN elaborado_por_ia_doc_id INT NULL COMMENT 'case_documents.id da minuta gerada'",
);
foreach ($queries as $q) {
    try { $pdo->exec($q); echo "OK: " . substr($q, 0, 70) . "...\n"; }
    catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) echo "Já existe: " . substr($q, 27, 30) . "\n";
        else echo "ERRO: " . $e->getMessage() . "\n";
    }
}

echo "\nCasos marcados como elaborado_por_ia: " .
     $pdo->query("SELECT COUNT(*) FROM cases WHERE elaborado_por_ia = 1")->fetchColumn() . "\n";
echo "\nPronto!\n";
