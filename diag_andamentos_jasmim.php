<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Buscando caso 'John Jasmim x Querela' ===\n";
$st = $pdo->prepare("SELECT id, title, case_number, status FROM cases
                     WHERE title LIKE ? OR title LIKE ? OR title LIKE ?
                     ORDER BY id DESC LIMIT 10");
$st->execute(array('%John%Jasmim%', '%Jasmim%Querela%', '%Querela%'));
$cases = $st->fetchAll();
foreach ($cases as $c) {
    echo "  #{$c['id']} | {$c['title']} | CNJ={$c['case_number']} | status={$c['status']}\n";
}

if (!$cases) { echo "  Nenhum caso encontrado.\n"; exit; }

$caseId = (int)$cases[0]['id'];
echo "\n=== Andamentos do caso #$caseId ===\n";
$st = $pdo->prepare("SELECT id, data_andamento, tipo, descricao FROM case_andamentos
                     WHERE case_id = ? ORDER BY data_andamento ASC, id ASC");
$st->execute(array($caseId));
$ands = $st->fetchAll();
echo "Total: " . count($ands) . "\n\n";
foreach ($ands as $a) {
    $preview = mb_substr(preg_replace('/\s+/', ' ', $a['descricao']), 0, 200, 'UTF-8');
    echo "  AND #{$a['id']} | {$a['data_andamento']} | tipo={$a['tipo']}\n";
    echo "    " . $preview . (mb_strlen($a['descricao']) > 200 ? '...' : '') . "\n\n";
}
