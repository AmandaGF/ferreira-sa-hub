<?php
/**
 * Diag v2: contagens por tela + detalhe dos 7 duplicados.
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

h('A) DUPLICATAS DETALHADAS');
$dups = $pdo->query("
    SELECT REGEXP_REPLACE(case_number, '[^0-9]', '') AS num_norm, COUNT(*) AS qtd, GROUP_CONCAT(id ORDER BY id) AS ids
    FROM cases
    WHERE case_number IS NOT NULL AND case_number != ''
    GROUP BY num_norm
    HAVING qtd > 1
");
$dupGroups = $dups->fetchAll();
foreach ($dupGroups as $g) {
    echo "\nCNJ: {$g['num_norm']}\n";
    $ids = explode(',', $g['ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT c.id, c.title, c.case_number, c.status, c.responsible_user_id, c.client_id, c.opened_at, c.created_at, c.notes, cl.name AS cliente
                         FROM cases c LEFT JOIN clients cl ON cl.id=c.client_id
                         WHERE c.id IN ($placeholders) ORDER BY c.id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $r) {
        echo "  #{$r['id']} | case_num='{$r['case_number']}' | status={$r['status']} | client=#{$r['client_id']} ({$r['cliente']})\n";
        echo "       title: " . mb_substr($r['title'] ?: '-', 0, 70) . "\n";
        echo "       opened: {$r['opened_at']} | created: {$r['created_at']} | resp_user: {$r['responsible_user_id']}\n";
        if (!empty($r['notes'])) echo "       notes: " . mb_substr($r['notes'], 0, 80) . "\n";
    }
}

h('B) CONTAGENS POR FILTRO COMUM EM TELAS');
// Kanban operacional padrao
$q1 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE COALESCE(kanban_oculto,0)=0 AND status NOT IN ('arquivado','finalizado','cancelado','perdido','concluido','renunciamos')")->fetchColumn();
echo "Kanban operacional (kanban_oculto=0, status nao terminal): $q1\n";

$q2 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE kanban_oculto=1")->fetchColumn();
echo "Com kanban_oculto=1: $q2\n";

$q3 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status IN ('arquivado','finalizado','cancelado','perdido','concluido','renunciamos')")->fetchColumn();
echo "Status terminal (arquivado/finalizado/etc): $q3\n";

$q4 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE marcado_para_arquivar=1")->fetchColumn();
echo "Marcados para arquivar: $q4\n";

$q5 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE is_incidental=1")->fetchColumn();
echo "Incidentais (is_incidental=1): $q5\n";

$q6 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE pro_bono=1")->fetchColumn();
echo "Pro bono: $q6\n";

$q7 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE is_parceria=1")->fetchColumn();
echo "Parceria: $q7\n";

$q8 = (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE acompanhamento_externo=1")->fetchColumn();
echo "Acompanhamento externo (so monitora, nao tramitamos): $q8\n";

h('C) STATUS POSSIVEIS (todos):');
$st = $pdo->query("SELECT status, COUNT(*) AS qtd FROM cases GROUP BY status ORDER BY qtd DESC");
$totalStatusKnown = 0;
foreach ($st->fetchAll() as $r) {
    printf("  %-25s %5d\n", $r['status'] ?? '(null)', $r['qtd']);
    $totalStatusKnown += $r['qtd'];
}
echo "  TOTAL agregado: $totalStatusKnown\n";

h('D) BREAKDOWN: status considerados "em andamento" (varios sinonimos)');
$st = $pdo->query("SELECT status, COUNT(*) AS qtd FROM cases WHERE status IN ('ativo','em_andamento','distribuido','em_elaboracao','aguardando_docs','suspenso','doc_faltante','aguardando_prazo','parceria_previdenciario') GROUP BY status ORDER BY qtd DESC");
$ativoTotal = 0;
foreach ($st->fetchAll() as $r) {
    printf("  %-25s %5d\n", $r['status'], $r['qtd']);
    $ativoTotal += $r['qtd'];
}
echo "  TOTAL 'em algum andamento': $ativoTotal\n";

h('E) WHAT processos/index.php FILTRA?');
// Vamos ler o head de modules/processos/index.php pra detectar o filtro
$arq = __DIR__ . '/modules/processos/index.php';
if (file_exists($arq)) {
    $cont = file_get_contents($arq);
    if (preg_match_all('/SELECT[\s\S]{0,80}FROM cases[\s\S]{0,200}/m', $cont, $m)) {
        echo "Trechos com SELECT FROM cases em processos/index.php:\n";
        foreach ($m[0] as $i => $t) echo "[$i] " . str_replace("\n", ' | ', mb_substr($t, 0, 180)) . "\n---\n";
    }
}

h('F) CASES SEM case_number — POR STATUS');
$st = $pdo->query("SELECT status, COUNT(*) AS qtd FROM cases WHERE case_number IS NULL OR case_number = '' GROUP BY status ORDER BY qtd DESC");
foreach ($st->fetchAll() as $r) {
    printf("  %-25s %5d\n", $r['status'] ?? '(null)', $r['qtd']);
}

h('FIM');
