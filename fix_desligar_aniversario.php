<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Desligar cron de aniversário até correção definitiva
$pdo->prepare("UPDATE configuracoes SET valor='0' WHERE chave='zapi_auto_aniversario'")->execute();
echo "[OK] zapi_auto_aniversario=0 (desligado)\n";

// Mostra estado atual
$q = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'zapi_auto%' ORDER BY chave");
foreach ($q->fetchAll() as $r) {
    echo "  {$r['chave']} = {$r['valor']}\n";
}
echo "\nPróximo cron das 09:00 não vai enviar parabéns automaticamente.\n";
echo "Reativar depois com: UPDATE configuracoes SET valor='1' WHERE chave='zapi_auto_aniversario'\n";
