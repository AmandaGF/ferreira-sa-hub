<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Estrutura notificacao_config ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM notificacao_config")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    if (in_array($c['Field'], array('tipo','mensagem_whatsapp','mensagem_email','ativo'))) {
        echo "  " . str_pad($c['Field'], 22) . " " . $c['Type'] . "\n";
    }
}

echo "\n=== Conteúdo atual de boas_vindas ===\n";
$st = $pdo->prepare("SELECT tipo, ativo, LENGTH(mensagem_whatsapp) AS tam, mensagem_whatsapp FROM notificacao_config WHERE tipo = 'boas_vindas'");
$st->execute();
$r = $st->fetch();
if ($r) {
    echo "  ativo: " . ($r['ativo'] ? 'sim' : 'nao') . "\n";
    echo "  tamanho: " . $r['tam'] . " bytes\n";
    echo "  --- conteúdo ---\n";
    echo $r['mensagem_whatsapp'] . "\n";
    echo "  --- fim ---\n";
} else {
    echo "  (não existe)\n";
}
