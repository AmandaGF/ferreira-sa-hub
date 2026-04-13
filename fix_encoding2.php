<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Fix residual: RêU e outros ===\n";
$rows = $pdo->query("SELECT id, descricao FROM case_andamentos WHERE case_id = 734")->fetchAll();
$fixed = 0;
foreach ($rows as $r) {
    $text = $r['descricao'];
    $patterns = array(
        'binêmio' => 'binômio', 'Juêzo' => 'Juízo', 'juêzo' => 'juízo',
        '3ê ' => '3ª ', '1ê ' => '1ª ', '2ê ' => '2ª ',
        'Lêgica' => 'Lógica',
        'prêpri' => 'própri', 'Prêpri' => 'Própri',
        'cêvel' => 'cível', 'Cêvel' => 'Cível',
        'possêvel' => 'possível', 'impossêvel' => 'impossível',
        'responsêvel' => 'responsável',
        'cônjuge' => 'cônjuge',
        'vêlido' => 'válido',
        'ônus' => 'ônus',
        'indêcios' => 'indícios',
        'especêfic' => 'específic',
        'relatêrio' => 'relatório',
        'obrigatêri' => 'obrigatóri',
        'necessêri' => 'necessári',
        'provisêri' => 'provisóri',
        'alimentêri' => 'alimentári',
        'processuêl' => 'processual',
        'compatêvel' => 'compatível',
        'êntegr' => 'íntegr',
        'êndice' => 'índice',
    );
    $new = str_replace(array_keys($patterns), array_values($patterns), $text);
    if ($new !== $text) {
        $pdo->prepare("UPDATE case_andamentos SET descricao = ? WHERE id = ?")->execute(array($new, $r['id']));
        echo "#" . $r['id'] . " corrigido\n";
        $fixed++;
    }
}
echo "Total corrigidos: $fixed\n";

echo "\n=== Amostra final ===\n";
$check = $pdo->query("SELECT id, LEFT(descricao, 200) as trecho FROM case_andamentos WHERE case_id = 734 ORDER BY id LIMIT 5")->fetchAll();
foreach ($check as $c) echo "#" . $c['id'] . ": " . $c['trecho'] . "\n\n";
