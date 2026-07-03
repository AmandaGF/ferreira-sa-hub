<?php
/**
 * Script one-shot: liga killswitch do treinamento de audiencia depois
 * de Amanda aprovar o termo v1 em 02/07/2026.
 *
 * Uso: GET ?key=fsa-hub-deploy-2026
 * Depois de rodar, este arquivo pode ser apagado.
 */
require_once __DIR__ . '/core/database.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$antes = $pdo->query("SELECT valor FROM configuracoes WHERE chave='treinamento_audiencia_ativo'")->fetchColumn();
echo "Killswitch antes: '{$antes}'\n";

$pdo->prepare("UPDATE configuracoes SET valor='1' WHERE chave='treinamento_audiencia_ativo'")->execute();

$depois = $pdo->query("SELECT valor FROM configuracoes WHERE chave='treinamento_audiencia_ativo'")->fetchColumn();
echo "Killswitch depois: '{$depois}'\n";
echo "\n";
echo $depois === '1' ? "✓ ATIVADO\n" : "✗ Nao ativado, ver banco\n";
