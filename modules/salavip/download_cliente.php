<?php
/**
 * Download/visualização de documento enviado pelo cliente via Central VIP.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$docId = (int)($_GET['id'] ?? 0);
if (!$docId) { http_response_code(400); die('ID não informado.'); }

$stmt = $pdo->prepare("SELECT arquivo_path, arquivo_nome, arquivo_tipo FROM salavip_documentos_cliente WHERE id = ?");
$stmt->execute(array($docId));
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); die('Documento não encontrado.'); }

// Arquivos ficam em /salavip/uploads/ (mesmo local do Central VIP)
$filePath = dirname(APP_ROOT) . '/salavip/uploads/' . $doc['arquivo_path'];
if (!file_exists($filePath)) { http_response_code(404); die('Arquivo não encontrado: ' . htmlspecialchars($doc['arquivo_path'])); }

$ext = strtolower(pathinfo($doc['arquivo_nome'] ?: $doc['arquivo_path'], PATHINFO_EXTENSION));
$mime = $doc['arquivo_tipo'] ?: 'application/octet-stream';
$inline = in_array($ext, array('pdf','jpg','jpeg','png','webp','gif'), true) ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $inline . '; filename="' . str_replace('"', '_', $doc['arquivo_nome']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=300');
readfile($filePath);
exit;
