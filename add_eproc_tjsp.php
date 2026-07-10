<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// Amanda pediu link do e-Proc TJSP (novo sistema, alem do e-SAJ)
$novos = array(
    array('Tribunais - Sudeste', 'TJSP — São Paulo — e-Proc 1º Grau', 'https://eproc1g.tjsp.jus.br/eproc', 'e-Proc TJSP — 1º Grau (novo sistema)'),
    array('Tribunais - Sudeste', 'TJSP — São Paulo — e-Proc 2º Grau', 'https://eproc2g.tjsp.jus.br/eproc', 'e-Proc TJSP — 2º Grau (novo sistema)'),
);

$check  = $pdo->prepare("SELECT id FROM portal_links WHERE title = ? LIMIT 1");
$insert = $pdo->prepare(
    'INSERT INTO portal_links (category, title, url, username, password_encrypted, hint, audience, is_favorite, sort_order, created_by)
     VALUES (?, ?, ?, NULL, NULL, ?, "internal", 0, 999, ?)'
);

$userId = 1; // Amanda (admin)
$adicionados = 0;
foreach ($novos as $l) {
    $check->execute([$l[1]]);
    if ($check->fetchColumn()) {
        echo "JA EXISTE: $l[1]\n";
        continue;
    }
    $insert->execute([$l[0], $l[1], $l[2], $l[3], $userId]);
    echo "ADICIONADO: $l[1]\n";
    $adicionados++;
}
echo "\nTotal adicionados: $adicionados\n";
