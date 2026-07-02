<?php
if (($_GET['key']??'') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('no'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Diag caso Robson Soares x Convivência ===\n\n";

// Achar case
$cs = $pdo->query("SELECT id, title, status FROM cases WHERE title LIKE '%Robson%Soares%'")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cs as $c) {
    echo "case #{$c['id']}: {$c['title']} (status={$c['status']})\n";

    // Audiencias
    echo "\n  Audiencias da solicitacao:\n";
    $st = $pdo->prepare("SELECT id, tipo, data_hora, status, audiencista_id, agenda_evento_id, created_at FROM audiencias WHERE case_id = ? ORDER BY id DESC");
    $st->execute(array((int)$c['id']));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        echo "    aud#{$a['id']} · tipo={$a['tipo']} · status={$a['status']} · data={$a['data_hora']}\n";
        echo "      audiencista_id={$a['audiencista_id']} · agenda_evento_id={$a['agenda_evento_id']}\n";
    }

    // Eventos de agenda desse case
    echo "\n  Eventos de agenda:\n";
    $st = $pdo->prepare("SELECT id, tipo, titulo, data_inicio, status, updated_at FROM agenda_eventos WHERE case_id = ? ORDER BY id DESC LIMIT 10");
    $st->execute(array((int)$c['id']));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
        echo "    ev#{$ev['id']} · tipo={$ev['tipo']} · '{$ev['titulo']}' · data={$ev['data_inicio']} · status={$ev['status']} · upd={$ev['updated_at']}\n";
    }
}
