<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Últimos 10 áudios ENVIADOS ===\n";
$q = $pdo->query("SELECT id, conversa_id, created_at, arquivo_url, arquivo_mime, arquivo_tamanho, status, zapi_message_id
                  FROM zapi_mensagens WHERE direcao='enviada' AND tipo='audio' ORDER BY id DESC LIMIT 10");
foreach ($q->fetchAll() as $r) {
    echo "\n#{$r['id']} conv={$r['conversa_id']} {$r['created_at']} status={$r['status']}\n";
    echo "  mime={$r['arquivo_mime']} size={$r['arquivo_tamanho']} bytes\n";
    echo "  url={$r['arquivo_url']}\n";

    // Testa se a URL é acessível externamente
    if ($r['arquivo_url']) {
        $ch = curl_init($r['arquivo_url']);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $clen = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($ch);
        echo "  HEAD → HTTP {$code}, Content-Type={$ctype}, Content-Length={$clen}\n";

        // Verifica se o arquivo existe no filesystem
        $filename = basename(parse_url($r['arquivo_url'], PHP_URL_PATH));
        $localPath = __DIR__ . '/files/whatsapp/' . $filename;
        echo "  Local: " . (file_exists($localPath) ? "EXISTE (" . filesize($localPath) . " bytes)" : "NAO EXISTE") . "\n";
    }
}

echo "\n\n=== .htaccess em /files/whatsapp/ ===\n";
$ht = __DIR__ . '/files/whatsapp/.htaccess';
echo file_exists($ht) ? file_get_contents($ht) : "Nenhum .htaccess — arquivos acessíveis livremente (OK).\n";

echo "\n=== .htaccess em /files/ ===\n";
$ht2 = __DIR__ . '/files/.htaccess';
echo file_exists($ht2) ? file_get_contents($ht2) : "Nenhum .htaccess.\n";
