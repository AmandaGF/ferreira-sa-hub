<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG: Origem dos LEADS arquivados/finalizados/cancelados ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . "\n\n";

$stagesFinais = array('arquivado','finalizado','cancelado','perdido');
$placeholders = implode(',', array_fill(0, count($stagesFinais), '?'));

// Pra cada lead em stage final, descobrir o stage de origem (último lead_moved no audit_log ou pipeline_history)
echo "--- LEADS em stage FINAL (arquivado/finalizado/cancelado/perdido), com origem ---\n";
$leads = $pdo->prepare("SELECT id, name, stage, linked_case_id, arquivado_em, updated_at, created_at
                        FROM pipeline_leads WHERE stage IN ({$placeholders})
                        ORDER BY updated_at DESC");
$leads->execute($stagesFinais);
$rows = $leads->fetchAll();
echo "Total: " . count($rows) . "\n\n";

$counts = array('arquivado'=>array(), 'finalizado'=>array(), 'cancelado'=>array(), 'perdido'=>array());

foreach ($rows as $l) {
    $lid = (int)$l['id'];
    $stage = $l['stage'];

    // Tentar pelo pipeline_history (mais confiável)
    $hist = $pdo->prepare("SELECT from_stage, to_stage, created_at FROM pipeline_history
                           WHERE lead_id = ? AND to_stage = ?
                           ORDER BY id DESC LIMIT 1");
    $hist->execute(array($lid, $stage));
    $h = $hist->fetch();
    $origem = $h ? $h['from_stage'] : null;
    $quando = $h ? $h['created_at'] : $l['updated_at'];

    // Fallback pelo audit_log
    if (!$origem) {
        $aud = $pdo->prepare("SELECT details, created_at FROM audit_log
                              WHERE entity_type='lead' AND entity_id=? AND action='lead_moved'
                                AND details LIKE ?
                              ORDER BY id DESC LIMIT 1");
        $aud->execute(array($lid, '% -> ' . $stage));
        $a = $aud->fetch();
        if ($a && preg_match('/^(\S+)\s+->\s+/', $a['details'], $m)) {
            $origem = $m[1];
            $quando = $a['created_at'];
        }
    }

    $origemStr = $origem ?: '(sem histórico)';
    echo "lead#{$lid} | {$l['name']} | stage={$stage} | origem={$origemStr} | quando={$quando}\n";

    $counts[$stage][$origemStr] = ($counts[$stage][$origemStr] ?? 0) + 1;
}

echo "\n--- RESUMO por stage atual e origem ---\n";
foreach ($counts as $stg => $orgs) {
    if (empty($orgs)) continue;
    echo "\n>>> {$stg}:\n";
    arsort($orgs);
    foreach ($orgs as $org => $qtd) {
        $marker = ($stg === 'arquivado' && $org !== 'pasta_apta' && $org !== 'para_arquivar') ? ' ⚠️ NÃO veio de PASTA APTA!' : '';
        echo "  origem={$org} -> {$qtd}{$marker}\n";
    }
}

// Lista detalhada APENAS dos arquivados que NÃO vieram de pasta_apta
echo "\n--- ⚠️ LEADS arquivados que NÃO vieram de pasta_apta (regra da Amanda violada) ---\n";
$prob = $pdo->prepare("SELECT pl.id, pl.name, pl.stage, pl.linked_case_id, pl.arquivado_em
                       FROM pipeline_leads pl
                       WHERE pl.stage = 'arquivado'");
$prob->execute();
$problemas = 0;
foreach ($prob->fetchAll() as $l) {
    $h = $pdo->prepare("SELECT from_stage, created_at FROM pipeline_history
                        WHERE lead_id = ? AND to_stage = 'arquivado'
                        ORDER BY id DESC LIMIT 1");
    $h->execute(array($l['id']));
    $r = $h->fetch();
    $origem = $r ? $r['from_stage'] : null;
    if (!$origem) {
        $aud = $pdo->prepare("SELECT details FROM audit_log WHERE entity_type='lead' AND entity_id=? AND action='lead_moved' AND details LIKE '% -> arquivado' ORDER BY id DESC LIMIT 1");
        $aud->execute(array($l['id']));
        $a = $aud->fetch();
        if ($a && preg_match('/^(\S+)\s+->\s+/', $a['details'], $m)) $origem = $m[1];
    }
    if ($origem !== 'pasta_apta' && $origem !== 'para_arquivar') {
        $problemas++;
        echo "lead#{$l['id']} | {$l['name']} | origem=" . ($origem ?: '(sem hist)') . " | linked_case_id={$l['linked_case_id']} | arq_em={$l['arquivado_em']}\n";
    }
}
echo "Total problemas: {$problemas}\n";

echo "\n=== FIM ===\n";
