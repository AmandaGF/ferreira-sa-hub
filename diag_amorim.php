<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diag Audiência Amorim (modalidade presencial que deveria ser online) ===\n\n";

// 1) Achar case do Leonardo Luiz Pereira de Amorim
$st = $pdo->query("SELECT id, title, client_id, court, comarca FROM cases WHERE title LIKE '%Amorim%' OR title LIKE '%Pereira de Amorim%' ORDER BY id DESC LIMIT 5");
$cases = $st->fetchAll(PDO::FETCH_ASSOC);
echo "Cases achados:\n";
foreach ($cases as $c) echo "  #{$c['id']} {$c['title']} · client={$c['client_id']}\n";
if (!$cases) { echo "  nenhum\n"; exit; }

foreach ($cases as $c) {
    $caseId = (int)$c['id'];
    echo "\n=== Case #{$caseId}: {$c['title']} ===\n";

    // 2) Eventos de agenda desse case (recentes)
    $st2 = $pdo->prepare("SELECT id, tipo, subtipo, modalidade, data_inicio, titulo, local, meet_link, status, created_at, updated_at
                          FROM agenda_eventos WHERE case_id = ? ORDER BY id DESC LIMIT 10");
    $st2->execute(array($caseId));
    $evs = $st2->fetchAll(PDO::FETCH_ASSOC);
    echo "\n-- agenda_eventos --\n";
    foreach ($evs as $ev) {
        echo "  #{$ev['id']} {$ev['tipo']}/{$ev['subtipo']} modalidade={$ev['modalidade']} data={$ev['data_inicio']} local='{$ev['local']}' meet='{$ev['meet_link']}' status={$ev['status']} upd={$ev['updated_at']}\n";
        echo "    titulo: {$ev['titulo']}\n";
    }

    // 3) Andamentos desse case com agenda_evento_id
    $st3 = $pdo->prepare("SELECT id, agenda_evento_id, data_andamento, tipo, visivel_cliente, LEFT(descricao,200) AS preview, created_at
                          FROM case_andamentos WHERE case_id = ? ORDER BY id DESC LIMIT 20");
    $st3->execute(array($caseId));
    $ands = $st3->fetchAll(PDO::FETCH_ASSOC);
    echo "\n-- case_andamentos --\n";
    foreach ($ands as $a) {
        $prev = str_replace(array("\n","\r"), ' | ', $a['preview']);
        echo "  #{$a['id']} evId={$a['agenda_evento_id']} data={$a['data_andamento']} tipo={$a['tipo']} vis={$a['visivel_cliente']} em={$a['created_at']}\n";
        echo "    {$prev}\n";
    }
}
