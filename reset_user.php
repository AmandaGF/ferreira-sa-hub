<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$pdo->exec("DELETE FROM salavip_usuarios WHERE cliente_id = 447");
echo "Usuário 12153828716 (Amanda) removido.\n";
echo "Pode criar novamente pelo fluxo normal (Criar Acesso Sala VIP na ficha do cliente).\n";
