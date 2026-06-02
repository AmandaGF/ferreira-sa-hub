<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL); ini_set('display_errors','1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== EVENTOS de agenda contendo 'jane' OU 'reis' ==\n";
$st = $pdo->prepare("SELECT e.id, e.titulo, e.tipo, e.data_inicio, e.status, e.case_id, e.client_id, e.responsavel_id, e.updated_at, c.name as client_name, cs.title as case_title
                     FROM agenda_eventos e
                     LEFT JOIN clients c ON c.id = e.client_id
                     LEFT JOIN cases cs ON cs.id = e.case_id
                     WHERE (e.titulo LIKE ? OR c.name LIKE ? OR cs.title LIKE ?) AND DATE(e.data_inicio) >= '2026-01-01'
                     ORDER BY e.data_inicio DESC LIMIT 25");
$st->execute(array('%jane%reis%','%jane%reis%','%jane%reis%'));
$rs = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$rs) {
    echo "  NADA encontrado com 'jane reis'. Vou buscar so 'jane'...\n";
    $st->execute(array('%jane%','%jane%','%jane%'));
    $rs = $st->fetchAll(PDO::FETCH_ASSOC);
}
foreach ($rs as $r) {
    echo "  ev_id={$r['id']} data={$r['data_inicio']} status={$r['status']} tipo={$r['tipo']}\n";
    echo "    titulo: {$r['titulo']}\n";
    echo "    case={$r['case_id']} ({$r['case_title']}) | client={$r['client_id']} ({$r['client_name']}) | resp={$r['responsavel_id']} | updated={$r['updated_at']}\n";
}

echo "\n== ATRASADOS detectados pela query do painel HOJE ('2026-06-01') ==\n";
$st2 = $pdo->prepare("SELECT id, titulo, tipo, data_inicio, status, case_id, client_id, responsavel_id
                      FROM agenda_eventos
                      WHERE DATE(data_inicio) < '2026-06-01' AND status NOT IN ('cancelado','remarcado','realizado')
                      AND titulo LIKE ? ORDER BY data_inicio LIMIT 10");
$st2->execute(array('%jane%'));
foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  ev_id={$r['id']} data={$r['data_inicio']} status={$r['status']} tipo={$r['tipo']} titulo={$r['titulo']}\n";
}
