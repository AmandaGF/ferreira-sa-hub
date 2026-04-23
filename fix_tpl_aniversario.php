<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') {
    http_response_code(403);
    exit('Chave inválida.');
}
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/database.php';
$pdo = db();
$dry = ($_GET['dry'] ?? '1') !== '0';

echo "=== TEMPLATES de aniversário ===\n";
echo "Modo: " . ($dry ? 'DRY-RUN' : 'EXECUTAR') . "\n\n";

$rows = $pdo->query("SELECT id, nome, atalho, conteudo FROM zapi_templates WHERE categoria = 'aniversario' ORDER BY id")->fetchAll();

foreach ($rows as $r) {
    echo "------ TPL #{$r['id']} — {$r['nome']} (atalho={$r['atalho']}) ------\n";
    echo $r['conteudo'] . "\n";
    echo "------ FIM ------\n\n";
}

echo "\n=== BUSCANDO 'Amanda' / 'Dra' / 'Dr.' nos templates ===\n";
$padroes = array(
    // padrões comuns de assinatura que devem virar "Equipe Ferreira & Sá Advocacia"
    '/Dra\.?\s*Amanda\s+Guedes\s+Ferreira[^\n]*/iu',
    '/Dra\.?\s*Amanda\s+Ferreira[^\n]*/iu',
    '/Dra\.?\s*Amanda[^\n]*/iu',
    '/Dr\.?\s*Amanda[^\n]*/iu',
    '/Amanda\s+Guedes\s+Ferreira[^\n]*/iu',
);
$substituicao = 'Equipe Ferreira & Sá Advocacia';

foreach ($rows as $r) {
    $novo = $r['conteudo'];
    $matched = false;
    foreach ($padroes as $p) {
        $novo2 = preg_replace($p, $substituicao, $novo);
        if ($novo2 !== $novo) { $novo = $novo2; $matched = true; }
    }
    // Normaliza múltiplos espaços e quebras
    $novo = preg_replace("/\n{3,}/", "\n\n", $novo);
    if ($matched) {
        echo "### TPL #{$r['id']} — {$r['nome']}: MUDA ###\n";
        echo "--- antes ---\n" . $r['conteudo'] . "\n";
        echo "--- depois ---\n" . $novo . "\n\n";
        if (!$dry) {
            $pdo->prepare("UPDATE zapi_templates SET conteudo = ? WHERE id = ?")
                ->execute(array($novo, $r['id']));
            echo "  → salvo.\n\n";
        }
    }
}

echo "\n=== FIM ===\n";
if ($dry) echo "\nPara executar de verdade: &dry=0\n";
