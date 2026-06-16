<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
$pdo = db();

$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('comemoracao_contrato_canal', '24')
               ON DUPLICATE KEY UPDATE valor='24'")->execute();
echo "✓ Canal mudado pra 24 (CX/Operacional).\n";

require_once __DIR__ . '/core/functions_zapi.php';
$grupo = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='comemoracao_contrato_grupo_id'")->fetchColumn();
echo "Grupo: $grupo\n\n";

echo "=== Teste do Jorjão (canal 24) ===\n";
$msg = "🔔 *Fala pessoal! Jorjão tá na área dnovo!* 🔔\n\nBora lá?! 🚀\n\n_(mensagem de teste do sino de comemoração — está tudo funcionando!)_";
$r = zapi_send_text('24', $grupo, $msg);
echo "ok = " . (!empty($r['ok']) ? 'SIM' : 'NAO') . "\n";
if (!empty($r['data'])) echo "data = " . json_encode($r['data']) . "\n";
if (!empty($r['erro'])) echo "erro = " . $r['erro'] . "\n";
