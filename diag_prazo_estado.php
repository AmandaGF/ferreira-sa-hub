<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== PRAZOS DO BANNER (3 vencidos) ===\n\n";
foreach (array(16, 17, 23) as $pid) {
    $r = $pdo->prepare("SELECT * FROM prazos_processuais WHERE id = ?");
    $r->execute(array($pid));
    $p = $r->fetch();
    if (!$p) continue;
    echo "PRAZO #$pid\n";
    echo "  desc: {$p['descricao_acao']}\n";
    echo "  case_id: " . ($p['case_id'] ?? 'NULL') . "\n";
    echo "  CNJ: {$p['numero_processo']}\n";
    echo "  prazo_fatal: {$p['prazo_fatal']}\n";
    echo "  concluido: {$p['concluido']}\n\n";

    // Procura intimacoes/publicacoes desse CNJ
    if ($p['case_id']) {
        $r2 = $pdo->prepare("SELECT id, status_prazo, data_prazo_fim, data_disponibilizacao, conteudo FROM case_publicacoes WHERE case_id = ? ORDER BY id DESC LIMIT 5");
        $r2->execute(array($p['case_id']));
        $pubs = $r2->fetchAll();
        echo "  Publicacoes do case #" . $p['case_id'] . ":\n";
        foreach ($pubs as $pub) {
            echo "    pub#{$pub['id']} status_prazo={$pub['status_prazo']} data_prazo_fim={$pub['data_prazo_fim']} disp={$pub['data_disponibilizacao']}\n";
            echo "      conteudo: " . mb_substr($pub['conteudo'], 0, 80) . "\n";
        }
    } else {
        // Tenta achar case pelo CNJ
        $r3 = $pdo->prepare("SELECT id, title FROM cases WHERE case_number = ? LIMIT 3");
        $r3->execute(array($p['numero_processo']));
        $cases = $r3->fetchAll();
        echo "  Cases com mesmo CNJ:\n";
        foreach ($cases as $c) echo "    #{$c['id']} {$c['title']}\n";
        if (!empty($cases)) {
            $ids = implode(',', array_column($cases, 'id'));
            $r4 = $pdo->query("SELECT id, case_id, status_prazo, data_prazo_fim FROM case_publicacoes WHERE case_id IN ($ids) ORDER BY id DESC LIMIT 8");
            foreach ($r4->fetchAll() as $pub) {
                echo "    pub#{$pub['id']} case=#{$pub['case_id']} status={$pub['status_prazo']} data={$pub['data_prazo_fim']}\n";
            }
        }
    }
    echo "\n";
}

echo "=== TAREFAS RELACIONADAS (case_tasks com PRAZO no titulo) ===\n";
foreach (array(16, 17, 23) as $pid) {
    $r = $pdo->prepare("SELECT * FROM prazos_processuais WHERE id = ?");
    $r->execute(array($pid));
    $p = $r->fetch();
    if (!$p || !$p['case_id']) continue;
    $r2 = $pdo->prepare("SELECT id, title, status, completed_at, subtipo FROM case_tasks WHERE case_id = ? AND title LIKE '%PRAZO%' ORDER BY id DESC LIMIT 5");
    $r2->execute(array($p['case_id']));
    foreach ($r2->fetchAll() as $t) {
        echo "  task#{$t['id']} status={$t['status']} subtipo={$t['subtipo']} concluida={$t['completed_at']} | {$t['title']}\n";
    }
}
