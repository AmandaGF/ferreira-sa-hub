<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');
$st = $pdo->prepare("DELETE FROM ia_briefings WHERE user_id = 1 AND data = CURDATE()");
$st->execute();
echo "Briefing Amanda hoje apagado (RowCount=" . $st->rowCount() . "). Gere novamente no painel.\n";
