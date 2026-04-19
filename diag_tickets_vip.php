<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Tickets helpdesk com origem='salavip' ===\n";
$n1 = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE origem = 'salavip'")->fetchColumn();
$n2 = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE origem IS NULL OR origem != 'salavip'")->fetchColumn();
echo "  Com origem='salavip': {$n1}\n";
echo "  Sem origem salavip (internos): {$n2}\n\n";

echo "=== Threads Central VIP (salavip_threads) ===\n";
try {
    $n3 = (int)$pdo->query("SELECT COUNT(*) FROM salavip_threads")->fetchColumn();
    echo "  Total: {$n3}\n";
    $rows = $pdo->query("SELECT id, cliente_id, assunto, status, criado_em FROM salavip_threads ORDER BY id DESC LIMIT 10")->fetchAll();
    foreach ($rows as $r) echo "  #{$r['id']} | cliente={$r['cliente_id']} | {$r['assunto']} | status={$r['status']} | {$r['criado_em']}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== Colunas de tickets (verificar se tem origem) ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll(PDO::FETCH_COLUMN);
foreach ($cols as $c) echo "  - $c\n";
