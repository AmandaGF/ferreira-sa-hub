<?php
/**
 * Salvar configuração no banco via URL
 * Uso: ?key=fsa-hub-deploy-2026&chave=NOME&valor=VALOR
 */
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('Chave inválida'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
header('Content-Type: text/plain; charset=utf-8');

$chave = $_GET['chave'] ?? '';
$valor = $_GET['valor'] ?? '';
if (!$chave) { echo "Uso: ?key=...&chave=NOME&valor=VALOR\n"; exit; }

$pdo = db();
$pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?")
    ->execute(array($chave, $valor, $valor));
echo "[OK] $chave salvo!\n";
