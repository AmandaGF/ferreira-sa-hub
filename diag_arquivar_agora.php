<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Validação de arquivamentos recentes (últimos 30 min) ===\n";
echo "Hoje: " . date('Y-m-d H:i:s') . "\n\n";

// CASES — arquivados via botão (kanban_oculto=1 + updated_at recente)
echo "--- CASES ocultados nos últimos 30 min ---\n";
$cs = $pdo->query("SELECT id, title, status, kanban_oculto, marcado_para_arquivar, closed_at, updated_at
                   FROM cases
                   WHERE kanban_oculto = 1
                     AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                   ORDER BY updated_at DESC")->fetchAll();
echo "Total: " . count($cs) . "\n\n";
foreach ($cs as $c) {
    $statusOk = ($c['status'] !== 'arquivado') ? '✅' : '❌ STATUS VIROU ARQUIVADO!';
    $closedOk = empty($c['closed_at']) ? '✅' : '⚠️ closed_at=' . $c['closed_at'];
    $flagOk = ($c['marcado_para_arquivar'] == 0) ? '✅ flag limpa' : '⚠️ flag ainda 1';
    echo "case#{$c['id']} | {$c['title']}\n";
    echo "  status='{$c['status']}' {$statusOk}\n";
    echo "  closed_at: {$closedOk}\n";
    echo "  marcado_para_arquivar: {$flagOk}\n";
    echo "  updated: {$c['updated_at']}\n\n";
}

// LEADS — arquivados via botão
echo "--- LEADS (Comercial) ocultados nos últimos 30 min ---\n";
$ls = $pdo->query("SELECT id, name, stage, kanban_oculto, marcado_para_arquivar, arquivado_em, updated_at
                   FROM pipeline_leads
                   WHERE kanban_oculto = 1
                     AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                   ORDER BY updated_at DESC")->fetchAll();
echo "Total: " . count($ls) . "\n\n";
foreach ($ls as $l) {
    $stageOk = ($l['stage'] !== 'arquivado') ? '✅' : '❌ STAGE VIROU ARQUIVADO!';
    $arqOk = empty($l['arquivado_em']) ? '✅' : '⚠️ arquivado_em=' . $l['arquivado_em'];
    $flagOk = ($l['marcado_para_arquivar'] == 0) ? '✅ flag limpa' : '⚠️ flag ainda 1';
    echo "lead#{$l['id']} | {$l['name']}\n";
    echo "  stage='{$l['stage']}' {$stageOk}\n";
    echo "  arquivado_em: {$arqOk}\n";
    echo "  marcado_para_arquivar: {$flagOk}\n";
    echo "  updated: {$l['updated_at']}\n\n";
}

// AUDIT LOG dos últimos 30 min — qualquer ação de arquivamento
echo "--- AUDIT_LOG arquivamento últimos 30 min ---\n";
$al = $pdo->query("SELECT created_at, user_id, action, entity_type, entity_id, details
                   FROM audit_log
                   WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                     AND (action LIKE '%arquiv%' OR action='kanban_oculto' OR action='ocultar_kanban' OR action='marcar_para_arquivar')
                   ORDER BY id DESC LIMIT 100")->fetchAll();
echo "Total: " . count($al) . "\n\n";
foreach ($al as $a) {
    echo "  {$a['created_at']} | uid={$a['user_id']} | {$a['action']} | {$a['entity_type']}#{$a['entity_id']} | " . substr($a['details'], 0, 200) . "\n";
}

echo "\n=== FIM ===\n";
