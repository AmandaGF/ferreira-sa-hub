<?php
/**
 * DIAG 09/07/2026 — merge Angela de Oliveira Louzada (Costa + Sobral)
 * Rodar: /conecta/diag_angela_merge.php?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }

header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors', '1');
$pdo = db();

echo "=== DIAG merge Angela de Oliveira Louzada ===\n\n";

// Localiza os 2 pelo nome
$st = $pdo->query(
    "SELECT id, name, cpf, phone, email, tags, status, created_at, updated_at
     FROM clients
     WHERE name LIKE '%Angela%Louzada%'
        OR name LIKE '%Angela%Oliveira%'
     ORDER BY id"
);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo str_repeat('-', 78) . "\n";
    foreach ($r as $k => $v) { if ($v !== null && $v !== '') echo str_pad($k, 15) . ": $v\n"; }
}
echo str_repeat('-', 78) . "\n\n";
if (count($rows) < 2) { echo "Menos de 2 resultados — verifique busca.\n"; exit; }

// Assume: manter o com CPF (Sobral) como PRINCIPAL, migrar do outro
$principal = null; $secundario = null;
foreach ($rows as $r) {
    if (!empty($r['cpf']) && strpos($r['name'], 'Sobral') !== false) $principal = $r;
    elseif (strpos($r['name'], 'Costa') !== false) $secundario = $r;
}
if (!$principal || !$secundario) {
    // Fallback: o com CPF é principal
    $principal  = !empty($rows[0]['cpf']) ? $rows[0] : $rows[1];
    $secundario = ($principal['id'] === $rows[0]['id']) ? $rows[1] : $rows[0];
}
$principalId  = (int)$principal['id'];
$secundarioId = (int)$secundario['id'];
echo "PRINCIPAL (mantém): #$principalId — {$principal['name']} (CPF {$principal['cpf']})\n";
echo "SECUNDARIO (some):  #$secundarioId — {$secundario['name']}\n\n";

// Descobrir tabelas com client_id (pra saber onde precisa migrar)
$dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
$st = $pdo->prepare(
    "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = ?
       AND COLUMN_NAME IN ('client_id','cliente_id','clientes_id')
     ORDER BY TABLE_NAME"
);
$st->execute(array($dbName));
$tabs = $st->fetchAll(PDO::FETCH_ASSOC);

echo "-- Tabelas com FK pra clients (contagem por client_id) --\n";
foreach ($tabs as $t) {
    $table = $t['TABLE_NAME']; $col = $t['COLUMN_NAME'];
    if ($table === 'clients') continue;
    try {
        $stC = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$col` = ?");
        $stC->execute(array($secundarioId));
        $n = (int)$stC->fetchColumn();
        if ($n > 0) {
            echo "  $table.$col -> $n registro(s) do SECUNDÁRIO #$secundarioId\n";
        }
    } catch (Throwable $e) { echo "  $table: ERRO $e\n"; }
}
echo "\n=== FIM diag ===\n";
