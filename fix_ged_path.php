<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Debug GED ===\n\n";

// Ver docs no GED
$docs = $pdo->query("SELECT id, cliente_id, titulo, arquivo_path, arquivo_nome, categoria FROM salavip_ged ORDER BY id DESC LIMIT 5")->fetchAll();
echo "Docs no GED: " . count($docs) . "\n";
foreach ($docs as $d) {
    echo "#" . $d['id'] . " cliente=" . $d['cliente_id'] . " path=" . $d['arquivo_path'] . " nome=" . $d['arquivo_nome'] . " cat=" . $d['categoria'] . " titulo=" . $d['titulo'] . "\n";
}

// Verificar caminhos possíveis
echo "\n=== Verificar caminhos ===\n";
$paths = [
    'conecta/salavip/uploads/ged/',
    'salavip/uploads/ged/',
    'salavip/uploads/',
    'conecta/uploads/ged/',
];
$baseDir = dirname(__DIR__);
foreach ($paths as $p) {
    $full = $baseDir . '/' . $p;
    echo "$p => " . (is_dir($full) ? "EXISTE" : "NÃO EXISTE") . "\n";
    if (is_dir($full)) {
        $files = array_diff(scandir($full), ['.','..']);
        echo "  Arquivos: " . implode(', ', $files) . "\n";
    }
}

// O que o download_ged.php usa como caminho
echo "\n=== download_ged.php path logic ===\n";
$dlFile = $baseDir . '/salavip/api/download_ged.php';
if (file_exists($dlFile)) {
    $content = file_get_contents($dlFile);
    // Encontrar a linha com o caminho
    foreach (explode("\n", $content) as $i => $line) {
        if (stripos($line, 'upload') !== false || stripos($line, 'path') !== false || stripos($line, 'arquivo') !== false) {
            echo "L" . ($i+1) . ": " . trim($line) . "\n";
        }
    }
}

// O que o ged.php do Conecta usa como uploadDir
echo "\n=== ged.php (Conecta) upload dir ===\n";
$gedFile = __DIR__ . '/modules/salavip/ged.php';
foreach (explode("\n", file_get_contents($gedFile)) as $i => $line) {
    if (stripos($line, 'uploadDir') !== false || stripos($line, 'uploads') !== false) {
        echo "L" . ($i+1) . ": " . trim($line) . "\n";
    }
}
