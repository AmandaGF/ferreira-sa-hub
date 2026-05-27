<?php
/**
 * Diag: contagens de processos por status, visibilidade, integridade.
 * URL: /conecta/diag_contagem_processos.php?key=fsa-hub-deploy-2026
 *
 * Objetivo: localizar onde os numeros nao batem.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
error_reporting(E_ALL);
ini_set('display_errors', '1');
require_once __DIR__ . '/core/database.php';

header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

set_exception_handler(function($e) {
    echo "\n=== EXCEPTION ===\n" . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
});

function h($s) { echo "\n========== $s ==========\n"; }

h('1) TOTAL DE CASES NO BANCO');
$total = (int)$pdo->query("SELECT COUNT(*) FROM cases")->fetchColumn();
echo "Total cases: $total\n";

h('2) CONTAGEM POR STATUS');
$st = $pdo->query("SELECT status, COUNT(*) AS qtd FROM cases GROUP BY status ORDER BY qtd DESC");
foreach ($st->fetchAll() as $r) {
    printf("  %-25s %5d\n", $r['status'] ?? '(null)', $r['qtd']);
}

h('3) CASES COM client_id NULL OU INVALIDO');
$semCliente = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE client_id IS NULL OR client_id = 0")->fetchColumn();
echo "Sem client_id: $semCliente\n";
$clienteInexistente = (int)$pdo->query("SELECT COUNT(*) FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.client_id IS NOT NULL AND cl.id IS NULL")->fetchColumn();
echo "client_id aponta para cliente inexistente: $clienteInexistente\n";

h('4) DUPLICATAS POR case_number NORMALIZADO');
$dups = $pdo->query("
    SELECT REGEXP_REPLACE(case_number, '[^0-9]', '') AS num_norm, COUNT(*) AS qtd, GROUP_CONCAT(id ORDER BY id) AS ids
    FROM cases
    WHERE case_number IS NOT NULL AND case_number != ''
    GROUP BY num_norm
    HAVING qtd > 1
    ORDER BY qtd DESC
    LIMIT 20
");
$dupRows = $dups->fetchAll();
echo "Grupos de duplicatas (top 20): " . count($dupRows) . "\n";
foreach ($dupRows as $r) {
    echo "  CNJ {$r['num_norm']} -> {$r['qtd']} cases (ids: {$r['ids']})\n";
}

h('5) CASES SEM case_number');
$semNum = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE case_number IS NULL OR case_number = ''")->fetchColumn();
echo "Sem case_number: $semNum (esperado: pre-protocolos, em distribuicao etc.)\n";

h('6) VISIBILIDADE: KANBAN OPERACIONAL (status != arquivado, finalizado, cancelado)');
$kanban = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE COALESCE(status,'') NOT IN ('arquivado','finalizado','cancelado','perdido')")->fetchColumn();
echo "Visiveis no Kanban: $kanban\n";

h('7) COLUNAS soft-delete / hidden');
$cols = $pdo->query("SHOW COLUMNS FROM cases")->fetchAll(PDO::FETCH_COLUMN);
echo "Colunas em cases: " . implode(', ', $cols) . "\n";
foreach (array('deleted_at','is_deleted','hidden','archived_at','is_archived') as $colTeste) {
    if (in_array($colTeste, $cols, true)) {
        echo "  ✓ existe coluna '$colTeste'\n";
        try {
            $q = $pdo->query("SELECT COUNT(*) FROM cases WHERE $colTeste IS NOT NULL");
            echo "    -> $colTeste IS NOT NULL: " . (int)$q->fetchColumn() . "\n";
        } catch (Exception $e) {}
    }
}

h('8) CONTAGEM POR responsible_user_id (top 15)');
$st = $pdo->query("
    SELECT u.id, u.name, COUNT(c.id) AS qtd
    FROM users u
    LEFT JOIN cases c ON c.responsible_user_id = u.id
    GROUP BY u.id, u.name
    ORDER BY qtd DESC
    LIMIT 15
");
foreach ($st->fetchAll() as $r) {
    printf("  %-3d %-30s %5d\n", $r['id'], mb_substr($r['name'], 0, 30), $r['qtd']);
}
$semResp = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE responsible_user_id IS NULL")->fetchColumn();
echo "  --- sem responsavel: $semResp ---\n";

h('9) CASES IMPORTADOS (source ou notes mencionando import)');
try {
    $imp = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE notes LIKE '%importac%' OR notes LIKE '%import%'")->fetchColumn();
    echo "Cases com 'import' em notes: $imp\n";
} catch (Exception $e) { echo "(coluna notes nao existe)\n"; }

h('10) ULTIMOS 10 CASES CRIADOS');
$st = $pdo->query("SELECT id, title, case_number, status, client_id, opened_at, created_at FROM cases ORDER BY id DESC LIMIT 10");
foreach ($st->fetchAll() as $r) {
    printf("  #%-5d %-15s %-12s client=%-5d %s | %s\n",
        $r['id'],
        mb_substr($r['case_number'] ?? '-', 0, 15),
        $r['status'] ?? '-',
        (int)$r['client_id'],
        $r['opened_at'] ?? '-',
        mb_substr($r['title'] ?? '-', 0, 40));
}

h('11) CASES POR ANO DE CADASTRO (opened_at ou created_at)');
try {
    $st = $pdo->query("SELECT YEAR(COALESCE(opened_at, created_at)) AS ano, COUNT(*) AS qtd FROM cases GROUP BY ano ORDER BY ano DESC");
    foreach ($st->fetchAll() as $r) {
        printf("  %s : %d\n", $r['ano'] ?? '(null)', $r['qtd']);
    }
} catch (Exception $e) { echo "Falhou: " . $e->getMessage() . "\n"; }

h('FIM');
