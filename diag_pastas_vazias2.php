<?php
// Onde estao os documentos das pastas vazias? E quem marcou pasta_apta?
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$vazias = [575,605,909,626,881,606,572,595,616,593,1216,1555,1569,1578,1579,1211,1210,584,615,1577,562,574];
$in = implode(',', array_map('intval', $vazias));

echo "=== ONDE ESTAO OS DOCUMENTOS? (" . date('d/m/Y H:i') . ") ===\n\n";

echo "--- 1. Backup de arquivos do WhatsApp: estado geral ---\n";
$q = $pdo->query("SELECT COALESCE(backup_status,'(null)') st, COUNT(*) n,
                  SUM(arquivo_salvo_drive = 1) salvos,
                  MIN(created_at) de, MAX(created_at) ate
                  FROM zapi_mensagens
                  WHERE arquivo_url IS NOT NULL AND arquivo_url <> ''
                  GROUP BY backup_status ORDER BY n DESC");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-18s %-6s (salvos no drive: %-5s) | de %s ate %s\n",
        $r['st'], $r['n'], $r['salvos'], substr((string)$r['de'],0,10), substr((string)$r['ate'],0,10));
}

echo "\n--- 2. pendente_manual: ainda da tempo? (link Z-API expira em 30d) ---\n";
$r = $pdo->query("SELECT
    SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) recuperavel,
    SUM(created_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)) perdido,
    COUNT(*) total
    FROM zapi_mensagens
    WHERE backup_status = 'pendente_manual' AND arquivo_url IS NOT NULL AND arquivo_url <> ''")->fetch(PDO::FETCH_ASSOC);
echo "  ainda recuperavel (<30d): " . ($r['recuperavel'] ?? 0) . "\n";
echo "  provavelmente perdido   : " . ($r['perdido'] ?? 0) . "\n";
echo "  total                   : " . ($r['total'] ?? 0) . "\n";

echo "\n--- 3. Motivo do pendente_manual ---\n";
$r = $pdo->query("SELECT
    SUM(cv.client_id IS NULL) sem_cliente,
    SUM(cv.client_id IS NOT NULL) com_cliente,
    COUNT(*) total
    FROM zapi_mensagens m JOIN zapi_conversas cv ON cv.id = m.conversa_id
    WHERE m.backup_status = 'pendente_manual' AND m.arquivo_url IS NOT NULL AND m.arquivo_url <> ''")->fetch(PDO::FETCH_ASSOC);
echo "  conversa SEM client_id        : " . ($r['sem_cliente'] ?? 0) . "\n";
echo "  tem cliente mas ficou pendente: " . ($r['com_cliente'] ?? 0) . "\n";

echo "\n--- 4. Os 22 casos vazios: existe midia de WhatsApp desses clientes? ---\n";
$q = $pdo->query("SELECT c.id case_id, c.client_id, cl.name, cl.phone,
                  (SELECT COUNT(*) FROM zapi_mensagens m
                     JOIN zapi_conversas cv ON cv.id = m.conversa_id
                    WHERE cv.client_id = c.client_id
                      AND m.arquivo_url IS NOT NULL AND m.arquivo_url <> '') total_midia,
                  (SELECT COUNT(*) FROM zapi_mensagens m
                     JOIN zapi_conversas cv ON cv.id = m.conversa_id
                    WHERE cv.client_id = c.client_id AND m.arquivo_salvo_drive = 1) no_drive
                  FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id
                  WHERE c.id IN ($in) ORDER BY total_midia DESC");
$comMidia = 0;
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if ((int)$r['total_midia'] > 0) { $comMidia++; }
    printf("  #%-5s client#%-5s midia_wa:%-4s no_drive:%-4s | %-28s %s\n",
        $r['case_id'], $r['client_id'] ?: '-', $r['total_midia'], $r['no_drive'],
        mb_substr((string)$r['name'], 0, 28), $r['phone']);
}
echo "\n  >> casos vazios que TEM midia no WhatsApp: $comMidia de " . count($vazias) . "\n";

echo "\n--- 5. Quem moveu o lead pra pasta_apta (pipeline_history) ---\n";
$q = $pdo->query("SELECT h.created_at, h.from_stage, h.to_stage, h.notes,
                  u.name AS quem, l.linked_case_id
                  FROM pipeline_history h
                  JOIN pipeline_leads l ON l.id = h.lead_id
                  LEFT JOIN users u ON u.id = h.changed_by
                  WHERE l.linked_case_id IN ($in) AND h.to_stage = 'pasta_apta'
                  ORDER BY h.created_at DESC");
$rows = $q->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { echo "  (nenhum registro)\n"; }
foreach ($rows as $r) {
    printf("  case#%-5s [%s] %-22s -> pasta_apta | por: %s\n",
        $r['linked_case_id'], substr((string)$r['created_at'],0,16),
        $r['from_stage'], $r['quem'] ?: '(desconhecido)');
}

echo "\n--- 6. Quem move pra pasta_apta em geral (ultimos 60d, todos os leads) ---\n";
$q = $pdo->query("SELECT u.name quem, COUNT(*) n
                  FROM pipeline_history h LEFT JOIN users u ON u.id = h.changed_by
                  WHERE h.to_stage = 'pasta_apta' AND h.created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                  GROUP BY h.changed_by ORDER BY n DESC");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  %-40s %s\n", $r['quem'] ?: '(desconhecido)', $r['n']);
}

echo "\n=== FIM ===\n";
