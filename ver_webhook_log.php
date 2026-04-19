<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Status das instâncias Z-API ===\n\n";
foreach ($pdo->query("SELECT * FROM zapi_instancias")->fetchAll() as $i) {
    echo "DDD {$i['ddd']} | nome={$i['nome']} | id=" . ($i['instancia_id'] ? 'OK (' . substr($i['instancia_id'], 0, 8) . '...)' : 'VAZIO') . " | token=" . ($i['token'] ? 'OK' : 'VAZIO') . " | conectado=" . ($i['conectado'] ? 'SIM' : 'NÃO') . " | última verificação=" . ($i['ultima_verificacao'] ?: 'nunca') . "\n";
}

echo "\n=== Últimas 50 linhas do log do webhook ===\n\n";
$log = APP_ROOT . '/files/zapi_webhook.log';
if (!file_exists($log)) { echo "Log não existe ainda.\n"; exit; }
$lines = file($log);
$ult = array_slice($lines, -50);
foreach ($ult as $l) echo $l;

echo "\n=== Contagem de eventos por número ===\n\n";
$log_all = file_get_contents($log);
preg_match_all('/\[(\d+)\]/', $log_all, $matches);
$counts = array_count_values($matches[1]);
foreach ($counts as $num => $n) echo "  DDD {$num}: {$n} eventos\n";

echo "\n=== Últimas mensagens recebidas por canal (DB) ===\n\n";
foreach (array('21', '24') as $ddd) {
    echo "--- DDD {$ddd} ---\n";
    $msgs = $pdo->query("SELECT m.id, m.direcao, m.tipo, LEFT(m.conteudo, 50) as c, m.created_at
                         FROM zapi_mensagens m
                         JOIN zapi_conversas co ON co.id = m.conversa_id
                         WHERE co.canal = '{$ddd}' AND m.direcao = 'recebida'
                         ORDER BY m.id DESC LIMIT 5")->fetchAll();
    if (empty($msgs)) echo "  (nenhuma mensagem recebida)\n";
    foreach ($msgs as $m) echo "  #{$m['id']} | {$m['tipo']} | '{$m['c']}' | {$m['created_at']}\n";
    echo "\n";
}
