<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Simulando login exato ===\n\n";

// Simular exatamente o que index.php faz
$cpfInput = '121.538.287-16'; // como o usuário digita
$senhaInput = 'Teste123@';

// Passo 1: limpar CPF (como index.php faz)
$cpf_raw = preg_replace('/\D/', '', $cpfInput);
echo "Input: '$cpfInput' -> raw: '$cpf_raw'\n";

// Passo 2: formatar
$cpf_fmt = '';
if (strlen($cpf_raw) === 11) {
    $cpf_fmt = substr($cpf_raw,0,3).'.'.substr($cpf_raw,3,3).'.'.substr($cpf_raw,6,3).'-'.substr($cpf_raw,9,2);
}
echo "Fmt: '$cpf_fmt'\n\n";

// Passo 3: buscar (como index.php faz)
$stmt = $pdo->prepare('SELECT * FROM salavip_usuarios WHERE cpf = ? OR cpf = ? LIMIT 1');
$stmt->execute([$cpf_raw, $cpf_fmt]);
$user = $stmt->fetch();

if (!$user) {
    echo "RESULTADO: Usuário NÃO encontrado!\n\n";

    // Debug: listar todos os CPFs
    echo "CPFs na tabela:\n";
    $all = $pdo->query("SELECT id, cpf, LENGTH(cpf) as len, HEX(cpf) as hex_cpf FROM salavip_usuarios")->fetchAll();
    foreach ($all as $a) {
        echo "  #" . $a['id'] . " cpf=[" . $a['cpf'] . "] len=" . $a['len'] . " hex=" . substr($a['hex_cpf'], 0, 40) . "\n";
    }
} else {
    echo "RESULTADO: Usuário ENCONTRADO #" . $user['id'] . "\n";
    echo "Ativo: " . $user['ativo'] . "\n";
    echo "Bloqueado: " . ($user['bloqueado_ate'] ?: 'NULL') . "\n";
    echo "Tentativas: " . $user['tentativas_login'] . "\n";
    echo "Hash: " . $user['senha_hash'] . "\n\n";

    echo "password_verify('$senhaInput'): " . (password_verify($senhaInput, $user['senha_hash']) ? 'OK ✅' : 'FALHOU ❌') . "\n";
}

// Verificar se o deploy atualizou o index.php
echo "\n=== Verificar index.php no servidor ===\n";
$serverFile = dirname(__DIR__) . '/salavip/index.php';
$content = file_get_contents($serverFile);
if (strpos($content, 'OR cpf = ?') !== false) {
    echo "index.php TEM o fix (OR cpf = ?)\n";
} else {
    echo "index.php NÃO TEM o fix! Deploy falhou!\n";
}
