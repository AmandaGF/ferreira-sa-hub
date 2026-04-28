<?php
/**
 * Cron horário de saúde do WhatsApp (item 2 do plano de prevenção).
 *
 * Roda a cada 1h via cPanel (curl HTTP). Detecta:
 * - Mensagens enviadas última hora SEM zapi_message_id (= falha silenciosa)
 * - Conversas duplicadas (mesmo client_id + canal, ambas não-grupo)
 * - Áudios .webm no nosso servidor com mais de 24h (replay vai quebrar)
 * - Instâncias 21 e 24 desconectadas
 * - Mensagens em fila com tentativas > 3
 *
 * Quando algo cruza threshold, manda notificação no sino do Hub pra gestão.
 * Antes do cliente reclamar, a equipe já sabe.
 *
 * Cron entry no cPanel:
 *   0 * * * * curl -s "https://ferreiraesa.com.br/conecta/cron/wa_saude_check.php?key=fsa-hub-deploy-2026"
 */
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_notify.php';

if (($_GET['key'] ?? '') !== 'fsa-hub-deploy-2026') { http_response_code(403); exit('forbidden'); }
header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$alertas = array();
$saidaTexto = "Cron wa_saude_check rodado em " . date('Y-m-d H:i:s') . "\n\n";

// 1. Mensagens enviadas última hora sem zapi_message_id
try {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens
                           WHERE direcao='enviada' AND (zapi_message_id IS NULL OR zapi_message_id = '')
                             AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
    $saidaTexto .= "Msgs enviadas última hora SEM zapi_message_id: {$n}\n";
    if ($n > 0) $alertas[] = "⚠️ {$n} msg(s) enviada(s) na última hora SEM message_id da Z-API (provável falha de envio)";
} catch (Exception $e) { $saidaTexto .= "Erro check msgs: " . $e->getMessage() . "\n"; }

// 2. Conversas duplicadas
try {
    $st = $pdo->query("SELECT client_id, canal, COUNT(*) as qtd
                       FROM zapi_conversas
                       WHERE client_id IS NOT NULL AND client_id > 0
                         AND COALESCE(eh_grupo, 0) = 0
                       GROUP BY client_id, canal HAVING qtd > 1");
    $dups = $st->fetchAll();
    $saidaTexto .= "Grupos de conversas duplicadas (mesmo client_id+canal): " . count($dups) . "\n";
    if (!empty($dups)) {
        $alertas[] = "⚠️ " . count($dups) . " conversa(s) duplicada(s) detectada(s) — clientes com 2+ convs no mesmo canal";
    }
} catch (Exception $e) { $saidaTexto .= "Erro check duplicatas: " . $e->getMessage() . "\n"; }

// 3. Áudios .webm pendurados há mais de 24h (vão quebrar replay)
$dirWa = APP_ROOT . '/files/whatsapp';
$contagemWebm = 0;
if (is_dir($dirWa)) {
    foreach (glob($dirWa . '/wa_audio_*.webm') as $f) {
        if (filemtime($f) < time() - 86400) $contagemWebm++;
    }
    $saidaTexto .= "Áudios .webm > 24h em /files/whatsapp/: {$contagemWebm}\n";
    // Não alerta — é histórico, só info. Áudios novos já vão em base64 pra Z-API.
}

// 4. Instâncias Z-API conectadas?
require_once __DIR__ . '/../core/functions_zapi.php';
foreach (array('21', '24') as $ddd) {
    try {
        $inst = zapi_get_instancia($ddd);
        $cfg = zapi_get_config();
        if (!$inst || empty($inst['instancia_id'])) continue;
        $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/status';
        $headers = array('Content-Type: application/json');
        if (!empty($cfg['client_token'])) $headers[] = 'Client-Token: ' . $cfg['client_token'];
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_HTTPHEADER=>$headers, CURLOPT_SSL_VERIFYPEER=>false));
        $r = curl_exec($ch); curl_close($ch);
        $j = json_decode($r, true);
        $conectado = is_array($j) && !empty($j['connected']);
        $saidaTexto .= "Instância DDD {$ddd}: " . ($conectado ? 'CONECTADA' : 'DESCONECTADA') . "\n";
        if (!$conectado) $alertas[] = "🔴 Instância DDD {$ddd} DESCONECTADA da Z-API — msgs não estão saindo";
    } catch (Exception $e) { $saidaTexto .= "Erro check instância {$ddd}: " . $e->getMessage() . "\n"; }
}

// 5. Fila de envio com retry > 3
try {
    $stF = $pdo->prepare("SELECT COUNT(*) FROM zapi_fila_envio WHERE status IN ('pendente','retry') AND tentativas > 3");
    $stF->execute();
    $filaErros = (int)$stF->fetchColumn();
    $saidaTexto .= "Fila com tentativas > 3: {$filaErros}\n";
    if ($filaErros > 0) $alertas[] = "⚠️ {$filaErros} mensagem(ns) na fila com 3+ tentativas falhas — investigar";
} catch (Exception $e) { /* tabela pode não existir */ }

// Notificar gestão SE houver alerta
if (!empty($alertas)) {
    $msg = "🩺 Saúde WhatsApp — alertas detectados:\n\n" . implode("\n", $alertas);
    try {
        notify_gestao('🩺 Alerta saúde WhatsApp', $msg, 'alerta', url('modules/admin/diag_wa.php'), '🩺');
    } catch (Exception $e) {}
    $saidaTexto .= "\n=== ALERTAS NOTIFICADOS PRA GESTÃO ===\n" . implode("\n", $alertas) . "\n";
} else {
    $saidaTexto .= "\n✅ Tudo OK — nenhum alerta pra notificar.\n";
}

// Audit log + retorna
audit_log('wa_saude_check', 'cron', 0, count($alertas) . ' alertas');
echo $saidaTexto;
