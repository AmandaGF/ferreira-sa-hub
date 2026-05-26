<?php
/**
 * Endpoint publico de compartilhamento de documento do GED da Central VIP.
 * URL: https://ferreiraesa.com.br/conecta/d.php?t=TOKEN
 *
 * Sem login. Token de 32 chars hex (random_bytes(16)) e praticamente
 * inguessavel. Token e gerado pelo advogado no /modules/salavip/ged.php.
 * Acessos sao auditados (incrementa share_acessos + share_ultimo_acesso).
 * Pode ser revogado a qualquer momento (share_revogado=1).
 *
 * Criado 26/05/2026 a pedido da Amanda: enviar link curto pelo WhatsApp
 * em vez de arquivos pesados.
 */
require_once __DIR__ . '/core/database.php';

$pdo = db();

$token = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['t'] ?? '');
if (strlen($token) < 16 || strlen($token) > 64) {
    http_response_code(404);
    _d_render_erro('Link invalido.');
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT g.id, g.arquivo_path, g.arquivo_nome, g.titulo, g.share_revogado, c.name AS cliente_nome
         FROM salavip_ged g LEFT JOIN clients c ON c.id = g.cliente_id
         WHERE g.share_token = ? LIMIT 1"
    );
    $stmt->execute(array($token));
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    _d_render_erro('Erro ao localizar documento.');
    exit;
}

if (!$doc) {
    http_response_code(404);
    _d_render_erro('Este link nao existe ou foi excluido.');
    exit;
}

if (!empty($doc['share_revogado'])) {
    http_response_code(403);
    _d_render_erro('Este link foi revogado.');
    exit;
}

$filePath = dirname(__DIR__) . '/salavip/uploads/ged/' . $doc['arquivo_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    _d_render_erro('Arquivo nao disponivel no servidor.');
    exit;
}

// Registra acesso (best-effort — nao bloqueia)
try {
    $pdo->prepare("UPDATE salavip_ged SET share_acessos = share_acessos + 1, share_ultimo_acesso = NOW() WHERE id = ?")
        ->execute(array((int)$doc['id']));
} catch (Throwable $e) { /* ignora */ }

$ext = strtolower(pathinfo($doc['arquivo_nome'] ?: $doc['arquivo_path'], PATHINFO_EXTENSION));
$mimeMap = array(
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
    'png'  => 'image/png',  'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
);
$mime = isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream';
$disposition = in_array($ext, array('pdf','jpg','jpeg','png','webp','gif'), true) ? 'inline' : 'attachment';
$nomeArq = $doc['arquivo_nome'] ?: $doc['arquivo_path'];

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . str_replace('"', '_', $nomeArq) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=300');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;

function _d_render_erro($msg) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Documento - Ferreira & Sa Advocacia</title>'
       . '<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f5ede3;color:#052228;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:1.5rem;}'
       . '.box{background:#fff;border-radius:14px;padding:2rem 2.4rem;max-width:440px;width:100%;text-align:center;box-shadow:0 6px 30px rgba(0,0,0,.08);}'
       . 'h1{margin:0 0 .8rem;font-size:1.2rem;color:#052228;}p{margin:.4rem 0;color:#52525b;font-size:.95rem;}'
       . '.logo{font-family:Georgia,serif;font-style:italic;color:#B87333;margin-bottom:.6rem;font-size:1.4rem;}</style></head><body>'
       . '<div class="box"><div class="logo">Ferreira &amp; Sa Advocacia</div>'
       . '<h1>Documento indisponivel</h1>'
       . '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p style="font-size:.78rem;color:#a1a1aa;margin-top:1rem;">Entre em contato com o escritorio se o problema persistir.</p>'
       . '</div></body></html>';
}
