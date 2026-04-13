<?php
/**
 * Sala VIP F&S — Autenticação e Sessão
 */

/**
 * Exige login. Redireciona para index.php se não autenticado.
 */
function salavip_require_login(): void {
    salavip_check_session_timeout();

    if (empty($_SESSION['salavip_user_id'])) {
        header('Location: ' . SALAVIP_BASE_URL . '/index.php');
        exit;
    }
}

/**
 * Retorna dados do usuário logado a partir da sessão.
 */
function salavip_current_user(): array {
    return [
        'id'             => $_SESSION['salavip_user_id'] ?? null,
        'cliente_id'     => $_SESSION['salavip_cliente_id'] ?? null,
        'nome_exibicao'  => $_SESSION['salavip_nome_exibicao'] ?? '',
        'cpf'            => $_SESSION['salavip_cpf'] ?? '',
        'email'          => $_SESSION['salavip_email'] ?? '',
        'logado_em'      => $_SESSION['salavip_logado_em'] ?? '',
    ];
}

/**
 * Retorna o cliente_id do usuário logado.
 */
function salavip_current_cliente_id(): int {
    return (int) ($_SESSION['salavip_cliente_id'] ?? 0);
}

/**
 * Registra log de acesso no banco.
 */
function salavip_log_acesso(PDO $pdo, int $usuario_id, string $acao): void {
    $stmt = $pdo->prepare(
        'INSERT INTO salavip_log_acesso (usuario_id, ip, user_agent, acao, criado_em) VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $usuario_id,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? '',
        $acao
    ]);
}

/**
 * Verifica timeout da sessão. Destrói e redireciona se expirada.
 */
function salavip_check_session_timeout(): void {
    if (isset($_SESSION['salavip_ultimo_atividade'])) {
        if ((time() - $_SESSION['salavip_ultimo_atividade']) > SALAVIP_SESSION_TIMEOUT) {
            session_unset();
            session_destroy();
            header('Location: ' . SALAVIP_BASE_URL . '/index.php?expirado=1');
            exit;
        }
    }
    $_SESSION['salavip_ultimo_atividade'] = time();
}

/**
 * Gera token aleatório seguro (64 hex chars).
 */
function salavip_gerar_token(): string {
    return bin2hex(random_bytes(32));
}

/**
 * Gera e armazena token CSRF na sessão.
 */
function salavip_gerar_csrf(): string {
    if (empty($_SESSION['salavip_csrf'])) {
        $_SESSION['salavip_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['salavip_csrf'];
}

/**
 * Valida token CSRF. Não regenera após validação (suporte multi-aba).
 */
function salavip_validar_csrf(string $token): bool {
    return isset($_SESSION['salavip_csrf']) && hash_equals($_SESSION['salavip_csrf'], $token);
}
