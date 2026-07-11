<?php
// One-shot: reseta o flag do case Denise pra tocar sino (Amanda 11/07 — mediação pré-processual, sem CNJ ainda)
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$n = $pdo->exec("UPDATE cases SET jorjao_distribuicao_tocado = 0 WHERE id = 631");
echo "Resetado #631: $n row afetada\n";
$r = $pdo->query("SELECT id, title, status, case_number, jorjao_distribuicao_tocado FROM cases WHERE id = 631")->fetch(PDO::FETCH_ASSOC);
print_r($r);
