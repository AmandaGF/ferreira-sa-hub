<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Move o link 531 pra 'Direito Imobiliário' (categoria que já existia)
$pdo->prepare("UPDATE portal_links SET category = ? WHERE id = 531")
    ->execute(array('Direito Imobiliário'));

$row = $pdo->query("SELECT id, category, title, url FROM portal_links WHERE id = 531")->fetch();
echo "✓ Atualizado:\n";
echo "  #{$row['id']}\n";
echo "  Categoria: {$row['category']}\n";
echo "  Título: {$row['title']}\n";
echo "  URL: {$row['url']}\n";
