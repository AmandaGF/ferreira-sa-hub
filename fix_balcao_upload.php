<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== FIX: Mover arquivos de balcão virtual para diretório correto ===\n\n";

$origemDir = dirname(__DIR__) . '/salavip/uploads/';
$destDir = dirname(__DIR__) . '/salavip/uploads/ged/';

if (!is_dir($destDir)) { mkdir($destDir, 0755, true); echo "Criada pasta destino\n"; }

// 1. Buscar registros salavip_ged cujo arquivo está fora do /ged/ mas registrado
$rows = $pdo->query("SELECT id, arquivo_path FROM salavip_ged WHERE arquivo_path IS NOT NULL")->fetchAll();
echo "Total registros GED: " . count($rows) . "\n\n";

foreach ($rows as $r) {
    $src = $origemDir . $r['arquivo_path'];
    $dst = $destDir . $r['arquivo_path'];

    if (file_exists($dst)) {
        echo "[OK já no lugar] {$r['arquivo_path']}\n";
        continue;
    }

    if (file_exists($src)) {
        if (rename($src, $dst)) {
            echo "[MOVIDO] {$r['arquivo_path']}\n";
        } else {
            echo "[ERRO mover] {$r['arquivo_path']}\n";
        }
    } else {
        echo "[NÃO ACHADO] {$r['arquivo_path']}\n";
    }
}

// 2. Listar arquivos órfãos em /salavip/uploads/ (direto) que começam com 'balcao_' ou 'ged_'
echo "\n--- Arquivos em /salavip/uploads/ (raiz) ---\n";
if (is_dir($origemDir)) {
    $dh = opendir($origemDir);
    while (($f = readdir($dh)) !== false) {
        if ($f === '.' || $f === '..' || is_dir($origemDir . $f)) continue;
        if (strpos($f, 'balcao_') === 0 || strpos($f, 'ged_') === 0) {
            $dst = $destDir . $f;
            if (!file_exists($dst)) {
                if (rename($origemDir . $f, $dst)) {
                    echo "[ÓRFÃO MOVIDO] $f\n";
                }
            }
        }
    }
    closedir($dh);
}

echo "\n=== CONCLUÍDO ===\n";
