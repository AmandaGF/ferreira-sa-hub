<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$nome = $_GET['nome'] ?? 'Gabrielle';

echo "=== 1) case_partes onde nome contem '$nome' ===\n";
$st = $pdo->prepare("SELECT cp.case_id, cp.tipo_parte, cp.nome, cp.client_id, c.title AS case_title, c.case_number, cl.name AS cliente_principal
                     FROM case_partes cp
                     LEFT JOIN cases c ON c.id = cp.case_id
                     LEFT JOIN clients cl ON cl.id = c.client_id
                     WHERE cp.nome LIKE ?
                     LIMIT 30");
$st->execute(array('%' . $nome . '%'));
$rows = $st->fetchAll();
if (!$rows) echo "  Nenhum.\n";
foreach ($rows as $r) {
    echo "  Caso #{$r['case_id']} | tipo={$r['tipo_parte']} | nome_parte=\"{$r['nome']}\" | client_id_parte={$r['client_id']}\n";
    echo "    Titulo: {$r['case_title']} | CNJ: {$r['case_number']} | Cliente principal: {$r['cliente_principal']}\n\n";
}

echo "\n=== 2) clients onde nome contem '$nome' ===\n";
$st = $pdo->prepare("SELECT id, name, cpf, phone FROM clients WHERE name LIKE ? LIMIT 20");
$st->execute(array('%' . $nome . '%'));
foreach ($st->fetchAll() as $r) {
    echo "  Cliente #{$r['id']}: {$r['name']} | CPF={$r['cpf']} | Tel={$r['phone']}\n";
}

echo "\n=== 3) SIMULA query atual da busca_global.php (linha 98-114) ===\n";
$q = $nome;
$like = '%' . $q . '%';
$qNumProc = preg_replace('/\D/', '', $q);
$likeNumProc = $qNumProc ? '%' . $qNumProc . '%' : '%zzzzz%';
$stmt = $pdo->prepare(
    "SELECT DISTINCT c.id, c.title AS titulo, c.case_number, cl.name AS cliente_nome
     FROM cases c
     LEFT JOIN clients cl ON cl.id = c.client_id
     LEFT JOIN case_partes cp ON cp.case_id = c.id
     WHERE c.title LIKE ?
        OR c.case_number LIKE ?
        OR REPLACE(REPLACE(REPLACE(c.case_number,'-',''),'.',''),'/','') LIKE ?
        OR cl.name LIKE ?
        OR cp.nome LIKE ?
     ORDER BY (c.title LIKE ?) DESC, c.updated_at DESC
     LIMIT 8"
);
$stmt->execute(array($like, $like, $likeNumProc, $like, $like, $q . '%'));
$rows = $stmt->fetchAll();
echo "  Resultados: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  Caso #{$r['id']} | {$r['titulo']} | CNJ={$r['case_number']} | Cliente={$r['cliente_nome']}\n";
}
