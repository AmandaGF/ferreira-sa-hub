<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// Verificar charset da conexão
$charset = $pdo->query("SHOW VARIABLES LIKE 'character_set_connection'")->fetch();
$collation = $pdo->query("SHOW VARIABLES LIKE 'collation_connection'")->fetch();
echo "Conexão charset: " . $charset['Value'] . "\n";
echo "Conexão collation: " . $collation['Value'] . "\n\n";

// Verificar charset da tabela
$tableCharset = $pdo->query("SHOW CREATE TABLE portal_links")->fetch();
echo "CREATE TABLE (trecho):\n";
$create = $tableCharset['Create Table'] ?? ($tableCharset[1] ?? '');
// Mostrar só a parte do charset
if (preg_match('/CHARSET=(\w+)/', $create, $m)) echo "Table charset: " . $m[1] . "\n";
if (preg_match('/COLLATE=(\w+)/', $create, $m)) echo "Table collate: " . $m[1] . "\n";

echo "\n--- Primeiros 5 links com acentos ---\n";
$rows = $pdo->query("SELECT id, title, category, HEX(SUBSTR(title,1,30)) as hex_title FROM portal_links WHERE title LIKE '%ã%' OR title LIKE '%é%' OR title LIKE '%ç%' OR title LIKE '%ó%' OR title LIKE '%í%' LIMIT 5")->fetchAll();
foreach ($rows as $r) {
    echo "#{$r['id']} | {$r['title']} | cat: {$r['category']} | hex: {$r['hex_title']}\n";
}

echo "\n--- Teste direto ---\n";
echo "UTF-8 test: ação çãéíóú\n";
echo "e() test: " . htmlspecialchars('Gestão de Clientes', ENT_QUOTES, 'UTF-8') . "\n";
