<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') die('no');
header('Content-Type: text/plain; charset=utf-8');
$fs = array(
  'modules/financeiro/index.php', 'modules/financeiro/api.php',
  'modules/financeiro/cliente.php', 'core/asaas_helper.php',
  'migrar_inadimplente_nego.php',
);
foreach ($fs as $rel) {
  $f = __DIR__ . '/' . $rel;
  if (!file_exists($f)) { echo "FALTA: $rel\n"; continue; }
  try { token_get_all(file_get_contents($f), TOKEN_PARSE); echo "OK   $rel\n"; }
  catch (Throwable $e) { echo "ERRO $rel (linha " . $e->getLine() . "): " . $e->getMessage() . "\n"; }
}
