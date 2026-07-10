<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('jorjao_pasta_apta_ativa', '1')
               ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute();
echo "OK: tocada 'pasta_apta' ativada em configuracoes\n";
$v = $pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_pasta_apta_ativa'")->fetchColumn();
echo "valor atual = '$v'\n";
