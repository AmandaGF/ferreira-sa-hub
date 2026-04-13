<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Deletar usuario Amanda da salavip_usuarios para que ela crie do zero pelo fluxo normal
$pdo->exec("DELETE FROM salavip_usuarios WHERE cliente_id = 447");
echo "Usuário Amanda removido da salavip_usuarios.\n";
echo "Agora ela pode ser cadastrada pelo fluxo normal (botão 'Criar Acesso Sala VIP' na ficha do cliente).\n";
