<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

$htPath = dirname(__DIR__) . '/salavip/uploads/.htaccess';

// Remover htaccess completamente — a pasta de uploads de fotos pode ser pública
// (fotos de perfil não são confidenciais, e o nome é randomizado)
if (file_exists($htPath)) {
    unlink($htPath);
    echo "htaccess REMOVIDO de: $htPath\n";
} else {
    echo "htaccess não existia\n";
}

// Garantir permissões
$dir = dirname(__DIR__) . '/salavip/uploads/';
chmod($dir, 0755);
echo "Dir perms set: 755\n";

// Listar arquivos
$files = scandir($dir);
echo "Files in uploads: " . implode(', ', array_diff($files, ['.','..'])) . "\n";

// Limpar foto_path do cliente (foto foi perdida pelo deploy)
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();
$pdo->exec("UPDATE clients SET foto_path = NULL WHERE foto_path IS NOT NULL AND foto_path != ''");
echo "foto_path resetado para todos os clientes (foto será reenviada).\n";
