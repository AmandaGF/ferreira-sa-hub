<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

echo "=== 1) Cliente Eduarda cadastrada ===\n";
$pdo = db();
$q = $pdo->prepare("SELECT id, name, phone, whatsapp_lid, whatsapp_lid_checado_em FROM clients WHERE id = 652");
$q->execute();
print_r($q->fetch());

echo "\n=== 2) Consulta Z-API /phone-exists pro telefone cadastrado ===\n";
$r = zapi_phone_exists('24', '21973698089');
print_r($r);

echo "\n=== 3) Status da msg 'Segue:' (#3579) ===\n";
$q = $pdo->prepare("SELECT id, conversa_id, direcao, tipo, status, entregue, lida, created_at, SUBSTR(conteudo,1,50) AS preview, zapi_message_id FROM zapi_mensagens WHERE id = 3579");
$q->execute();
print_r($q->fetch());

echo "\n=== 4) Últimas 5 msgs na conv 648 (Eduarda) ===\n";
$q = $pdo->query("SELECT id, direcao, status, entregue, lida, created_at, SUBSTR(conteudo,1,40) AS preview FROM zapi_mensagens WHERE conversa_id = 648 ORDER BY id DESC LIMIT 5");
foreach ($q->fetchAll() as $r) {
    echo "#{$r['id']} [{$r['direcao']}] status={$r['status']} entregue={$r['entregue']} lida={$r['lida']} {$r['created_at']}: {$r['preview']}\n";
}
