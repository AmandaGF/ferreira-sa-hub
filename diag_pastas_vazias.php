<?php
// Por que pasta vazia virou "Pasta Apta"? E onde estao os documentos do cliente?
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Casos auditados como pasta VAZIA (sem documento do cliente)
$vazias = [575,605,909,626,881,606,572,595,616,593,1216,1555,1569,1578,1579,1211,1210,584,615,1577,562,574];

echo "=== POR QUE PASTA VAZIA VIROU 'PASTA APTA'? (" . date('d/m/Y H:i') . ") ===\n";
echo "Casos auditados como vazios: " . count($vazias) . "\n\n";

$in = implode(',', array_map('intval', $vazias));

echo "--- 1. Checklist de documentos por caso ---\n";
foreach ($vazias as $id) {
    $s = $pdo->prepare("SELECT status, COUNT(*) n FROM documentos_pendentes WHERE case_id = ? GROUP BY status");
    $s->execute([$id]);
    $r = $s->fetchAll(PDO::FETCH_KEY_PAIR);
    $t = $pdo->prepare("SELECT COUNT(*) FROM case_tasks WHERE case_id = ? AND tipo IS NULL AND status IN ('pendente','a_fazer')");
    $t->execute([$id]);
    $pend = $t->fetchColumn();
    printf("  #%-5s docs_pendentes: pendente=%-3s recebido=%-3s | tarefas de checklist em aberto: %s\n",
        $id, $r['pendente'] ?? 0, $r['recebido'] ?? 0, $pend);
}

echo "\n--- 2. Lead vinculado: quem marcou pasta_apta e quando ---\n";
$q = $pdo->query("SELECT c.id AS case_id, c.title, c.status AS case_status,
                  l.id AS lead_id, l.stage, l.updated_at AS lead_updated
                  FROM cases c LEFT JOIN pipeline_leads l ON l.linked_case_id = c.id
                  WHERE c.id IN ($in) ORDER BY c.id");
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("  #%-5s lead:%-6s stage:%-20s lead_upd:%s | %s\n",
        $r['case_id'], $r['lead_id'] ?: '-', $r['stage'] ?: '(sem lead)',
        $r['lead_updated'] ?: '-', mb_substr((string)$r['title'], 0, 40));
}

echo "\n--- 3. Historico do pipeline (quem moveu pra pasta_apta) ---\n";
try {
    $q = $pdo->query("SELECT h.*, l.linked_case_id FROM pipeline_history h
                      JOIN pipeline_leads l ON l.id = h.lead_id
                      WHERE l.linked_case_id IN ($in) AND (h.to_stage = 'pasta_apta' OR h.new_stage = 'pasta_apta')
                      ORDER BY h.id DESC LIMIT 30");
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) { echo "  (nada em pipeline_history)\n"; }
    foreach ($rows as $r) {
        echo "  case#{$r['linked_case_id']} | " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Exception $e) { echo "  ERRO/tabela ausente: " . $e->getMessage() . "\n"; }

echo "\n--- 4. Audit log de mudanca de status desses casos ---\n";
try {
    $q = $pdo->query("SELECT a.created_at, a.user_id, u.name AS user_name, a.action, a.details
                      FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                      WHERE a.details LIKE '%em_elaboracao%'
                      ORDER BY a.id DESC LIMIT 15");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  [{$r['created_at']}] " . ($r['user_name'] ?: 'user#'.$r['user_id']) . " | {$r['action']} | "
             . mb_substr((string)$r['details'], 0, 120) . "\n";
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- 5. ONDE ESTAO OS DOCUMENTOS? Midia de WhatsApp por cliente ---\n";
try {
    $q = $pdo->query("SELECT c.id AS case_id, c.client_id, cl.name, cl.phone,
                      (SELECT COUNT(*) FROM zapi_mensagens m
                       JOIN zapi_conversas cv ON cv.id = m.conversa_id
                       WHERE cv.client_id = c.client_id AND m.media_url IS NOT NULL) AS midias
                      FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id
                      WHERE c.id IN ($in) ORDER BY midias DESC");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("  #%-5s client#%-5s midias_wa: %-4s | %s (%s)\n",
            $r['case_id'], $r['client_id'], $r['midias'], mb_substr((string)$r['name'],0,30), $r['phone']);
    }
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n--- 6. Backup de arquivos do WhatsApp (a pendencia dos 199) ---\n";
try {
    $q = $pdo->query("SELECT backup_status, COUNT(*) n, MIN(created_at) mais_antiga, MAX(created_at) mais_nova
                      FROM zapi_mensagens
                      WHERE media_url IS NOT NULL AND media_url <> ''
                      GROUP BY backup_status ORDER BY n DESC");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("  %-16s %-5s | de %s ate %s\n", $r['backup_status'] ?? '(null)', $r['n'],
            substr((string)$r['mais_antiga'],0,10), substr((string)$r['mais_nova'],0,10));
    }

    echo "\n  >> pendente_manual por idade (link Z-API expira em 30 dias):\n";
    $q = $pdo->query("SELECT
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS ainda_recuperavel,
        SUM(created_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)) AS provavelmente_perdido,
        COUNT(*) total
        FROM zapi_mensagens
        WHERE backup_status = 'pendente_manual' AND media_url IS NOT NULL");
    $r = $q->fetch(PDO::FETCH_ASSOC);
    echo "     ainda recuperavel (<30d): " . ($r['ainda_recuperavel'] ?? 0) . "\n";
    echo "     provavelmente perdido   : " . ($r['provavelmente_perdido'] ?? 0) . "\n";
    echo "     total pendente_manual   : " . ($r['total'] ?? 0) . "\n";

    echo "\n  >> motivo do pendente_manual (conversa sem cliente vs cliente sem pasta):\n";
    $q = $pdo->query("SELECT
        SUM(cv.client_id IS NULL) AS conversa_sem_cliente,
        SUM(cv.client_id IS NOT NULL) AS tem_cliente_mas_pendente,
        COUNT(*) total
        FROM zapi_mensagens m
        JOIN zapi_conversas cv ON cv.id = m.conversa_id
        WHERE m.backup_status = 'pendente_manual' AND m.media_url IS NOT NULL");
    $r = $q->fetch(PDO::FETCH_ASSOC);
    echo "     conversa sem client_id       : " . ($r['conversa_sem_cliente'] ?? 0) . "\n";
    echo "     tem cliente mas ficou pendente: " . ($r['tem_cliente_mas_pendente'] ?? 0) . "\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
