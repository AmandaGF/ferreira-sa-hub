<?php
/**
 * Cron: cobrança de CHAMADOS PARADOS (Helpdesk).
 *
 * Chamado aberto sem movimento há +N horas → notifica responsável(is) + resumo
 * no grupo do WhatsApp. Liga/desliga, grupo e horas: painel ⚙️ do Helpdesk.
 *
 * Rodar 1×/hora via cPanel cron:
 *   php /home/ferre315/public_html/conecta/cron/helpdesk_cobranca.php
 * Ou via HTTP com chave:
 *   https://ferreiraesa.com.br/conecta/cron/helpdesk_cobranca.php?key=fsa-hub-deploy-2026
 *   (?forcar=1 ignora horário comercial; ?dry=1 só simula, não envia)
 */

$isCli = php_sapi_name() === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_helpdesk_cobranca.php';

$pdo = db();

echo "=== Cobrança Helpdesk — chamados parados ===\n";
echo "Execução: " . date('Y-m-d H:i:s') . "\n\n";

$opts = array();
if (!empty($_GET['forcar'])) $opts['forcar_horario'] = true;
if (!empty($_GET['dry']))    { $opts['dry'] = true; $opts['ignorar_ativo'] = true; }

$rep = helpdesk_cobranca_run($pdo, $opts);

if (!empty($rep['erro']))            echo "ERRO: {$rep['erro']}\n";
elseif (empty($rep['ativo']))        echo "Cobrança DESATIVADA (ligue no painel do Helpdesk).\n";
elseif (empty($rep['horario_ok']))   echo "Fora do horário comercial. Nada a fazer.\n";
else {
    echo "Parados: {$rep['parados']} | notificados nesta rodada: {$rep['notificados']}\n";
    foreach ($rep['detalhe'] as $d) {
        echo "  • #{$d['ticket']} {$d['titulo']} — {$d['horas']}h — resp: {$d['responsaveis']}\n";
    }
    if (!empty($rep['grupo_preview'])) echo "\n[DRY] Preview grupo:\n{$rep['grupo_preview']}\n";
    else echo "Grupo: " . (!empty($rep['grupo_enviado']) ? "enviado" : (isset($rep['grupo_erro']) ? "erro: {$rep['grupo_erro']}" : "não enviado (throttle/sem grupo)")) . "\n";
}

echo "\n=== FIM ===\n";
