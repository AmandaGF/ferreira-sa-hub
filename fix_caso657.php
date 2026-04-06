<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave invalida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Caso #657 tem court=1ª Vara de Família mas sem comarca/uf/sistema
// Copiar dados faltantes do caso #675 (mesmo processo)
$pdo->prepare("UPDATE cases SET
    comarca = COALESCE(NULLIF(comarca,''), 'Barra Mansa'),
    comarca_uf = COALESCE(NULLIF(comarca_uf,''), 'RJ'),
    sistema_tribunal = COALESCE(NULLIF(sistema_tribunal,''), 'PJE')
    WHERE id = 657")->execute();

echo "Caso #657 atualizado com comarca/UF/sistema.\n";

// Verificar
$r = $pdo->query("SELECT id, title, comarca, comarca_uf, sistema_tribunal FROM cases WHERE id IN (657, 675) ORDER BY id")->fetchAll();
foreach ($r as $row) {
    echo "#{$row['id']} {$row['title']} | comarca={$row['comarca']} uf={$row['comarca_uf']} sistema={$row['sistema_tribunal']}\n";
}
echo "\n=== FEITO ===\n";
