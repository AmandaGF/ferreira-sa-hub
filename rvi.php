<?php
if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); die('403'); }
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/database.php';
require_once __DIR__ . '/core/functions_ia.php';
require_once __DIR__ . '/core/functions_aviso_cliente.php';
if (function_exists('opcache_reset')) opcache_reset();
header('Content-Type: text/plain; charset=utf-8');
@set_time_limit(180);
$pdo = db();

$cl = $pdo->query("SELECT id, name FROM clients WHERE name LIKE '%Rayane%Viana%' LIMIT 1")->fetch();
$a = $pdo->query("SELECT ca.descricao, ca.data_andamento, ca.tipo, cs.title AS case_title
                  FROM case_andamentos ca JOIN cases cs ON cs.id=ca.case_id
                  WHERE cs.client_id={$cl['id']} AND cs.status NOT IN ('arquivado','cancelado','renunciamos','concluido','finalizado')
                    AND COALESCE(ca.visivel_cliente,0)=1
                  ORDER BY ca.data_andamento DESC, ca.id DESC LIMIT 1")->fetch();

$modo = aviso_cliente_determinar_modo($pdo, (int)$cl['id'], (string)$a['data_andamento']);
echo "modo=" . json_encode($modo) . "\n\n";

// Chamada DIRETA na IA sem os guards, pra ver o que ela gera cru
require_once __DIR__ . '/core/functions_ia.php';

$assinante = 'Alfredo Neves';
$primNome = 'Rayane';
$hora = (int)date('G');
$periodoDia = ($hora>=5 && $hora<12)?'manhã (use bom dia)':(($hora<18)?'tarde':'noite');

$diasSem = $modo['dias'];
$blocoModo = "🚨 MODO **LONGA_ESPERA** ({$diasSem} dias sem movimento).\n"
           . "COPIE esta estrutura:\n"
           . "```\n"
           . "*_{$assinante}_*:\n"
           . "Bom dia, {$primNome}!\n\n"
           . "Sabemos que a espera está longa. Já fizemos contato com o cartório — os processos seguem ordem cronológica de julgamento. Estamos monitorando de perto.\n\n"
           . "Só reforçando: o último andamento foi em [DD/MM]: [1 frase].\n"
           . "```\n";

$system = $blocoModo . "\n\nGere UMA mensagem CURTA de WhatsApp para o cliente. Comece com *_Alfredo Neves_*: em linha propria. Fim.";
$user = "Andamento (12/06/2026):\n" . $a['descricao'];

echo "\n=== CHAMADA CRU (sem guards) ===\n";
$r = ia_chamar('teste_rayane', 'claude-haiku-4-5-20251001', $system, array(array('role'=>'user','content'=>$user)),
    array('max_tokens'=>400, 'temperature'=>0.3, 'bypass_killswitch'=>true, 'bypass_user_whitelist'=>true));
echo "ok=" . (!empty($r['ok'])?'1':'0') . "\n";
if (!empty($r['erro'])) echo "erro=" . $r['erro'] . "\n";
echo "---\n" . ($r['texto'] ?? '(vazio)') . "\n---\n";
