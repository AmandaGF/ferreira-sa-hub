<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function h($s) { echo "\n========== $s ==========\n"; }

h('1) CASES DA ENAYLE');
$st = $pdo->query("
    SELECT c.id, c.title, c.case_number, c.status, c.client_id, c.kanban_oculto,
           c.processo_principal_id, c.created_at, c.notes,
           cl.name AS cliente
    FROM cases c
    LEFT JOIN clients cl ON cl.id = c.client_id
    WHERE c.title LIKE '%enayle%' OR cl.name LIKE '%enayle%'
    ORDER BY c.id ASC
");
$cases = $st->fetchAll();
echo count($cases) . " cases encontrados\n";
foreach ($cases as $c) {
    echo "\n  #{$c['id']} | {$c['title']}\n";
    echo "    cliente: {$c['cliente']} (#{$c['client_id']})\n";
    echo "    CNJ: " . ($c['case_number'] ?? '-') . "\n";
    echo "    status: {$c['status']} | kanban_oculto: {$c['kanban_oculto']}\n";
    echo "    principal_id: " . ($c['processo_principal_id'] ?? '-') . "\n";
    echo "    created: {$c['created_at']}\n";
    if ($c['notes']) echo "    notes: " . mb_substr($c['notes'], 0, 120) . "\n";
}

h('2) DUPLICATAS POR CNJ NORMALIZADO');
foreach ($cases as $c) {
    if (!$c['case_number']) continue;
    $cnjDg = preg_replace('/\D/', '', $c['case_number']);
    $st = $pdo->prepare("
        SELECT id, title, status, created_at
        FROM cases
        WHERE REPLACE(REPLACE(REPLACE(case_number,'-',''),'.',''),'/','') = ?
          AND id != ?
        ORDER BY created_at
    ");
    $st->execute(array($cnjDg, $c['id']));
    $dups = $st->fetchAll();
    if ($dups) {
        echo "\n  Case #{$c['id']} tem " . count($dups) . " duplicata(s):\n";
        foreach ($dups as $d) {
            echo "    -> #{$d['id']} ({$d['status']}) {$d['title']} | criado em {$d['created_at']}\n";
        }
    }
}

h('3) AUDIT LOG: merge_cases nos ULTIMOS 50 REGISTROS');
try {
    $cols = $pdo->query("SHOW COLUMNS FROM audit_log")->fetchAll(PDO::FETCH_COLUMN);
    echo "Colunas audit_log: " . implode(', ', $cols) . "\n\n";

    $colAction = in_array('action', $cols) ? 'action' : (in_array('acao', $cols) ? 'acao' : 'action');
    $colDet = in_array('details', $cols) ? 'details' : (in_array('detalhes', $cols) ? 'detalhes' : 'details');
    $colEntId = in_array('entity_id', $cols) ? 'entity_id' : (in_array('record_id', $cols) ? 'record_id' : null);

    $st = $pdo->query("
        SELECT * FROM audit_log
        WHERE $colAction LIKE '%merge%'
        ORDER BY id DESC LIMIT 20
    ");
    foreach ($st->fetchAll() as $a) {
        $userId = $a['user_id'] ?? '?';
        $when = $a['created_at'] ?? $a['data_hora'] ?? '?';
        $entId = $a[$colEntId] ?? '?';
        echo "  #{$a['id']} user=$userId $when $a[$colAction] entity#$entId | " . mb_substr($a[$colDet] ?? '', 0, 80) . "\n";
    }
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}

h('4) LOG DE ERRO RECENTE (files/erro_*.log)');
$logs = glob(__DIR__ . '/files/erro_*.log');
foreach ($logs as $log) {
    $stat = stat($log);
    if (time() - $stat['mtime'] > 86400 * 7) continue; // só ultimos 7 dias
    echo "\n  {$log} (mtime: " . date('Y-m-d H:i:s', $stat['mtime']) . ")\n";
    $linhas = file($log);
    foreach (array_slice($linhas, -5) as $l) echo "    " . rtrim($l) . "\n";
}

echo "\nFIM.\n";
