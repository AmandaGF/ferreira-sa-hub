<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
ini_set('display_errors', '1');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== DIAG: Campos do Kanban Comercial ===\n\n";

// 1. Listar colunas do pipeline_leads
echo "--- Colunas existentes em pipeline_leads ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM pipeline_leads")->fetchAll();
$have = array();
foreach ($cols as $c) {
    $have[$c['Field']] = $c['Type'];
    echo "  {$c['Field']}  ({$c['Type']})\n";
}

// 2. Ver quais campos esperados existem
echo "\n--- Verificar campos esperados ---\n";
$esperados = array('valor_acao', 'honorarios_cents', 'exito_percentual', 'vencimento_parcela', 'forma_pagamento', 'urgencia', 'observacoes', 'nome_pasta', 'assigned_to', 'case_type');
foreach ($esperados as $f) {
    echo "  $f: " . (isset($have[$f]) ? 'OK (' . $have[$f] . ')' : '!!!! FALTANDO !!!!') . "\n";
}

// 3. Últimos 5 leads editados recentemente
echo "\n--- Últimos 5 leads editados ---\n";
$sel = "id, name, stage, assigned_to, ";
foreach ($esperados as $f) if (isset($have[$f])) $sel .= "$f, ";
$sel = rtrim($sel, ', ');
$sql = "SELECT $sel FROM pipeline_leads ORDER BY updated_at DESC LIMIT 5";
echo "SQL: $sql\n\n";
$rows = $pdo->query($sql)->fetchAll();
foreach ($rows as $r) {
    echo "  Lead #{$r['id']} — {$r['name']} (stage={$r['stage']})\n";
    foreach ($esperados as $f) if (isset($have[$f])) {
        $v = $r[$f] ?? 'NULL';
        if ($v === '' || $v === null) $v = '(vazio)';
        echo "    $f: $v\n";
    }
    echo "\n";
}

echo "=== FIM ===\n";
