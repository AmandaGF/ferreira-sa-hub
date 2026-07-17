<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
ini_set('display_errors', '1');
error_reporting(E_ALL);

foreach (array(97, 1923) as $cid) {
    echo "\n=== CONV #$cid ===\n";
    try {
        $st = $pdo->prepare("SELECT id, direcao, created_at, tipo, LEFT(texto,80) t
                             FROM zapi_mensagens
                             WHERE conversa_id = ?
                             ORDER BY id DESC LIMIT 8");
        $st->execute(array($cid));
        $n=0;
        foreach ($st as $r) { $n++;
            printf("  msg#%s %s dir=%s tipo=%s | %s\n", $r['id'], $r['created_at'], $r['direcao'], $r['tipo']?:'-', preg_replace('/\s+/',' ', (string)$r['t']));
        }
        if (!$n) echo "  (sem msgs)\n";
    } catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
}

echo "\n=== TOTAIS ULT 24H ===\n";
$c = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
echo "Total msgs 24h: $c\n";
$cr = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE direcao='recebida' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
echo "Recebidas 24h: $cr\n";
