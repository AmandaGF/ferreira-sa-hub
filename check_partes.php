<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Partes do case 736 (case_partes) ===\n";
$rows = $pdo->query("SELECT id, papel, nome, cpf, tipo_pessoa FROM case_partes WHERE case_id = 736")->fetchAll();
echo "Total: " . count($rows) . "\n";
foreach ($rows as $r) echo "#" . $r['id'] . " papel=" . $r['papel'] . " nome=" . ($r['nome'] ?: 'VAZIO') . " cpf=" . ($r['cpf'] ?: 'VAZIO') . "\n";

echo "\n=== Dados legados (cases) ===\n";
$c = $pdo->query("SELECT parte_re_nome, parte_re_cpf_cnpj FROM cases WHERE id = 736")->fetch();
echo "parte_re_nome: " . ($c['parte_re_nome'] ?: 'VAZIO') . "\n";
echo "parte_re_cpf_cnpj: " . ($c['parte_re_cpf_cnpj'] ?: 'VAZIO') . "\n";
