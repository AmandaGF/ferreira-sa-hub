<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Negado.'); }
ini_set('display_errors', '1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Test em_risco.php query ===\n\n";

echo "1) Self-heals\n";
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_snooze_ate DATE NULL"); echo "  add snooze_ate\n"; } catch (Exception $e) { echo "  ja existe snooze_ate\n"; }
try { $pdo->exec("ALTER TABLE clients ADD COLUMN esfriando_snooze_por INT NULL"); echo "  add snooze_por\n"; } catch (Exception $e) { echo "  ja existe snooze_por\n"; }

echo "\n2) Query principal (filtro=em_risco)\n";
try {
    $whereSql = "1=1 AND EXISTS (SELECT 1 FROM cases cs WHERE cs.client_id = c.id AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido') AND COALESCE(cs.kanban_oculto,0) = 0) AND COALESCE(c.esfriando_score,0) >= 40 AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())";
    $sql = "SELECT c.id, c.name, c.phone, c.esfriando_score, c.esfriando_motivos, c.esfriando_em,
                   c.esfriando_snooze_ate, c.esfriando_snooze_por,
                   u.name AS snooze_por_name,
                   (SELECT cs.id FROM cases cs WHERE cs.client_id = c.id
                      AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido')
                      AND COALESCE(cs.kanban_oculto,0)=0
                    ORDER BY cs.updated_at DESC LIMIT 1) AS principal_case_id,
                   (SELECT COUNT(*) FROM cases cs WHERE cs.client_id = c.id
                      AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido')
                      AND COALESCE(cs.kanban_oculto,0)=0) AS qtd_cases_ativos
            FROM clients c
            LEFT JOIN users u ON u.id = c.esfriando_snooze_por
            WHERE $whereSql
            ORDER BY COALESCE(c.esfriando_score,0) DESC, c.name ASC
            LIMIT 200";
    $st = $pdo->query($sql);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    echo "  OK — " . count($rows) . " rows\n";
} catch (Throwable $e) {
    echo "  ERRO: " . $e->getMessage() . "\n";
    echo "  Line: " . $e->getLine() . " File: " . $e->getFile() . "\n";
}

echo "\n3) Contagens\n";
$baseCnt = "FROM clients c WHERE EXISTS (SELECT 1 FROM cases cs WHERE cs.client_id = c.id AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido') AND COALESCE(cs.kanban_oculto,0)=0)";
foreach (array(
    'em_risco' => "AND COALESCE(c.esfriando_score,0) >= 40 AND (c.esfriando_snooze_ate IS NULL OR c.esfriando_snooze_ate < CURDATE())",
    'todos'    => "",
) as $k => $cond) {
    try {
        $n = (int)$pdo->query("SELECT COUNT(*) $baseCnt $cond")->fetchColumn();
        echo "  $k: $n\n";
    } catch (Throwable $e) {
        echo "  $k ERRO: " . $e->getMessage() . "\n";
    }
}

echo "\n=== FIM ===\n";
