<?php
/**
 * Sala VIP F&S — Upload de Documento
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

salavip_require_login();

// Somente POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sv_flash('error', 'Método não permitido.');
    sv_redirect('pages/documentos.php');
}

// Validar CSRF
$csrf = $_POST['csrf_token'] ?? '';
if (!salavip_validar_csrf($csrf)) {
    sv_flash('error', 'Token de segurança inválido. Tente novamente.');
    sv_redirect('pages/documentos.php');
}

$user = salavip_current_user();
$pdo  = sv_db();

// Validar título
$titulo = trim($_POST['titulo'] ?? '');
if ($titulo === '') {
    sv_flash('error', 'O título do documento é obrigatório.');
    sv_redirect('pages/documentos.php');
}

// Validar arquivo enviado
if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    $erroUpload = $_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE;
    $msgErro = match ((int) $erroUpload) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande.',
        UPLOAD_ERR_NO_FILE   => 'Nenhum arquivo selecionado.',
        UPLOAD_ERR_PARTIAL   => 'Upload incompleto. Tente novamente.',
        default              => 'Erro no upload. Tente novamente.',
    };
    sv_flash('error', $msgErro);
    sv_redirect('pages/documentos.php');
}

$arquivo = $_FILES['arquivo'];

// Validar tamanho
if ($arquivo['size'] > SALAVIP_MAX_UPLOAD) {
    sv_flash('error', 'Arquivo excede o limite de 10MB.');
    sv_redirect('pages/documentos.php');
}

// Validar extensão
$extensoesPermitidas = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
$nomeOriginal = $arquivo['name'];
$extensao     = strtolower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));

if (!in_array($extensao, $extensoesPermitidas, true)) {
    sv_flash('error', 'Tipo de arquivo não permitido. Envie: PDF, JPG, PNG, DOC ou DOCX.');
    sv_redirect('pages/documentos.php');
}

// Validar MIME
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
    sv_flash('error', 'O conteúdo do arquivo não corresponde à extensão informada.');
    sv_redirect('pages/documentos.php');
}

// Gerar nome seguro
$nomeSafe = time() . '_' . random_int(1000, 9999) . '.' . $extensao;

// Garantir diretório de upload
if (!is_dir(SALAVIP_UPLOAD_DIR)) {
    mkdir(SALAVIP_UPLOAD_DIR, 0755, true);
}

$caminhoDestino = SALAVIP_UPLOAD_DIR . $nomeSafe;

if (!move_uploaded_file($arquivo['tmp_name'], $caminhoDestino)) {
    sv_flash('error', 'Erro ao salvar o arquivo. Tente novamente.');
    sv_redirect('pages/documentos.php');
}

// Inserir no banco
$processoId = !empty($_POST['processo_id']) ? (int) $_POST['processo_id'] : null;
$descricao  = trim($_POST['descricao'] ?? '');

try {
    $stmt = $pdo->prepare(
        'INSERT INTO salavip_documentos_cliente
            (cliente_id, processo_id, titulo, descricao, arquivo_path, arquivo_nome, arquivo_tipo, arquivo_tamanho, criado_em)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    $stmt->execute([
        $user['cliente_id'],
        $processoId,
        $titulo,
        $descricao,
        $nomeSafe,
        $nomeOriginal,
        $mimeReal,
        $arquivo['size'],
    ]);

    salavip_log_acesso($pdo, $user['id'], 'upload_documento');

    sv_flash('success', 'Documento enviado com sucesso.');
} catch (PDOException $e) {
    // Remover arquivo se insert falhar
    @unlink($caminhoDestino);
    error_log('Sala VIP upload_doc erro: ' . $e->getMessage());
    sv_flash('error', 'Erro ao registrar o documento. Tente novamente.');
}

sv_redirect('pages/documentos.php');
