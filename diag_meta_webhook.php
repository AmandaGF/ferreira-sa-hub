<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "== meta_config (estado atual) ==\n";
foreach ($pdo->query("SELECT chave, IF(chave IN ('meta_app_secret'), CONCAT('***', RIGHT(valor,4)), valor) AS valor, atualizado_em FROM meta_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  {$r['chave']} = '{$r['valor']}' (em {$r['atualizado_em']})\n";
}

echo "\n== meta_webhook_log (ultimas 10 entradas) ==\n";
foreach ($pdo->query("SELECT id, direcao, object_type, LEFT(payload, 180) AS prev, ip, received_at FROM meta_webhook_log ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #{$r['id']} dir={$r['direcao']} obj={$r['object_type']} ip={$r['ip']} em {$r['received_at']}\n";
    echo "    preview: {$r['prev']}\n";
}

echo "\n== Totais ==\n";
echo "  handshakes: " . (int)$pdo->query("SELECT COUNT(*) FROM meta_webhook_log WHERE direcao='handshake'")->fetchColumn() . "\n";
echo "  eventos:    " . (int)$pdo->query("SELECT COUNT(*) FROM meta_webhook_log WHERE direcao='event'")->fetchColumn() . "\n";
