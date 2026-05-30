<?php
/**
 * Diag: por que prazo da Gabrielli (case #1193) continua aparecendo
 * no banner mesmo depois da Amanda marcar como "sem o que fazer".
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== PRAZOS DO CASE #1193 (Gabrielli) e RELACIONADOS ===\n\n";

// Mostra colunas todas pra ver se ha 'sem_acao' ou similar
$st = $pdo->query("SHOW COLUMNS FROM prazos_processuais");
echo "COLUNAS de prazos_processuais:\n";
foreach ($st->fetchAll() as $c) {
    printf("  %-30s %s\n", $c['Field'], $c['Type']);
}

echo "\n--- TODOS OS PRAZOS DO BANNER (vencidos NAO concluidos) ---\n";
$st = $pdo->query("
    SELECT *, DATEDIFF(prazo_fatal, CURDATE()) AS dias
    FROM prazos_processuais
    WHERE concluido = 0
      AND prazo_fatal <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY prazo_fatal ASC
");
foreach ($st->fetchAll() as $p) {
    echo "\nprazo #{$p['id']}\n";
    foreach ($p as $k => $v) {
        if ($v === null || $v === '') continue;
        echo "  $k: " . mb_substr((string)$v, 0, 150) . "\n";
    }
}

echo "\n--- AUDITORIA DOS PRAZOS (ultimas 20 acoes) ---\n";
try {
    $st = $pdo->query("
        SELECT id, user_id, action, entity, entity_id, details, created_at
        FROM audit_log
        WHERE entity = 'prazo' OR entity = 'prazos_processuais' OR details LIKE '%prazo%'
        ORDER BY id DESC LIMIT 25
    ");
    foreach ($st->fetchAll() as $a) {
        printf("  #%-5d user=%-3d %s %s [%s#%s] %s\n",
            $a['id'], $a['user_id'], $a['created_at'], $a['action'],
            $a['entity'], $a['entity_id'] ?? '?', mb_substr($a['details'] ?? '', 0, 60));
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

echo "\nFIM.\n";
