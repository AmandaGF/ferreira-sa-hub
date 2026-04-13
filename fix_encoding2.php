<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Fix residual: ê incorretos ===\n";

// Agora os textos têm 'ê' onde deveria ser outros acentos. Vou corrigir por contexto:
$rows = $pdo->query("SELECT id, descricao FROM case_andamentos WHERE case_id = 734")->fetchAll();
$fixed = 0;
foreach ($rows as $r) {
    $text = $r['descricao'];
    $patterns = array(
        'Ministêrio' => 'Ministério', 'ministêrio' => 'ministério',
        'salêrio' => 'salário', 'mênimo' => 'mínimo',
        'Nê ' => 'Nº ', 'nê ' => 'nº ',
        'deveré' => 'deverá',
        'DECISêO' => 'DECISÃO',
        'justiêa' => 'justiça',
        'Pêblico' => 'Público', 'pêblico' => 'público',
        'resistência' => 'resistência',
        ' rê ' => ' ré ',
        'apelaêão' => 'apelação',
        'informaêão' => 'informação',
        'prestaêão' => 'prestação',
        'comprovanêo' => 'comprovação',
        'êrea' => 'área',
        ' nê ' => ' nº ',
    );
    $new = str_replace(array_keys($patterns), array_values($patterns), $text);
    if ($new !== $text) {
        $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?")->execute(array($new, $r['id']));
        echo "#" . $r['id'] . " corrigido\n";
        $fixed++;
    }
}
echo "Total corrigidos: $fixed\n";

// Verificar se sobrou algo
echo "\n=== Verificação ===\n";
$check = $pdo->query("SELECT id, LEFT(descricao, 150) as trecho FROM case_andamentos WHERE case_id = 734 AND (descricao LIKE '%ê%') ORDER BY id LIMIT 5")->fetchAll();
foreach ($check as $c) {
    echo "#" . $c['id'] . ": " . $c['trecho'] . "\n\n";
}
