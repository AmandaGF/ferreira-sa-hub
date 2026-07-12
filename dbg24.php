<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== 1. CLIENTES chamados Queila ===\n";
$st = $pdo->query("SELECT id, name, phone, email FROM clients WHERE name LIKE '%Queila%' OR name LIKE '%Quéila%' LIMIT 10");
foreach ($st as $r) {
    printf("  #%d %s | tel=%s | email=%s\n", $r['id'], $r['name'], $r['phone'], $r['email']);
}

echo "\n=== 2. Últimas 20 mensagens ENVIADAS no canal 24 (últimas 24h) ===\n";
$st = $pdo->query("SELECT m.id, m.direcao, m.status, m.zapi_message_id, m.created_at,
                          co.numero, co.nome_contato, m.enviado_por_id
                   FROM zapi_mensagens m
                   JOIN zapi_conversas co ON co.id = m.conversa_id
                   WHERE co.canal = '24' AND m.direcao = 'enviada'
                     AND m.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   ORDER BY m.created_at DESC LIMIT 20");
foreach ($st as $r) {
    $mid = $r['zapi_message_id'] ?? '';
    $prefix = substr($mid, 0, 4);
    $tam = strlen($mid);
    $tipo = ($tam === 20 || $prefix === '3EB0') ? 'SINT?' : ($tam === 32 ? 'REAL' : 'OUTRO');
    printf("  #%d %s status=%s mid=%s(%d,%s) contato=%s (%s)\n",
        $r['id'], $r['created_at'], ($r['status']?:'VAZIO'), substr($mid,0,20), $tam, $tipo,
        substr($r['nome_contato'] ?? '', 0, 25), $r['numero']);
}

echo "\n=== 3. Instância Z-API canal 24 (config) ===\n";
$st = $pdo->query("SELECT canal, instance_id, connected, ultima_verificacao FROM zapi_instancias WHERE canal = '24'");
foreach ($st as $r) {
    printf("  canal=%s inst=%s conn=%s verif=%s\n", $r['canal'], substr($r['instance_id'],0,20).'...', $r['connected'], $r['ultima_verificacao']);
}

echo "\n=== 4. Msgs SEM status (fantasmas) canal 24 últimas 48h ===\n";
$st = $pdo->query("SELECT COUNT(*) FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id
                   WHERE co.canal='24' AND m.direcao='enviada' AND (m.status IS NULL OR m.status='')
                     AND m.created_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)");
echo "  Total: " . $st->fetchColumn() . "\n";

echo "\n=== 5. Ratio de sucesso canal 24 hoje ===\n";
$st = $pdo->query("SELECT
    SUM(m.status IN ('SENT','RECEIVED','READ','MESSAGE_STATUS_SERVER_ACK','MESSAGE_STATUS_DELIVERY_ACK','MESSAGE_STATUS_READ')) AS ok,
    SUM(m.status IS NULL OR m.status='' OR m.status LIKE '%FAIL%' OR m.status LIKE '%ERROR%') AS falhou,
    COUNT(*) AS total
    FROM zapi_mensagens m JOIN zapi_conversas co ON co.id=m.conversa_id
    WHERE co.canal='24' AND m.direcao='enviada' AND DATE(m.created_at)=CURDATE()");
if ($r = $st->fetch()) {
    printf("  Total hoje: %d · OK: %d · Falhou/sem status: %d\n", $r['total'], $r['ok'], $r['falhou']);
}
