<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== DIAG USUÁRIOS ATIVOS (dados brutos do admin treinamento) ===\n\n";

$sql = "SELECT u.id, u.name, u.role, u.setor,
               LENGTH(u.name) AS len_name,
               CHAR_LENGTH(u.name) AS char_name,
               COUNT(CASE WHEN p.concluido = 1 THEN 1 END) AS concluidos,
               MAX(p.updated_at) AS ultimo_acesso,
               COALESCE(SUM(p.pontos_ganhos), 0) AS pontos
        FROM users u
        LEFT JOIN treinamento_progresso p ON p.user_id = u.id
        WHERE u.is_active = 1
        GROUP BY u.id
        ORDER BY concluidos DESC, pontos DESC";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll();

echo "Total usuários ativos: " . count($rows) . "\n\n";

foreach ($rows as $r) {
    echo "id=" . $r['id']
       . " | name=[" . $r['name'] . "]"
       . " | LEN=" . $r['len_name']
       . " | CHAR=" . $r['char_name']
       . " | role=[" . ($r['role'] ?? 'null') . "]"
       . " | setor=[" . ($r['setor'] ?? 'null') . "]"
       . " | concluidos=" . $r['concluidos']
       . " | ultimo_acesso=" . ($r['ultimo_acesso'] ?? 'null')
       . " | pontos=" . $r['pontos']
       . "\n";
}

echo "\n=== Encoding da conexão ===\n";
$enc = $pdo->query("SHOW VARIABLES LIKE 'character_set_connection'")->fetch();
echo "character_set_connection: " . ($enc['Value'] ?? '?') . "\n";
$col = $pdo->query("SHOW VARIABLES LIKE 'collation_connection'")->fetch();
echo "collation_connection: " . ($col['Value'] ?? '?') . "\n";

echo "\n=== Schema da coluna name ===\n";
$desc = $pdo->query("SHOW COLUMNS FROM users LIKE 'name'")->fetch();
print_r($desc);
