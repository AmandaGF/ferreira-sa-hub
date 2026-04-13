<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Debug login Sala VIP ===\n\n";

$rows = $pdo->query("SELECT id, cpf, ativo, tentativas_login, bloqueado_ate, nome_exibicao, LEFT(senha_hash,20) as hash_preview FROM salavip_usuarios ORDER BY id DESC LIMIT 5")->fetchAll();
foreach ($rows as $u) {
    echo "#" . $u['id'] . " cpf=[" . $u['cpf'] . "] ativo=" . $u['ativo'] . " tentativas=" . $u['tentativas_login'] . " bloqueado=" . ($u['bloqueado_ate'] ?: 'NULL') . " nome=" . $u['nome_exibicao'] . " hash=" . $u['hash_preview'] . "...\n";
}

echo "\n=== Como index.php busca o CPF ===\n";
$loginFile = dirname(__DIR__) . '/salavip/index.php';
$lines = file($loginFile);
foreach ($lines as $i => $line) {
    if (stripos($line, 'cpf') !== false && (stripos($line, 'SELECT') !== false || stripos($line, 'preg_replace') !== false || stripos($line, 'WHERE') !== false)) {
        echo "L" . ($i+1) . ": " . trim($line) . "\n";
    }
}

echo "\n=== Teste de match CPF ===\n";
// O CPF está salvo formatado ou só dígitos?
$cpfDigits = '12153828716';
$cpfFmt = '121.538.287-16';

$s1 = $pdo->prepare("SELECT id, cpf FROM salavip_usuarios WHERE cpf = ?");
$s1->execute([$cpfDigits]);
$r1 = $s1->fetch();
echo "Busca digits '$cpfDigits': " . ($r1 ? "ENCONTRADO cpf=[" . $r1['cpf'] . "]" : "NÃO") . "\n";

$s1->execute([$cpfFmt]);
$r2 = $s1->fetch();
echo "Busca fmt '$cpfFmt': " . ($r2 ? "ENCONTRADO cpf=[" . $r2['cpf'] . "]" : "NÃO") . "\n";

// Busca com REPLACE
$s2 = $pdo->prepare("SELECT id, cpf FROM salavip_usuarios WHERE REPLACE(REPLACE(cpf,'.',''),'-','') = ?");
$s2->execute([$cpfDigits]);
$r3 = $s2->fetch();
echo "Busca REPLACE '$cpfDigits': " . ($r3 ? "ENCONTRADO cpf=[" . $r3['cpf'] . "]" : "NÃO") . "\n";
