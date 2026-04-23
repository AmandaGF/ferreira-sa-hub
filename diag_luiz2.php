<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG Luiz v2 — " . date('Y-m-d H:i:s') . " ===\n\n";

// Mensagens fromMe (enviadas pelo celular diretamente) nos últimos 2h — canal 21 (Luiz)
echo "--- fromMe SEM user_id (mandadas do celular) — canal 21, 2h ---\n";
$r = $pdo->query("SELECT m.id, m.created_at, m.enviado_por_id, co.id AS conv, co.nome_contato, co.telefone, co.atendente_id, LEFT(m.conteudo, 50) AS previa
    FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE co.canal = '21' AND m.direcao = 'enviada' AND m.enviado_por_id IS NULL
      AND m.created_at > DATE_SUB(NOW(), INTERVAL 2 HOUR)
    ORDER BY m.id DESC LIMIT 15");
foreach ($r as $m) {
    $min = (int)((time() - strtotime($m['created_at'])) / 60);
    echo sprintf("  #%d %s (há %dmin) conv=%d atendente=%s %s [%s] — %s\n",
        $m['id'], $m['created_at'], $min, $m['conv'], $m['atendente_id'] ?: 'NULL',
        $m['nome_contato'] ?: '?', $m['telefone'], trim($m['previa']));
}

// Todas as conversas canal 21 com atividade recente (2h) mostrando atendente
echo "\n--- Conversas canal 21 com atividade (2h) ---\n";
$r = $pdo->query("SELECT co.id, co.nome_contato, co.telefone, co.atendente_id, u.name AS atendente_nome, co.status,
    co.ultima_msg_em, co.ultima_mensagem
    FROM zapi_conversas co LEFT JOIN users u ON u.id = co.atendente_id
    WHERE co.canal='21' AND co.ultima_msg_em > DATE_SUB(NOW(), INTERVAL 2 HOUR)
      AND co.status != 'arquivado'
    ORDER BY co.ultima_msg_em DESC");
foreach ($r as $c) {
    echo sprintf("  #%d %s (%s) atendente=%s [%s] ult=%s — %s\n",
        $c['id'], $c['nome_contato'] ?: '?', $c['telefone'],
        $c['atendente_nome'] ?: ($c['atendente_id'] ? 'user#'.$c['atendente_id'] : 'SEM'),
        $c['status'], $c['ultima_msg_em'], mb_substr((string)$c['ultima_mensagem'], 0, 70, 'UTF-8'));
}

// Etiquetas aplicadas nas conversas do Luiz (canal 21)
echo "\n--- Etiquetas aplicadas em conversas canal 21 com atendente=Luiz ---\n";
$r = $pdo->query("SELECT co.id, co.nome_contato, e.nome AS etiqueta
    FROM zapi_conversas co
    JOIN zapi_conversa_etiquetas ce ON ce.conversa_id = co.id
    JOIN zapi_etiquetas e ON e.id = ce.etiqueta_id
    WHERE co.canal='21' AND co.atendente_id=6
    ORDER BY co.ultima_msg_em DESC LIMIT 20");
$cntE = 0;
foreach ($r as $l) { echo "  conv#{$l['id']} {$l['nome_contato']} → {$l['etiqueta']}\n"; $cntE++; }
if (!$cntE) echo "  (nenhuma)\n";

echo "\n=== FIM ===\n";
