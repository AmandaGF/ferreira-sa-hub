<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== Mensagens em notificacao_config ==\n";
foreach ($pdo->query("SELECT tipo, titulo, LENGTH(mensagem_whatsapp) AS len, mensagem_whatsapp FROM notificacao_config WHERE tipo='boas_vindas'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "tipo: {$r['tipo']}\n";
    echo "titulo: {$r['titulo']}\n";
    echo "len: {$r['len']}\n";
    echo "===INICIO MSG===\n{$r['mensagem_whatsapp']}\n===FIM MSG===\n";
}

echo "\n== Schema da coluna ==\n";
foreach ($pdo->query("SHOW COLUMNS FROM notificacao_config LIKE 'mensagem_whatsapp'")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    foreach ($c as $k => $v) echo "  $k = " . var_export($v, true) . "\n";
}
