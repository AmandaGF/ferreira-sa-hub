<?php
/**
 * Dispara jorjao pra Josieli Braz (case #1600, ja em em_elaboracao).
 * Amanda 10/07 - config jorjao_pasta_apta_ativa ja setada em '1'.
 */
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_jorjao.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');

$pdo = db();
$vNow = $pdo->query("SELECT valor FROM configuracoes WHERE chave='jorjao_pasta_apta_ativa'")->fetchColumn();
echo "Config jorjao_pasta_apta_ativa = '$vNow'\n\n";

// Buscar case Josieli
$st = $pdo->prepare("SELECT c.id, c.title, c.case_type, c.client_id, c.responsible_user_id, cl.name AS cli_nome, u.name AS resp_nome
                     FROM cases c LEFT JOIN clients cl ON cl.id = c.client_id
                     LEFT JOIN users u ON u.id = c.responsible_user_id
                     WHERE c.id = 1600");
$st->execute();
$c = $st->fetch(PDO::FETCH_ASSOC);
echo "Case #{$c['id']} — {$c['title']}\n";
echo "Cliente: {$c['cli_nome']} · tipo: {$c['case_type']}\n";

// Busca CX que moveu (mais permissiva)
$cxNome = '';
try {
    $stAud = $pdo->query("SELECT u.name, a.details FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
                           WHERE a.entity_type='case' AND a.entity_id = 1600
                           ORDER BY a.id DESC LIMIT 5");
    foreach ($stAud->fetchAll(PDO::FETCH_ASSOC) as $r) {
        echo "  audit: {$r['name']} - " . mb_substr((string)$r['details'], 0, 100) . "\n";
        if (!$cxNome && $r['name']) $cxNome = $r['name'];
    }
} catch (Throwable $e) {}
if (!$cxNome) $cxNome = 'a equipe';
$cxPrimeiro = preg_split('/\s+/', $cxNome)[0];
echo "\nCX detectada: $cxPrimeiro\n\n";

// Dispara
$vars = array(
    'cliente'     => $c['cli_nome'],
    'tipo_caso'   => $c['case_type'] ?: 'não informado',
    'cx'          => $cxPrimeiro,
    'responsavel' => $c['resp_nome'] ? preg_split('/\s+/', $c['resp_nome'])[0] : 'time operacional',
    'hoje'        => date('d/m/Y'),
    '_case_id'    => 1600,
);
$r = jorjao_enviar('pasta_apta', $vars);
echo "== RESULTADO ==\n";
print_r($r);
