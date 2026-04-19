<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== BREAKDOWN INADIMPLÊNCIA (apenas OVERDUE) ===\n\n";

$anos = $pdo->query("SELECT YEAR(vencimento) AS ano, COUNT(*) AS qtd, SUM(valor) AS total
                      FROM asaas_cobrancas WHERE status = 'OVERDUE' GROUP BY ano ORDER BY ano")->fetchAll();
echo "Por ano de vencimento:\n";
foreach ($anos as $r) {
    echo sprintf("  %s:  %3d cobranças  R$ %11s\n", $r['ano'], $r['qtd'], number_format($r['total'],2,',','.'));
}

echo "\n--- USUÁRIOS (todos, ativos ou não) ---\n";
try {
    $stmt = $pdo->prepare("SELECT id, name, email, role, active FROM users ORDER BY name");
    $stmt->execute();
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Total: " . count($all) . "\n";
    foreach ($all as $r) {
        echo sprintf("id=%-3s  %-35s  %-42s  role=%-12s active=%s\n",
            $r['id'] ?? '?', $r['name'] ?? '?', $r['email'] ?? '?', $r['role'] ?? '?', $r['active'] ?? '?');
    }
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}
