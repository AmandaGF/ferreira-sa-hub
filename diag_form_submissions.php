<?php
require_once __DIR__ . '/core/middleware.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$tipos = array('convivencia','gastos_pensao','despesas_mensais','cadastro_cliente','calculadora_lead');

echo "=== Resumo por tipo (form_submissions) ===\n";
foreach ($tipos as $t) {
    $st = $pdo->prepare("SELECT COUNT(*) AS n, MAX(created_at) AS ultimo FROM form_submissions WHERE form_type = ?");
    $st->execute(array($t));
    $r = $st->fetch();
    printf("  %-22s total=%-4d  ultimo=%s\n", $t, (int)$r['n'], $r['ultimo'] ?? '(nunca)');
}

echo "\n=== Ultimos 10 registros GLOBAIS (qualquer tipo) ===\n";
$st = $pdo->query(
    "SELECT id, form_type, created_at,
            COALESCE(NULLIF(client_name,''), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.nome')), '?') AS nome
     FROM form_submissions ORDER BY id DESC LIMIT 10"
);
foreach ($st->fetchAll() as $r) {
    echo "  {$r['created_at']}  #{$r['id']}  {$r['form_type']}  — " . substr((string)$r['nome'], 0, 60) . "\n";
}

echo "\n=== Convivencia + Gastos: detalhe dos ultimos 10 de CADA tipo ===\n";
foreach (array('convivencia','gastos_pensao','despesas_mensais') as $t) {
    echo "\n  --- $t ---\n";
    $st = $pdo->prepare(
        "SELECT id, created_at,
                COALESCE(NULLIF(client_name,''), JSON_UNQUOTE(JSON_EXTRACT(payload, '$.nome')), '?') AS nome
         FROM form_submissions WHERE form_type = ? ORDER BY id DESC LIMIT 10"
    );
    $st->execute(array($t));
    $rows = $st->fetchAll();
    if (!$rows) { echo "    (nenhum)\n"; continue; }
    foreach ($rows as $r) {
        echo "    {$r['created_at']}  #{$r['id']}  " . substr((string)$r['nome'], 0, 60) . "\n";
    }
}

echo "\n=== Total de registros desde 28/abril/2026 ===\n";
$st = $pdo->query("SELECT form_type, COUNT(*) AS n FROM form_submissions WHERE created_at >= '2026-04-28' GROUP BY form_type ORDER BY n DESC");
foreach ($st->fetchAll() as $r) {
    printf("  %-22s %d\n", $r['form_type'], $r['n']);
}
