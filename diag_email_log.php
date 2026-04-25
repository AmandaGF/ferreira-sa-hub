<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Últimas 5 execuções email_monitor_log ===\n\n";
$q = $pdo->query("SELECT * FROM email_monitor_log ORDER BY id DESC LIMIT 5");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} {$r['executado_em']} ({$r['modo']})\n";
    echo "  lidos={$r['emails_lidos']} inseridos={$r['andamentos_inseridos']} ignorados={$r['emails_ignorados']} dup={$r['duplicatas_ignoradas']} erros={$r['erros']}\n";
    if ($r['detalhes']) {
        $linhas = explode("\n", $r['detalhes']);
        echo "  detalhes (" . count($linhas) . " linhas):\n";
        foreach (array_slice($linhas, 0, 10) as $ln) echo "    " . $ln . "\n";
        if (count($linhas) > 10) echo "    ... (+" . (count($linhas) - 10) . ")\n";
    }
    echo "\n";
}

echo "=== Últimos 10 case_andamentos com tipo_origem='email_pje' ===\n\n";
$q = $pdo->query("SELECT ca.id, ca.case_id, c.case_number, ca.data_andamento, ca.hora_andamento, SUBSTR(ca.descricao,1,100) AS preview FROM case_andamentos ca LEFT JOIN cases c ON c.id=ca.case_id WHERE ca.tipo_origem='email_pje' ORDER BY ca.id DESC LIMIT 10");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} case #{$r['case_id']} ({$r['case_number']}) {$r['data_andamento']} {$r['hora_andamento']}: {$r['preview']}\n";
}

echo "\n=== TOTAL ===\n";
$tot = (int)$pdo->query("SELECT COUNT(*) FROM case_andamentos WHERE tipo_origem='email_pje'")->fetchColumn();
echo "Total andamentos email_pje: {$tot}\n";
