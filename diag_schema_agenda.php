<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "=== Colunas com 'phone'/'telefone' em todas as tabelas ===\n\n";
$st = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE()
                   AND (COLUMN_NAME LIKE '%phone%' OR COLUMN_NAME LIKE '%telefone%' OR COLUMN_NAME LIKE '%celular%' OR COLUMN_NAME LIKE '%whatsapp%')
                   ORDER BY TABLE_NAME, COLUMN_NAME");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo sprintf("  %-30s %-25s %s\n", $c['TABLE_NAME'], $c['COLUMN_NAME'], $c['COLUMN_TYPE']);
}

$nome = trim($_GET['nome'] ?? '');
if ($nome) {
    echo "\n=== Telefones do cliente '$nome' em todas as tabelas relevantes ===\n";
    $stCli = $pdo->prepare("SELECT * FROM clients WHERE name LIKE ? ORDER BY id");
    $stCli->execute(array('%' . $nome . '%'));
    foreach ($stCli->fetchAll(PDO::FETCH_ASSOC) as $cli) {
        echo sprintf("\nCLIENTE #%d  %s\n", $cli['id'], $cli['name']);
        echo "  clients.phone           = " . ($cli['phone'] ?: '(vazio)') . "\n";
        echo "  clients.phone_secundario= " . ($cli['phone_secundario'] ?? '(coluna nao existe)') . "\n";
        echo "  clients.email           = " . ($cli['email'] ?: '(vazio)') . "\n";
        $cid = (int)$cli['id'];

        // Partes do processo
        try {
            $st = $pdo->prepare("SELECT id, case_id, papel, nome, telefone FROM case_partes WHERE client_id = ?");
            $st->execute(array($cid));
            $r = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($r) {
                echo "  case_partes onde client_id=$cid:\n";
                foreach ($r as $cp) echo "    case#{$cp['case_id']} papel={$cp['papel']} parte_id={$cp['id']} tel='{$cp['telefone']}' (nome='{$cp['nome']}')\n";
            }
        } catch (Throwable $e) {}

        // pipeline_leads
        try {
            $st = $pdo->prepare("SELECT id, telefone, name FROM pipeline_leads WHERE client_id = ? OR name LIKE ?");
            $st->execute(array($cid, '%' . $cli['name'] . '%'));
            $r = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($r) {
                echo "  pipeline_leads:\n";
                foreach ($r as $l) echo "    lead#{$l['id']} tel='{$l['telefone']}' nome='{$l['name']}'\n";
            }
        } catch (Throwable $e) {}

        // zapi_conversas
        try {
            $st = $pdo->prepare("SELECT id, telefone, nome_cliente, client_id FROM zapi_conversas WHERE client_id = ? OR nome_cliente LIKE ? LIMIT 10");
            $st->execute(array($cid, '%' . $cli['name'] . '%'));
            $r = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($r) {
                echo "  zapi_conversas:\n";
                foreach ($r as $z) echo "    conv#{$z['id']} tel='{$z['telefone']}' nome='{$z['nome_cliente']}' client_id={$z['client_id']}\n";
            }
        } catch (Throwable $e) {}
    }
}

if (!$nome) {
    echo "\nPra ver detalhes de um cliente: ?key=...&nome=NOME\n";
}
