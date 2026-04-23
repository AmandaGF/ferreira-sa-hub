<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG fromMe (celular) — " . date('Y-m-d H:i:s') . " ===\n\n";

// Mensagens fromMe sem user_id (do celular) nos últimos 3h, ambos canais
echo "--- fromMe SEM user_id (últimas 3h, ambos canais) ---\n";
$r = $pdo->query("SELECT m.id, m.created_at, co.canal, co.id AS conv, co.nome_contato, co.telefone, co.atendente_id,
    LEFT(m.conteudo, 60) AS previa, m.tipo
    FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE m.direcao = 'enviada' AND m.enviado_por_id IS NULL
      AND m.created_at > DATE_SUB(NOW(), INTERVAL 3 HOUR)
    ORDER BY m.id DESC LIMIT 30");
$conta = array('21' => 0, '24' => 0);
foreach ($r as $m) {
    $conta[$m['canal']]++;
    $min = (int)((time() - strtotime($m['created_at'])) / 60);
    echo sprintf("  #%d %s (há %dmin) ch=%s conv=%d [%s] %s — %s\n",
        $m['id'], $m['created_at'], $min, $m['canal'], $m['conv'], $m['tipo'],
        $m['nome_contato'] ?: '?', trim($m['previa']));
}
echo "\n  Total canal 21: {$conta['21']} | canal 24: {$conta['24']}\n";

// Log webhook: últimas entries com "fromMe salvo"
echo "\n--- Log 'fromMe salvo' (últimas 20 no arquivo) ---\n";
$log = __DIR__ . '/files/zapi_webhook.log';
if (file_exists($log)) {
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    $tail = array_slice($lines, -1500); // pega as últimas 1500 linhas e filtra
    $filtradas = array();
    foreach ($tail as $l) {
        if (strpos($l, 'fromMe') !== false || strpos($l, 'MATCH-') !== false) {
            $filtradas[] = $l;
        }
    }
    $filtradas = array_slice($filtradas, -20);
    foreach ($filtradas as $l) echo "  " . mb_substr($l, 0, 200, 'UTF-8') . "\n";
}

// Canal 24 - últimas mensagens de qualquer tipo pra ver se Luiz Eduardo (user_id=6) apareceu
echo "\n--- Canal 24 últimas 10 msgs (todas) ---\n";
$r = $pdo->query("SELECT m.id, m.created_at, m.direcao, m.enviado_por_id, co.nome_contato, LEFT(m.conteudo, 60) AS previa
    FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE co.canal='24' ORDER BY m.id DESC LIMIT 10");
foreach ($r as $m) {
    $min = (int)((time() - strtotime($m['created_at'])) / 60);
    echo sprintf("  #%d %s (há %dmin) %s user_id=%s %s — %s\n",
        $m['id'], $m['created_at'], $min, $m['direcao'],
        $m['enviado_por_id'] ?: 'NULL', $m['nome_contato'] ?: '?', trim($m['previa']));
}
