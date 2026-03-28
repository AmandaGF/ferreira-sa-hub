<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

$pdo = db();

// Apagar lead "Amanda teste" do pipeline
$pdo->exec("DELETE FROM pipeline_history WHERE lead_id IN (SELECT id FROM pipeline_leads WHERE name LIKE '%Amanda teste%')");
$pdo->exec("DELETE FROM pipeline_leads WHERE name LIKE '%Amanda teste%'");
echo "Lead 'Amanda teste' apagado do Pipeline.\n";

// Apagar cliente "Amanda teste" do CRM
$pdo->exec("DELETE FROM contacts WHERE client_id IN (SELECT id FROM clients WHERE name LIKE '%Amanda teste%')");
$pdo->exec("DELETE FROM cases WHERE client_id IN (SELECT id FROM clients WHERE name LIKE '%Amanda teste%')");
$pdo->exec("UPDATE form_submissions SET linked_client_id = NULL WHERE linked_client_id IN (SELECT id FROM clients WHERE name LIKE '%Amanda teste%')");
$pdo->exec("DELETE FROM clients WHERE name LIKE '%Amanda teste%'");
echo "Cliente 'Amanda teste' apagado do CRM.\n";

echo "\nPronto!\n";
