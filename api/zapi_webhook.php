<?php
/**
 * Ferreira & Sá Hub — Webhook Z-API
 * Endpoint público. Z-API chama isso ao receber/enviar/alterar status de mensagens.
 *
 * URL: /conecta/api/zapi_webhook.php?numero=21 (ou =24)
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/database.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/functions_zapi.php';

header('Content-Type: application/json; charset=utf-8');

// Log em arquivo pra debug
$logFile = APP_ROOT . '/files/zapi_webhook.log';
$log = function ($msg) use ($logFile) {
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
};

$numero = $_GET['numero'] ?? '';
if (!in_array($numero, array('21', '24'), true)) {
    http_response_code(400);
    echo json_encode(array('error' => 'Parametro numero invalido'));
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody, true);
if (!$payload) {
    $log("[{$numero}] payload vazio ou invalido");
    echo json_encode(array('status' => 'ignored'));
    exit;
}

$log("[{$numero}] " . substr($rawBody, 0, 500));

$tipoEvt = $payload['type'] ?? '';
$pdo = db();

try {
    switch ($tipoEvt) {

        // ── Mensagem recebida ───────────────────────────
        case 'ReceivedCallback':
        case 'MessageReceived':
        case 'message': {
            $fromMe = !empty($payload['fromMe']);
            if ($fromMe) {
                // Mensagens que EU enviei pelo WhatsApp (não pelo Hub) — ignoro pra não duplicar
                $log("[{$numero}] fromMe=true, ignorado");
                echo json_encode(array('status' => 'ignored_fromMe'));
                exit;
            }

            $telefone  = $payload['phone'] ?? ($payload['author'] ?? '');
            $nome      = $payload['senderName'] ?? ($payload['chatName'] ?? null);
            $zapiMsgId = $payload['messageId'] ?? '';

            if (!$telefone) {
                $log("[{$numero}] sem telefone, ignorado");
                echo json_encode(array('status' => 'no_phone'));
                exit;
            }

            $conv = zapi_buscar_ou_criar_conversa($telefone, $numero, $nome);
            if (!$conv) {
                $log("[{$numero}] falha ao criar conversa");
                echo json_encode(array('status' => 'conv_error'));
                exit;
            }

            $tipo     = zapi_detecta_tipo($payload);
            $conteudo = zapi_extrai_conteudo($payload, $tipo);
            $arquivo  = zapi_extrai_arquivo($payload, $tipo);

            $msgId = zapi_salvar_mensagem_recebida($conv['id'], $payload, $tipo, $conteudo, $arquivo, $zapiMsgId);

            // Atualiza resumo da conversa
            $ultMsg = $conteudo ?: ('[' . $tipo . ']');
            $pdo->prepare(
                "UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                 nao_lidas = nao_lidas + 1,
                 nome_contato = COALESCE(NULLIF(nome_contato,''), ?)
                 WHERE id = ?"
            )->execute(array(mb_substr($ultMsg, 0, 500), $nome, $conv['id']));

            // Auto-resposta fora do horário (1ª mensagem do dia da conversa)
            if (zapi_fora_horario() && (int)$conv['nao_lidas'] === 0) {
                $tpl = zapi_get_template('Fora do horário', array('nome' => $nome ?: 'cliente'));
                if ($tpl) {
                    zapi_send_text($numero, $telefone, $tpl);
                    $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, direcao, tipo, conteudo, enviado_por_bot, status) VALUES (?, 'enviada', 'texto', ?, 1, 'enviada')")
                        ->execute(array($conv['id'], $tpl));
                }
            }

            $log("[{$numero}] msg_id={$msgId} conv_id={$conv['id']} tipo={$tipo}");
            echo json_encode(array('status' => 'ok', 'msg_id' => $msgId, 'conv_id' => $conv['id']));
            break;
        }

        // ── Status de mensagem ──────────────────────────
        case 'MessageStatusCallback':
        case 'DeliveryCallback': {
            $msgId  = $payload['messageId'] ?? ($payload['ids'][0] ?? '');
            $status = $payload['status'] ?? '';
            if ($msgId) {
                $pdo->prepare("UPDATE zapi_mensagens SET status = ?, entregue = IF(? IN ('DELIVERED','RECEIVED','READ'), 1, entregue), lida = IF(?='READ', 1, lida) WHERE zapi_message_id = ?")
                    ->execute(array($status, $status, $status, $msgId));
            }
            echo json_encode(array('status' => 'ok'));
            break;
        }

        // ── Conectado/desconectado ──────────────────────
        case 'ConnectedCallback':
        case 'InstanceConnected': {
            $pdo->prepare("UPDATE zapi_instancias SET conectado = 1, ultima_verificacao = NOW() WHERE ddd = ?")
                ->execute(array($numero));
            echo json_encode(array('status' => 'ok'));
            break;
        }
        case 'DisconnectedCallback':
        case 'InstanceDisconnected': {
            $pdo->prepare("UPDATE zapi_instancias SET conectado = 0, ultima_verificacao = NOW() WHERE ddd = ?")
                ->execute(array($numero));
            echo json_encode(array('status' => 'ok'));
            break;
        }

        default:
            $log("[{$numero}] evento ignorado: {$tipoEvt}");
            echo json_encode(array('status' => 'ignored', 'type' => $tipoEvt));
    }
} catch (Exception $e) {
    $log("[{$numero}] EXCEPTION: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(array('error' => $e->getMessage()));
}
