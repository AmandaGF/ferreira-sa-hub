<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MIGRAÇÃO: Chamados Sala VIP ===\n\n";

// 1. Adicionar colunas na tabela tickets
$cols = array(
    "client_id" => "ADD COLUMN client_id INT UNSIGNED DEFAULT NULL AFTER requester_id",
    "case_id" => "ADD COLUMN case_id INT UNSIGNED DEFAULT NULL AFTER client_id",
    "origem" => "ADD COLUMN origem VARCHAR(30) DEFAULT 'helpdesk' AFTER status",
    "sla_prazo" => "ADD COLUMN sla_prazo DATETIME DEFAULT NULL AFTER origem",
);

foreach ($cols as $col => $sql) {
    $chk = $pdo->query("SHOW COLUMNS FROM tickets LIKE '$col'");
    if ($chk->fetch()) { echo "[JÁ EXISTE] tickets.$col\n"; continue; }
    try { $pdo->exec("ALTER TABLE tickets $sql"); echo "[OK] tickets.$col\n"; }
    catch (Exception $e) { echo "[ERRO] $col: " . $e->getMessage() . "\n"; }
}

// 2. Adicionar colunas em ticket_messages para suportar sender_type
$msgCols = array(
    "sender_type" => "ADD COLUMN sender_type VARCHAR(20) DEFAULT 'equipe' AFTER ticket_id",
    "sender_id" => "ADD COLUMN sender_id INT UNSIGNED DEFAULT NULL AFTER sender_type",
);

foreach ($msgCols as $col => $sql) {
    $chk = $pdo->query("SHOW COLUMNS FROM ticket_messages LIKE '$col'");
    if ($chk->fetch()) { echo "[JÁ EXISTE] ticket_messages.$col\n"; continue; }
    try { $pdo->exec("ALTER TABLE ticket_messages $sql"); echo "[OK] ticket_messages.$col\n"; }
    catch (Exception $e) { echo "[ERRO] $col: " . $e->getMessage() . "\n"; }
}

// 3. Tornar user_id nullable (para mensagens de clientes que não têm user)
echo "\n--- Tornar user_id nullable ---\n";
try {
    $pdo->exec("ALTER TABLE ticket_messages MODIFY COLUMN user_id INT UNSIGNED DEFAULT NULL");
    echo "[OK] ticket_messages.user_id agora nullable\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 4. Tornar requester_id nullable (para tickets criados por clientes)
echo "\n--- Tornar requester_id nullable ---\n";
try {
    // Primeiro dropar a FK se existir
    $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tickets' AND COLUMN_NAME = 'requester_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
    foreach ($fks as $fk) {
        try { $pdo->exec("ALTER TABLE tickets DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME']); echo "[OK] FK removida: " . $fk['CONSTRAINT_NAME'] . "\n"; } catch (Exception $e) {}
    }
    $pdo->exec("ALTER TABLE tickets MODIFY COLUMN requester_id INT UNSIGNED DEFAULT NULL");
    echo "[OK] tickets.requester_id agora nullable\n";
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

// 5. Dropar FK de user_id em ticket_messages também
echo "\n--- Tornar ticket_messages.user_id sem FK ---\n";
try {
    $fks = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ticket_messages' AND COLUMN_NAME = 'user_id' AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
    foreach ($fks as $fk) {
        try { $pdo->exec("ALTER TABLE ticket_messages DROP FOREIGN KEY " . $fk['CONSTRAINT_NAME']); echo "[OK] FK removida: " . $fk['CONSTRAINT_NAME'] . "\n"; } catch (Exception $e) {}
    }
} catch (Exception $e) {
    echo "[INFO] " . $e->getMessage() . "\n";
}

echo "\n=== MIGRAÇÃO CONCLUÍDA ===\n";
