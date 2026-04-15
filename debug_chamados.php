<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DEBUG Chamados ===\n\n";

// 1. Verificar estrutura de tickets
echo "--- Colunas tickets ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM tickets")->fetchAll();
foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']}) " . ($c['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";

echo "\n--- Colunas ticket_messages ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM ticket_messages")->fetchAll();
foreach ($cols as $c) echo "  {$c['Field']} ({$c['Type']}) " . ($c['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . "\n";

// 2. Tentar simular o INSERT como o chamados.php faz
echo "\n--- Simulando INSERT ticket ---\n";
try {
    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO tickets (title, category, priority, status, requester_id, client_id, case_id, origem, sla_prazo, created_at, updated_at)
         VALUES (?, ?, ?, 'aberto', NULL, ?, NULL, 'salavip', ?, NOW(), NOW())"
    )->execute(array('TESTE DEBUG', 'duvida', 'normal', 1, '2026-04-20 18:00:00'));
    $tid = $pdo->lastInsertId();
    echo "[OK] Ticket #$tid inserido\n";

    $pdo->prepare(
        "INSERT INTO ticket_messages (ticket_id, sender_type, sender_id, user_id, message, created_at)
         VALUES (?, 'cliente', ?, NULL, ?, NOW())"
    )->execute(array($tid, 1, 'mensagem teste'));
    echo "[OK] Mensagem inserida\n";

    $pdo->rollBack();
    echo "[OK] Rollback realizado\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}

// 3. Verificar se include files existem
echo "\n--- Includes Sala VIP ---\n";
$base = dirname(__DIR__) . '/salavip';
$files = array(
    '/includes/auth.php',
    '/includes/functions.php',
    '/includes/header.php',
    '/includes/footer.php',
    '/config.php',
    '/pages/chamados.php',
);
foreach ($files as $f) {
    echo (file_exists($base . $f) ? '[OK] ' : '[FALTA] ') . $f . "\n";
}

// 4. Verificar funções usadas pelo chamados.php
echo "\n--- Verificando functions.php ---\n";
if (file_exists($base . '/includes/functions.php')) {
    $content = file_get_contents($base . '/includes/functions.php');
    foreach (array('sv_e', 'sv_flash', 'sv_redirect', 'salavip_validar_csrf', 'salavip_gerar_csrf', 'salavip_current_cliente_id', 'sv_db', 'salavip_current_user') as $fn) {
        echo (strpos($content, "function $fn") !== false ? '[OK] ' : '[FALTA] ') . $fn . "()\n";
    }
}
