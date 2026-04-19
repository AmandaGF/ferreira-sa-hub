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
            $telefone  = $payload['phone'] ?? ($payload['author'] ?? '');
            $nome      = $payload['senderName'] ?? ($payload['chatName'] ?? null);
            $zapiMsgId = $payload['messageId'] ?? '';

            if (!$telefone) {
                $log("[{$numero}] sem telefone, ignorado");
                echo json_encode(array('status' => 'no_phone'));
                exit;
            }

            // Se fromMe, a mensagem foi ENVIADA pelo celular (ou pelo Hub).
            // Verificar se já existe no banco (Hub insere antes de chamar Z-API, então já estaria lá).
            // Se não existe → foi pelo celular e precisamos salvar pra espelhar no Hub.
            if ($fromMe) {
                $ja = $pdo->prepare("SELECT id FROM zapi_mensagens WHERE zapi_message_id = ? LIMIT 1");
                $ja->execute(array($zapiMsgId));
                if ($ja->fetchColumn()) {
                    $log("[{$numero}] fromMe já existe (Hub enviou) msgId={$zapiMsgId}, ignorado");
                    echo json_encode(array('status' => 'ignored_duplicate'));
                    exit;
                }
                $log("[{$numero}] fromMe NOVO — mensagem enviada pelo celular msgId={$zapiMsgId}");
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

            // Se ainda ficou como 'outro', logar payload completo pra análise
            if ($tipo === 'outro') {
                $log("[{$numero}] TIPO_OUTRO payload=" . substr(json_encode($payload), 0, 2000));
            }

            if ($fromMe) {
                // Mensagem enviada pelo celular — espelhar como 'enviada' no Hub
                $tiposValidos = array('texto','imagem','documento','audio','video','sticker','localizacao','contato','outro');
                if (!in_array($tipo, $tiposValidos, true)) $tipo = 'outro';
                $pdo->prepare(
                    "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo,
                        arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho, status)
                     VALUES (?, ?, 'enviada', ?, ?, ?, ?, ?, ?, 'enviada')"
                )->execute(array(
                    $conv['id'], $zapiMsgId, $tipo, $conteudo,
                    $arquivo['url']  ?? null, $arquivo['nome'] ?? null,
                    $arquivo['mime'] ?? null, $arquivo['tamanho'] ?? null,
                ));
                $msgId = (int)$pdo->lastInsertId();

                // Atualiza resumo (sem incrementar não-lidas)
                $ultMsg = $conteudo ?: ('[' . $tipo . ']');
                $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW() WHERE id = ?")
                    ->execute(array(mb_substr($ultMsg, 0, 500), $conv['id']));

                $log("[{$numero}] fromMe salvo msg_id={$msgId} conv_id={$conv['id']} tipo={$tipo}");
                echo json_encode(array('status' => 'ok_fromMe', 'msg_id' => $msgId, 'conv_id' => $conv['id']));
                break; // não disparar automações quando nós que mandamos
            }

            $msgId = zapi_salvar_mensagem_recebida($conv['id'], $payload, $tipo, $conteudo, $arquivo, $zapiMsgId);

            // ── TRANSCRIÇÃO DE ÁUDIO via Groq ──
            if ($tipo === 'audio' && $msgId) {
                require_once APP_ROOT . '/core/functions_groq.php';
                if (groq_transcribe_enabled()) {
                    try {
                        $t = groq_transcribe_mensagem($msgId);
                        if (empty($t['ok'])) $log("[{$numero}] transcricao falhou msg={$msgId}: " . ($t['erro'] ?? '?'));
                        else $log("[{$numero}] transcricao OK msg={$msgId} (" . strlen($t['text']) . " chars)");
                    } catch (Exception $e) { $log("[{$numero}] transcricao EXCEPTION: " . $e->getMessage()); }
                }
            }

            // Checa se é primeira mensagem da conversa (antes de atualizar o contador)
            $totalMsgs = (int)$pdo->query("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = " . (int)$conv['id'] . " AND direcao = 'recebida'")->fetchColumn();
            $ehPrimeira = ($totalMsgs === 1);

            // Atualiza resumo da conversa
            $ultMsg = $conteudo ?: ('[' . $tipo . ']');
            $pdo->prepare(
                "UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                 nao_lidas = nao_lidas + 1,
                 nome_contato = COALESCE(NULLIF(nome_contato,''), ?)
                 WHERE id = ?"
            )->execute(array(mb_substr($ultMsg, 0, 500), $nome, $conv['id']));

            // ── AUTOMAÇÃO 1: Fora do horário ──
            if (zapi_auto_cfg('zapi_auto_fora_horario', '1') === '1' && zapi_fora_horario() && (int)$conv['nao_lidas'] === 0) {
                $tplNome = zapi_auto_cfg('zapi_auto_fora_horario_tpl', 'Fora do horário');
                $tpl = zapi_get_template($tplNome, array('nome' => $nome ?: 'cliente'));
                if ($tpl) {
                    zapi_send_text($numero, $telefone, $tpl);
                    $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, direcao, tipo, conteudo, enviado_por_bot, status) VALUES (?, 'enviada', 'texto', ?, 1, 'enviada')")
                        ->execute(array($conv['id'], $tpl));
                }
            }

            // ── AUTOMAÇÃO 2: Boas-vindas ao primeiro contato ──
            if (zapi_auto_cfg('zapi_auto_boasvindas', '0') === '1' && $ehPrimeira && !zapi_fora_horario()) {
                $canalBv = zapi_auto_cfg('zapi_auto_boasvindas_canal', '21');
                if ($canalBv === 'ambos' || $canalBv === $numero) {
                    $tplNome = zapi_auto_cfg('zapi_auto_boasvindas_tpl', 'Boas-vindas Comercial');
                    $tpl = zapi_get_template($tplNome, array('nome' => $nome ?: 'cliente'));
                    if ($tpl) {
                        zapi_send_text($numero, $telefone, $tpl);
                        $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, direcao, tipo, conteudo, enviado_por_bot, status) VALUES (?, 'enviada', 'texto', ?, 1, 'enviada')")
                            ->execute(array($conv['id'], $tpl));
                    }
                }
            }

            // ── BOT IA (DDD 21 apenas, se bot_ativo nessa conversa) ──
            if ($numero === '21' && (int)$conv['bot_ativo'] === 1 && zapi_auto_cfg('zapi_bot_ia_ativo', '0') === '1') {
                require_once APP_ROOT . '/core/functions_bot_ia.php';
                try { bot_ia_processar($conv['id'], $conteudo); }
                catch (Exception $e) { $log("[{$numero}] BOT_IA ERRO: " . $e->getMessage()); }
            }

            // ── AUTOMAÇÃO 3: Confirmação de documento no DDD 24 ──
            if (zapi_auto_cfg('zapi_auto_doc_24', '0') === '1' && $numero === '24' && in_array($tipo, array('documento','imagem','video'), true)) {
                $tplNome = zapi_auto_cfg('zapi_auto_doc_24_tpl', 'Confirmação de documentos');
                $tpl = zapi_get_template($tplNome, array('nome' => $nome ?: 'cliente'));
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
                // NÃO atualiza status se a mensagem foi apagada via Hub (preserva status='deletada')
                $pdo->prepare("UPDATE zapi_mensagens
                               SET status = ?,
                                   entregue = IF(? IN ('DELIVERED','RECEIVED','READ'), 1, entregue),
                                   lida = IF(?='READ', 1, lida)
                               WHERE zapi_message_id = ? AND status != 'deletada'")
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
