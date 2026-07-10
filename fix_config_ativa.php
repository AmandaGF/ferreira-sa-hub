<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
// Renomeia _ativa -> _ativo (padrao das demais tocadas)
$st = $pdo->prepare("UPDATE configuracoes SET chave='jorjao_pasta_apta_ativo' WHERE chave='jorjao_pasta_apta_ativa'");
$st->execute();
echo "Renamed: " . $st->rowCount() . " row(s)\n";
// Garante que valor eh 1 (Amanda quer ligado)
$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('jorjao_pasta_apta_ativo', '1')
               ON DUPLICATE KEY UPDATE valor='1'")->execute();
$v = $pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_pasta_apta_ativo'")->fetchColumn();
echo "jorjao_pasta_apta_ativo = '$v'\n";
$v2 = $pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_pasta_apta_ativa'")->fetchColumn();
echo "jorjao_pasta_apta_ativa (obsoleto) = " . ($v2 === false ? '(nao existe)' : "'$v2'") . "\n";
