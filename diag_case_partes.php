<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Casos onde Amanda Guedes Ferreira (id=447) aparece como PARTE (tabela case_partes) ===\n\n";
$rows = $pdo->query("SELECT cs.id, cs.client_title, cs.case_type, cs.status, cs.drive_folder_url, cs.client_id,
                            cl_principal.name as cliente_principal, cp.tipo_parte
                     FROM case_partes cp
                     JOIN cases cs ON cs.id = cp.case_id
                     LEFT JOIN clients cl_principal ON cl_principal.id = cs.client_id
                     WHERE cp.client_id = 447
                     ORDER BY cs.id DESC")->fetchAll();
if (empty($rows)) echo "  Nenhum (como parte secundária).\n";
foreach ($rows as $r) {
    echo "  Caso #{$r['id']} | cliente_principal={$r['cliente_principal']} (id={$r['client_id']}) | tipo_parte={$r['tipo_parte']}\n";
    echo "    Título: {$r['client_title']} | Tipo: {$r['case_type']} | Status: {$r['status']}\n";
    echo "    Drive: " . ($r['drive_folder_url'] ? substr($r['drive_folder_url'], 0, 70) : 'SEM PASTA') . "\n\n";
}

echo "=== Total de casos na tabela ===\n";
echo "  " . (int)$pdo->query("SELECT COUNT(*) FROM cases WHERE status != 'arquivado'")->fetchColumn() . " ativos\n";
