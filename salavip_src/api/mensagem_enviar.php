<?php
/**
 * Central VIP F&S — Enviar Mensagem (nova thread ou resposta)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

salavip_require_login();

// Somente POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sv_flash('error', 'Método não permitido.');
    sv_redirect('pages/mensagens.php');
}

// Validar CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (!salavip_validar_csrf($csrf)) {
    sv_flash('error', 'Token de segurança inválido. Tente novamente.');
    sv_redirect('pages/mensagens.php');
}

$user = salavip_current_user();
$pdo  = sv_db();

$threadId  = !empty($_POST['thread_id']) ? (int) $_POST['thread_id'] : null;
$mensagem  = trim($_POST['mensagem'] ?? '');

// ── Funções auxiliares ───────────────────────────────────────────────

/**
 * Processa anexo opcional. Retorna [path, nome] ou [null, null].
 */
function _processar_anexo(): array {
    if (!isset($_FILES['anexo']) || $_FILES['anexo']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }

    if ($_FILES['anexo']['error'] !== UPLOAD_ERR_OK) {
        sv_flash('error', 'Erro no upload do anexo. Tente novamente.');
        sv_redirect('pages/mensagens.php');
    }

    $arquivo = $_FILES['anexo'];

    // Tamanho
    if ($arquivo['size'] > SALAVIP_MAX_UPLOAD) {
        sv_flash('error', 'Anexo excede o limite de 10MB.');
        sv_redirect('pages/mensagens.php');
    }

    // Extensão
    $extensoesPermitidas = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
    $nomeOriginal = $arquivo['name'];
    $extensao     = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));

    if (!in_array($extensao, $extensoesPermitidas, true)) {
        sv_flash('error', 'Tipo de anexo não permitido. Envie: PDF, JPG, PNG, DOC ou DOCX.');
        sv_redirect('pages/mensagens.php');
    }

    // MIME
    $mimesPermitidos = [
        'pdf'  => ['application/pdf'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ];

    $mimeReal = mime_content_type($arquivo['tmp_name']);
    if (!isset($mimesPermitidos[$extensao]) || !in_array($mimeReal, $mimesPermitidos[$extensao], true)) {
        sv_flash('error', 'O conteúdo do anexo não corresponde à extensão.');
        sv_redirect('pages/mensagens.php');
    }

    // Salvar
    $nomeSafe = time() . '_' . random_int(1000, 9999) . '.' . $extensao;

    if (!is_dir(SALAVIP_UPLOAD_DIR)) {
        mkdir(SALAVIP_UPLOAD_DIR, 0755, true);
    }

    $destino = SALAVIP_UPLOAD_DIR . $nomeSafe;
    if (!move_uploaded_file($arquivo['tmp_name'], $destino)) {
        sv_flash('error', 'Erro ao salvar o anexo.');
        sv_redirect('pages/mensagens.php');
    }

    return [$nomeSafe, $nomeOriginal];
}

// ── Modo: Nova Thread ────────────────────────────────────────────────

if ($threadId === null) {
    $assunto   = trim($_POST['assunto'] ?? '');
    $categoria = trim($_POST['categoria'] ?? '');
    $processoId = !empty($_POST['processo_id']) ? (int) $_POST['processo_id'] : null;

    // Validações
    if ($assunto === '') {
        sv_flash('error', 'O assunto é obrigatório.');
        sv_redirect('pages/mensagem_nova.php');
    }
    if (mb_strlen($mensagem) < 10) {
        sv_flash('error', 'A mensagem deve ter pelo menos 10 caracteres.');
        sv_redirect('pages/mensagem_nova.php');
    }

    // Anexo opcional
    [$anexoPath, $anexoNome] = _processar_anexo();

    try {
        $pdo->beginTransaction();

        // Criar thread
        $stmt = $pdo->prepare(
            'INSERT INTO salavip_threads (cliente_id, assunto, categoria, processo_id, status, criado_em, atualizado_em)
             VALUES (?, ?, ?, ?, \'aberta\', NOW(), NOW())'
        );
        $stmt->execute([
            $user['cliente_id'],
            $assunto,
            $categoria ?: null,
            $processoId,
        ]);
        $novoThreadId = (int) $pdo->lastInsertId();

        // Criar mensagem
        $stmt = $pdo->prepare(
            'INSERT INTO salavip_mensagens
                (thread_id, cliente_id, assunto, mensagem, origem, remetente_id, remetente_nome, lida_equipe, anexo_path, anexo_nome, criado_em)
             VALUES (?, ?, ?, ?, \'salavip\', ?, ?, 0, ?, ?, NOW())'
        );
        $stmt->execute([
            $novoThreadId,
            $user['cliente_id'],
            $assunto,
            $mensagem,
            $user['id'],
            $user['nome_exibicao'],
            $anexoPath,
            $anexoNome,
        ]);

        $pdo->commit();

        salavip_log_acesso($pdo, $user['id'], 'enviou_mensagem');

        sv_flash('success', 'Mensagem enviada com sucesso.');
        sv_redirect('pages/mensagem_ver.php?id=' . $novoThreadId);

    } catch (PDOException $e) {
        $pdo->rollBack();
        // Limpar anexo se houver
        if ($anexoPath && file_exists(SALAVIP_UPLOAD_DIR . $anexoPath)) {
            @unlink(SALAVIP_UPLOAD_DIR . $anexoPath);
        }
        error_log('Central VIP mensagem_enviar (nova) erro: ' . $e->getMessage());
        sv_flash('error', 'Erro ao enviar mensagem. Tente novamente.');
        sv_redirect('pages/mensagem_nova.php');
    }
}

// ── Modo: Resposta (reply) ───────────────────────────────────────────

// Validar mensagem
if (mb_strlen($mensagem) < 10) {
    sv_flash('error', 'A mensagem deve ter pelo menos 10 caracteres.');
    sv_redirect('pages/mensagem_ver.php?id=' . $threadId);
}

// Verificar que a thread pertence ao cliente e não está fechada
$stmt = $pdo->prepare(
    'SELECT id, status FROM salavip_threads WHERE id = ? AND cliente_id = ?'
);
$stmt->execute([$threadId, $user['cliente_id']]);
$thread = $stmt->fetch();

if (!$thread) {
    sv_flash('error', 'Conversa não encontrada.');
    sv_redirect('pages/mensagens.php');
}

if ($thread['status'] === 'fechada') {
    sv_flash('error', 'Esta conversa foi encerrada e não aceita novas mensagens.');
    sv_redirect('pages/mensagem_ver.php?id=' . $threadId);
}

// Anexo opcional
[$anexoPath, $anexoNome] = _processar_anexo();

try {
    $pdo->beginTransaction();

    // Inserir mensagem
    $stmt = $pdo->prepare(
        'INSERT INTO salavip_mensagens
            (thread_id, cliente_id, mensagem, origem, remetente_id, remetente_nome, lida_equipe, anexo_path, anexo_nome, criado_em)
         VALUES (?, ?, ?, \'salavip\', ?, ?, 0, ?, ?, NOW())'
    );
    $stmt->execute([
        $threadId,
        $user['cliente_id'],
        $mensagem,
        $user['id'],
        $user['nome_exibicao'],
        $anexoPath,
        $anexoNome,
    ]);

    // Reabrir thread (cliente respondeu)
    $stmt = $pdo->prepare(
        'UPDATE salavip_threads SET status = \'aberta\', atualizado_em = NOW() WHERE id = ?'
    );
    $stmt->execute([$threadId]);

    $pdo->commit();

    salavip_log_acesso($pdo, $user['id'], 'respondeu_mensagem');

    sv_flash('success', 'Resposta enviada com sucesso.');

} catch (PDOException $e) {
    $pdo->rollBack();
    if ($anexoPath && file_exists(SALAVIP_UPLOAD_DIR . $anexoPath)) {
        @unlink(SALAVIP_UPLOAD_DIR . $anexoPath);
    }
    error_log('Central VIP mensagem_enviar (reply) erro: ' . $e->getMessage());
    sv_flash('error', 'Erro ao enviar resposta. Tente novamente.');
}

sv_redirect('pages/mensagem_ver.php?id=' . $threadId);
