<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG perda de msgs — " . date('Y-m-d H:i:s') . " ===\n\n";

// 1) Verifica se zapi_mensagens tem FK com ON DELETE CASCADE pra zapi_conversas
echo "--- 1) Foreign keys de zapi_mensagens ---\n";
try {
    $r = $pdo->query("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zapi_mensagens'
          AND REFERENCED_TABLE_NAME IS NOT NULL");
    foreach ($r as $f) echo "  {$f['CONSTRAINT_NAME']}: {$f['COLUMN_NAME']} → {$f['REFERENCED_TABLE_NAME']}.{$f['REFERENCED_COLUMN_NAME']}\n";

    $r2 = $pdo->query("SELECT CONSTRAINT_NAME, DELETE_RULE, UPDATE_RULE
        FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'zapi_mensagens'");
    foreach ($r2 as $f) echo "  {$f['CONSTRAINT_NAME']}: DELETE={$f['DELETE_RULE']} UPDATE={$f['UPDATE_RULE']}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 2) Mensagens ORFÃS (conversa_id não existe mais)
echo "\n--- 2) Mensagens órfãs (conversa_id sem conversa associada) ---\n";
try {
    $q = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens m
        LEFT JOIN zapi_conversas co ON co.id = m.conversa_id
        WHERE co.id IS NULL");
    echo "  Total órfãs: " . (int)$q->fetchColumn() . "\n";

    $q2 = $pdo->query("SELECT m.conversa_id, COUNT(*) AS n, MAX(m.created_at) AS ult
        FROM zapi_mensagens m
        LEFT JOIN zapi_conversas co ON co.id = m.conversa_id
        WHERE co.id IS NULL
        GROUP BY m.conversa_id ORDER BY n DESC LIMIT 20");
    foreach ($q2 as $r) echo "  conv_id {$r['conversa_id']} (morta): {$r['n']} msgs, última {$r['ult']}\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

// 3) Audit log de deletes recentes
echo "\n--- 3) Audit zapi_conversas deletadas recentes ---\n";
try {
    $r = $pdo->query("SELECT * FROM audit_log WHERE (acao LIKE '%zapi%' OR entidade LIKE '%zapi%' OR descricao LIKE '%mescla%' OR descricao LIKE '%merge%')
        AND criado_em > DATE_SUB(NOW(), INTERVAL 3 DAY)
        ORDER BY id DESC LIMIT 10");
    foreach ($r as $l) echo "  #{$l['id']} {$l['criado_em']} [{$l['acao']}] {$l['entidade']}#{$l['entidade_id']} — " . mb_substr((string)$l['descricao'], 0, 80, 'UTF-8') . "\n";
} catch (Exception $e) { echo "  (sem audit_log compatível)\n"; }

// 4) Contagem geral atual
echo "\n--- 4) Contagem geral ---\n";
try {
    echo "  Total conversas ativas: " . (int)$pdo->query("SELECT COUNT(*) FROM zapi_conversas")->fetchColumn() . "\n";
    echo "  Total mensagens: " . (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens")->fetchColumn() . "\n";
    echo "  Msgs órfãs (FK quebrada): " . (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens m LEFT JOIN zapi_conversas co ON co.id = m.conversa_id WHERE co.id IS NULL")->fetchColumn() . "\n";
} catch (Exception $e) {}
