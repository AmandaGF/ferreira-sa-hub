<?php
/**
 * Sala VIP F&S — Atualizar Dados do Usuário
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

salavip_require_login();

// Somente POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sv_flash('error', 'Método não permitido.');
    sv_redirect('pages/meus_dados.php');
}

// Validar CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (!salavip_validar_csrf($csrf)) {
    sv_flash('error', 'Token de segurança inválido. Tente novamente.');
    sv_redirect('pages/meus_dados.php');
}

$user = salavip_current_user();
$pdo  = sv_db();
$acao = $_GET['acao'] ?? '';

// ── Ação: Alterar Senha ──────────────────────────────────────────────

if ($acao === 'senha') {
    $senhaAtual    = $_POST['senha_atual'] ?? '';
    $novaSenha     = $_POST['nova_senha'] ?? '';
    $confirmarSenha = $_POST['confirmar_senha'] ?? '';

    // Campos obrigatórios
    if ($senhaAtual === '' || $novaSenha === '' || $confirmarSenha === '') {
        sv_flash('error', 'Preencha todos os campos de senha.');
        sv_redirect('pages/meus_dados.php');
    }

    // Confirmação
    if ($novaSenha !== $confirmarSenha) {
        sv_flash('error', 'A nova senha e a confirmação não coincidem.');
        sv_redirect('pages/meus_dados.php');
    }

    // Requisitos de segurança: mínimo 8 chars, letra maiúscula, número
    if (mb_strlen($novaSenha) < 8) {
        sv_flash('error', 'A nova senha deve ter pelo menos 8 caracteres.');
        sv_redirect('pages/meus_dados.php');
    }
    if (!preg_match('/[A-Z]/', $novaSenha)) {
        sv_flash('error', 'A nova senha deve conter pelo menos uma letra maiúscula.');
        sv_redirect('pages/meus_dados.php');
    }
    if (!preg_match('/[0-9]/', $novaSenha)) {
        sv_flash('error', 'A nova senha deve conter pelo menos um número.');
        sv_redirect('pages/meus_dados.php');
    }

    // Verificar senha atual
    $stmt = $pdo->prepare('SELECT senha_hash FROM salavip_usuarios WHERE id = ?');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($senhaAtual, $row['senha_hash'])) {
        sv_flash('error', 'Senha atual incorreta.');
        sv_redirect('pages/meus_dados.php');
    }

    // Atualizar senha
    $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('UPDATE salavip_usuarios SET senha_hash = ? WHERE id = ?');
    $stmt->execute([$novoHash, $user['id']]);

    salavip_log_acesso($pdo, $user['id'], 'alterou_senha');

    sv_flash('success', 'Senha alterada com sucesso.');
    sv_redirect('pages/meus_dados.php');
}

// ── Ação desconhecida ────────────────────────────────────────────────

sv_flash('error', 'Ação não reconhecida.');
sv_redirect('pages/meus_dados.php');
