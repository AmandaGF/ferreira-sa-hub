<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->exec("DELETE FROM agenda_eventos WHERE id IN (28, 29)");
echo "Eventos #28 e #29 apagados.\n";
