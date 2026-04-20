<?php
/**
 * Helpdesk — Download autenticado de anexos.
 * Arquivos em /files/helpdesk/ são bloqueados pelo web server (403),
 * então servimos via PHP com verificação de login.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit('Anexo não encontrado'); }

$stmt = $pdo->prepare("SELECT a.*, t.id AS ticket_id
                       FROM ticket_attachments a
                       JOIN tickets t ON t.id = a.ticket_id
                       WHERE a.id = ?");
$stmt->execute(array($id));
$a = $stmt->fetch();
if (!$a) { http_response_code(404); exit('Anexo não encontrado'); }

$path = APP_ROOT . '/files/helpdesk/' . $a['arquivo_path'];
if (!file_exists($path)) { http_response_code(404); exit('Arquivo físico ausente'); }

// Se for imagem, deixa inline (pra preview no <img>). Se não, attachment.
$mime = $a['arquivo_mime'] ?: 'application/octet-stream';
$isInline = strpos($mime, 'image/') === 0 || $mime === 'application/pdf';
$disp = $isInline ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode($a['arquivo_nome']) . '"');
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
