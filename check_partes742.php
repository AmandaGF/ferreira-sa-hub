<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Partes do case 742 ===\n";
$rows = $pdo->query("SELECT id, papel, nome, cpf, client_id, tipo_pessoa FROM case_partes WHERE case_id = 742 ORDER BY id")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "#" . $r['id'] . " papel=" . $r['papel'] . " client_id=" . ($r['client_id'] ?: 'NULL') . " nome=[" . ($r['nome'] ?: 'VAZIO') . "] cpf=" . ($r['cpf'] ?: 'VAZIO') . "\n";
}

echo "\n=== Dados legados ===\n";
$c = $pdo->query("SELECT client_id, parte_re_nome, parte_re_cpf_cnpj, filhos_json FROM cases WHERE id = 742")->fetch();
echo "client_id: " . $c['client_id'] . "\n";
echo "parte_re_nome: " . ($c['parte_re_nome'] ?: 'VAZIO') . "\n";
echo "filhos_json: " . ($c['filhos_json'] ?: 'VAZIO') . "\n";

echo "\n=== Cliente 379 ===\n";
$cl = $pdo->query("SELECT id, name FROM clients WHERE id = 379")->fetch();
echo "Nome: " . ($cl['name'] ?? 'N/A') . "\n";
