<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Caso #669 foi marcado como distribuido sem preencher dados
// Voltar para em_andamento (Em Execução)
$pdo->prepare("UPDATE cases SET status = 'em_andamento', updated_at = NOW() WHERE id = 669")->execute();
echo "Caso #669 restaurado para em_andamento (Em Execução).\n";

$r = $pdo->query("SELECT id, title, status, kanban_oculto FROM cases WHERE id = 669")->fetch();
echo "Resultado: status={$r['status']} kanban_oculto={$r['kanban_oculto']}\n";
echo "\n=== FEITO ===\n";
