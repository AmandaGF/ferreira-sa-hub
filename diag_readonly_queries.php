<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== QUERY 1 — andamentos importados via email_pje ===\n\n";
$q = $pdo->query(
    "SELECT
       ca.id, ca.case_id, c.title, c.case_number,
       ca.data_andamento, ca.hora_andamento, ca.descricao,
       ca.tipo_origem, ca.visivel_cliente, ca.segredo_justica,
       ca.created_at
     FROM case_andamentos ca
     JOIN cases c ON c.id = ca.case_id
     WHERE ca.tipo_origem = 'email_pje'
     ORDER BY ca.created_at DESC"
);
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
echo "Total: " . count($rows) . "\n\n";
foreach ($rows as $r) {
    echo "#{$r['id']} case #{$r['case_id']} — {$r['title']}\n";
    echo "  case_number: {$r['case_number']}\n";
    echo "  data_andamento: {$r['data_andamento']} {$r['hora_andamento']}\n";
    echo "  descricao: {$r['descricao']}\n";
    echo "  tipo_origem: {$r['tipo_origem']} | visivel_cliente: {$r['visivel_cliente']} | segredo_justica: {$r['segredo_justica']}\n";
    echo "  created_at: {$r['created_at']}\n\n";
}

echo "=== QUERY 2 — casos #735 e #803 ===\n\n";
$q = $pdo->query("SELECT id, title, case_number, segredo_justica, status FROM cases WHERE id IN (735, 803)");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "case #{$r['id']} — {$r['title']}\n";
    echo "  case_number: {$r['case_number']}\n";
    echo "  segredo_justica: {$r['segredo_justica']}\n";
    echo "  status: {$r['status']}\n\n";
}
