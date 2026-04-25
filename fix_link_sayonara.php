<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
@header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Vincula as 2 convivências da Sayonara (sem client_id) ao client #2313
$st = $pdo->prepare("UPDATE form_submissions SET linked_client_id = 2313 WHERE id IN (528, 529) AND form_type='convivencia' AND linked_client_id IS NULL");
$st->execute();
echo "Linkadas: " . $st->rowCount() . " convivências da Sayonara → client #2313\n";

$q = $pdo->query("SELECT id, protocol, linked_client_id, created_at FROM form_submissions WHERE id IN (526,527,528,529) ORDER BY id");
echo "\nEstado final:\n";
foreach ($q->fetchAll() as $r) {
    echo "  #{$r['id']} {$r['protocol']} {$r['created_at']} → client #{$r['linked_client_id']}\n";
}
