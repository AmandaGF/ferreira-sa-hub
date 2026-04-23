<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== DIAG Luiz Eduardo — " . date('Y-m-d H:i:s') . " ===\n\n";

// 1) Quem é o Luiz
$luiz = $pdo->query("SELECT id, name, role, is_active FROM users WHERE LOWER(name) LIKE '%luiz%' ORDER BY id")->fetchAll();
echo "--- Users Luiz ---\n";
foreach ($luiz as $u) echo "  id={$u['id']} name={$u['name']} role={$u['role']} ativo={$u['is_active']}\n";

$luizId = 0;
foreach ($luiz as $u) { if (stripos($u['name'], 'luiz eduardo') !== false) { $luizId = (int)$u['id']; break; } }
if (!$luizId && $luiz) $luizId = (int)$luiz[0]['id'];
echo "\n  Usando user_id = {$luizId}\n\n";

// 2) Últimas mensagens enviadas pelo Luiz nas últimas 24h (ambos canais)
echo "--- Últimas mensagens ENVIADAS pelo Luiz (24h) ---\n";
$stmt = $pdo->prepare("SELECT m.id, m.created_at, co.canal, co.id AS conv_id, co.nome_contato, co.telefone, co.atendente_id, co.status,
    LEFT(m.conteudo, 60) AS previa
    FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
    WHERE m.enviado_por_id = ?
      AND m.direcao = 'enviada'
      AND m.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY m.id DESC LIMIT 20");
$stmt->execute(array($luizId));
$msgs = $stmt->fetchAll();
if (!$msgs) { echo "  (nenhuma)\n"; }
foreach ($msgs as $m) {
    $min = (int)((time() - strtotime($m['created_at'])) / 60);
    echo sprintf("  #%d %s (há %dmin) canal=%s conv=%d atendente=%s status=%s %s — %s\n",
        $m['id'], $m['created_at'], $min, $m['canal'], $m['conv_id'],
        $m['atendente_id'] ?: 'NULL', $m['status'],
        $m['nome_contato'] ?: '?', trim($m['previa']));
}

// 3) Conversas onde Luiz é atendente atual (pode estar "escondidas" sob filtro "minhas" da Amanda)
echo "\n--- Conversas canal 21 com Luiz como atendente_id ---\n";
$stmt = $pdo->prepare("SELECT id, nome_contato, telefone, status, ultima_msg_em, ultima_mensagem
    FROM zapi_conversas WHERE canal='21' AND atendente_id = ? ORDER BY ultima_msg_em DESC LIMIT 10");
$stmt->execute(array($luizId));
foreach ($stmt as $c) {
    echo "  #{$c['id']} [{$c['status']}] {$c['nome_contato']} ({$c['telefone']}) ult={$c['ultima_msg_em']} — " . mb_substr((string)$c['ultima_mensagem'], 0, 60, 'UTF-8') . "\n";
}

// 4) Test: Amanda consegue ver as msgs do Luiz? Simula query do listar_conversas sem filtro
echo "\n--- Canal 24 últimas conversas (qualquer atendente) ---\n";
$stmt = $pdo->query("SELECT id, nome_contato, telefone, atendente_id, status, ultima_msg_em
    FROM zapi_conversas WHERE canal='24' AND status != 'arquivado' ORDER BY ultima_msg_em DESC LIMIT 8");
foreach ($stmt as $c) {
    echo "  #{$c['id']} [{$c['status']}] atendente={$c['atendente_id']} {$c['nome_contato']} ({$c['telefone']}) ult={$c['ultima_msg_em']}\n";
}

echo "\n=== FIM ===\n";
