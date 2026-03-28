<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

$pdo = db();

// 1. Adicionar "elaboracao" ao ENUM do stage
echo "1. Alterando ENUM do stage...\n";
try {
    $pdo->exec("ALTER TABLE pipeline_leads MODIFY COLUMN stage ENUM('novo','contato_inicial','agendado','proposta','elaboracao','contrato','perdido') NOT NULL DEFAULT 'novo'");
    echo "   OK: 'elaboracao' adicionado ao ENUM\n\n";
} catch (Exception $e) {
    echo "   ERRO: " . $e->getMessage() . "\n\n";
}

// 2. Corrigir leads com stage vazio
$fixed = $pdo->exec("UPDATE pipeline_leads SET stage = 'novo' WHERE stage = '' OR stage IS NULL");
echo "2. Leads com stage vazio corrigidos: $fixed\n\n";

// 3. Mostrar resultado
$leads = $pdo->query("SELECT id, name, stage FROM pipeline_leads ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "3. Leads atuais:\n";
foreach ($leads as $l) {
    echo "   #" . $l['id'] . " " . $l['name'] . " -> [" . $l['stage'] . "]\n";
}
echo "\nPronto! Agora o Pipeline aceita 'Elaboracao Contrato'.\n";
