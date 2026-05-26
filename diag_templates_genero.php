<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Templates com construções tipo '(a)' ===\n\n";
$rows = $pdo->query("SELECT id, nome, categoria, conteudo FROM zapi_templates WHERE ativo = 1 ORDER BY categoria, nome")->fetchAll();
$comProblema = array();
foreach ($rows as $r) {
    // Detecta padrão "(a)" ou "(o)" ou "(es)"
    if (preg_match('/\([aoe]s?\)/u', $r['conteudo'])) {
        $comProblema[] = $r;
    }
}
echo "Total com (a)/(o)/(es): " . count($comProblema) . " (de " . count($rows) . " ativos)\n\n";

foreach ($comProblema as $r) {
    echo "─── #{$r['id']} · [{$r['categoria']}] · {$r['nome']} ───\n";
    // Mostrar só as linhas com o padrão pra ler rápido
    foreach (explode("\n", $r['conteudo']) as $line) {
        if (preg_match('/\([aoe]s?\)/u', $line)) {
            echo "  ▸ " . trim($line) . "\n";
        }
    }
    echo "\n";
}
