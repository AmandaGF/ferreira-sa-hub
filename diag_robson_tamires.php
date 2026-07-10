<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG compromisso ROBSON na pasta da TAMIRES ===\n\n";

// 1. Case da Tamires
echo "-- Cases 'Tamires Silva' --\n";
$st = $pdo->query("SELECT id, title, client_id, case_number, status FROM cases WHERE title LIKE '%Tamires%Silva%' OR title LIKE '%Tamires da Silva%'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  case #{$r['id']} client_id=$r[client_id] · {$r['title']} · {$r['case_number']} · status=$r[status]\n";
    $caseTamires = (int)$r['id'];
}

// 2. Eventos com 'Robson' no titulo/descricao
echo "\n-- Eventos com 'Robson Machado' ou 'ROBSON' --\n";
$st = $pdo->query("SELECT * FROM agenda_eventos WHERE titulo LIKE '%ROBSON%' OR titulo LIKE '%Robson%' OR descricao LIKE '%Robson%' ORDER BY id DESC LIMIT 10");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo str_repeat('-',60) . "\n";
    foreach ($r as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25) . ": $v\n";
}

// 3. Detalhes do case #Tamires + case #Robson (buscando)
echo "\n\n-- Cases com nome 'Robson' --\n";
$st = $pdo->query("SELECT id, title, client_id, case_number, status FROM cases WHERE title LIKE '%Robson%'");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  case #{$r['id']} client_id=$r[client_id] · {$r['title']} · $r[case_number] · status=$r[status]\n";
}

// 4. Ver todos compromissos que aparecem na pasta da Tamires (mesma query que caso_ver usa)
echo "\n\n-- Query 'compromissos do case' pro case da Tamires (#$caseTamires se achado) --\n";
if (isset($caseTamires) && $caseTamires) {
    $st = $pdo->prepare("SELECT id, titulo, tipo, data_inicio, status, case_id, client_id, descricao FROM agenda_eventos WHERE case_id = ? AND status NOT IN ('cancelado','realizado','concluido') ORDER BY data_inicio LIMIT 20");
    $st->execute(array($caseTamires));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        echo "  ev #$e[id] tipo=$e[tipo] status=$e[status] em=$e[data_inicio]\n";
        echo "     titulo: $e[titulo]\n";
        if ($e['descricao']) echo "     descr: " . mb_substr($e['descricao'], 0, 200) . "\n";
        echo "     client_id_evento=$e[client_id]\n";
    }
}

// 5. Buscar por audiencia AIJ Robson na tabela audiencias/solicitacoes
echo "\n\n-- Buscar em audiencias (Robson) --\n";
try {
    $st = $pdo->query("SELECT * FROM audiencias WHERE case_id IN (SELECT id FROM cases WHERE title LIKE '%Robson%') OR case_id = " . ($caseTamires ?? 0) . " OR orientacoes LIKE '%ROBSON%' ORDER BY id DESC LIMIT 5");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo str_repeat('-',60) . "\n";
        foreach ($r as $k=>$v) if ($v !== null && $v !== '') echo str_pad($k,25) . ": " . mb_substr((string)$v, 0, 200) . "\n";
    }
} catch (Throwable $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
