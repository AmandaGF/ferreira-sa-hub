<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Janela: desde o deploy do refactor (09:01) até agora
$desde = '2026-05-01 09:01:00';
echo "=== Validação de arquivamentos desde {$desde} ===\n";
echo "Agora: " . date('Y-m-d H:i:s') . "\n\n";

echo "--- CASES ocultados desde o deploy do refactor ---\n";
$cs = $pdo->prepare("SELECT id, title, status, kanban_oculto, marcado_para_arquivar, closed_at, updated_at
                     FROM cases
                     WHERE kanban_oculto = 1
                       AND updated_at >= ?
                     ORDER BY updated_at DESC");
$cs->execute(array($desde));
$rows = $cs->fetchAll();
echo "Total: " . count($rows) . "\n\n";
foreach ($rows as $c) {
    $statusOk = ($c['status'] !== 'arquivado') ? '✅ status preservado' : '❌ STATUS VIROU ARQUIVADO!';
    $closedOk = empty($c['closed_at']) ? '✅ closed_at intocado' : '⚠️ closed_at=' . $c['closed_at'];
    $flagOk = ($c['marcado_para_arquivar'] == 0) ? '✅ flag limpa' : '⚠️ flag ainda 1';
    echo "case#{$c['id']} | {$c['title']}\n";
    echo "  status='{$c['status']}' {$statusOk}\n";
    echo "  closed_at: {$closedOk}\n";
    echo "  flag: {$flagOk}\n";
    echo "  updated: {$c['updated_at']}\n\n";
}

echo "--- LEADS (Comercial) ocultados desde deploy ---\n";
$ls = $pdo->prepare("SELECT id, name, stage, kanban_oculto, marcado_para_arquivar, arquivado_em, updated_at
                     FROM pipeline_leads
                     WHERE kanban_oculto = 1
                       AND updated_at >= ?
                     ORDER BY updated_at DESC");
$ls->execute(array($desde));
$lrows = $ls->fetchAll();
echo "Total: " . count($lrows) . "\n\n";
foreach ($lrows as $l) {
    $stageOk = ($l['stage'] !== 'arquivado') ? '✅ stage preservado' : '❌ STAGE VIROU ARQUIVADO!';
    $arqOk = empty($l['arquivado_em']) ? '✅ arquivado_em intocado' : '⚠️ arquivado_em=' . $l['arquivado_em'];
    $flagOk = ($l['marcado_para_arquivar'] == 0) ? '✅ flag limpa' : '⚠️ flag ainda 1';
    echo "lead#{$l['id']} | {$l['name']}\n";
    echo "  stage='{$l['stage']}' {$stageOk}\n";
    echo "  arquivado_em: {$arqOk}\n";
    echo "  flag: {$flagOk}\n";
    echo "  updated: {$l['updated_at']}\n\n";
}

echo "--- AUDIT_LOG desde deploy (arquivamento + marcação) ---\n";
$al = $pdo->prepare("SELECT created_at, user_id, action, entity_type, entity_id, details
                     FROM audit_log
                     WHERE created_at >= ?
                       AND (action LIKE '%arquiv%' OR action='kanban_oculto' OR action='ocultar_kanban' OR action='marcar_para_arquivar')
                     ORDER BY id DESC LIMIT 100");
$al->execute(array($desde));
$alrows = $al->fetchAll();
echo "Total: " . count($alrows) . "\n\n";
foreach ($alrows as $a) {
    echo "  {$a['created_at']} | uid={$a['user_id']} | {$a['action']} | {$a['entity_type']}#{$a['entity_id']} | " . substr($a['details'], 0, 200) . "\n";
}
echo "\n=== FIM ===\n";
