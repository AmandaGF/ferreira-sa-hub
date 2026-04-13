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
        'RêU' => 'RÉU', 'Rêu' => 'Réu', 'rêu' => 'réu',
        'êbito' => 'ébito', 'dêbito' => 'débito',
        'mêdic' => 'médic',
        'perêcia' => 'perícia',
        'côdigo' => 'código',
        'têcnic' => 'técnic',
        'crêdit' => 'crédit',
        'prêpri' => 'própri',
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
