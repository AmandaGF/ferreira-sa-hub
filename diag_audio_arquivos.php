<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
$pdo = db();

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Diag arquivos áudio</title>';
echo '<style>body{font-family:system-ui;padding:20px;max-width:1300px;margin:0 auto}table{width:100%;border-collapse:collapse;margin:.5rem 0}th,td{padding:6px 8px;border-bottom:1px solid #ddd;font-size:12px;text-align:left;vertical-align:top}th{background:#052228;color:#fff}h2{color:#052228;border-bottom:2px solid #B87333;padding-bottom:6px;margin-top:2rem}.ok{color:#065f46;font-weight:700}.no{color:#991b1b;font-weight:700}</style></head><body>';
echo '<h1>🎵 Verificar arquivos físicos dos áudios da Enayle</h1>';

// Áudios da conv 660 e 795
echo '<h2>Áudios das conversas 660 e 795 (Enayle)</h2>';
try {
    $st = $pdo->prepare("SELECT * FROM zapi_mensagens WHERE conversa_id IN (660, 795) AND tipo IN ('audio','documento','imagem','video') ORDER BY id DESC LIMIT 30");
    $st->execute();
    $msgs = $st->fetchAll();
    if (!empty($msgs)) {
        echo '<p style="color:#6b7280;font-size:.8rem">Colunas disponíveis: <code>' . htmlspecialchars(implode(', ', array_keys($msgs[0]))) . '</code></p>';
    }
} catch (Exception $e) {
    echo '<p class="no">Erro: ' . htmlspecialchars($e->getMessage()) . '</p>';
    $msgs = array();
}

echo '<table><thead><tr><th>ID</th><th>Conv</th><th>Dir</th><th>Tipo</th><th>arquivo_url</th><th>Mime</th><th>Quando</th><th>Existe servidor?</th></tr></thead><tbody>';
foreach ($msgs as $m) {
    // Tenta achar URL do arquivo em vários nomes possíveis
    $url = $m['arquivo_url'] ?? $m['midia_url'] ?? $m['file_url'] ?? $m['url'] ?? '';
    // Conteúdo pode conter a URL também (se for media salvo na coluna texto)
    if (!$url && !empty($m['conteudo']) && (strpos($m['conteudo'], 'http') === 0 || strpos($m['conteudo'], '/files/whatsapp') !== false)) {
        $url = $m['conteudo'];
    }
    $existe = '?';
    $tamanho = '';
    $contentType = '';

    if ($url) {
        // Caso 1: URL do nosso Hub (/conecta/files/whatsapp/...)
        if (strpos($url, '/conecta/files/whatsapp/') !== false || strpos($url, 'ferreiraesa.com.br') !== false) {
            $nome = basename(parse_url($url, PHP_URL_PATH));
            $caminhoLocal = __DIR__ . '/files/whatsapp/' . $nome;
            if (file_exists($caminhoLocal)) {
                $existe = '<span class="ok">✓ existe</span>';
                $tamanho = ' (' . round(filesize($caminhoLocal) / 1024, 1) . ' KB)';
            } else {
                $existe = '<span class="no">✕ NÃO existe</span>';
            }
            // HEAD HTTP pra ver Content-Type
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_SSL_VERIFYPEER=>false));
            curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            curl_close($ch);
            $existe .= '<br><small>HTTP ' . $http . ' · CT: ' . htmlspecialchars($contentType ?: '?') . '</small>';
        } else {
            // URL externa (CDN da Z-API). Faz HEAD pra ver
            $ch = curl_init($url);
            curl_setopt_array($ch, array(CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>5, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_SSL_VERIFYPEER=>false));
            curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            curl_close($ch);
            $cls = ($http >= 200 && $http < 300) ? 'ok' : 'no';
            $existe = '<span class="' . $cls . '">CDN HTTP ' . $http . '</span><br><small>CT: ' . htmlspecialchars($contentType ?: '?') . ' · ' . ($contentLength > 0 ? round($contentLength/1024,1).'KB' : '?') . '</small>';
        }
    } else {
        $existe = '<em>(sem URL)</em>';
    }

    $mime = $m['arquivo_mime'] ?? $m['midia_mime'] ?? $m['mime'] ?? '-';
    $quando = $m['criada_em'] ?? $m['created_at'] ?? '-';
    echo '<tr><td>' . $m['id'] . '</td><td>' . $m['conversa_id'] . '</td><td>' . htmlspecialchars($m['direcao'] ?? '-') . '</td><td><strong>' . htmlspecialchars($m['tipo'] ?? '-') . '</strong></td><td><code style="font-size:10px">' . htmlspecialchars(mb_substr($url ?: '(vazio)', 0, 80)) . '</code></td><td>' . htmlspecialchars($mime) . '</td><td>' . htmlspecialchars($quando) . '</td><td>' . $existe . $tamanho . '</td></tr>';
}
echo '</tbody></table>';

echo '<hr><p style="font-size:.8rem;color:#94a3b8;">' . date('Y-m-d H:i:s') . '</p>';
echo '</body></html>';
