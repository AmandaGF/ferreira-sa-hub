<?php
/**
 * Migração 24/Abr/2026 — limpar chat_lid auto-infectado.
 *
 * Antes do fix, o webhook copiava o telefone (@lid bruto) pro campo chat_lid
 * quando este estava vazio. Isso contaminou o identificador canônico, fazendo
 * matching de conversas cruzar pessoas diferentes.
 *
 * Esta migração zera chat_lid em todas as linhas onde ele é idêntico ao
 * telefone ou é um formato claramente não-@lid (número puro). Conversas
 * continuam encontrandas via estratégia 0c do webhook (match por telefone @lid).
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== MIGRAÇÃO limpar chat_lid auto-infectado ===\n\n";

// 1) Conta casos
$q = $pdo->query("SELECT COUNT(*) FROM zapi_conversas
                  WHERE chat_lid IS NOT NULL
                    AND chat_lid != ''
                    AND (chat_lid = telefone
                         OR (chat_lid NOT LIKE '%@lid' AND chat_lid NOT LIKE '%@%'))");
$total = (int)$q->fetchColumn();
echo "chat_lid a limpar (auto-infectados ou sem @): {$total}\n";

if ($total === 0) { echo "Nada a fazer.\n"; exit; }

// 2) Amostra antes
echo "\nAmostra (primeiras 10):\n";
$q = $pdo->query("SELECT id, telefone, chat_lid, client_id, canal FROM zapi_conversas
                  WHERE chat_lid IS NOT NULL AND chat_lid != ''
                    AND (chat_lid = telefone
                         OR (chat_lid NOT LIKE '%@lid' AND chat_lid NOT LIKE '%@%'))
                  ORDER BY id DESC LIMIT 10");
foreach ($q->fetchAll() as $r) {
    echo "  conv #{$r['id']} canal={$r['canal']} tel={$r['telefone']} chat_lid={$r['chat_lid']} client={$r['client_id']}\n";
}

// 3) Executa UPDATE
$n = $pdo->exec("UPDATE zapi_conversas
                 SET chat_lid = NULL
                 WHERE chat_lid IS NOT NULL AND chat_lid != ''
                   AND (chat_lid = telefone
                        OR (chat_lid NOT LIKE '%@lid' AND chat_lid NOT LIKE '%@%'))");
echo "\n[OK] {$n} conversas tiveram chat_lid zerado.\n";
echo "chat_lid será repopulado por futuros webhooks quando a Z-API enviar o valor real.\n";
