<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('no');
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions.php';
$pdo = db();
$rows = $pdo->query("SELECT ac.client_id, cl.name, COUNT(*) qtd, SUM(ac.valor) v
  FROM asaas_cobrancas ac LEFT JOIN clients cl ON cl.id=ac.client_id
  WHERE ac.status='OVERDUE' GROUP BY ac.client_id ORDER BY MIN(ac.vencimento) LIMIT 8")->fetchAll();
foreach ($rows as $r) {
  $href = function_exists('module_url') ? module_url('financeiro', 'cliente.php?id=' . $r['client_id']) : '(sem module_url)';
  echo "client_id=" . var_export($r['client_id'], true) . " | " . ($r['name'] ?: '(SEM CLIENTE)') . " | qtd={$r['qtd']}\n   href = $href\n";
}
