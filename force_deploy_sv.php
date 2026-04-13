<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Simular exatamente o que processo_detalhe.php faz para case 637, client 386
$caseId = 637;
$clienteId = 386;

$stmtCase = $pdo->prepare("SELECT * FROM cases WHERE id = ? AND client_id = ? AND salavip_ativo = 1");
$stmtCase->execute([$caseId, $clienteId]);
$caso = $stmtCase->fetch();
echo "Caso encontrado: " . ($caso ? 'SIM' : 'NÃO') . "\n";

$stmtAnd = $pdo->prepare("SELECT id, data_andamento, tipo, visivel_cliente, LEFT(descricao,100) as desc_short FROM case_andamentos WHERE case_id = ? AND visivel_cliente = 1 ORDER BY data_andamento DESC, created_at DESC");
$stmtAnd->execute([$caseId]);
$andamentos = $stmtAnd->fetchAll();
echo "Andamentos visíveis: " . count($andamentos) . "\n\n";
foreach ($andamentos as $a) {
    echo "#" . $a['id'] . " " . $a['data_andamento'] . " " . $a['tipo'] . " vis=" . $a['visivel_cliente'] . " :: " . $a['desc_short'] . "\n";
}
