<?php
/**
 * Download de arquivo do GED (lado escritório).
 * Serve o arquivo diretamente do filesystem evitando problemas de rewrite/URL encoding.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$docId = (int)($_GET['id'] ?? 0);

if (!$docId) {
    http_response_code(400);
    die('ID do documento não informado.');
}

$stmt = $pdo->prepare("SELECT arquivo_path, arquivo_nome FROM salavip_ged WHERE id = ?");
$stmt->execute(array($docId));
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Documento não encontrado.');
}

$filePath = dirname(APP_ROOT) . '/salavip/uploads/ged/' . $doc['arquivo_path'];

if (!file_exists($filePath)) {
    http_response_code(404);
    die('Arquivo não encontrado no servidor: ' . htmlspecialchars($doc['arquivo_path']));
}

$ext = strtolower(pathinfo($doc['arquivo_nome'] ?: $doc['arquivo_path'], PATHINFO_EXTENSION));
$mimeTypes = array(
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
);
$mime = isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';

header('Content-Type: ' . $mime);
// Imagens e PDFs: abrir inline no navegador; outros: download
$disposition = in_array($ext, array('pdf','jpg','jpeg','png','webp','gif')) ? 'inline' : 'attachment';
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '_', ($doc['arquivo_nome'] ?: $doc['arquivo_path'])) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=300');

readfile($filePath);
exit;
