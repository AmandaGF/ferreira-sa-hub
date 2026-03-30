<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAGNÓSTICO DO BANCO ===\n\n";

// 1. Verificar tabelas existentes
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "TABELAS (" . count($tables) . "):\n";
foreach ($tables as $t) { echo "  ✓ $t\n"; }

// 2. Verificar tabelas esperadas
$expected = array('users','clients','cases','case_tasks','contacts','pipeline_leads','pipeline_history','tickets','ticket_assignees','ticket_messages','portal_links','form_submissions','audit_log','notifications','birthday_greetings','birthday_messages','message_templates','document_history');
echo "\nTABELAS FALTANDO:\n";
$missing = 0;
foreach ($expected as $e) {
    if (!in_array($e, $tables)) { echo "  ✗ $e\n"; $missing++; }
}
if ($missing === 0) echo "  Nenhuma!\n";

// 3. Verificar colunas críticas
echo "\nCOLUNAS CRÍTICAS:\n";
$checks = array(
    array('clients', 'client_status'),
    array('clients', 'gender'),
    array('clients', 'has_children'),
    array('clients', 'pix_key'),
    array('cases', 'category'),
    array('cases', 'internal_number'),
    array('cases', 'drive_folder_url'),
    array('pipeline_leads', 'linked_case_id'),
);
foreach ($checks as $c) {
    try {
        $pdo->query("SELECT `{$c[1]}` FROM `{$c[0]}` LIMIT 0");
        echo "  ✓ {$c[0]}.{$c[1]}\n";
    } catch (Exception $e) {
        echo "  ✗ {$c[0]}.{$c[1]} — NÃO EXISTE\n";
    }
}

// 4. Verificar ENUM do pipeline_leads.stage
echo "\nPIPELINE STAGES (ENUM):\n";
try {
    $col = $pdo->query("SHOW COLUMNS FROM pipeline_leads LIKE 'stage'")->fetch();
    echo "  " . $col['Type'] . "\n";
} catch (Exception $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
}

// 5. Contagens
echo "\nCONTAGENS:\n";
$counts = array('users','clients','cases','pipeline_leads','form_submissions','notifications','tickets','portal_links','message_templates');
foreach ($counts as $t) {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  $t: $n\n";
    } catch (Exception $e) {
        echo "  $t: ERRO\n";
    }
}

echo "\n=== FIM ===\n";
