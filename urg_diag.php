<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== COLUNAS urgencia em cases ===\n";
foreach ($pdo->query("DESCRIBE cases") as $r) {
    if (strpos($r['Field'], 'urgencia') !== false) printf("  %s (%s) default=%s\n", $r['Field'], $r['Type'], $r['Default']);
}
echo "\n=== CASES COM URGENCIA=1 e NÃO RESOLVIDA ===\n";
try {
    $q = $pdo->query("SELECT cs.id, cs.title, cs.urgencia_operacional, cs.urgencia_operacional_desc,
                             cs.urgencia_operacional_em, cs.urgencia_operacional_resolvido_em,
                             cs.urgencia_operacional_por, u.name pediu_por, cl.name cliente
                      FROM cases cs
                      LEFT JOIN clients cl ON cl.id = cs.client_id
                      LEFT JOIN users u ON u.id = cs.urgencia_operacional_por
                      WHERE cs.urgencia_operacional = 1");
    $n = 0;
    foreach ($q as $r) {
        $n++;
        printf("  case#%d %s\n", $r['id'], $r['title']);
        printf("    flag=%d em=%s por=%s(%s) resolvido=%s\n",
            $r['urgencia_operacional'], $r['urgencia_operacional_em'],
            $r['urgencia_operacional_por'], $r['pediu_por'], $r['urgencia_operacional_resolvido_em'] ?: 'NULL');
        printf("    desc: %s\n", $r['urgencia_operacional_desc']);
        printf("    cliente: %s\n", $r['cliente']);
    }
    echo "  Total: $n\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }

echo "\n=== TESTE QUERY DO BANNER (mesma do layout_start.php) ===\n";
try {
    $q = $pdo->query("SELECT cs.id, cs.title, cs.urgencia_operacional_desc,
                             TIMESTAMPDIFF(MINUTE, cs.urgencia_operacional_em, NOW()) mins,
                             cl.name AS client_name, u.name AS pediu_por
                      FROM cases cs
                      LEFT JOIN clients cl ON cl.id = cs.client_id
                      LEFT JOIN users u ON u.id = cs.urgencia_operacional_por
                      WHERE cs.urgencia_operacional = 1
                        AND cs.urgencia_operacional_resolvido_em IS NULL
                      ORDER BY cs.urgencia_operacional_em DESC LIMIT 10");
    $n = 0;
    foreach ($q as $r) {
        $n++;
        printf("  case#%d %s pediu %d min atras por %s\n", $r['id'], $r['client_name']?:$r['title'], $r['mins'], $r['pediu_por']);
    }
    echo "  Total: $n\n";
    if ($n == 0) echo "  ⚠ Query nao retornou nada — banner nao aparecera\n";
} catch (Exception $e) { echo "  ERRO: " . $e->getMessage() . "\n"; }
