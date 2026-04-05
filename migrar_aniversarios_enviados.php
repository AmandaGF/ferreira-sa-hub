<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: Sincronizar aniversários enviados ===\n\n";

// Buscar no audit_log os aniversários enviados que não estão na birthday_greetings
$ano = (int)date('Y');

try {
    $rows = $pdo->query(
        "SELECT DISTINCT entity_id as client_id, user_id, DATE(created_at) as enviado_em
         FROM audit_log
         WHERE action = 'aniversario_enviado' AND entity_type = 'client'
         AND YEAR(created_at) = $ano"
    )->fetchAll();

    echo "Encontrados no audit_log: " . count($rows) . " registros\n\n";

    $migrados = 0;
    foreach ($rows as $r) {
        $clientId = (int)$r['client_id'];
        $userId = (int)$r['user_id'];

        // Verificar se já existe na birthday_greetings
        $stmt = $pdo->prepare("SELECT 1 FROM birthday_greetings WHERE client_id = ? AND year = ? LIMIT 1");
        $stmt->execute(array($clientId, $ano));
        if ($stmt->fetch()) {
            echo "[SKIP] Client #$clientId — já existe na birthday_greetings\n";
            continue;
        }

        // Inserir
        $pdo->prepare("INSERT IGNORE INTO birthday_greetings (client_id, year, sent_by) VALUES (?, ?, ?)")
            ->execute(array($clientId, $ano, $userId ?: 1));
        echo "[OK] Client #$clientId — migrado (enviado em {$r['enviado_em']})\n";
        $migrados++;
    }

    echo "\nMigrados: $migrados\n";
} catch (Exception $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
