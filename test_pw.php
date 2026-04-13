<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$u = $pdo->query("SELECT id, cpf, senha_hash, ativo, tentativas_login, bloqueado_ate FROM salavip_usuarios WHERE id = 3")->fetch();
echo "User #3: cpf=" . $u['cpf'] . " ativo=" . $u['ativo'] . " tentativas=" . $u['tentativas_login'] . " bloqueado=" . ($u['bloqueado_ate'] ?: 'NULL') . "\n";
echo "Hash: " . $u['senha_hash'] . "\n\n";

// Resetar tentativas e bloqueio
$pdo->exec("UPDATE salavip_usuarios SET tentativas_login=0, bloqueado_ate=NULL WHERE id=3");
echo "Tentativas resetadas.\n\n";

// Setar nova senha conhecida para teste
$novaSenha = 'Teste123@';
$hash = password_hash($novaSenha, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE salavip_usuarios SET senha_hash=? WHERE id=3")->execute([$hash]);
echo "Nova senha definida: $novaSenha\n";
echo "Novo hash: $hash\n";
echo "Verify test: " . (password_verify($novaSenha, $hash) ? 'OK' : 'FALHOU') . "\n";
