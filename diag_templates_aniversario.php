<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('Acesso negado.');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Templates de aniversário (cat='aniversario') ===\n\n";
$rows = $pdo->query("SELECT id, nome, conteudo, ativo FROM zapi_templates WHERE categoria = 'aniversario' ORDER BY nome")->fetchAll();
foreach ($rows as $r) {
    echo "─── #{$r['id']} · " . ($r['ativo'] ? 'ATIVO' : 'inativo') . " · " . $r['nome'] . " ───\n";
    echo $r['conteudo'] . "\n\n";
}

echo "\n=== Distribuição de gender em clients ===\n";
$g = $pdo->query("SELECT COALESCE(NULLIF(gender,''),'(vazio)') AS g, COUNT(*) AS n FROM clients GROUP BY g ORDER BY n DESC")->fetchAll();
foreach ($g as $row) {
    echo "  " . str_pad($row['g'], 15, ' ') . " " . $row['n'] . "\n";
}
