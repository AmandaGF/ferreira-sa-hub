<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG tarefa revelia + quebra de sigilo na pasta Kamilly ===\n\n";

// Case Kamilly Machado x Alimentos
echo "-- Case 'Kamilly Machado x Alimentos' --\n";
$st = $pdo->query("SELECT id, title, client_id, case_number FROM cases WHERE title LIKE '%Kamilly%Machado%'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  case #{$r['id']} client_id=$r[client_id] · {$r['title']} · $r[case_number]\n";
    $caseKamilly = (int)$r['id'];
}

// Detalhes da tarefa 2388
echo "\n-- Detalhes completos task #2388 --\n";
try {
    $st = $pdo->prepare("SELECT * FROM case_tasks WHERE id = 2388");
    $st->execute();
    $t = $st->fetch(PDO::FETCH_ASSOC);
    foreach ($t as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25).": $v\n";
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

// Audit log das tarefas
echo "\n\n-- Audit log de tarefa #2388 --\n";
try {
    $st = $pdo->query("SELECT * FROM audit_log WHERE entity_type='case_task' AND entity_id=2388 ORDER BY id");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        foreach ($a as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,20).": " . mb_substr((string)$v, 0, 200) . "\n";
        echo "---\n";
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

// Ver outras tarefas criadas por quem criou a 2388 no mesmo minuto
echo "\n\n-- Outras tarefas criadas no MESMO minuto (23:42 09/07) --\n";
try {
    $st = $pdo->query(
        "SELECT t.id, t.case_id, t.title, t.created_at, c.title AS case_titulo
         FROM case_tasks t
         LEFT JOIN cases c ON c.id = t.case_id
         WHERE t.created_at BETWEEN '2026-07-09 23:41:00' AND '2026-07-09 23:44:00'
         ORDER BY t.id"
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  #$r[id] case=$r[case_id] ($r[case_titulo]) em=$r[created_at]\n";
        echo "     title: $r[title]\n";
    }
} catch (Throwable $e) { echo "ERRO: " . $e->getMessage() . "\n"; }

// Todas tarefas da Kamilly
echo "\n-- Tarefas do case da Kamilly (#$caseKamilly) --\n";
$st = $pdo->prepare(
    "SELECT id, title, tipo, status, assigned_to, due_date, created_at, descricao
     FROM case_tasks WHERE case_id = ? ORDER BY id DESC"
);
$st->execute(array($caseKamilly));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
    echo "  task #{$t['id']} tipo={$t['tipo']} status={$t['status']} em=$t[created_at]\n";
    echo "     title: {$t['title']}\n";
    if ($t['descricao']) echo "     descr: " . mb_substr($t['descricao'], 0, 200) . "\n";
    echo "\n";
}

// Busca a tarefa de revelia
echo "\n-- Todas tarefas 'revelia' ou 'quebra sigilo' no sistema --\n";
$st = $pdo->query(
    "SELECT t.id, t.case_id, t.title, t.tipo, t.status, t.created_at,
            c.title AS case_titulo
     FROM case_tasks t
     LEFT JOIN cases c ON c.id = t.case_id
     WHERE t.title LIKE '%revelia%' OR t.title LIKE '%quebra%sigilo%'
     ORDER BY t.id DESC"
);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
    echo "  task #{$t['id']} case_id={$t['case_id']} ({$t['case_titulo']}) tipo={$t['tipo']} status={$t['status']} em=$t[created_at]\n";
    echo "     title: {$t['title']}\n\n";
}
