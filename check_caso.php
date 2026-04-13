<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Teste query andamento (mesma da meus_processos.php) ===\n\n";

// Simular exatamente a query do meus_processos.php
$stmtAnd = $pdo->prepare(
    "SELECT data_andamento, descricao FROM case_andamentos
     WHERE case_id = ? AND visivel_cliente = 1
     ORDER BY data_andamento DESC, created_at DESC LIMIT 1"
);

// Testar com case 637
$stmtAnd->execute([637]);
$and = $stmtAnd->fetch();
echo "Case 637: " . ($and ? $and['data_andamento'] . " :: " . substr($and['descricao'], 0, 80) : "NENHUM") . "\n";

// Verificar colunas de case_andamentos
echo "\n=== Colunas case_andamentos ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM case_andamentos")->fetchAll();
foreach ($cols as $c) echo $c['Field'] . " (" . $c['Type'] . ")\n";

// Verificar se é o user correto que está logado
echo "\n=== Todos os salavip_usuarios ===\n";
$users = $pdo->query("SELECT su.id, su.cliente_id, su.cpf, su.ativo, c.name FROM salavip_usuarios su JOIN clients c ON c.id = su.cliente_id ORDER BY su.id")->fetchAll();
foreach ($users as $u) echo "#" . $u['id'] . " cliente_id=" . $u['cliente_id'] . " cpf=" . $u['cpf'] . " ativo=" . $u['ativo'] . " :: " . $u['name'] . "\n";

// Todos os processos com salavip_ativo=1
echo "\n=== Processos com salavip_ativo=1 ===\n";
$cases = $pdo->query("SELECT id, client_id, title, case_number FROM cases WHERE salavip_ativo = 1 ORDER BY id")->fetchAll();
foreach ($cases as $cs) echo "#" . $cs['id'] . " client=" . $cs['client_id'] . " :: " . $cs['title'] . " — " . ($cs['case_number'] ?: 'sem nº') . "\n";
