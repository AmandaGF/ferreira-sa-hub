<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CONVERSAS com formato LID (numero > 13 digitos OU contem @lid) ===\n";
$st = $pdo->query("SELECT id, canal, telefone, nome_contato, atendente_id, chat_lid, created_at, ultima_msg_em, status
                   FROM zapi_conversas
                   WHERE (LENGTH(telefone) > 13 OR telefone REGEXP '[^0-9]' OR nome_contato LIKE '%@lid%' OR chat_lid IS NOT NULL)
                     AND status NOT IN ('arquivado','resolvido')
                   ORDER BY id DESC LIMIT 20");
foreach ($st as $r) {
    printf("  #%d canal=%s tel=%-20s nome=%s\n     at=%s chat_lid=%s ult=%s\n",
        $r['id'], $r['canal'], $r['telefone'], substr($r['nome_contato']??'',0,30),
        $r['atendente_id']?:'-', $r['chat_lid']?:'-', $r['ultima_msg_em']);
}

echo "\n=== Conversas da Nativania (atendente 12) hoje ===\n";
$st = $pdo->query("SELECT id, canal, telefone, nome_contato, chat_lid, status, ultima_msg_em
                   FROM zapi_conversas WHERE atendente_id = 12 AND DATE(ultima_msg_em) = CURDATE()
                   ORDER BY ultima_msg_em DESC LIMIT 10");
foreach ($st as $r) {
    printf("  #%d canal=%s tel=%s nome=%s status=%s\n",
        $r['id'], $r['canal'], $r['telefone'], substr($r['nome_contato']??'',0,30), $r['status']);
}
