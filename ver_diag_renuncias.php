<?php
/** Diag temp: valida os INSERTs (renuncias + case_tasks) em transação com rollback. Remover. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('nope'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$case = $pdo->query("SELECT id, client_id, responsible_user_id FROM cases LIMIT 1")->fetch();
if (!$case) { echo "Sem cases pra testar.\n"; exit; }
echo "case#{$case['id']} client#{$case['client_id']} resp=" . ($case['responsible_user_id'] ?: 'NULL') . "\n\n";

try {
    $pdo->beginTransaction();

    $pdo->prepare("INSERT INTO renuncias
        (case_id, client_id, tipo, motivo, motivo_outro, observacao, comprovante_nome, comprovante_path, comprovante_mime, created_by, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute(array($case['id'], $case['client_id'], 'renuncia', 'inadimplencia', null, 'teste', 'x.pdf', 'stored.pdf', 'application/pdf', 1));
    echo "✓ INSERT renuncias OK (id=" . $pdo->lastInsertId() . ")\n";

    $pdo->prepare("INSERT INTO case_tasks
        (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,NOW())")
        ->execute(array($case['id'], 'TESTE renuncia', 'juntar_documento', 'desc teste',
                        $case['responsible_user_id'] ?: null, date('Y-m-d', strtotime('+3 days')), 'alta', 'a_fazer', 0));
    $tid = $pdo->lastInsertId();
    echo "✓ INSERT case_tasks OK (id=$tid)\n";

    // confere que a tarefa apareceria no Kanban (tipo != '' e status)
    $chk = $pdo->prepare("SELECT tipo, status, prioridade, assigned_to FROM case_tasks WHERE id=?");
    $chk->execute(array($tid));
    echo "  task: " . json_encode($chk->fetch()) . "\n";

    $pdo->rollBack();
    echo "\n✓ rollback feito — nada gravado. SQL dos dois INSERTs está correto.\n";
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ ERRO SQL: " . $e->getMessage() . "\n";
}
