<?php
// Diagnóstico da tocada peticao_distribuida — Amanda 11/07 relatou que nao tocou
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();

echo "=== DIAG JORJAO peticao_distribuida ===\n\n";

// 1. Cases atualizados nas últimas 6h com case_number
echo "-- Cases atualizados nas ultimas 6h com case_number --\n";
$st = $pdo->query("SELECT id, title, status, case_number, jorjao_distribuicao_tocado,
                          created_at, updated_at
                   FROM cases
                   WHERE updated_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                   ORDER BY updated_at DESC LIMIT 30");
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    printf("  #%d [%s] tocado=%s cn=%s status=%s\n     '%s' (upd %s)\n",
        $r['id'], substr($r['created_at'],0,10),
        $r['jorjao_distribuicao_tocado'], ($r['case_number'] ?: 'VAZIO'),
        $r['status'], substr($r['title'],0,60), $r['updated_at']);
}
if (empty($rows)) echo "  (nenhum case atualizado nas ultimas 6h)\n";

// 2. Todos os cases com jorjao_distribuicao_tocado=0 (candidatos)
echo "\n-- Candidatos vivos (tocado=0) --\n";
$st = $pdo->query("SELECT COUNT(*) FROM cases WHERE jorjao_distribuicao_tocado = 0");
echo "  Total: " . (int)$st->fetchColumn() . "\n";

$st = $pdo->query("SELECT COUNT(*) FROM cases WHERE jorjao_distribuicao_tocado = 0
                    AND ((case_number IS NOT NULL AND case_number <> '') OR status = 'em_andamento')
                    AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
echo "  Que passariam no filtro do cron: " . (int)$st->fetchColumn() . "\n";

// 3. Cases já tocados nas últimas 24h
echo "\n-- Cases ja tocados nas ultimas 24h (jorjao_distribuicao_tocado_em) --\n";
try {
    $st = $pdo->query("SELECT id, title, case_number, jorjao_distribuicao_tocado_em
                       FROM cases
                       WHERE jorjao_distribuicao_tocado_em >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                       ORDER BY jorjao_distribuicao_tocado_em DESC LIMIT 10");
    foreach ($st as $r) {
        printf("  #%d cn=%s em %s '%s'\n", $r['id'], ($r['case_number']?:'-'),
            $r['jorjao_distribuicao_tocado_em'], substr($r['title'],0,60));
    }
} catch (Exception $e) { echo "  (coluna _em nao existe): " . $e->getMessage() . "\n"; }

// 4. Últimas mensagens Zapi mandadas pro grupo do Jorjão (canal 24)
echo "\n-- Ultimas msgs Zapi canal 24 nas ultimas 6h --\n";
try {
    $st = $pdo->query("SELECT m.direcao, m.texto, m.created_at, co.numero, co.nome_contato
                       FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                       WHERE co.canal = '24' AND m.direcao = 'enviada'
                         AND m.created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                       ORDER BY m.created_at DESC LIMIT 10");
    foreach ($st as $r) {
        printf("  %s [%s / %s]\n    %s\n", $r['created_at'], $r['numero'],
            substr($r['nome_contato'],0,30), substr($r['texto'],0,120));
    }
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }

// 5. Audit_log recente do Jorjão
echo "\n-- Audit_log jorjao_* ultimas 6h --\n";
try {
    $st = $pdo->query("SELECT created_at, action, target_type, target_id, details
                       FROM audit_log
                       WHERE action LIKE '%jorjao%' AND created_at >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                       ORDER BY created_at DESC LIMIT 20");
    foreach ($st as $r) {
        printf("  %s %s (%s#%d) %s\n", $r['created_at'], $r['action'],
            $r['target_type'], $r['target_id'], substr($r['details'],0,150));
    }
} catch (Exception $e) { echo "  erro: " . $e->getMessage() . "\n"; }

echo "\n=== FIM ===\n";
