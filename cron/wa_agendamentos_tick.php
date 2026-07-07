<?php
/**
 * Cron: envio dos agendamentos pontuais de WhatsApp.
 *
 * Varre wa_agendamentos.status='pendente' com agendado_para <= NOW() e envia
 * cada um via zapi_send_text. Se falhar, incrementa tentativas — depois de 3
 * tentativas, marca como 'falhou' (evita loop infinito de retry).
 *
 * Recomendado no cPanel a cada 1 minuto:
 *   * * * * *  curl -s "https://ferreiraesa.com.br/conecta/cron/wa_agendamentos_tick.php?key=fsa-hub-deploy-2026"
 *
 * Killswitch: `configuracoes.wa_agenda_ativo` = '0' desliga o envio (o cron
 * ainda roda mas não manda nada).
 *
 * Lock leve (60s) evita 2 ticks simultâneos derrubando a Z-API.
 */

$isCli = php_sapi_name() === 'cli';
$keyOk = ($_GET['key'] ?? '') === 'fsa-hub-deploy-2026';
if (!$isCli && !$keyOk) { http_response_code(403); die('Acesso negado.'); }

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_zapi.php';

if (!$isCli) { header('Content-Type: text/plain; charset=utf-8'); }
$pdo = db();
$inicio = microtime(true);
$now = date('Y-m-d H:i:s');
echo "[{$now}] === wa_agendamentos_tick ===\n";

// Lock leve
$lockFile = dirname(__DIR__) . '/files/wa_agendamentos_tick.lock';
if (file_exists($lockFile)) {
    $age = time() - @filemtime($lockFile);
    if ($age < 60) { echo "OUTRO TICK EM EXECUCAO (lock ha {$age}s) — abortando\n"; exit; }
    @unlink($lockFile);
}
@file_put_contents($lockFile, $now);

$ok = 0; $falhas = 0; $descartados = 0;

try {
    // Killswitch
    $kill = $pdo->query("SELECT valor FROM configuracoes WHERE chave='wa_agenda_ativo'")->fetchColumn();
    if ($kill !== '1' && $kill !== 1 && $kill !== null) {
        echo "KILLSWITCH DESLIGADO (wa_agenda_ativo={$kill}) — nada enviado\n";
        @unlink($lockFile);
        exit;
    }

    // Pega pendentes vencidos. LIMIT 30 por tick pra não estourar rate limit
    // (30 msgs * 500ms = 15s). Se acumular, ticks seguintes drenam.
    $vencidos = $pdo->query("
        SELECT a.*, c.name AS client_name_real, c.gender AS client_gender
        FROM wa_agendamentos a
        LEFT JOIN clients c ON c.id = a.client_id
        WHERE a.status = 'pendente' AND a.agendado_para <= NOW()
        ORDER BY a.agendado_para ASC
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($vencidos)) {
        echo "Nenhum agendamento vencido.\n";
        @unlink($lockFile);
        exit;
    }

    echo "Agendamentos vencidos: " . count($vencidos) . "\n";

    foreach ($vencidos as $ag) {
        $id = (int)$ag['id'];
        $canal = $ag['canal'];
        $telefone = $ag['telefone'];
        $mensagem = (string)$ag['mensagem'];
        $nomeCliente = $ag['client_name_real'] ?: ($ag['nome_contato'] ?: '');
        $tentAtual = (int)$ag['tentativas'];

        // Sanidade — antes de mandar, garante que ainda está pendente (evita
        // corrida entre ticks paralelos: se outro tick pegou e enviou, esse
        // aqui só ignora).
        $atualStatus = $pdo->prepare("SELECT status, tentativas FROM wa_agendamentos WHERE id = ? FOR UPDATE");
        // MySQL FOR UPDATE só funciona dentro de transação — sem trans o lock
        // não é respeitado, mas o UPDATE condicional final ('WHERE status=pendente')
        // já evita reenvio. Não abrimos transação aqui pra manter o cron leve.
        $atualStatus->execute(array($id));
        $atual = $atualStatus->fetch(PDO::FETCH_ASSOC);
        if (!$atual || $atual['status'] !== 'pendente') {
            echo "  [SKIP] #{$id} status mudou pra {$atual['status']} — outro tick pegou\n";
            $descartados++;
            continue;
        }

        // Substituição de variáveis
        $primeiroNome = trim(explode(' ', $nomeCliente)[0]);
        $mensagemFinal = strtr($mensagem, array(
            '{{primeiro_nome}}' => $primeiroNome,
            '{{nome}}'          => $nomeCliente,
            '{{data_hoje}}'     => date('d/m/Y'),
        ));

        // 🔗 Shortlinks: rastreia clique do cliente em URLs enviadas
        try {
            require_once dirname(__DIR__) . '/core/functions_shortlinks.php';
            $mensagemFinal = sl_encurtar_urls_no_texto($mensagemFinal, array(
                'client_id'  => $ag['client_id'] ? (int)$ag['client_id'] : null,
                'case_id'    => $ag['case_id'] ? (int)$ag['case_id'] : null,
                'canal'      => $canal,
                'criado_por' => $ag['criado_por'] ? (int)$ag['criado_por'] : null,
            ));
        } catch (Exception $_e) {}

        $r = zapi_send_text($canal, $telefone, $mensagemFinal);
        $novoTent = $tentAtual + 1;

        if (!empty($r['ok'])) {
            $zapiId = '';
            if (is_array($r['data'] ?? null)) {
                $zapiId = $r['data']['id'] ?? ($r['data']['zaapId'] ?? ($r['data']['messageId'] ?? ''));
            }
            $pdo->prepare("UPDATE wa_agendamentos
                SET status = 'enviado', enviado_em = NOW(), zapi_message_id = ?, tentativas = ?, erro = NULL
                WHERE id = ? AND status = 'pendente'")
                ->execute(array($zapiId, $novoTent, $id));
            echo "  [OK] #{$id} → {$telefone} ({$nomeCliente}) via canal {$canal}\n";
            $ok++;
        } else {
            $http = $r['http_code'] ?? '?';
            $erroTxt = 'HTTP ' . $http;
            if (!empty($r['erro'])) $erroTxt .= ' — ' . $r['erro'];
            elseif (isset($r['data'])) $erroTxt .= ' — ' . (is_string($r['data']) ? $r['data'] : json_encode($r['data']));
            $erroTxt = mb_substr($erroTxt, 0, 500);

            // Depois de 3 tentativas, dá desistido — evita loop infinito quando
            // o telefone está desativado ou a Z-API bloqueou o número.
            $novoStatus = $novoTent >= 3 ? 'falhou' : 'pendente';
            $pdo->prepare("UPDATE wa_agendamentos SET tentativas = ?, erro = ?, status = ? WHERE id = ? AND status = 'pendente'")
                ->execute(array($novoTent, $erroTxt, $novoStatus, $id));
            echo "  [FALHA {$novoTent}/3] #{$id} → {$telefone} — {$erroTxt}\n";
            $falhas++;
        }

        // Rate limit — 500ms entre chamadas evita saturar a Z-API
        usleep(500000);
    }

    $dur = round(microtime(true) - $inicio, 1);
    echo "\n=== CONCLUIDO ({$dur}s) === Enviados: {$ok} | Falhas: {$falhas} | Pulados: {$descartados}\n";

    if (function_exists('audit_log')) {
        try { audit_log('wa_agenda_cron_tick', 'wa_agendamentos', 0, "ok={$ok} falhas={$falhas} skip={$descartados}"); } catch (Exception $e) {}
    }
} catch (Exception $e) {
    echo "ERRO FATAL: " . $e->getMessage() . "\n";
} finally {
    @unlink($lockFile);
}
