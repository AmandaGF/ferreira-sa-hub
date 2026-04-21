<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Migração: pipeline_leads.num_parcelas ===\n\n";

try {
    $pdo->exec("ALTER TABLE pipeline_leads ADD COLUMN num_parcelas SMALLINT UNSIGNED NULL DEFAULT 1 COMMENT 'Parcelas do honorário (1 = à vista)' AFTER honorarios_cents");
    echo "OK — coluna num_parcelas adicionada.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        echo "Coluna já existia, nada a fazer.\n";
    } else {
        echo "ERRO: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Backfill: leads que têm 'num_parcelas' no document_history.params_json (contrato já gerado)
echo "\n=== Backfill via document_history ===\n";
$stmt = $pdo->query(
    "SELECT pl.id AS lead_id, dh.params_json
     FROM pipeline_leads pl
     INNER JOIN document_history dh ON dh.client_id = pl.client_id AND dh.params_json LIKE '%num_parcelas%'
     WHERE pl.num_parcelas IS NULL OR pl.num_parcelas = 1
     GROUP BY pl.id
     ORDER BY pl.id"
);
$upd = $pdo->prepare("UPDATE pipeline_leads SET num_parcelas = ? WHERE id = ? AND (num_parcelas IS NULL OR num_parcelas = 1)");
$count = 0;
while ($row = $stmt->fetch()) {
    $p = json_decode($row['params_json'], true);
    $np = isset($p['num_parcelas']) ? (int)$p['num_parcelas'] : 0;
    if ($np > 1 && $np <= 60) {
        $upd->execute(array($np, $row['lead_id']));
        $count++;
    }
}
echo "Atualizados $count leads com num_parcelas do contrato.\n";

echo "\n=== FIM ===\n";
