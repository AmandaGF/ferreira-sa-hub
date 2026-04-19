<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->exec("UPDATE honorarios_cobranca SET status='notificado_1', updated_at=NOW() WHERE id=1");
$pdo->exec("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_por) VALUES (1, 'observacao', 'Cancelamento revertido — clique acidental em 19/04', 1)");
echo "✓ Cobrança #1 restaurada para status=notificado_1 (mesma etapa das outras parcelas da Lais)\n";
