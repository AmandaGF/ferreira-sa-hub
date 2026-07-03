<?php
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
$token = $pdo->query("SELECT valor FROM configuracoes WHERE chave='cpfcnpj_api_token'")->fetchColumn();
$pacote = $pdo->query("SELECT valor FROM configuracoes WHERE chave='cpfcnpj_pacote'")->fetchColumn();
echo "Servico contratado: CPFCNPJ.com.br\n";
echo "Painel: https://www.cpfcnpj.com.br/\n\n";
echo "Token da conta: " . ($token ?: '9320d4099cf4099528cce511241c48a0 (fallback default)') . "\n";
echo "Pacote atual:   " . ($pacote ?: '1 (fallback — mais basico)') . "\n\n";
echo "Ultima consulta CPF em cache (indica se ta funcionando):\n";
$r = $pdo->query("SELECT consultado_em, cpf FROM cpf_cache ORDER BY consultado_em DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($r) echo "  " . $r['consultado_em'] . " (CPF " . substr($r['cpf'],0,3) . "***" . substr($r['cpf'],-2) . ")\n";
else echo "  nenhuma consulta cacheada ainda\n";
