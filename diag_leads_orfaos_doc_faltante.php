<?php
/**
 * Diagnostico: identifica casos em doc_faltante que NAO tem lead correspondente
 * no Pipeline Comercial (vitimas do bug onde a duplicata "roubava" o lead da
 * pasta original).
 *
 * Tambem mostra: schema da tabela pipeline_leads, casos vs leads por cliente.
 *
 * Acesse: ferreiraesa.com.br/conecta/diag_leads_orfaos_doc_faltante.php?key=fsa-hub-deploy-2026
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$pdo = db();

echo "=== Diagnostico: casos em doc_faltante sem lead ===\n\n";

// 1. Constraint de pipeline_leads
echo "--- Indexes de pipeline_leads ---\n";
$idx = $pdo->query("SHOW INDEX FROM pipeline_leads")->fetchAll(PDO::FETCH_ASSOC);
$unicosPorCol = array();
foreach ($idx as $i) {
    $marker = $i['Non_unique'] == 0 ? 'UNIQUE' : '      ';
    echo "  [" . $marker . "] " . $i['Key_name'] . " on " . $i['Column_name'] . "\n";
    if ($i['Non_unique'] == 0 && $i['Column_name'] === 'client_id') {
        echo "  >>> ATENCAO: pipeline_leads.client_id eh UNIQUE — nao da pra ter 2 leads do mesmo cliente!\n";
    }
}

// 2. Casos em doc_faltante
$cases = $pdo->query("
    SELECT cs.id, cs.title, cs.client_id, c.name AS client_name, cs.case_number
    FROM cases cs
    LEFT JOIN clients c ON c.id = cs.client_id
    WHERE cs.status = 'doc_faltante'
      AND COALESCE(cs.kanban_oculto, 0) = 0
    ORDER BY cs.client_id, cs.id
")->fetchAll();

echo "\n--- Casos em doc_faltante (" . count($cases) . " total) ---\n";

$orfaos = array();
$cobertos = array();
foreach ($cases as $cs) {
    $st = $pdo->prepare("SELECT id, stage, linked_case_id FROM pipeline_leads WHERE linked_case_id = ? LIMIT 1");
    $st->execute(array($cs['id']));
    $lead = $st->fetch();
    if ($lead) {
        $cobertos[] = $cs;
        echo "  [OK]   #" . str_pad((string)$cs['id'], 4, ' ') . " " . substr((string)$cs['client_name'], 0, 30)
            . " | Lead #" . $lead['id'] . " (" . $lead['stage'] . ")\n";
    } else {
        $orfaos[] = $cs;
        // Tem OUTRO lead do mesmo cliente?
        $st2 = $pdo->prepare("SELECT id, stage, linked_case_id FROM pipeline_leads WHERE client_id = ? AND stage NOT IN ('finalizado','perdido') ORDER BY id DESC");
        $st2->execute(array($cs['client_id']));
        $outros = $st2->fetchAll();
        $outrosStr = '';
        foreach ($outros as $o) {
            $outrosStr .= ' Lead#' . $o['id'] . '(' . $o['stage'] . ',linked=' . ($o['linked_case_id'] ?: 'NULL') . ')';
        }
        echo "  [ORF]  #" . str_pad((string)$cs['id'], 4, ' ') . " " . substr((string)$cs['client_name'], 0, 30)
            . " | SEM LEAD." . ($outrosStr ? ' Outros leads do cliente:' . $outrosStr : ' Cliente sem nenhum lead.') . "\n";
    }
}

echo "\n--- Resumo ---\n";
echo "  Cobertos:  " . count($cobertos) . "\n";
echo "  Orfaos:    " . count($orfaos) . " <-- precisam ser reparados\n";

// 3. Casos duplicados por cliente (varios casos pro mesmo cliente)
echo "\n--- Clientes com 2+ casos em doc_faltante (potencial duplicata) ---\n";
$dups = $pdo->query("
    SELECT cs.client_id, c.name, COUNT(*) AS qtd, GROUP_CONCAT(cs.id ORDER BY cs.id) AS case_ids
    FROM cases cs
    LEFT JOIN clients c ON c.id = cs.client_id
    WHERE cs.status = 'doc_faltante' AND COALESCE(cs.kanban_oculto,0) = 0
    GROUP BY cs.client_id
    HAVING COUNT(*) >= 2
    ORDER BY qtd DESC
")->fetchAll();
if (empty($dups)) {
    echo "  Nenhum cliente com 2+ casos em doc_faltante.\n";
} else {
    foreach ($dups as $d) {
        echo "  Cliente#" . $d['client_id'] . " (" . substr((string)$d['name'], 0, 30) . "): "
            . $d['qtd'] . " casos = [" . $d['case_ids'] . "]\n";
    }
}

echo "\n[FIM]\n";
echo "Para reparar os orfaos, rode: ferreiraesa.com.br/conecta/reparar_leads_orfaos_doc_faltante.php?key=fsa-hub-deploy-2026\n";
