<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Check foto_path do cliente 447
$r = $pdo->query("SELECT id, name, foto_path FROM clients WHERE id = 447")->fetch();
echo "Cliente: " . $r['name'] . "\n";
echo "foto_path: " . ($r['foto_path'] ?: 'NULL') . "\n\n";

// Check uploads dir
$uploadsDir = dirname(__DIR__) . '/salavip/uploads/';
echo "Uploads dir: $uploadsDir\n";
echo "Exists: " . (is_dir($uploadsDir) ? 'YES' : 'NO') . "\n";

// List files
if (is_dir($uploadsDir)) {
    $files = scandir($uploadsDir);
    echo "Files: " . implode(', ', $files) . "\n";
}

// Check .htaccess
$htaccess = $uploadsDir . '.htaccess';
echo "\n.htaccess content:\n";
echo file_exists($htaccess) ? file_get_contents($htaccess) : 'NOT FOUND';
