<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Ver lead da Leidiane
$stmt = $pdo->query("SELECT l.*, c.name as client_name, c.phone as client_phone FROM pipeline_leads l LEFT JOIN clients c ON c.id = l.client_id WHERE l.client_id = 2311");
$leads = $stmt->fetchAll();
echo "Leads client_id=2311:\n";
foreach ($leads as $l) {
    echo "  Lead #{$l['id']} | name={$l['name']} | stage={$l['stage']} | client_name={$l['client_name']} | phone={$l['client_phone']}\n";
}

// Corrigir: atualizar name do lead com nome do cliente
foreach ($leads as $l) {
    if ($l['client_name'] && $l['name'] !== $l['client_name']) {
        $pdo->prepare("UPDATE pipeline_leads SET name = ? WHERE id = ?")->execute(array($l['client_name'], $l['id']));
        echo "  => Lead #{$l['id']} name atualizado para: {$l['client_name']}\n";
    }
}
// Remover lead duplicado (manter só o mais antigo)
if (count($leads) > 1) {
    // Manter o primeiro, excluir os demais
    $keep = $leads[0]['id'];
    for ($i = 1; $i < count($leads); $i++) {
        $delId = $leads[$i]['id'];
        $pdo->prepare("DELETE FROM pipeline_leads WHERE id = ?")->execute(array($delId));
        echo "  Lead #{$delId} duplicado excluído\n";
    }
    echo "  Mantido Lead #{$keep}\n";
}
echo "\n=== OK ===\n";
