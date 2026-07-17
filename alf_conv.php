<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

foreach (array(97, 1923) as $cid) {
    echo "\n=== CONV #$cid — TODAS AS MSGS ULTIMAS 24H ===\n";
    foreach ($pdo->query("SELECT m.id, m.direcao, m.created_at, m.tipo, LEFT(m.texto,80) t
                          FROM zapi_mensagens m
                          WHERE m.conversa_id = $cid AND m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                          ORDER BY m.id DESC LIMIT 15") as $r) {
        printf("  msg#%d %s dir=%s tipo=%s | %s\n", $r['id'], $r['created_at'], $r['direcao'],
            $r['tipo']?:'-', preg_replace('/\s+/', ' ', (string)$r['t']));
    }
}

echo "\n\n=== CONTADOR TOTAL DE MSGS CANAL 24 nas ULT 24H ===\n";
$c = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens m
                  JOIN zapi_conversas co ON co.id=m.conversa_id
                  WHERE co.canal='24' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
echo "  Total: $c\n";
$cr = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens m
                   JOIN zapi_conversas co ON co.id=m.conversa_id
                   WHERE co.canal='24' AND m.direcao='recebida' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
echo "  Recebidas: $cr\n";
$ce = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens m
                   JOIN zapi_conversas co ON co.id=m.conversa_id
                   WHERE co.canal='24' AND m.direcao='enviada' AND m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
echo "  Enviadas: $ce\n";

// Se recebidas > 0 no total, mas 0 vinham na query anterior, tem algo estranho no filtro
