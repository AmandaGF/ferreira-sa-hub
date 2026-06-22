<?php
/**
 * Cron: cobrança de leads comerciais (canal 21) sem resposta.
 *
 * Para cada conversa do canal 21 cuja ÚLTIMA mensagem foi do LEAD há mais de N min
 * (default 5), notifica o responsável (mesmo fluxo de lead novo: notify + push) e,
 * no máximo 1×/30min em horário comercial, manda um resumo no grupo do WhatsApp.
 *
 * Liga/desliga e ID do grupo: módulo CRM Comercial (configuracoes comercial_*).
 *
 * Rodar a cada 5 minutos via cPanel cron:
 *   php /home/ferre315/public_html/conecta/cron/comercial_cobranca.php
 * Ou via HTTP com chave:
 *   https://ferreiraesa.com.br/conecta/cron/comercial_cobranca.php?key=fsa-hub-deploy-2026
 *   (?forcar=1 ignora horário comercial; útil pra teste)
 */

$isCli = php_sapi_name() === 'cli';
if (!$isCli && ($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { die('Acesso negado.'); }
if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_comercial.php';

$pdo = db();

echo "=== Cobrança Comercial — leads sem resposta (canal 21) ===\n";
echo "Execução: " . date('Y-m-d H:i:s') . "\n\n";

$opts = array();
if (!empty($_GET['forcar'])) $opts['forcar_horario'] = true;

$rep = comercial_rodar_cobranca($pdo, $opts);

if (empty($rep['ativo']))       echo "Cobrança DESATIVADA (ligue no CRM Comercial).\n";
elseif (empty($rep['horario_ok'])) echo "Fora do horário comercial. Nada a fazer.\n";
else {
    echo "Pendentes (+{$rep['pendentes']} encontrados, " . count($rep['detalhe']) . " notificados nesta rodada):\n";
    foreach ($rep['detalhe'] as $d) {
        echo "  • conv#{$d['conversa']} {$d['nome']} — {$d['min']}min — resp: {$d['responsavel']}\n";
    }
    echo "Grupo: " . (!empty($rep['grupo_enviado']) ? "enviado" : (isset($rep['grupo_erro']) ? "erro: {$rep['grupo_erro']}" : "não enviado (throttle/sem grupo)")) . "\n";
}

echo "\n=== FIM ===\n";
