<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Garante que o self-heal disparou (idempotente — ignorado se já existe)
try { $pdo->exec("ALTER TABLE agenda_eventos ADD COLUMN subtipo VARCHAR(40) DEFAULT NULL AFTER tipo"); echo "  + coluna subtipo criada agora\n"; } catch (Exception $e) { echo "  - coluna subtipo ja existe (ok)\n"; }
try { $pdo->exec("ALTER TABLE agenda_eventos ADD INDEX idx_subtipo (subtipo)"); echo "  + index idx_subtipo criado agora\n"; } catch (Exception $e) { echo "  - index idx_subtipo ja existe (ok)\n"; }
echo "\n";

echo "=== SHOW CREATE TABLE agenda_eventos ===\n";
$row = $pdo->query("SHOW CREATE TABLE agenda_eventos")->fetch(PDO::FETCH_ASSOC);
echo ($row['Create Table'] ?? array_values($row)[1]) . "\n\n";

echo "=== Coluna subtipo presente? ===\n";
$cols = $pdo->query("SHOW COLUMNS FROM agenda_eventos LIKE 'subtipo'")->fetchAll();
echo "  " . (count($cols) ? "SIM (" . $cols[0]['Type'] . ", default=" . var_export($cols[0]['Default'], true) . ")" : "NAO") . "\n\n";

echo "=== Indice idx_subtipo presente? ===\n";
$idx = $pdo->query("SHOW INDEX FROM agenda_eventos WHERE Key_name = 'idx_subtipo'")->fetchAll();
echo "  " . (count($idx) ? "SIM" : "NAO") . "\n\n";

echo "=== Query Marina (dias=30) — primeiros 5 ===\n";
$st = $pdo->prepare(
    "SELECT c.id, c.case_number, c.title, c.comarca, c.responsible_user_id,
            u.name AS responsavel_nome,
            MAX(a.data_andamento) AS ultimo_andamento,
            DATEDIFF(NOW(), MAX(a.data_andamento)) AS dias_parado
     FROM cases c
     LEFT JOIN case_andamentos a ON a.case_id = c.id
     LEFT JOIN users u ON u.id = c.responsible_user_id
     WHERE c.sistema_tribunal = 'PJe'
       AND c.comarca_uf = 'RJ'
       AND c.status NOT IN ('arquivado','cancelado','concluido','renunciamos')
       AND IFNULL(c.kanban_oculto, 0) = 0
     GROUP BY c.id
     HAVING ultimo_andamento IS NULL OR DATEDIFF(NOW(), ultimo_andamento) > ?
     ORDER BY dias_parado DESC
     LIMIT 5"
);
$st->execute(array(30));
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "  #{$r['id']}  {$r['case_number']}  ultimo={$r['ultimo_andamento']}  dias={$r['dias_parado']}  resp={$r['responsavel_nome']}\n";
}
