<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG duplicatas WA — " . date('Y-m-d H:i:s') . " ===\n\n";

// === 1) Conversas com chat_lid que NUNCA receberam msg enviada pelo Hub (user_id != NULL)
// Ou seja: conv existe, tem atividade, mas ninguém do Hub respondeu por ela.
// Candidata a ser "duplicata órfã" de outra conversa onde o atendente está respondendo.
echo "--- 1) Conversas SEM envio do Hub (potenciais duplicatas 'órfãs') ---\n";
echo "    Critério: conv com mensagens, mas NENHUMA 'enviada' pelo Hub (enviado_por_id IS NULL em TODAS enviadas)\n\n";
$r = $pdo->query("
    SELECT co.id, co.canal, co.nome_contato, co.telefone, co.chat_lid, co.atendente_id, co.status, co.created_at, co.ultima_msg_em,
        (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS total_msgs,
        (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id AND m.direcao = 'enviada' AND m.enviado_por_id IS NOT NULL) AS enviadas_hub,
        (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id AND m.direcao = 'enviada') AS enviadas_total
    FROM zapi_conversas co
    WHERE co.created_at > DATE_SUB(NOW(), INTERVAL 14 DAY)
      AND (co.eh_grupo = 0 OR co.eh_grupo IS NULL)
    HAVING total_msgs > 2 AND enviadas_hub = 0
    ORDER BY co.ultima_msg_em DESC
    LIMIT 40");
$suspeitas = $r->fetchAll();
echo "  Encontradas: " . count($suspeitas) . "\n\n";
foreach ($suspeitas as $s) {
    echo sprintf("  #%d canal=%s tel=%s chat_lid=%s nome='%s' msgs=%d (enviadas_tot=%d enviadas_hub=%d) atend=%s [%s] criada=%s ult=%s\n",
        $s['id'], $s['canal'], $s['telefone'], $s['chat_lid'] ?: '-',
        $s['nome_contato'] ?: '?', $s['total_msgs'], $s['enviadas_total'], $s['enviadas_hub'],
        $s['atendente_id'] ?: '-', $s['status'], $s['created_at'], $s['ultima_msg_em']);
}

// === 2) Pra cada suspeita, procura GÊMEA — conv com mesmo canal + últimos 10 dígitos do telefone OU mesmo nome_contato
echo "\n--- 2) Possíveis gêmeas (mesmo número OU mesmo nome no mesmo canal) ---\n\n";
foreach ($suspeitas as $s) {
    $tel10 = $s['telefone'] ? substr(preg_replace('/\D/', '', str_replace('@lid', '', $s['telefone'])), -10) : '';
    $params = array($s['canal'], $s['id']);
    $where = "canal = ? AND id != ?";
    $sqlParts = array();
    if ($tel10 && strlen($tel10) >= 10) {
        $sqlParts[] = "RIGHT(REPLACE(REPLACE(telefone,'@lid',''),'@g.us',''), 10) = ?";
        $params[] = $tel10;
    }
    if (!empty($s['nome_contato']) && mb_strlen($s['nome_contato']) >= 4) {
        $sqlParts[] = "LOWER(nome_contato) = LOWER(?)";
        $params[] = $s['nome_contato'];
    }
    if (!$sqlParts) continue;
    $where .= " AND (" . implode(' OR ', $sqlParts) . ")";

    $q = $pdo->prepare("SELECT id, telefone, chat_lid, nome_contato, atendente_id, status, ultima_msg_em,
        (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = zapi_conversas.id) AS total_msgs,
        (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = zapi_conversas.id AND m.enviado_por_id IS NOT NULL) AS hub
        FROM zapi_conversas WHERE {$where} AND (eh_grupo = 0 OR eh_grupo IS NULL)");
    $q->execute($params);
    $gemeas = $q->fetchAll();
    if (!$gemeas) continue;
    echo "  PAR: suspeita #{$s['id']} (canal={$s['canal']}) tel={$s['telefone']} nome='{$s['nome_contato']}'\n";
    echo "    ↪ suspeita: msgs={$s['total_msgs']} enviadas_hub=0 atend=" . ($s['atendente_id'] ?: '-') . "\n";
    foreach ($gemeas as $g) {
        echo sprintf("    ↪ gêmea   #%d tel=%s chat_lid=%s nome='%s' msgs=%d hub=%d atend=%s [%s] ult=%s\n",
            $g['id'], $g['telefone'], $g['chat_lid'] ?: '-', $g['nome_contato'] ?: '?',
            $g['total_msgs'], $g['hub'], $g['atendente_id'] ?: '-', $g['status'], $g['ultima_msg_em']);
    }
    echo "\n";
}

echo "\n=== FIM ===\n";
