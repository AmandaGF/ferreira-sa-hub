<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== Simular query da Tabela (5 leads) — trazer onboard? ===\n";
$stmtT = $pdo->query("SELECT pl.id, pl.name, pl.onboard_realizado, pl.onboard_nao_precisa FROM pipeline_leads pl WHERE pl.converted_at IS NOT NULL ORDER BY pl.converted_at DESC LIMIT 5");
foreach ($stmtT as $r) {
    $onb = !empty($r['onboard_realizado']) ? 'sim' : (!empty($r['onboard_nao_precisa']) ? 'nao' : 'sem');
    printf("  #%d %s | onboard_realizado=%s onboard_nao_precisa=%s => data-onboard='%s'\n",
        $r['id'], substr($r['name'],0,25), $r['onboard_realizado'], $r['onboard_nao_precisa'], $onb);
}

echo "\n=== Contagem por estado ===\n";
$q = $pdo->query("SELECT
    SUM(onboard_realizado=1) AS sim,
    SUM(onboard_nao_precisa=1) AS nao,
    SUM(COALESCE(onboard_realizado,0)=0 AND COALESCE(onboard_nao_precisa,0)=0) AS sem,
    COUNT(*) AS tot
    FROM pipeline_leads WHERE converted_at IS NOT NULL")->fetch(PDO::FETCH_ASSOC);
print_r($q);

echo "\n=== Arquivo servido tem <select id=filterOnboard>? ===\n";
$path = __DIR__ . '/modules/pipeline/index.php';
$c = file_get_contents($path);
$mtime = date('Y-m-d H:i:s', filemtime($path));
echo "  mtime: $mtime\n";
echo "  tem filterOnboard: " . (strpos($c, 'filterOnboard') !== false ? 'SIM' : 'NAO') . "\n";
echo "  tem data-onboard: " . (strpos($c, 'data-onboard') !== false ? 'SIM' : 'NAO') . "\n";
echo "  tem pipSaveOnboard: " . (strpos($c, 'pipSaveOnboard') !== false ? 'SIM' : 'NAO') . "\n";

// Ver se opcache esta segurando bytecode antigo
if (function_exists('opcache_get_status')) {
    $s = opcache_get_status(false);
    echo "  opcache habilitado: " . ($s['opcache_enabled'] ? 'SIM' : 'NAO') . "\n";
    if (isset($s['scripts'][$path])) {
        $sc = $s['scripts'][$path];
        echo "  opcache tem esse arquivo? SIM (compilado " . date('Y-m-d H:i:s', $sc['last_used_timestamp']) . ")\n";
    }
    opcache_reset();
    echo "  opcache reset OK\n";
}
