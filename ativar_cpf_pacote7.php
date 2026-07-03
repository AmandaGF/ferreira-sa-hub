<?php
/**
 * Script one-shot: muda o pacote CPFCNPJ contratado de 1 pra 7
 * (Amanda 03/07/2026 — passou a incluir data de nascimento na resposta).
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$antes = $pdo->query("SELECT valor FROM configuracoes WHERE chave='cpfcnpj_pacote'")->fetchColumn();
echo "Pacote antes: '{$antes}'\n";

// Upsert (INSERT ... ON DUPLICATE KEY UPDATE)
$pdo->prepare(
    "INSERT INTO configuracoes (chave, valor)
     VALUES ('cpfcnpj_pacote', '7')
     ON DUPLICATE KEY UPDATE valor = '7'"
)->execute();

$depois = $pdo->query("SELECT valor FROM configuracoes WHERE chave='cpfcnpj_pacote'")->fetchColumn();
echo "Pacote depois: '{$depois}'\n\n";
echo $depois === '7' ? "✓ Pacote atualizado para 7\n" : "✗ falhou\n";

// Limpa cache: consultas antigas (pacote 1) nao tem nascimento — nao serve
try {
    $st = $pdo->query("SELECT COUNT(*) FROM cpf_cache");
    $c = (int)$st->fetchColumn();
    echo "\nCache atual de CPFs: {$c} entradas\n";
    echo "(nao vou apagar automaticamente — as antigas sao validas mesmo sem nascimento;\n";
    echo " novas consultas ja gravam com nascimento e sobrescrevem quando aparecerem de novo)\n";
} catch (Exception $e) {}
