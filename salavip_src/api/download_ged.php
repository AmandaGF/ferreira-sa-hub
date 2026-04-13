<?php
/**
 * Sala VIP F&S — Download seguro de documento GED
 * Valida que o documento pertence ao cliente logado antes de servir o arquivo.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

salavip_require_login();

$pdo = sv_db();
$user = salavip_current_user();
$clienteId = salavip_current_cliente_id();
$docId = (int)($_GET['id'] ?? 0);

if (!$docId) {
    http_response_code(400);
    die('ID do documento não informado.');
}

// Buscar documento validando que pertence ao cliente
$stmt = $pdo->prepare(
    "SELECT * FROM salavip_ged WHERE id = ? AND cliente_id = ? AND visivel_cliente = 1"
);
$stmt->execute([$docId, $clienteId]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    die('Documento não encontrado ou sem permissão de acesso.');
}

$filePath = $doc['arquivo_path'];

// Se caminho relativo, resolver a partir do diretório de uploads
if (strpos($filePath, '/') !== 0 && strpos($filePath, ':') === false) {
    $filePath = __DIR__ . '/../uploads/' . $filePath;
}

if (!file_exists($filePath)) {
    http_response_code(404);
    die('Arquivo não encontrado no servidor.');
}

// Servir o arquivo
$mimeType = $doc['arquivo_tipo'] ?: 'application/octet-stream';
$fileName = $doc['arquivo_nome'] ?: 'documento';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . str_replace('"', '_', $fileName) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
exit;
