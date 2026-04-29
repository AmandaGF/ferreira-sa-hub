<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit; }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

// 2 casos da Aline (id 432)
$st = $pdo->prepare("SELECT * FROM cases WHERE id IN (859, 710) ORDER BY id");
$st->execute();
$casos = $st->fetchAll();

foreach ($casos as $c) {
    echo "==== CASE {$c['id']} ====\n";
    foreach ($c as $k => $v) {
        $vstr = is_null($v) ? 'NULL' : (is_string($v) ? mb_substr($v, 0, 80) : (string)$v);
        echo "  {$k}: {$vstr}\n";
    }
    echo "\n";
}

// Replica a query EXATA do meus_processos.php
echo "==== Query exata do meus_processos.php ====\n";
$st = $pdo->prepare("SELECT * FROM cases WHERE client_id = ? AND salavip_ativo = 1 ORDER BY opened_at DESC");
$st->execute([432]);
$rows = $st->fetchAll();
echo "Total retornado: " . count($rows) . "\n";
foreach ($rows as $r) echo "  case {$r['id']} | {$r['title']} | salavip_ativo={$r['salavip_ativo']} | opened_at={$r['opened_at']}\n";
