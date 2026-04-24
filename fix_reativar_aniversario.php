<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Religa o cron de aniversário — agora com proteção @lid
$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('zapi_auto_aniversario', '1')
               ON DUPLICATE KEY UPDATE valor='1'")->execute();

echo "[OK] zapi_auto_aniversario=1 (reativado com proteção @lid)\n\n";

// Mostra estado final
$q = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'zapi_auto_aniversario%' ORDER BY chave");
foreach ($q->fetchAll() as $r) {
    echo "  {$r['chave']} = {$r['valor']}\n";
}

echo "\nProximo cron ira enviar parabens automaticamente.\n";
echo "Com a defesa nova: se um cliente nao tem @lid validado, ele e PULADO em vez\n";
echo "de enviar mensagem pra contato errado.\n";
