<?php
/**
 * Migração one-shot: limpa duplicatas de conversas WhatsApp por client_id.
 * Aplica a função zapi_auto_merge_por_client_id em TODOS os clientes que
 * têm 2+ conversas no mesmo canal — mescla na mais recente.
 *
 * Idempotente: se rodar de novo e não houver mais duplicatas, retorna 0.
 * Pode apagar depois.
 *
 * Uso: ?key=fsa-hub-deploy-2026
 */
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_zapi.php';

$key = $_GET['key'] ?? '';
if ($key !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }

$pdo = db();
header('Content-Type: text/plain; charset=utf-8');

echo "=== Limpeza retroativa: duplicatas de conversa WhatsApp por client_id ===\n\n";

$stmt = $pdo->query(
    "SELECT client_id, canal, COUNT(*) as cnt, MAX(id) AS id_destino, GROUP_CONCAT(id ORDER BY id ASC) AS todos_ids
     FROM zapi_conversas
     WHERE client_id IS NOT NULL AND client_id > 0
       AND COALESCE(eh_grupo,0) = 0
     GROUP BY client_id, canal
     HAVING cnt > 1
     ORDER BY cnt DESC, client_id ASC"
);
$grupos = $stmt->fetchAll();

if (empty($grupos)) {
    echo "✅ Nenhuma duplicata encontrada. Banco limpo.\n";
    exit;
}

echo count($grupos) . " cliente(s) com conversas duplicadas encontrado(s):\n\n";

$totalMerged = 0;
foreach ($grupos as $g) {
    $clienteName = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
    $clienteName->execute(array($g['client_id']));
    $nome = (string)$clienteName->fetchColumn();
    echo "→ Cliente #{$g['client_id']} ({$nome}) — canal {$g['canal']} — {$g['cnt']} conversas (IDs: {$g['todos_ids']})\n";
    echo "   Mantendo a mais recente (#{$g['id_destino']}), mesclando as outras...\n";

    $merged = zapi_auto_merge_por_client_id($pdo, (int)$g['id_destino'], (int)$g['client_id'], $g['canal']);
    echo "   ✓ {$merged} conversa(s) mesclada(s)\n\n";
    $totalMerged += $merged;
}

echo "=== Concluído: {$totalMerged} conversas mescladas em " . count($grupos) . " clientes ===\n";
