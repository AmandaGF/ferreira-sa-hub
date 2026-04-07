<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Fix: preencher client_id em prazos que tem case_id ===\n\n";

$stmt = $pdo->query("SELECT pp.id, pp.case_id, pp.client_id, pp.numero_processo, pp.descricao_acao,
    cs.client_id as case_client_id, cs.case_number
    FROM prazos_processuais pp
    LEFT JOIN cases cs ON cs.id = pp.case_id
    WHERE pp.case_id IS NOT NULL AND (pp.client_id IS NULL OR pp.client_id = 0)");
$rows = $stmt->fetchAll();

echo "Prazos sem client_id: " . count($rows) . "\n\n";

$fixed = 0;
foreach ($rows as $r) {
    if ($r['case_client_id']) {
        $pdo->prepare("UPDATE prazos_processuais SET client_id = ?, numero_processo = COALESCE(numero_processo, ?) WHERE id = ?")
            ->execute(array($r['case_client_id'], $r['case_number'], $r['id']));
        echo "#{$r['id']} — client_id={$r['case_client_id']} num={$r['case_number']} desc={$r['descricao_acao']}\n";
        $fixed++;
    }
}

echo "\n$fixed prazo(s) corrigido(s).\n=== FEITO ===\n";
