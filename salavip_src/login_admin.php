<?php
/**
 * Central VIP F&S — Login admin (impersonate de cliente).
 *
 * Recebe ?token=XXX gerado por modules/salavip/acessos.php (action
 * gerar_link_impersonate, restrito a Amanda user_id=1). Valida token
 * (não usado, não expirado), cria sessão como o cliente alvo + flag
 * `salavip_impersonator_admin_id` que dispara banner amarelo no header.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = sv_db();

$token = $_GET['token'] ?? '';
if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    exit('Token inválido.');
}

// Self-heal (caso ainda não exista — gerada normalmente no Hub)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS salavip_impersonate_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    salavip_user_id INT NOT NULL,
    admin_user_id INT NOT NULL,
    usado_em DATETIME NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    expira_em DATETIME NOT NULL,
    INDEX idx_token (token),
    INDEX idx_expira (expira_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// Valida token (não usado + dentro da validade)
$st = $pdo->prepare("SELECT t.*, u.id AS user_id, u.cliente_id, u.nome_exibicao, u.cpf, u.email, u.ativo
                     FROM salavip_impersonate_tokens t
                     JOIN salavip_usuarios u ON u.id = t.salavip_user_id
                     WHERE t.token = ? AND t.usado_em IS NULL AND t.expira_em > NOW()
                     LIMIT 1");
$st->execute([$token]);
$row = $st->fetch();

if (!$row) {
    http_response_code(403);
    exit('Token inválido, expirado ou já utilizado. Volte ao Hub e clique em "Entrar como cliente" novamente.');
}
if (empty($row['ativo'])) {
    http_response_code(403);
    exit('Cliente está com acesso desativado.');
}

// Marca token como usado (one-time)
$pdo->prepare("UPDATE salavip_impersonate_tokens SET usado_em = NOW() WHERE id = ?")->execute([$row['id']]);

// Cria sessão como o cliente
$_SESSION['salavip_user_id']             = (int)$row['user_id'];
$_SESSION['salavip_cliente_id']          = (int)$row['cliente_id'];
$_SESSION['salavip_nome_exibicao']       = $row['nome_exibicao'];
$_SESSION['salavip_cpf']                 = $row['cpf'];
$_SESSION['salavip_email']               = $row['email'];
$_SESSION['salavip_logado_em']           = date('Y-m-d H:i:s');
$_SESSION['salavip_ultimo_atividade']    = time();
// Flag de impersonação — header VIP exibe banner + botão sair
$_SESSION['salavip_impersonator_admin_id'] = (int)$row['admin_user_id'];

// Loga acesso (importante pra auditoria — quem entrou na conta de quem)
salavip_log_acesso($pdo, (int)$row['user_id'], 'impersonate_admin');

header('Location: ' . SALAVIP_BASE_URL . '/pages/dashboard.php');
exit;
