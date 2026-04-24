<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('x'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Ver se intake_visitas existe no mesmo banco
$q = $pdo->query("SHOW TABLES LIKE 'intake_visitas'");
$existe = $q->fetch();
echo "intake_visitas no mesmo banco? " . ($existe ? 'SIM ✓' : 'NÃO ✗') . "\n\n";

if ($existe) {
    // Lista últimas 30 entradas após 01/04
    $q = $pdo->query("SELECT id, protocol, client_name, client_phone, client_email, relationship_role, created_at FROM intake_visitas WHERE created_at > '2026-04-01' ORDER BY id DESC");
    $res = $q->fetchAll();
    echo "=== Entradas em intake_visitas DEPOIS de 01/04 (perdidas no Hub) ===\n";
    echo "Total: " . count($res) . "\n\n";
    foreach ($res as $r) {
        echo "#{$r['id']} [{$r['protocol']}] {$r['created_at']}\n";
        echo "  {$r['client_name']} — tel={$r['client_phone']} — {$r['relationship_role']}\n";
    }
} else {
    echo "Vamos tentar descobrir em qual banco/config está...\n";
    // Ver se config.php do submit usa outro PDO
    $configPath = dirname(__DIR__) . '/convivencia_form/config.php';
    if (file_exists($configPath)) {
        $c = file_get_contents($configPath);
        echo "=== config.php do convivencia_form ===\n";
        // Extrai info de banco sem expor senha
        if (preg_match('/DB_NAME[^;]+/', $c, $m)) echo $m[0] . "\n";
        if (preg_match('/DB_USER[^;]+/', $c, $m)) echo $m[0] . "\n";
        if (preg_match('/DB_HOST[^;]+/', $c, $m)) echo $m[0] . "\n";
        echo "(senha omitida)\n";
    }
}
