<?php
/**
 * Migracao: unifica cases com status='ativo' em status='em_andamento'.
 *
 * Motivo: o sistema tinha dois status sinonimos ('ativo' e 'em_andamento') que
 * causavam contagens divergentes entre telas (Dashboard, Operacional, Relatorios,
 * CRM, etc.). 'em_andamento' tem coluna no Kanban; 'ativo' caia em fallback
 * silencioso, sumindo de alguns dashboards.
 *
 * URL: /conecta/migrar_unificar_status_ativo.php?key=fsa-hub-deploy-2026
 *      adicione &confirma=1 pra rodar de verdade (sem isso, so simula).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_utils.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$confirma = isset($_GET['confirma']) && $_GET['confirma'] == '1';

echo "=== MIGRAÇÃO: status 'ativo' -> 'em_andamento' (cases) ===\n";
echo "Modo: " . ($confirma ? 'EXECUTAR DE VERDADE' : 'SIMULAÇÃO (use &confirma=1 pra rodar)') . "\n\n";

$qtd = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='ativo'")->fetchColumn();
echo "Cases com status='ativo' hoje: $qtd\n";

if ($qtd === 0) {
    echo "Nada a migrar. Banco ja esta limpo.\n";
    exit;
}

// Amostra dos 10 primeiros
echo "\nAmostra (10 primeiros):\n";
$st = $pdo->query("SELECT id, title, case_number, client_id, opened_at FROM cases WHERE status='ativo' ORDER BY id LIMIT 10");
foreach ($st->fetchAll() as $r) {
    printf("  #%-5d %-15s client=%-5d %s | %s\n", $r['id'], $r['case_number'] ?: '-', (int)$r['client_id'], $r['opened_at'] ?: '-', mb_substr($r['title'] ?: '-', 0, 40));
}

if (!$confirma) {
    echo "\n>>> Simulacao concluida. Adicione &confirma=1 na URL pra aplicar o UPDATE.\n";
    exit;
}

echo "\n>>> APLICANDO UPDATE...\n";
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("UPDATE cases SET status='em_andamento' WHERE status='ativo'");
    $stmt->execute();
    $afetados = $stmt->rowCount();
    echo "  -> Linhas afetadas: $afetados\n";

    // Audit log (uma entrada por linha seria caro; faz uma entrada agregada)
    audit_log('cases_status_migrado', 'case', null, "status='ativo' -> 'em_andamento' em $afetados cases (migracao_unificar_status_ativo, " . date('Y-m-d H:i') . ")");

    $pdo->commit();
    echo "  -> Commit OK\n";

    // Verificacao pos-migracao
    $apos = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='ativo'")->fetchColumn();
    $emAnd = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status='em_andamento'")->fetchColumn();
    echo "\nVerificacao pos-migracao:\n";
    echo "  status='ativo'        : $apos (deve ser 0)\n";
    echo "  status='em_andamento' : $emAnd\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "ERRO: " . $e->getMessage() . "\n";
}

echo "\nFIM.\n";
