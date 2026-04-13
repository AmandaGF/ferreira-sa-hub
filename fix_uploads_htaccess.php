<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Vincular a foto existente ao cliente 447
$dir = dirname(__DIR__) . '/salavip/uploads/';
$files = glob($dir . 'foto_447_*');
echo "Fotos encontradas: " . count($files) . "\n";
foreach ($files as $f) {
    $filename = basename($f);
    echo "  $filename (" . filesize($f) . " bytes)\n";
    $pdo->prepare("UPDATE clients SET foto_path = ? WHERE id = 447")->execute([$filename]);
    echo "  → Vinculada ao cliente 447\n";
}

// Testar acesso direto
$testUrl = "https://www.ferreiraesa.com.br/salavip/uploads/" . basename($files[0] ?? '');
echo "\nURL: $testUrl\n";
echo "Test curl: ";
$ch = curl_init($testUrl);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true]);
curl_exec($ch);
echo "HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
curl_close($ch);
