<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

echo "=== CASE Felipe Maria x Alimentos ===\n";
$c = $pdo->query("SELECT id, title, status FROM cases WHERE title LIKE '%Felipe Maria%' OR title LIKE '%Felipe José%Alimentos%' LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
foreach ($c as $r) print_r($r);
if (empty($c)) { echo "  Nao encontrado por nome parcial. Tentando outro...\n"; }

echo "\n=== Case_id=? RENUNCIAS registradas ===\n";
foreach ($c as $r) {
    echo "-- case #{$r['id']}: {$r['title']} --\n";
    $rs = $pdo->prepare("SELECT id, tipo, motivo, comprovante_path, created_at FROM renuncias WHERE case_id = ?");
    $rs->execute(array($r['id']));
    $ren = $rs->fetchAll();
    if (empty($ren)) echo "  (sem registros em renuncias)\n";
    foreach ($ren as $x) print_r($x);
}

// Verificar tambem se tabela tem coluna pipeline_leads.gerid_positivo (o carimbo)
echo "\n=== pipeline_leads.gerid_positivo ===\n";
try {
    $co = $pdo->query("SHOW COLUMNS FROM pipeline_leads LIKE 'gerid_positivo'")->fetch();
    if ($co) print_r($co);
    else echo "  Coluna nao existe ainda — self-heal do gerid nao rodou.\n";
} catch (Exception $e) { echo "erro: " . $e->getMessage() . "\n"; }

echo "\n=== Testar tabela pipeline_leads schema onboard ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM pipeline_leads LIKE '%onboard%'") as $r) echo "  " . $r['Field'] . " (" . $r['Type'] . ")\n";
