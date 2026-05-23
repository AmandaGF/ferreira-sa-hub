<?php
/**
 * Central VIP F&S — Upload de Documento
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
    $erroUpload = (int)($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE);
    $erroMsgs = [UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande.', UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande.', UPLOAD_ERR_NO_FILE => 'Nenhum arquivo selecionado.', UPLOAD_ERR_PARTIAL => 'Upload incompleto. Tente novamente.'];
    $msgErro = isset($erroMsgs[$erroUpload]) ? $erroMsgs[$erroUpload] : 'Erro no upload. Tente novamente.';
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

// Self-heal: colunas pra rastrear envio pro Drive e resolução de pendência
try { $pdo->exec("ALTER TABLE salavip_documentos_cliente ADD COLUMN drive_file_id VARCHAR(200) NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE salavip_documentos_cliente ADD COLUMN drive_subido_em DATETIME NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE documentos_pendentes ADD COLUMN resolvido_em DATETIME NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE documentos_pendentes ADD COLUMN resolvido_via VARCHAR(30) NULL"); } catch (Exception $e) {}

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

    $docId = (int)$pdo->lastInsertId();
    salavip_log_acesso($pdo, $user['id'], 'upload_documento');

    // ─── PÓS-UPLOAD: 3 ações automáticas pra fechar o ciclo ───
    // (a) Notifica o responsável do caso (ou admin/gestao) que o cliente enviou doc
    // (b) Tenta subir o arquivo pro Drive do processo (se houver pasta)
    // (c) Marca documentos_pendentes como resolvido se o título bater com algum pedido

    $cliId = (int)$user['cliente_id'];
    $clienteNome = '';
    try {
        $stC = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $stC->execute(array($cliId));
        $clienteNome = (string)$stC->fetchColumn();
    } catch (Exception $e) {}

    // (a) Notificação
    try {
        // Acha o responsável do case (se vinculado), senão notifica admins
        $stCs = null; $responsavelId = null;
        if ($processoId) {
            $stCs = $pdo->prepare("SELECT responsible_user_id, title FROM cases WHERE id = ?");
            $stCs->execute(array($processoId));
            $cs = $stCs->fetch(PDO::FETCH_ASSOC);
            if ($cs && !empty($cs['responsible_user_id'])) $responsavelId = (int)$cs['responsible_user_id'];
        }
        $tituloN = '📎 Documento recebido — ' . ($clienteNome ?: 'cliente');
        $msgN = 'Novo documento "' . substr($titulo, 0, 80) . '" enviado pela Central VIP.';
        // Link absoluto pro modulo salavip do Hub onde a equipe vê os docs do cliente
        $linkN = '/conecta/modules/salavip/?cliente=' . $cliId;
        $insN = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, type, link, icon, created_at)
             VALUES (?, ?, ?, 'info', ?, '📎', NOW())"
        );
        if ($responsavelId) {
            $insN->execute(array($responsavelId, $tituloN, $msgN, $linkN));
        } else {
            // Sem responsável → notifica todos admins ativos
            $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin','gestao') AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) $insN->execute(array((int)$adminId, $tituloN, $msgN, $linkN));
        }
    } catch (Exception $e) { error_log('upload_doc notify falhou: ' . $e->getMessage()); }

    // (b) Drive — sobe o arquivo pra pasta do processo (se houver)
    if ($processoId) {
        try {
            $stD = $pdo->prepare("SELECT drive_folder_url FROM cases WHERE id = ? AND drive_folder_url IS NOT NULL AND drive_folder_url != ''");
            $stD->execute(array($processoId));
            $folder = (string)$stD->fetchColumn();
            if ($folder && file_exists(dirname(__DIR__, 2) . '/conecta/core/google_drive.php')) {
                require_once dirname(__DIR__, 2) . '/conecta/core/google_drive.php';
                // URL pública do arquivo (Apps Script baixa via UrlFetchApp)
                $publicUrl = rtrim(SALAVIP_BASE_URL ?? 'https://ferreiraesa.com.br/salavip', '/') . '/uploads/' . $nomeSafe;
                $nomeDrive = 'CLIENTE_' . preg_replace('/[^\w\.\-]/u', '_', mb_substr($titulo, 0, 60)) . '_' . date('Ymd') . '.' . $extensao;
                $r = upload_file_to_drive($folder, $nomeDrive, $publicUrl, $mimeReal);
                if (!empty($r['success'])) {
                    $pdo->prepare("UPDATE salavip_documentos_cliente SET drive_file_id = ?, drive_subido_em = NOW() WHERE id = ?")
                        ->execute(array($r['fileId'] ?? '', $docId));
                }
            }
        } catch (Exception $e) { error_log('upload_doc drive falhou: ' . $e->getMessage()); }
    }

    // (c) Resolve documentos_pendentes correspondente (match por título, case-insensitive)
    try {
        if ($processoId) {
            $stU = $pdo->prepare(
                "UPDATE documentos_pendentes SET resolvido = 1, resolvido_em = NOW(), resolvido_via = 'central_vip'
                 WHERE case_id = ? AND resolvido = 0
                   AND (LOWER(documento) LIKE CONCAT('%', LOWER(?), '%') OR LOWER(?) LIKE CONCAT('%', LOWER(documento), '%'))"
            );
            $stU->execute(array($processoId, $titulo, $titulo));
            if ($stU->rowCount() > 0) error_log('upload_doc: ' . $stU->rowCount() . ' doc(s) pendente(s) resolvido(s) por match de título');
        }
    } catch (Exception $e) { error_log('upload_doc resolve pendente falhou: ' . $e->getMessage()); }

    sv_flash('success', 'Documento enviado com sucesso. A equipe foi notificada.');
} catch (PDOException $e) {
    // Remover arquivo se insert falhar
    @unlink($caminhoDestino);
    error_log('Central VIP upload_doc erro: ' . $e->getMessage());
    sv_flash('error', 'Erro ao registrar o documento. Tente novamente.');
}

sv_redirect('pages/documentos.php');
