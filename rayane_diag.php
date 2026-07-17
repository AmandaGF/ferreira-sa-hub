<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_aviso_cliente.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
@set_time_limit(180);
@ob_end_flush();

echo "iniciando...\n"; flush();

$st = $pdo->prepare("SELECT id, name FROM clients WHERE name LIKE '%Rayane%' AND name LIKE '%Viana%' LIMIT 1");
$st->execute();
$cl = $st->fetch(PDO::FETCH_ASSOC);
if (!$cl) { echo "cliente Rayane Viana nao achado\n"; exit; }
echo "Cliente #{$cl['id']} = {$cl['name']}\n"; flush();

$st = $pdo->prepare(
    "SELECT ca.id AS andamento_id, ca.descricao, ca.data_andamento, ca.tipo,
            cs.id AS case_id, cs.title AS case_title
       FROM case_andamentos ca
       JOIN cases cs ON cs.id = ca.case_id
      WHERE cs.client_id = ?
        AND cs.status NOT IN ('arquivado','cancelado','renunciamos','concluido','finalizado')
        AND COALESCE(ca.visivel_cliente, 0) = 1
      ORDER BY ca.data_andamento DESC, ca.id DESC LIMIT 1"
);
$st->execute(array((int)$cl['id']));
$a = $st->fetch(PDO::FETCH_ASSOC);
echo "andamento data=" . $a['data_andamento'] . "\n"; flush();

$modoInfo = aviso_cliente_determinar_modo($pdo, (int)$cl['id'], (string)$a['data_andamento']);
echo "modo=" . $modoInfo['modo'] . " dias=" . $modoInfo['dias'] . " perguntou=" . (int)$modoInfo['cliente_perguntou_apos'] . "\n\n"; flush();

echo "==== chamando IA ====\n"; flush();
$msg = aviso_cliente_resumir_via_ia(array($a), $cl['name'], $a['case_title'], array(), $modoInfo);
echo "==== RESULTADO ====\n";
echo ($msg ?: '(vazio)') . "\n";
