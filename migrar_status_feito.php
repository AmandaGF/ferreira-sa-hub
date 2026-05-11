<?php
/**
 * One-shot 11/05/2026 — normaliza case_tasks.status de 'feito' pra 'concluido'.
 *
 * Contexto: caso_ver.php trata 'feito' e 'concluido' como sinonimos (isDone =
 * status==='concluido' || status==='feito'). Mas o kanban de tarefas
 * (modules/tarefas/) so reconhece 'concluido' como terminal — qualquer outro
 * vira 'a_fazer' (linha 283 do index.php). Resultado: tarefas com 'feito'
 * apareciam como 'em aberto' no kanban da agenda, apesar de estarem
 * concluidas na pasta.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_status_feito.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

$cnt = (int)$pdo->query("SELECT COUNT(*) FROM case_tasks WHERE status = 'feito'")->fetchColumn();
echo "Tarefas com status='feito' antes: {$cnt}\n";

if ($cnt === 0) { echo "Nada a migrar.\n"; exit; }

try {
    $stmt = $pdo->prepare("UPDATE case_tasks
                           SET status = 'concluido',
                               completed_at = COALESCE(completed_at, updated_at, NOW())
                           WHERE status = 'feito'");
    $stmt->execute();
    $afetadas = $stmt->rowCount();
    echo "✓ Atualizadas {$afetadas} tarefas: 'feito' -> 'concluido'.\n";

    $cntDepois = (int)$pdo->query("SELECT COUNT(*) FROM case_tasks WHERE status = 'feito'")->fetchColumn();
    echo "Tarefas com status='feito' depois: {$cntDepois}\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
