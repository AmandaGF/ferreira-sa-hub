<?php
/**
 * Diag — mostra o que a sessão atual da Central VIP está vendo.
 * Acessar logado(a) como o cliente investigado.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors','1'); error_reporting(E_ALL);

$pdo = sv_db();

echo "==== SESSAO ATUAL ====\n";
foreach (['salavip_user_id','salavip_cliente_id','salavip_nome_exibicao','salavip_cpf','salavip_email','salavip_logado_em','salavip_impersonator_admin_id'] as $k) {
    $v = $_SESSION[$k] ?? '(nao setado)';
    echo "  $k = $v\n";
}

$clienteId = (int) ($_SESSION['salavip_cliente_id'] ?? 0);
$userId    = (int) ($_SESSION['salavip_user_id'] ?? 0);

echo "\n==== salavip_current_cliente_id() ====\n";
echo "  retorna: " . salavip_current_cliente_id() . "\n";

echo "\n==== Query EXATA do meus_processos.php ====\n";
echo "  SELECT * FROM cases WHERE client_id = $clienteId AND salavip_ativo = 1 ORDER BY opened_at DESC\n";
$st = $pdo->prepare("SELECT id, title, status, salavip_ativo, segredo_justica, opened_at, kanban_oculto, created_at FROM cases WHERE client_id = ? AND salavip_ativo = 1 ORDER BY opened_at DESC");
$st->execute([$clienteId]);
$rows = $st->fetchAll();
echo "  Total retornado: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "    case {$r['id']} | {$r['title']} | status={$r['status']} | salavip_ativo={$r['salavip_ativo']} | segredo={$r['segredo_justica']} | opened_at={$r['opened_at']} | created_at={$r['created_at']}\n";
}

echo "\n==== KPI dashboard (com filtro NOT IN cancelado/arquivado) ====\n";
$st = $pdo->prepare("SELECT COUNT(*) FROM cases WHERE client_id = ? AND salavip_ativo = 1 AND status NOT IN ('cancelado','arquivado')");
$st->execute([$clienteId]);
echo "  KPI: " . (int)$st->fetchColumn() . "\n";

echo "\n==== Cookies recebidos ====\n";
foreach ($_COOKIE as $k => $v) {
    echo "  $k = " . substr($v, 0, 40) . (strlen($v) > 40 ? '...' : '') . "\n";
}

echo "\n==== session_id() / session_name() ====\n";
echo "  session_id   = " . session_id() . "\n";
echo "  session_name = " . session_name() . "\n";

echo "\n==== HEADERS DA REQUISICAO ====\n";
echo "  Host       = " . ($_SERVER['HTTP_HOST'] ?? '') . "\n";
echo "  URI        = " . ($_SERVER['REQUEST_URI'] ?? '') . "\n";
echo "  User-Agent = " . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 80) . "\n";

echo "\nFIM.\n";
