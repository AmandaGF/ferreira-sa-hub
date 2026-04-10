<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$rows = $pdo->query("SELECT id, titulo, tipo, status, data_inicio FROM agenda_eventos ORDER BY id DESC LIMIT 15")->fetchAll();
foreach ($rows as $r) {
    echo "#" . $r['id'] . " | " . $r['status'] . " | " . $r['data_inicio'] . " | " . $r['titulo'] . "\n";
}
