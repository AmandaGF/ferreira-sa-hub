<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1. Acha conversa da Joziane
$st = $pdo->prepare("SELECT co.id, co.telefone, co.nome_contato, co.canal, c.name AS client_name
                     FROM zapi_conversas co
                     LEFT JOIN clients c ON c.id = co.client_id
                     WHERE co.nome_contato LIKE ? OR co.nome_contato LIKE ? OR c.name LIKE ? OR c.name LIKE ?
                     ORDER BY co.ultima_msg_em DESC LIMIT 5");
$st->execute(array('%Joziane%','%Josiane%','%Joziane%','%Josiane%'));
echo "=== Conversas Joziane/Josiane ===\n";
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "  Conv #{$c['id']} | tel={$c['telefone']} | nome_contato='{$c['nome_contato']}' | cliente='{$c['client_name']}' | canal={$c['canal']}\n";
}

// 2. Ultimas imagens enviadas (qualquer cliente)
echo "\n=== Ultimas 10 imagens ENVIADAS (qualquer destinatario) ===\n";
$st = $pdo->query("SELECT m.id, m.conversa_id, m.zapi_message_id, m.tipo, m.arquivo_url, m.arquivo_nome,
                          m.arquivo_mime, m.status, m.lida, m.created_at,
                          co.telefone, co.canal, co.nome_contato
                   FROM zapi_mensagens m
                   JOIN zapi_conversas co ON co.id = m.conversa_id
                   WHERE m.direcao = 'enviada' AND m.tipo = 'imagem'
                   ORDER BY m.created_at DESC LIMIT 10");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
    echo "  MSG #{$m['id']} | conv #{$m['conversa_id']} ({$m['nome_contato']} {$m['telefone']}) canal {$m['canal']}\n";
    echo "    em: {$m['created_at']} | zapi_id='{$m['zapi_message_id']}' | status={$m['status']} | lida={$m['lida']}\n";
    echo "    arquivo: {$m['arquivo_nome']} ({$m['arquivo_mime']})\n";
    echo "    URL: {$m['arquivo_url']}\n";

    // Tenta HEAD pra ver se URL e acessivel publicamente
    $ch = curl_init($m['arquivo_url']);
    curl_setopt_array($ch, array(
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        echo "    HEAD HTTP: erro cURL '{$err}'\n";
    } else {
        echo "    HEAD HTTP: $http " . ($http === 200 ? '✓ acessivel' : '⚠️ NAO ACESSIVEL — Z-API nao consegue baixar') . "\n";
    }
    echo "\n";
}

// 3. Conta diretorio /files/whatsapp
echo "=== Pasta /files/whatsapp ===\n";
$dir = __DIR__ . '/files/whatsapp';
if (is_dir($dir)) {
    $arquivos = glob($dir . '/wa_*');
    echo "  Total arquivos: " . count($arquivos) . "\n";
    // Mostra 3 mais recentes
    usort($arquivos, function($a, $b){ return filemtime($b) - filemtime($a); });
    echo "  3 mais recentes:\n";
    foreach (array_slice($arquivos, 0, 3) as $f) {
        echo "    " . basename($f) . " — " . filesize($f) . " bytes — " . date('Y-m-d H:i:s', filemtime($f)) . " — perm=" . substr(sprintf('%o', fileperms($f)), -4) . "\n";
    }
} else {
    echo "  ⚠️ Pasta NAO existe!\n";
}
