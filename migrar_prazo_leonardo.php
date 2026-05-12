<?php
/**
 * One-shot 12/05/2026 — corrige prazos_processuais marcados como concluido=1
 * indevidamente (a tarefa vinculada via case_tasks.prazo_id ainda esta pendente).
 *
 * Caso reportado: prazo de Contestacao do Leonardo Braga (#886, prazos_processuais #12)
 * nasceu/ficou concluido=1, entao nao aparecia no modulo /prazos (que lista
 * WHERE concluido=0). A tarefa #1049 e os eventos da agenda estavam pendentes.
 *
 * Uso: curl https://ferreiraesa.com.br/conecta/migrar_prazo_leonardo.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Forbidden.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1) Lista prazos concluido=1 cuja tarefa vinculada NAO esta concluida (inconsistentes)
echo "=== Prazos com concluido=1 mas tarefa vinculada ainda PENDENTE ===\n\n";
$rows = $pdo->query(
    "SELECT p.id AS prazo_id, p.case_id, p.descricao_acao, p.prazo_fatal, p.concluido,
            t.id AS task_id, t.title AS task_title, t.status AS task_status,
            cs.title AS case_title
     FROM prazos_processuais p
     JOIN case_tasks t ON t.prazo_id = p.id
     LEFT JOIN cases cs ON cs.id = p.case_id
     WHERE p.concluido = 1 AND t.status NOT IN ('concluido','feito')
     ORDER BY p.prazo_fatal DESC"
)->fetchAll();

if (!$rows) {
    echo "Nenhum prazo inconsistente via tarefa vinculada.\n\n";
} else {
    foreach ($rows as $r) {
        echo "Prazo #{$r['prazo_id']} (case #{$r['case_id']} — {$r['case_title']}): \"{$r['descricao_acao']}\" fatal {$r['prazo_fatal']}\n";
        echo "  -> Tarefa #{$r['task_id']} status='{$r['task_status']}' — inconsistente\n";
    }
    echo "\nCorrigindo (concluido=0, cumprido_em=NULL)...\n";
    $ids = array_column($rows, 'prazo_id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("UPDATE prazos_processuais SET concluido = 0 WHERE id IN ($ph)");
    $st->execute($ids);
    echo "✓ " . $st->rowCount() . " prazo(s) corrigido(s).\n\n";
}

// 2) Garante o caso especifico do Leonardo Braga (#886, prazo #12) — se ainda
//    estiver concluido=1 (sem tarefa vinculada o JOIN acima nao pegaria)
$p12 = $pdo->prepare("SELECT id, case_id, descricao_acao, prazo_fatal, concluido FROM prazos_processuais WHERE id = 12");
$p12->execute();
$row12 = $p12->fetch();
if ($row12) {
    echo "=== Prazo #12 (Leonardo Braga) ===\n";
    echo "  descricao={$row12['descricao_acao']} fatal={$row12['prazo_fatal']} concluido={$row12['concluido']}\n";
    if ((int)$row12['concluido'] === 1) {
        $pdo->prepare("UPDATE prazos_processuais SET concluido = 0 WHERE id = 12")->execute();
        echo "  -> Corrigido pra concluido=0.\n";
    } else {
        echo "  -> Ja esta concluido=0, nada a fazer.\n";
    }
} else {
    echo "Prazo #12 nao encontrado (pode ter id diferente). Buscando por case_id=886...\n";
    $rows886 = $pdo->query("SELECT id, descricao_acao, prazo_fatal, concluido FROM prazos_processuais WHERE case_id = 886")->fetchAll();
    foreach ($rows886 as $r) {
        echo "  Prazo #{$r['id']}: {$r['descricao_acao']} fatal={$r['prazo_fatal']} concluido={$r['concluido']}\n";
        if ((int)$r['concluido'] === 1) {
            $pdo->prepare("UPDATE prazos_processuais SET concluido = 0 WHERE id = ?")->execute(array($r['id']));
            echo "    -> Corrigido pra concluido=0.\n";
        }
    }
}

echo "\nPronto. Confira o modulo /prazos.\n";
