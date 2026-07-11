<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$total   = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
$comUf   = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE address_state IS NOT NULL AND address_state <> ''")->fetchColumn();
$semUf   = $total - $comUf;
echo "Total de clients: $total\n";
echo "Com address_state:    $comUf\n";
echo "Sem UF:               $semUf\n\n";

echo "Distribuicao por UF:\n";
$stmt = $pdo->query("
    SELECT UPPER(TRIM(address_state)) uf, COUNT(*) q
    FROM clients
    WHERE address_state IS NOT NULL AND address_state <> ''
    GROUP BY uf ORDER BY q DESC");
foreach ($stmt as $r) echo "  " . str_pad($r['uf'], 4) . " -> " . $r['q'] . "\n";

echo "\nAmostras dos valores 'estranhos' (fora dos 27 UFs padrao):\n";
$ufsValidas = "'AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'";
$stmt = $pdo->query("SELECT DISTINCT address_state FROM clients WHERE address_state IS NOT NULL AND address_state <> '' AND UPPER(TRIM(address_state)) NOT IN ($ufsValidas) LIMIT 20");
foreach ($stmt as $r) echo "  [" . $r['address_state'] . "]\n";
