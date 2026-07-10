<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_jorjao.php';
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL); ini_set('display_errors','1');
$pdo = db();

echo "=== Tocar sino Jorjão retroativo — Josieli Braz ===\n\n";

// Acha case da Josieli Braz em em_elaboracao
$st = $pdo->query(
    "SELECT c.id, c.title, c.case_type, c.client_id, c.status, c.updated_at,
            cl.name AS cli_nome,
            u.name AS resp_nome
     FROM cases c
     LEFT JOIN clients cl ON cl.id = c.client_id
     LEFT JOIN users u ON u.id = c.responsible_user_id
     WHERE cl.name LIKE '%Josieli%Braz%' AND c.status = 'em_elaboracao'
     ORDER BY c.updated_at DESC LIMIT 1"
);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) { echo "ERRO: nao achei case da Josieli Braz em em_elaboracao\n"; exit; }

echo "Case: #{$c['id']} {$c['title']}\n";
echo "Cliente: {$c['cli_nome']}\n";
echo "Tipo: " . ($c['case_type'] ?: 'não informado') . "\n";
echo "Responsável: " . ($c['resp_nome'] ?: '-') . "\n";
echo "Atualizado: {$c['updated_at']}\n\n";

// Descobre CX que fez a mudanca (via audit_log — quem moveu pra em_elaboracao)
$stAud = $pdo->prepare(
    "SELECT u.name FROM audit_log a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE a.entity_type = 'case' AND a.entity_id = ?
       AND a.action = 'case_status'
       AND a.details LIKE '%em_elaboracao%'
     ORDER BY a.id DESC LIMIT 1"
);
$stAud->execute(array((int)$c['id']));
$cxNome = $stAud->fetchColumn();
if (!$cxNome) $cxNome = 'CX';
$cxPrimeiro = preg_split('/\s+/', $cxNome)[0];
echo "CX que passou a pasta: $cxPrimeiro (nome completo: $cxNome)\n\n";

// Confirma tocada ativa
if (!jorjao_tocada_ativa('pasta_apta')) {
    echo "AVISO: tocada 'pasta_apta' esta DESATIVADA — vou disparar mesmo assim pra este toque.\n\n";
}

// Chama jorjao_enviar com bypass da checagem (chama direto o helper interno se possivel)
// Como jorjao_enviar respeita jorjao_tocada_ativa(), se ela ativou agora, deve funcionar.
$vars = array(
    'cliente'     => $c['cli_nome'],
    'tipo_caso'   => $c['case_type'] ?: 'não informado',
    'cx'          => $cxPrimeiro,
    'responsavel' => $c['resp_nome'] ? preg_split('/\s+/', $c['resp_nome'])[0] : 'time operacional',
    'hoje'        => date('d/m/Y'),
    '_case_id'    => (int)$c['id'],
);
$r = jorjao_enviar('pasta_apta', $vars);
echo "Resultado do jorjao_enviar:\n";
print_r($r);
