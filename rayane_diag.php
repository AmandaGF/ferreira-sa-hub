<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_aviso_cliente.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
@set_time_limit(120);

// Rayane Viana — busca por nome
$st = $pdo->prepare("SELECT id, name, phone FROM clients WHERE name LIKE '%Rayane%' AND name LIKE '%Viana%' LIMIT 1");
$st->execute();
$cl = $st->fetch(PDO::FETCH_ASSOC);
if (!$cl) { echo "cliente Rayane Viana nao achado\n"; exit; }
echo "Cliente #{$cl['id']} = {$cl['name']}\n\n";

// Case ativo dela + ultimo andamento visivel
$st = $pdo->prepare(
    "SELECT ca.id AS andamento_id, ca.descricao, ca.data_andamento, ca.tipo, ca.visivel_cliente,
            cs.id AS case_id, cs.title AS case_title, cs.status AS case_status
       FROM case_andamentos ca
       JOIN cases cs ON cs.id = ca.case_id
      WHERE cs.client_id = ?
        AND cs.status NOT IN ('arquivado','cancelado','renunciamos','concluido','finalizado')
        AND COALESCE(ca.visivel_cliente, 0) = 1
      ORDER BY ca.data_andamento DESC, ca.id DESC LIMIT 1"
);
$st->execute(array((int)$cl['id']));
$a = $st->fetch(PDO::FETCH_ASSOC);
if (!$a) { echo "sem andamento\n"; exit; }
echo "Case #{$a['case_id']} '{$a['case_title']}' status={$a['case_status']}\n";
echo "Ultimo andamento #{$a['andamento_id']} data={$a['data_andamento']} tipo={$a['tipo']}\n";
echo "Descricao:\n" . $a['descricao'] . "\n\n";

// Modo detection
$modoInfo = aviso_cliente_determinar_modo($pdo, (int)$cl['id'], (string)$a['data_andamento']);
echo "=== MODO DETECTADO ===\n";
print_r($modoInfo);

// Ultimas msgs dela (canal 24 — pra ver o que a query encontra)
echo "\n=== ULTIMAS 5 MSGS RECEBIDAS DELA (canal 24) ===\n";
$st = $pdo->prepare(
    "SELECT m.created_at, LEFT(m.texto, 80) t
       FROM zapi_mensagens m
       JOIN zapi_conversas co ON co.id = m.conversa_id
      WHERE co.client_id = ?
        AND co.canal = '24'
        AND m.direcao = 'recebida'
      ORDER BY m.created_at DESC LIMIT 5"
);
$st->execute(array((int)$cl['id']));
foreach ($st as $r) {
    printf("  %s | %s\n", $r['created_at'], preg_replace('/\s+/', ' ', $r['t']));
}

// Ultimas 3 msgs enviadas por notif (context da IA)
echo "\n=== ULTIMAS 3 msgs enviadas por aviso_cliente (contexto anti-repeticao) ===\n";
$ultimasMsgs = array();
$st = $pdo->prepare("SELECT ca2.notif_cliente_texto FROM case_andamentos ca2
                     JOIN cases cs2 ON cs2.id = ca2.case_id
                     WHERE cs2.client_id = ? AND ca2.notif_cliente_status = 'enviado'
                       AND ca2.notif_cliente_texto IS NOT NULL
                     ORDER BY ca2.notif_cliente_enviada_em DESC LIMIT 3");
$st->execute(array((int)$cl['id']));
foreach ($st as $r) {
    if (!empty($r['notif_cliente_texto'])) {
        $ultimasMsgs[] = $r['notif_cliente_texto'];
        echo "  * " . mb_substr($r['notif_cliente_texto'], 0, 100) . "...\n";
    }
}
if (empty($ultimasMsgs)) echo "  (nenhuma — sera a primeira msg dessa cliente)\n";

// Gera mensagem
echo "\n=== CHAMANDO IA ===\n";
$msg = aviso_cliente_resumir_via_ia(array($a), $cl['name'], $a['case_title'], $ultimasMsgs, $modoInfo);
echo "\n=== RESULTADO ===\n";
echo ($msg ?: '(vazio/descartado)') . "\n";
