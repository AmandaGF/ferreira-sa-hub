<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/database.php';

$pdo = db();

// Corrigir leads com stage vazio
$fixed = $pdo->exec("UPDATE pipeline_leads SET stage = 'elaboracao' WHERE stage = '' OR stage IS NULL");
echo "Leads corrigidos (stage vazio -> elaboracao): $fixed\n";

// Corrigir leads com stage que não existe no sistema
$validStages = "'novo','contato_inicial','agendado','proposta','elaboracao','contrato','perdido'";
$invalid = $pdo->query("SELECT id, name, stage FROM pipeline_leads WHERE stage NOT IN ($validStages)")->fetchAll(PDO::FETCH_ASSOC);
echo "Leads com stage invalido: " . count($invalid) . "\n";
foreach ($invalid as $l) {
    echo "  #" . $l['id'] . " " . $l['name'] . " stage=[" . $l['stage'] . "] -> novo\n";
    $pdo->prepare("UPDATE pipeline_leads SET stage = 'novo' WHERE id = ?")->execute(array($l['id']));
}

// Mostrar resultado
$leads = $pdo->query("SELECT id, name, stage FROM pipeline_leads ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo "\nLeads atuais:\n";
foreach ($leads as $l) {
    echo "  #" . $l['id'] . " " . $l['name'] . " -> " . $l['stage'] . "\n";
}
echo "\nPronto! Acesse o Pipeline.\n";
