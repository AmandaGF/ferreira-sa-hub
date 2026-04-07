<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Restaurar caso #669 — remover kanban_oculto
$pdo->prepare("UPDATE cases SET kanban_oculto = 0, updated_at = NOW() WHERE id = 669")->execute();
echo "Caso #669 restaurado (kanban_oculto = 0).\n";

$r = $pdo->query("SELECT id, title, status, kanban_oculto FROM cases WHERE id = 669")->fetch();
echo "Resultado: status={$r['status']} kanban_oculto={$r['kanban_oculto']}\n";
echo "\n=== FEITO ===\n";
