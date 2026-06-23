<?php
/**
 * Migração — cria tabela crm_operacional_obs (idempotente).
 * URL: https://ferreiraesa.com.br/conecta/migrar_crm_operacional.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/functions_crm_operacional.php';
$pdo = db();
crm_op_self_heal($pdo);

// Confirma criação
$r = $pdo->query("SHOW TABLES LIKE 'crm_operacional_obs'")->fetchColumn();
echo $r ? "✅ Tabela crm_operacional_obs OK\n" : "❌ Falhou\n";

$st = $pdo->query("SHOW TABLE STATUS LIKE 'crm_operacional_obs'")->fetch(PDO::FETCH_ASSOC);
echo "Collation: " . ($st['Collation'] ?? '?') . "\n";
echo "Rows: " . ($st['Rows'] ?? '?') . "\n";
echo "\nMigração concluída.\n";
