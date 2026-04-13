<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$cpf = '12153828716';
$senha = 'Ar192114@';
$senhaHash = password_hash($senha, PASSWORD_DEFAULT);

// Buscar cliente pelo CPF
$stmt = $pdo->prepare("SELECT id, name, email FROM clients WHERE REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),' ','') = ?");
$stmt->execute([$cpf]);
$cliente = $stmt->fetch();

if (!$cliente) {
    echo "Cliente com CPF $cpf não encontrado na tabela clients.\n";
    echo "Criando usuário sem vínculo...\n";
    $clienteId = 0;
    $nome = 'Amanda Guedes Ferreira';
    $email = 'contato@ferreiraesa.com.br';
} else {
    $clienteId = (int)$cliente['id'];
    $nome = $cliente['name'];
    $email = $cliente['email'] ?: 'contato@ferreiraesa.com.br';
    echo "Cliente encontrado: #$clienteId — $nome ($email)\n";
}

// Verificar se já existe
$exists = $pdo->prepare("SELECT id FROM salavip_usuarios WHERE cpf = ?");
$exists->execute([$cpf]);
if ($exists->fetch()) {
    // Atualizar
    $pdo->prepare("UPDATE salavip_usuarios SET senha_hash=?, ativo=1, nome_exibicao=?, email=?, cliente_id=?, tentativas_login=0, bloqueado_ate=NULL WHERE cpf=?")
        ->execute([$senhaHash, $nome, $email, $clienteId, $cpf]);
    echo "Usuário atualizado (senha redefinida, ativado).\n";
} else {
    // Criar
    $pdo->prepare("INSERT INTO salavip_usuarios (cliente_id, cpf, senha_hash, email, nome_exibicao, ativo) VALUES (?,?,?,?,?,1)")
        ->execute([$clienteId, $cpf, $senhaHash, $email, $nome]);
    echo "Usuário criado com sucesso!\n";
}

echo "\nCPF: $cpf\nSenha: $senha\nAtivo: Sim\nURL: https://www.ferreiraesa.com.br/salavip/\n";
