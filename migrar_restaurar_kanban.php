<?php
/**
 * Restaura ao Kanban Operacional os cases que foram ocultados
 * mas NÃO foram arquivados manualmente (status != 'arquivado').
 * Cobertura: cases com kanban_oculto=1 e status em em_andamento/em_elaboracao/
 * distribuido/aguardando_docs/aguardando_prazo/suspenso/doc_faltante/cancelado/etc.
 * Mantém intactos os com status='arquivado'.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();

$executar = isset($_GET['executar']);

echo "=== " . ($executar ? "RESTAURANDO" : "PREVIEW") . ": cases ocultos do Kanban Operacional ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . "\n\n";

$rows = $pdo->query("SELECT id, title, status, updated_at
                     FROM cases
                     WHERE kanban_oculto = 1
                       AND status NOT IN ('arquivado')
                     ORDER BY updated_at DESC")->fetchAll();
echo "Vão ser restaurados: " . count($rows) . " cases\n\n";

if (!$executar) {
    foreach ($rows as $r) {
        echo "case#{$r['id']} | {$r['title']} | status={$r['status']} | updated={$r['updated_at']}\n";
    }
    echo "\nPara executar: adicione &executar=1\n";
    exit;
}

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE cases SET kanban_oculto = 0, updated_at = NOW() WHERE id = ?");
    foreach ($rows as $r) {
        $upd->execute(array($r['id']));
        audit_log('kanban_restaurar_massa', 'case', (int)$r['id'], "kanban_oculto: 1 -> 0 (status={$r['status']})");
    }
    $pdo->commit();
    echo "✓ Restaurados " . count($rows) . " cases ao Kanban Operacional.\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
