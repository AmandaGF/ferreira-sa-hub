<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
ini_set('display_errors','1');
error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

// 1. Listar categorias existentes pra escolher a melhor
echo "=== Categorias existentes ===\n";
$st = $pdo->query("SELECT category, COUNT(*) n FROM portal_links GROUP BY category ORDER BY category");
foreach ($st as $r) echo "  - {$r['category']} ({$r['n']} links)\n";
echo "\n";

// 2. Checa se ja existe esse link (evita duplicar)
$existe = $pdo->prepare("SELECT id, category, title FROM portal_links WHERE url = ? LIMIT 1");
$existe->execute(array('https://www.registrodeimoveis.org.br/calculadora?cod=clc326c6d'));
$jaExiste = $existe->fetch();

if ($jaExiste) {
    echo "✓ Link já cadastrado: #{$jaExiste['id']} em '{$jaExiste['category']}' como '{$jaExiste['title']}'\n";
    exit;
}

// 3. Insere
$catEscolhida = 'Cartórios e Tabelionatos'; // tentativa 1
// Se a categoria escolhida nao existe, tenta outras
$catsExistentes = array_column($pdo->query("SELECT DISTINCT category FROM portal_links")->fetchAll(PDO::FETCH_ASSOC), 'category');
$preferencias = array('Cartórios e Tabelionatos','Cálculos Jurídicos','Cálculos','Cartórios','Ferramentas','Consultas Públicas');
foreach ($preferencias as $tent) {
    if (in_array($tent, $catsExistentes, true)) { $catEscolhida = $tent; break; }
}
// fallback: usa categoria nova "Cartórios e Tabelionatos"
if (!in_array($catEscolhida, $catsExistentes, true)) {
    $catEscolhida = 'Cartórios e Tabelionatos';
}

$ins = $pdo->prepare("INSERT INTO portal_links
    (category, title, url, description, icon, audience, sort_order, created_at, updated_at)
    VALUES (?, ?, ?, ?, ?, 'internal', 0, NOW(), NOW())");
$ins->execute(array(
    $catEscolhida,
    'Cálculo de Emolumentos — Registro de Imóveis',
    'https://www.registrodeimoveis.org.br/calculadora?cod=clc326c6d',
    'Calculadora oficial de emolumentos do Registro de Imóveis (RI Brasil) — útil pra estimativa de custas em escrituras, registros, averbações, etc.',
    '🏠',
));
$novoId = (int)$pdo->lastInsertId();
echo "✓ Link cadastrado: #$novoId em categoria '$catEscolhida'\n";
