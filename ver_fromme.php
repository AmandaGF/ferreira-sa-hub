<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';

$logFile = APP_ROOT . '/files/zapi_webhook.log';
echo "=== Procurando eventos fromMe no log ===\n\n";

if (!is_readable($logFile)) { die("Log inacessível\n"); }
$lines = file($logFile);
$tail = array_slice($lines, -1000);

$fromMe = 0; $received = 0; $statusCb = 0; $outros = 0;
$primeirosFromMe = array();

foreach ($tail as $l) {
    if (preg_match('/"fromMe":true/', $l)) {
        $fromMe++;
        if (count($primeirosFromMe) < 5) $primeirosFromMe[] = trim($l);
    }
    if (preg_match('/"type":"ReceivedCallback"/', $l) || preg_match('/"type":"message"/', $l)) $received++;
    if (preg_match('/"type":"MessageStatusCallback"/', $l)) $statusCb++;
}

echo "Últimas ~1000 linhas do log analisadas:\n";
echo "  ReceivedCallback (mensagens): {$received}\n";
echo "  MessageStatusCallback (status): {$statusCb}\n";
echo "  Contendo fromMe:true: {$fromMe}\n\n";

if ($fromMe > 0) {
    echo "Primeiros eventos fromMe=true:\n";
    foreach ($primeirosFromMe as $l) {
        echo "  " . substr($l, 0, 300) . "\n\n";
    }
} else {
    echo "❌ Nenhum fromMe=true chegou no webhook — Z-API não está enviando esses eventos\n";
    echo "   Possíveis causas:\n";
    echo "   1. Configuração do Z-API bloqueando 'notifySentByMe'\n";
    echo "   2. Multi-Device não encaminha msgs enviadas pelo celular\n";
}

// Verificar também se tem no banco alguma mensagem enviada hoje que não seja do Hub
echo "\n\n--- Mensagens enviadas hoje no banco (por origem) ---\n";
$pdo = db();
$hoje = date('Y-m-d');
$rows = $pdo->query("
    SELECT DATE(created_at) as dia,
           COUNT(CASE WHEN enviado_por_id IS NOT NULL THEN 1 END) as pelo_hub,
           COUNT(CASE WHEN enviado_por_id IS NULL AND enviado_por_bot=0 THEN 1 END) as pelo_celular,
           COUNT(CASE WHEN enviado_por_bot=1 THEN 1 END) as pelo_bot,
           COUNT(*) as total
    FROM zapi_mensagens
    WHERE direcao='enviada' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
    GROUP BY DATE(created_at) ORDER BY dia DESC
")->fetchAll();
foreach ($rows as $r) {
    echo sprintf("  %s  total=%d  hub=%d  celular=%d  bot=%d\n",
        $r['dia'], $r['total'], $r['pelo_hub'], $r['pelo_celular'], $r['pelo_bot']);
}

// E verificar a última entrada do log
echo "\n--- Última entrada do log ---\n";
echo "  " . trim(end($lines)) . "\n";
