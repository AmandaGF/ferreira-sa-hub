<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Verificar processos do cliente 447 (Amanda)
echo "=== Processos do cliente 447 ===\n";
$rows = $pdo->query("SELECT id, title, status, salavip_ativo FROM cases WHERE client_id = 447 ORDER BY id DESC LIMIT 10")->fetchAll();
foreach ($rows as $r) {
    echo "#" . $r['id'] . " salavip=" . $r['salavip_ativo'] . " status=" . $r['status'] . " :: " . $r['title'] . "\n";
}

// Verificar se a coluna existe
echo "\n=== Coluna salavip_ativo ===\n";
$col = $pdo->query("SHOW COLUMNS FROM cases LIKE 'salavip_ativo'")->fetch();
echo $col ? "Existe: " . $col['Type'] . " Default=" . $col['Default'] : "NÃO EXISTE";
echo "\n";

// Verificar toggle_salavip na API
echo "\n=== Verificando API handler ===\n";
$apiFile = __DIR__ . '/modules/operacional/api.php';
echo (strpos(file_get_contents($apiFile), 'toggle_salavip') !== false) ? "toggle_salavip: ENCONTRADO" : "toggle_salavip: NÃO ENCONTRADO";
echo "\n";

// Verificar botão no caso_ver
echo "\n=== Verificando botão no caso_ver ===\n";
$verFile = __DIR__ . '/modules/operacional/caso_ver.php';
echo (strpos(file_get_contents($verFile), 'salavip_ativo') !== false) ? "salavip_ativo: ENCONTRADO no caso_ver" : "salavip_ativo: NÃO ENCONTRADO no caso_ver";
echo "\n";
