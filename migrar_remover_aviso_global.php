<?php
/**
 * Remove o banner global de aviso (instabilidade/manutenção).
 * Esvazia configuracoes.aviso_global_msg → layout_start.php deixa de exibir o banner.
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
set_time_limit(60);

require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

echo "=== Remover aviso global ===\n\n";

$st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'aviso_global_msg'");
$st->execute();
$atual = $st->fetchColumn();
echo "Valor atual: " . ($atual === false ? '(chave inexistente)' : '"' . $atual . '"') . "\n";

$up = $pdo->prepare("UPDATE configuracoes SET valor = '' WHERE chave = 'aviso_global_msg'");
$up->execute();
echo "Linhas afetadas: " . $up->rowCount() . "\n";

// confirmação
$st->execute();
$novo = $st->fetchColumn();
echo "Valor agora: \"" . $novo . "\"\n";

echo "\nPronto! Banner removido.\n";
