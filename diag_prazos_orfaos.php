<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== DIAG: Intimacoes VENCIDAS que Amanda nao acha nas pastas ===\n\n";

// Pega prazos_processuais vencidos + case_tasks tipo prazo vencidos
echo "-- prazos_processuais vencidos, com 'Publicacao: INTIMACAO' ou similares --\n";
$st = $pdo->query(
    "SELECT p.id, p.case_id, p.numero_processo, p.descricao_acao, p.prazo_fatal, p.usuario_id,
            p.concluido, p.concluido_em,
            c.title AS case_title, c.status AS case_status
     FROM prazos_processuais p
     LEFT JOIN cases c ON c.id = p.case_id
     WHERE p.concluido = 0
       AND (p.descricao_acao LIKE 'Publica%INTIMA%' OR p.descricao_acao LIKE 'Intima%'
            OR p.descricao_acao LIKE 'CumSen%' OR p.descricao_acao LIKE 'DivLit%')
       AND p.prazo_fatal < CURDATE()
     ORDER BY p.prazo_fatal
     LIMIT 30"
);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  prazo #{$r['id']}  fatal={$r['prazo_fatal']}\n";
    echo "     descricao: {$r['descricao_acao']}\n";
    echo "     numero_processo(campo do prazo): " . ($r['numero_processo'] ?: '(vazio)') . "\n";
    echo "     case_id: " . ($r['case_id'] === null ? 'NULL !!' : $r['case_id']) . " ($r[case_title]) status=$r[case_status]\n\n";
}

echo "\n-- case_tasks tipo='prazo' vencidos, com 'INTIMACAO' no titulo --\n";
$st = $pdo->query(
    "SELECT ct.id, ct.case_id, ct.title, ct.due_date, ct.status, ct.assigned_to,
            c.title AS case_title, c.status AS case_status
     FROM case_tasks ct
     LEFT JOIN cases c ON c.id = ct.case_id
     WHERE ct.tipo = 'prazo'
       AND ct.status != 'concluido'
       AND (ct.title LIKE '%INTIMA%' OR ct.title LIKE '%Publica%INTIMA%')
       AND ct.due_date < CURDATE()
     ORDER BY ct.due_date
     LIMIT 30"
);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  task #{$r['id']}  due={$r['due_date']}  status={$r['status']}\n";
    echo "     title: {$r['title']}\n";
    echo "     case_id: " . ($r['case_id'] === null ? 'NULL !!' : $r['case_id']) . " ($r[case_title]) status=$r[case_status]\n\n";
}

echo "\n-- case_publicacoes vencidas com prazo em curso --\n";
$st = $pdo->query(
    "SELECT p.id, p.case_id, p.tipo_publicacao, p.data_prazo_fim, p.status_prazo, p.task_id,
            LEFT(p.conteudo, 100) AS conteudo_preview,
            c.title AS case_title
     FROM case_publicacoes p
     LEFT JOIN cases c ON c.id = p.case_id
     WHERE p.status_prazo = 'pendente'
       AND p.data_prazo_fim < CURDATE()
     ORDER BY p.data_prazo_fim DESC
     LIMIT 30"
);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  pub #{$r['id']}  fatal={$r['data_prazo_fim']}  task_id=" . ($r['task_id']?:'NULL') . "\n";
    echo "     case_id: " . ($r['case_id']?:'NULL') . " ($r[case_title])\n";
    echo "     preview: {$r['conteudo_preview']}\n\n";
}

echo "\n-- caso especifico: Nilceia Marchiori (numero 5000933-78.2026.4.02.5109) --\n";
$st = $pdo->prepare("SELECT id, title, status, client_id, case_number FROM cases WHERE case_number = ? OR case_number LIKE '%5000933-78%' LIMIT 5");
$st->execute(array('5000933-78.2026.4.02.5109'));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  case #{$r['id']}  {$r['title']}  status={$r['status']}  num={$r['case_number']}\n";
    // Ver tarefas deste case
    $stT = $pdo->prepare("SELECT id, title, tipo, status, due_date FROM case_tasks WHERE case_id = ? ORDER BY id DESC LIMIT 10");
    $stT->execute(array($r['id']));
    echo "  Tarefas do case:\n";
    foreach ($stT->fetchAll(PDO::FETCH_ASSOC) as $t) {
        echo "     task #{$t['id']} tipo={$t['tipo']} status={$t['status']} due={$t['due_date']} · {$t['title']}\n";
    }
    // Ver prazos_processuais deste case
    $stP = $pdo->prepare("SELECT id, descricao_acao, prazo_fatal, concluido FROM prazos_processuais WHERE case_id = ? ORDER BY id DESC LIMIT 10");
    $stP->execute(array($r['id']));
    echo "  prazos_processuais do case:\n";
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $p) {
        echo "     prazo #{$p['id']} concluido=$p[concluido] fatal={$p['prazo_fatal']} · {$p['descricao_acao']}\n";
    }
    // Ver case_publicacoes
    $stPub = $pdo->prepare("SELECT id, data_prazo_fim, status_prazo, task_id FROM case_publicacoes WHERE case_id = ? ORDER BY id DESC LIMIT 10");
    $stPub->execute(array($r['id']));
    echo "  case_publicacoes do case:\n";
    foreach ($stPub->fetchAll(PDO::FETCH_ASSOC) as $pu) {
        echo "     pub #{$pu['id']} fatal={$pu['data_prazo_fim']} status_prazo=$pu[status_prazo] task_id=" . ($pu['task_id']?:'NULL') . "\n";
    }
}
