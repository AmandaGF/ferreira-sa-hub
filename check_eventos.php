<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Estado dos eventos Robson/Tamires ===\n\n";

foreach (array(645, 644, 157, 664) as $id) {
    $st = $pdo->prepare("SELECT ae.id, ae.titulo, ae.case_id, ae.client_id, ae.tipo, c.title AS case_titulo, cl.name AS client_nome
                          FROM agenda_eventos ae
                          LEFT JOIN cases c ON c.id = ae.case_id
                          LEFT JOIN clients cl ON cl.id = ae.client_id
                          WHERE ae.id = ?");
    $st->execute(array($id));
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) { echo "ev #$id nao existe\n\n"; continue; }
    echo "-- ev #{$r['id']} tipo={$r['tipo']} --\n";
    echo "   titulo: {$r['titulo']}\n";
    echo "   case_id={$r['case_id']} → CASE: {$r['case_titulo']}\n";
    echo "   client_id={$r['client_id']} → CLIENT: {$r['client_nome']}\n\n";
}
