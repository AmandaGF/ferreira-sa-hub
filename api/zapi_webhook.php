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

// ─── Log estruturado em DB pra debug forense (item 1 do plano de prevenção) ─
// Cada webhook chamada vira 1 linha com payload + estratégia que bateu + conv/msg
// resultantes. Quando der bug, dá pra reconstruir exatamente o que aconteceu.
try { $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_webhook_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    canal VARCHAR(10) NULL,
    tipo_evento VARCHAR(40) NULL,
    payload_json LONGTEXT NULL,
    estrategia_match VARCHAR(40) NULL,
    conversa_id INT NULL,
    mensagem_id INT NULL,
    erro TEXT NULL,
    duracao_ms INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_canal_evt (canal, tipo_evento),
    INDEX idx_conv (conversa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

$_zapiWebhookStart = microtime(true);
$_zapiWebhookCtx = array(
    'estrategia' => null, 'conversa_id' => null, 'mensagem_id' => null, 'erro' => null,
);
function _zapi_webhook_log_finalizar($numero, $tipoEvt, $rawBody) {
    global $_zapiWebhookStart, $_zapiWebhookCtx;
    try {
        $duracao = (int)((microtime(true) - $_zapiWebhookStart) * 1000);
        db()->prepare("INSERT INTO zapi_webhook_log
            (canal, tipo_evento, payload_json, estrategia_match, conversa_id, mensagem_id, erro, duracao_ms)
            VALUES (?,?,?,?,?,?,?,?)")
            ->execute(array(
                $numero, $tipoEvt, mb_substr($rawBody, 0, 8000),
                $_zapiWebhookCtx['estrategia'], $_zapiWebhookCtx['conversa_id'],
                $_zapiWebhookCtx['mensagem_id'], $_zapiWebhookCtx['erro'], $duracao
            ));
    } catch (Exception $e) {}
}
register_shutdown_function('_zapi_webhook_log_finalizar', $numero, $tipoEvt, $rawBody);

try {
    switch ($tipoEvt) {

        // ── Mensagem recebida ───────────────────────────
        case 'ReceivedCallback':
        case 'MessageReceived':
        case 'message': {
            $fromMe = !empty($payload['fromMe']);

            // Z-API pode entregar phone como @lid (ID interno Multi-Device) OU número real.
            // senderPhoneNumber SEMPRE vem com o número real (quando disponível) —
            // priorizar ele resolve o principal vetor de duplicação.
            // Ref: https://www.z-api.io/blog/lid-no-whatsapp-o-que-e-por-que-aparece/
            $phoneRaw        = $payload['phone'] ?? ($payload['author'] ?? '');
            $senderPhoneNum  = $payload['senderPhoneNumber'] ?? '';  // número real (preferido)
            $senderLid       = $payload['senderLid'] ?? '';           // ID @lid do remetente
            $chatLid         = $payload['chatLid'] ?? '';             // identificador "mais estável" oficial
            $participantPhone= $payload['participantPhone'] ?? '';   // só em grupos
            $ehGrupoFlag     = !empty($payload['isGroup']);

            // Decide o telefone "canônico" pra armazenar na conversa:
            // - Se tem senderPhoneNumber (número real) → usa ele
            // - Senão (ex: veio só @lid do Multi-Device) → usa phone como está
            $telefone = ($senderPhoneNum && preg_match('/\d{8,}/', $senderPhoneNum)) ? $senderPhoneNum : $phoneRaw;

            $chatName  = $payload['chatName'] ?? null;
            $ehGrupo   = $ehGrupoFlag || zapi_eh_grupo($phoneRaw, $payload);
            // Se é grupo, sempre usa chatName (nome do grupo). senderName seria o membro
            // específico que escreveu — criaria confusão no CRM.
            // Se é conversa 1:1 fromMe, senderName = nosso escritório → usa chatName.
            // Se é conversa 1:1 não fromMe, senderName = o contato real.
            if ($ehGrupo) {
                $nome = $chatName ?: ('Grupo ' . substr(preg_replace('/[^0-9]/', '', $telefone), -6));
            } else {
                $nome = $fromMe ? $chatName : ($payload['senderName'] ?? $chatName);
            }
            $zapiMsgId = $payload['messageId'] ?? '';

            if (!$telefone) {
                $log("[{$numero}] sem telefone, ignorado");
                echo json_encode(array('status' => 'no_phone'));
                exit;
            }

            // Se fromMe, a mensagem foi ENVIADA pelo celular (ou pelo Hub).
            // Verificar se já existe no banco (Hub insere antes de chamar Z-API, então já estaria lá).
            if ($fromMe) {
                $ja = $pdo->prepare("SELECT id FROM zapi_mensagens WHERE zapi_message_id = ? LIMIT 1");
                $ja->execute(array($zapiMsgId));
                if ($ja->fetchColumn()) {
                    $log("[{$numero}] fromMe já existe (Hub enviou) msgId={$zapiMsgId}, ignorado");
                    echo json_encode(array('status' => 'ignored_duplicate'));
                    exit;
                }
                $log("[{$numero}] fromMe NOVO — mensagem enviada pelo celular msgId={$zapiMsgId} phone={$telefone}");
            }

            // Self-heal: coluna chat_lid pra armazenar identificador LID estável da Z-API.
            // Conforme doc oficial, chatLid é o ID recomendado como primary key do contato.
            try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN chat_lid VARCHAR(50) NULL"); } catch (Exception $e) {}
            try { $pdo->exec("CREATE INDEX idx_chat_lid ON zapi_conversas(chat_lid)"); } catch (Exception $e) {}

            // Prevenção de duplicação — matching em cascata.
            // Problema antigo: usávamos `phone` como chave, mas ele pode vir como @lid
            // (ID interno Multi-Device) OU número real. Criava duplicatas.
            // Solução nova: priorizar chatLid/senderLid (IDs estáveis da Z-API) +
            // senderPhoneNumber (número real) antes de cair no match por nome.
            $ehLid = (strpos($phoneRaw, '@lid') !== false);
            $conv = null;

            // Estratégia 0: Match por CHAT_LID (identificador mais estável segundo Z-API)
            if (!$ehGrupo && !empty($chatLid)) {
                $q0 = $pdo->prepare("SELECT * FROM zapi_conversas
                                     WHERE canal = ? AND chat_lid = ?
                                       AND (eh_grupo = 0 OR eh_grupo IS NULL)
                                     LIMIT 1");
                $q0->execute(array($numero, $chatLid));
                $conv = $q0->fetch();
                if ($conv) { $log("[{$numero}] MATCH-CHATLID chatLid={$chatLid} → conversa #{$conv['id']}"); $_zapiWebhookCtx['estrategia'] = 'CHATLID'; }
            }

            // Estratégia 0a-bis: Match via clients.whatsapp_lid (LID canônico do cliente).
            // Adicionado em 28/Abr/2026 — resolve o bug que criava conversas duplicadas
            // pra clientes que já tinham LID cadastrado, mas a conv antiga deles ainda não
            // tinha chat_lid preenchido (vinha do tempo pré-Sprint 26).
            // Caso real: Enayle Garcia Fontes (id 674, whatsapp_lid=132508599484417@lid)
            // tinha conv 660 sem chat_lid + conv 795 duplicada criada durante o bug @lid.
            // Ao achar a conv mais recente do client_id no canal, ATUALIZA conv.chat_lid
            // pra próximas msgs já baterem na Estratégia 0 acima.
            if (!$conv && !$ehGrupo && ($chatLid || $ehLid)) {
                $lidBuscar = $chatLid ?: $phoneRaw;
                $q0b2 = $pdo->prepare("SELECT c.* FROM zapi_conversas c
                                       JOIN clients cl ON cl.id = c.client_id
                                       WHERE c.canal = ?
                                         AND (cl.whatsapp_lid = ? OR cl.whatsapp_lid = ?)
                                         AND (c.eh_grupo = 0 OR c.eh_grupo IS NULL)
                                       ORDER BY c.ultima_msg_em DESC, c.id DESC LIMIT 1");
                $q0b2->execute(array($numero, $lidBuscar, str_replace('@lid', '', $lidBuscar)));
                $conv = $q0b2->fetch();
                if ($conv) {
                    $log("[{$numero}] MATCH-CLIENT-LID lid={$lidBuscar} → conversa #{$conv['id']} (via clients.whatsapp_lid)");
                    $_zapiWebhookCtx['estrategia'] = 'CLIENT-LID';
                    // Auto-popula chat_lid na conv pra próximas msgs já baterem na Estratégia 0
                    if (empty($conv['chat_lid']) && $chatLid) {
                        $pdo->prepare("UPDATE zapi_conversas SET chat_lid = ? WHERE id = ?")
                            ->execute(array($chatLid, $conv['id']));
                        $log("[{$numero}] auto-upgrade conv #{$conv['id']} chat_lid={$chatLid}");
                    }
                    // Auto-merge agressivo (item 3 do plano): se houver outras conv
                    // duplicadas pro mesmo client_id no canal, mescla todas pra cá.
                    // Resolve sozinho casos legacy sem precisar de script manual.
                    if (!empty($conv['client_id'])) {
                        $merged = zapi_auto_merge_por_client_id($pdo, (int)$conv['id'], (int)$conv['client_id'], $numero);
                        if ($merged > 0) $log("[{$numero}] auto-merge: {$merged} conversa(s) mescladas em #{$conv['id']}");
                    }
                }
            }

            // Estratégia 0b: Match por TELEFONE REAL (senderPhoneNumber)
            // Se temos o número real, procura conversa que já tenha esse número,
            // mesmo que a conversa atual esteja com @lid.
            if (!$conv && !$ehGrupo && $senderPhoneNum && preg_match('/\d{8,}/', $senderPhoneNum)) {
                $telReal = preg_replace('/\D/', '', $senderPhoneNum);
                $ult10 = strlen($telReal) >= 10 ? substr($telReal, -10) : $telReal;
                $q0b = $pdo->prepare("SELECT * FROM zapi_conversas
                                      WHERE canal = ?
                                        AND (eh_grupo = 0 OR eh_grupo IS NULL)
                                        AND RIGHT(REPLACE(REPLACE(telefone,'@lid',''),'@g.us',''), 10) = ?
                                      ORDER BY ultima_msg_em DESC LIMIT 1");
                $q0b->execute(array($numero, $ult10));
                $conv = $q0b->fetch();
                if ($conv) { $log("[{$numero}] MATCH-TELREAL senderPhone={$senderPhoneNum} → conversa #{$conv['id']}"); $_zapiWebhookCtx['estrategia'] = 'TELREAL'; }
            }

            // Estratégia 0c: Match por @LID PURO (phone igual a LID)
            // Quando o payload não traz chatLid nem chatName (ex: isEdit=true em
            // mensagens editadas), mas o phone é @lid, procura conversa existente
            // que tenha esse mesmo @lid em chat_lid OU em telefone.
            if (!$conv && !$ehGrupo && $ehLid) {
                $q0c = $pdo->prepare("SELECT * FROM zapi_conversas
                                      WHERE canal = ?
                                        AND (eh_grupo = 0 OR eh_grupo IS NULL)
                                        AND (chat_lid = ? OR telefone = ?)
                                      ORDER BY ultima_msg_em DESC LIMIT 1");
                $q0c->execute(array($numero, $phoneRaw, $phoneRaw));
                $conv = $q0c->fetch();
                if ($conv) { $log("[{$numero}] MATCH-LID phone={$phoneRaw} → conversa #{$conv['id']}"); $_zapiWebhookCtx['estrategia'] = 'LID-PURO'; }
            }

            // Estratégia 0d: isEdit — acha conversa via editMessageId (mensagem
            // original). Payloads de edição não trazem chatLid/chatName, e o phone
            // pode ser @lid. A mensagem original já está no banco com conversa_id.
            if (!$conv && !empty($payload['isEdit']) && !empty($payload['editMessageId'])) {
                $q0d = $pdo->prepare("SELECT c.* FROM zapi_conversas c
                                      JOIN zapi_mensagens m ON m.conversa_id = c.id
                                      WHERE m.zapi_message_id = ? AND c.canal = ?
                                      ORDER BY m.id DESC LIMIT 1");
                $q0d->execute(array($payload['editMessageId'], $numero));
                $conv = $q0d->fetch();
                if ($conv) { $log("[{$numero}] MATCH-EDIT editMessageId={$payload['editMessageId']} → conversa #{$conv['id']}"); $_zapiWebhookCtx['estrategia'] = 'EDIT'; }
            }

            // Estratégias 1 e 2 (MATCH por NOME EXATO e PARCIAL) REMOVIDAS em 24/Abr/2026.
            // Contexto: match por nome causava cruzamento catastrófico entre clientes
            // diferentes (ex.: msg de JOSE caía na conversa da Alícia porque algum
            // campo de nome batia). Identificadores canônicos são chatLid e
            // senderPhoneNumber — nome é label visual, NÃO chave de matching.
            //
            // Cai pra Estratégia 3 (telefone puro) se as anteriores (0, 0b, 0c, 0d) falharem.

            // Estratégia 3: telefone "puro" igual (casos muito raros onde Z-API troca formato)
            if (!$conv && !$ehLid && !$ehGrupo) {
                $telPuro = preg_replace('/\D/', '', $telefone);
                if ($telPuro && strlen($telPuro) >= 10) {
                    $q = $pdo->prepare("SELECT * FROM zapi_conversas
                                        WHERE canal = ?
                                          AND REPLACE(telefone,'@lid','') LIKE ?
                                          AND (eh_grupo = 0 OR eh_grupo IS NULL)
                                        ORDER BY ultima_msg_em DESC LIMIT 1");
                    $q->execute(array($numero, '%' . substr($telPuro, -10) . '%'));
                    $conv = $q->fetch();
                    if ($conv) { $log("[{$numero}] MATCH-TEL tel-final='{$telPuro}' → conversa #{$conv['id']}"); $_zapiWebhookCtx['estrategia'] = 'TEL-PURO'; }
                }
            }

            if (!$conv) {
                $conv = zapi_buscar_ou_criar_conversa($telefone, $numero, $nome, $ehGrupo);
                $_zapiWebhookCtx['estrategia'] = 'NOVA';
            } elseif ($ehGrupo && !empty($chatName)) {
                // Conversa de grupo já existente: mantém nome_contato sincronizado com
                // o chatName atual (nome do grupo pode ter mudado, ou foi gravado
                // errado antes do fix de detecção de grupo).
                $nomeAtual = $conv['nome_contato'] ?? '';
                $novoNomeGrupo = '👥 ' . $chatName;
                if ($nomeAtual !== $novoNomeGrupo) {
                    $pdo->prepare("UPDATE zapi_conversas SET nome_contato = ?, eh_grupo = 1 WHERE id = ?")
                        ->execute(array($novoNomeGrupo, $conv['id']));
                    $conv['nome_contato'] = $novoNomeGrupo;
                    $conv['eh_grupo'] = 1;
                }
            }
            if (!$conv) {
                $log("[{$numero}] falha ao criar conversa");
                echo json_encode(array('status' => 'conv_error'));
                exit;
            }

            // ── DEFESA fromMe (24/Abr/2026) ──
            // Se é fromMe (mensagem enviada pelo escritório) e o telefone do payload
            // NÃO bate com o telefone da conversa achada, CRIA conversa nova em vez
            // de gravar msg em conversa errada. Evita o cruzamento entre clientes
            // diferentes (ex: msgs do cron de aniversário caindo todas na mesma conv).
            // Match aceitável: últimos 10 dígitos iguais, OU ambos têm mesmo @lid.
            if ($fromMe && !$ehGrupo && $conv) {
                $telPayload = preg_replace('/\D/', '', str_replace(array('@lid','@g.us'), '', (string)$telefone));
                $telConv    = preg_replace('/\D/', '', str_replace(array('@lid','@g.us'), '', (string)$conv['telefone']));
                $match10 = (strlen($telPayload) >= 10 && strlen($telConv) >= 10 && substr($telPayload, -10) === substr($telConv, -10));
                $matchLid = ($chatLid && $conv['chat_lid'] === $chatLid);
                if (!$match10 && !$matchLid) {
                    $log("[{$numero}] fromMe DIVERGENTE — payload tel={$telefone} conv#{$conv['id']} tel={$conv['telefone']}. Criando conv nova.");
                    $conv = zapi_buscar_ou_criar_conversa($telefone, $numero, $nome, false);
                    if (!$conv) {
                        echo json_encode(array('status' => 'conv_error_fromme'));
                        exit;
                    }
                    // Se mesmo assim voltou uma conv que não bate, aborta (safe)
                    $telConv2 = preg_replace('/\D/', '', str_replace(array('@lid','@g.us'), '', (string)$conv['telefone']));
                    if (strlen($telPayload) >= 10 && strlen($telConv2) >= 10 && substr($telPayload, -10) !== substr($telConv2, -10) && !($chatLid && $conv['chat_lid'] === $chatLid)) {
                        $log("[{$numero}] fromMe ainda DIVERGENTE após buscar/criar. Abortando pra não gravar em conv errada.");
                        echo json_encode(array('status' => 'fromMe_mismatch_abort'));
                        exit;
                    }
                }
            }

            // Captura foto de perfil do payload (vem pronta no webhook — não precisa
            // chamar /profile-picture). `photo` = foto do chat (em 1:1 = avatar do
            // contato; em grupo = ícone do grupo). `senderPhoto` = de quem escreveu.
            // Ref: https://developer.z-api.io/en/webhooks/on-message-received
            //
            // Regra: prefere 'photo' pro avatar do chat (uniforme 1:1 e grupo).
            // Salva local SEMPRE (link WhatsApp expira em 48h) via helper dedicado.
            $fotoPayload = '';
            if (!empty($payload['photo']) && is_string($payload['photo'])) {
                $fotoPayload = $payload['photo'];
            } elseif (!empty($payload['senderPhoto']) && is_string($payload['senderPhoto']) && !$fromMe) {
                // senderPhoto só se não for fromMe (senão pega o avatar do escritório)
                $fotoPayload = $payload['senderPhoto'];
            }
            if ($fotoPayload && strpos($fotoPayload, 'http') === 0) {
                try {
                    // Atualiza se: não tem foto salva OU a URL mudou (contato trocou de foto)
                    $st = $pdo->prepare("SELECT foto_perfil_url FROM zapi_conversas WHERE id = ?");
                    $st->execute(array($conv['id']));
                    $fotoAtual = (string)$st->fetchColumn();
                    if ($fotoAtual !== $fotoPayload) {
                        // Baixa e salva local — URL expira em 48h
                        zapi_salvar_foto_webhook($conv['id'], $fotoPayload);
                    }
                } catch (Exception $eFoto) {}
            }

            // Upgrade: se a conversa existente tem telefone @lid mas agora recebemos
            // o número real via senderPhoneNumber, atualiza pro número real.
            // Também salva chat_lid/sender_lid se ainda não estavam preenchidos.
            if (!$ehGrupo) {
                $updates = array(); $params = array();
                $telConvAtual = (string)($conv['telefone'] ?? '');
                $convTemLid = strpos($telConvAtual, '@lid') !== false;
                if ($convTemLid && $senderPhoneNum && preg_match('/\d{8,}/', $senderPhoneNum)) {
                    $telRealNorm = zapi_normaliza_telefone($senderPhoneNum);
                    $updates[] = 'telefone = ?'; $params[] = $telRealNorm;
                    $log("[{$numero}] UPGRADE tel @lid → {$telRealNorm} na conversa #{$conv['id']}");
                }
                if ($chatLid && empty($conv['chat_lid'])) {
                    $updates[] = 'chat_lid = ?'; $params[] = $chatLid;
                }
                // FIX 24/Abr/2026: NÃO mais copiar o telefone (@lid bruto) pro
                // chat_lid quando este está vazio. Isso auto-infectava o campo
                // canônico e cruzava conversas de pessoas diferentes. Se chat_lid
                // real não chegou, deixa NULL e aguarda próximo webhook com LID
                // legítimo. Estratégia 0c (match por telefone @lid) continua
                // funcionando via coluna `telefone`.
                if (!empty($updates)) {
                    $params[] = $conv['id'];
                    try { $pdo->prepare("UPDATE zapi_conversas SET " . implode(',', $updates) . " WHERE id = ?")->execute($params); } catch (Exception $e) {}
                }

                // VINCULAÇÃO DE CLIENTE POR @lid (24/Abr/2026) ──
                // Se a conversa ainda não tem client_id mas temos chat_lid,
                // procura cliente cujo whatsapp_lid bate e vincula automaticamente.
                // clients.whatsapp_lid é populado via backfill_client_lids.php
                // consultando /phone-exists — @lid é identificador único e fixo.
                $lidPraMatch = $chatLid ?: ((!empty($conv['chat_lid'])) ? $conv['chat_lid'] : '');
                if (empty($conv['client_id']) && $lidPraMatch) {
                    try {
                        $clst = $pdo->prepare("SELECT id FROM clients WHERE whatsapp_lid = ? LIMIT 1");
                        $clst->execute(array($lidPraMatch));
                        $cidMatch = $clst->fetchColumn();
                        if ($cidMatch) {
                            $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")->execute(array($cidMatch, $conv['id']));
                            $conv['client_id'] = $cidMatch;
                            $log("[{$numero}] VINCULO-LID conv#{$conv['id']} → client#{$cidMatch} via whatsapp_lid={$lidPraMatch}");
                        }
                    } catch (Exception $e) {}
                }
            }

            $tipo     = zapi_detecta_tipo($payload);
            $conteudo = zapi_extrai_conteudo($payload, $tipo);
            $arquivo  = zapi_extrai_arquivo($payload, $tipo);

            // Se ainda ficou como 'outro', logar payload completo pra análise
            if ($tipo === 'outro') {
                $log("[{$numero}] TIPO_OUTRO payload=" . substr(json_encode($payload), 0, 2000));
            }

            // REAÇÃO: associa à mensagem original em vez de criar msg nova.
            // - fromMe=false (contato reagiu): atualiza reacao_cliente
            // - fromMe=true  (atendente reagiu pelo celular): atualiza minha_reacao
            // IMPORTANTE: reações NUNCA viram mensagem no chat, mesmo que o match falhe
            // (evita poluir visual com "[reagiu com X]"). Se não achar alvo, loga pra análise
            // e descarta silenciosamente.
            if ($tipo === 'reacao') {
                $emoji = $payload['reaction']['value']
                      ?? ($payload['reaction']['reaction']
                      ?? ($payload['reactionMessage']['text']
                      ?? ($payload['message']['reactionMessage']['text'] ?? '')));
                // Varia bastante por versão Z-API: tentar múltiplos paths
                $alvoId = $payload['reaction']['msgId']
                       ?? ($payload['reaction']['messageId']
                       ?? ($payload['reaction']['referencedMessage']['key']['id']
                       ?? ($payload['reactionMessage']['messageId']
                       ?? ($payload['message']['reactionMessage']['key']['id']
                       ?? ($payload['referenceMessageId'] ?? '')))));
                try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN reacao_cliente VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
                try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN minha_reacao VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
                if ($alvoId) {
                    $coluna = $fromMe ? 'minha_reacao' : 'reacao_cliente';
                    // Match por zapi_message_id apenas (é único global). Antes filtrávamos
                    // por conversa_id também, mas se a mensagem original está numa conversa
                    // duplicada/mesclada, o UPDATE falhava e a reação sumia.
                    $stmtR = $pdo->prepare("UPDATE zapi_mensagens SET {$coluna} = ? WHERE zapi_message_id = ?");
                    $stmtR->execute(array($emoji !== '' ? $emoji : null, $alvoId));
                    $log("[{$numero}] reacao " . ($fromMe ? 'fromMe' : 'contato') . " '{$emoji}' → msg_zapi_id={$alvoId} rows=" . $stmtR->rowCount());
                    echo json_encode(array('status' => 'reaction_applied', 'emoji' => $emoji, 'fromMe' => $fromMe));
                } else {
                    // Não achou alvo — log detalhado pra análise do payload, mas NÃO grava
                    // mensagem no chat.
                    $log("[{$numero}] REACAO_ORFA emoji='{$emoji}' fromMe=" . ($fromMe ? '1' : '0') . " conv={$conv['id']} payload=" . substr(json_encode($payload), 0, 1500));
                    echo json_encode(array('status' => 'reaction_orphan', 'emoji' => $emoji));
                }
                break; // sempre descarta como mensagem (não cai no INSERT)
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
                $_zapiWebhookCtx['conversa_id'] = (int)$conv['id'];
                $_zapiWebhookCtx['mensagem_id'] = $msgId;

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

            // Atualiza resumo da conversa.
            // Se a conversa estava 'resolvido', REABRE automaticamente — cliente
            // voltou a falar, precisa ser atendido de novo. Volta pra em_atendimento
            // se tinha atendente, ou pra aguardando se não tinha.
            $ultMsg = $conteudo ?: ('[' . $tipo . ']');
            $pdo->prepare(
                "UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                 nao_lidas = nao_lidas + 1,
                 nome_contato = COALESCE(NULLIF(nome_contato,''), ?),
                 status = CASE
                     WHEN status = 'resolvido' AND atendente_id IS NOT NULL THEN 'em_atendimento'
                     WHEN status = 'resolvido' THEN 'aguardando'
                     ELSE status
                 END
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
