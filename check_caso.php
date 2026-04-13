<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Buscar caso pelo número
$r = $pdo->query("SELECT id, client_id, title, status, salavip_ativo, case_number FROM cases WHERE case_number LIKE '%0803040-34.2026%'")->fetch();
if (!$r) { echo "Caso não encontrado.\n"; exit; }
$caseId = $r['id'];
echo "Caso #$caseId: " . $r['title'] . " | client_id=" . $r['client_id'] . " | salavip=" . $r['salavip_ativo'] . " | status=" . $r['status'] . "\n\n";

// Andamentos
$total = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = $caseId")->fetchColumn();
$visiveis = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE case_id = $caseId AND visivel_cliente = 1")->fetchColumn();
echo "Andamentos: total=$total visiveis=$visiveis\n\n";

// Últimos 5
$rows = $pdo->query("SELECT id, data_andamento, tipo, visivel_cliente, LEFT(descricao,80) as trecho FROM case_andamentos WHERE case_id = $caseId ORDER BY data_andamento DESC LIMIT 5")->fetchAll();
foreach ($rows as $a) {
    echo "#" . $a['id'] . " vis=" . $a['visivel_cliente'] . " " . $a['data_andamento'] . " " . $a['tipo'] . " :: " . $a['trecho'] . "\n";
}

// Verificar cliente vinculado ao usuario salavip
echo "\n=== Usuario Sala VIP ===\n";
$su = $pdo->query("SELECT id, cliente_id FROM salavip_usuarios WHERE cliente_id = " . $r['client_id'])->fetch();
echo $su ? "SalaVIP user #" . $su['id'] . " cliente_id=" . $su['cliente_id'] : "Nenhum usuario SalaVIP para client_id=" . $r['client_id'];
echo "\n";
