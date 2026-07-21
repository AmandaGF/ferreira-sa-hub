<?php
/** Diag temporario: lista colunas/tabelas com 'gerid' no nome. Apagar depois. */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$db  = $pdo->query('SELECT DATABASE()')->fetchColumn();

echo "COLUNAS com 'gerid' no nome:\n";
$q = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME LIKE '%gerid%' ORDER BY TABLE_NAME, COLUMN_NAME");
$q->execute(array($db));
foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ehSugerido = (stripos($r['COLUMN_NAME'], 'sugerid') !== false) ? '  <- FALSO POSITIVO (sugerido)' : '  <- REAL, precisa renomear';
    echo "  {$r['TABLE_NAME']}.{$r['COLUMN_NAME']}{$ehSugerido}\n";
}

echo "\nTABELAS com 'gerid' no nome:\n";
$q2 = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME LIKE '%gerid%'");
$q2->execute(array($db));
$t = $q2->fetchAll(PDO::FETCH_COLUMN);
echo $t ? ('  ' . implode("\n  ", $t) . "\n") : "  (nenhuma)\n";
