<?php
/**
 * Ferreira & Sá Hub — Helpers Z-API (WhatsApp CRM)
 * Credenciais armazenadas no banco (zapi_instancias + configuracoes).
 */

/**
 * Retorna config global (base_url + client_token)
 */
function zapi_get_config() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array(
        'base_url'     => 'https://api.z-api.io/instances',
        'client_token' => '',
    );
    try {
        $rows = db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'zapi_%'")->fetchAll();
        foreach ($rows as $r) {
            if ($r['chave'] === 'zapi_base_url' && $r['valor']) $cache['base_url'] = $r['valor'];
            if ($r['chave'] === 'zapi_client_token') $cache['client_token'] = $r['valor'];
        }
    } catch (Exception $e) { /* tabela pode não existir */ }
    return $cache;
}

/**
 * Retorna dados da instância pelo DDD ('21' ou '24').
 */
function zapi_get_instancia($ddd) {
    $stmt = db()->prepare("SELECT * FROM zapi_instancias WHERE ddd = ? AND ativo = 1 LIMIT 1");
    $stmt->execute(array($ddd));
    return $stmt->fetch();
}

/**
 * Verifica se a instância está configurada (tem ID e token).
 */
function zapi_instancia_configurada($ddd) {
    $inst = zapi_get_instancia($ddd);
    return $inst && $inst['instancia_id'] !== '' && $inst['token'] !== '';
}

/**
 * Adiciona sugestão de mensagem à fila (caixa de envios pendentes).
 * Uso: zapi_fila_enfileirar('andamento', $clientId, $telefone, 'Olá...', array('case_id' => 123));
 */
function zapi_fila_enfileirar($origem, $clientId, $telefone, $mensagem, $opts = array()) {
    $pdo = db();
    // Self-heal: coluna origem_id pra ligar a fila ao objeto de origem (andamento, cobrança, etc)
    try { $pdo->exec("ALTER TABLE zapi_fila_envio ADD COLUMN origem_id INT UNSIGNED NULL"); } catch (Exception $e) {}
    try {
        // Evita duplicar: se já existe pendente pro mesmo client + origem + mensagem recente, ignora
        $stmtDup = $pdo->prepare("SELECT id FROM zapi_fila_envio
            WHERE origem = ? AND client_id = ? AND status = 'pendente'
              AND mensagem = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1");
        $stmtDup->execute(array($origem, $clientId, $mensagem));
        if ($stmtDup->fetchColumn()) return null; // já tem sugestão igual recente

        $sql = "INSERT INTO zapi_fila_envio (origem, origem_id, client_id, case_id, lead_id, telefone, nome_contato, canal_sugerido, mensagem, criada_por)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $pdo->prepare($sql)->execute(array(
            $origem,
            isset($opts['origem_id']) ? (int)$opts['origem_id'] : null,
            $clientId ?: null,
            $opts['case_id'] ?? null,
            $opts['lead_id'] ?? null,
            preg_replace('/\D/', '', $telefone),
            $opts['nome'] ?? null,
            in_array($opts['canal'] ?? '24', array('21','24'), true) ? $opts['canal'] : '24',
            $mensagem,
            $opts['criada_por'] ?? null,
        ));
        return (int)$pdo->lastInsertId();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Envia texto via Z-API.
 * @param string $replyTo (opcional) — zapi_message_id da mensagem sendo respondida.
 *                         Se preenchido, Z-API exibe o "quoted" no WhatsApp do destinatário.
 * @return array ['ok' => bool, 'data' => mixed, 'http_code' => int]
 */
function zapi_send_text($ddd, $telefone, $mensagem, $replyTo = null) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada para DDD ' . $ddd);
    }

    // ── DEFESA MÍNIMA contra bug do @lid (24/Abr/2026) ──
    // NUNCA enviar pra @lid bruto — Z-API entrega pro DONO ATUAL do LID, que pode
    // ter mudado. Também valida que o resultado numérico tem comprimento plausível
    // (10-13 dígitos). Contexto: conv 686 (Alícia) tinha telefone=99037785145538@lid
    // e mensagens dessa conv (parabéns JOSE, "Vamos liberar") iam parar em WhatsApps
    // aleatórios. Fix completo (match por chat_lid, etc) fica pra depois.
    if (stripos((string)$telefone, '@lid') !== false || stripos((string)$telefone, '@s.whatsapp.net') !== false) {
        @error_log('[zapi_send_text] BLOQUEADO: envio pra @lid — ddd=' . $ddd . ' tel=' . $telefone);
        return array('ok' => false, 'erro' => 'Envio bloqueado: telefone e um @lid (identificador interno WhatsApp), nao um numero real. Atualize o contato com o numero E.164 correto antes de enviar.');
    }
    $_digits = preg_replace('/\D/', '', (string)$telefone);
    // Aceita 10+ dígitos. Antes limitava a 13/14, mas conversas legítimas usam
    // @lid numérico (15+ dígitos) como endereço funcional quando a Z-API já tem
    // histórico dele. A proteção real contra msg indo pra contato errado é a
    // "defesa fromMe" no webhook (api/zapi_webhook.php) — se o payload de
    // confirmação não bate com a conv achada, o Hub cria conv nova em vez de
    // gravar em conv cruzada. Esse fluxo vale aqui também (no pior caso o
    // envio vai pra contato certo e o histórico é registrado corretamente).
    if (strlen($_digits) < 10) {
        @error_log('[zapi_send_text] BLOQUEADO: telefone curto demais — ddd=' . $ddd . ' tel=' . $telefone . ' digits=' . strlen($_digits));
        return array('ok' => false, 'erro' => 'Envio bloqueado: telefone "' . $telefone . '" tem menos de 10 digitos, nao e um numero valido.');
    }

    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/send-text';
    $telefone_norm = zapi_normaliza_telefone($telefone);

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $body = array('phone' => $telefone_norm, 'message' => $mensagem);
    if (!empty($replyTo)) { $body['messageId'] = $replyTo; }

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $json = json_decode($resp, true);
    return array(
        'ok'        => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'data'      => $json ?: $resp,
        'erro'      => $err,
    );
}

/**
 * Envia imagem via Z-API.
 * $imagem pode ser URL HTTPS pública OU base64 (data URI ou raw).
 */
function zapi_send_image($ddd, $telefone, $imagem, $caption = '') {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada');
    }
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/send-image';

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $body = array(
        'phone'   => zapi_normaliza_telefone($telefone),
        'image'   => $imagem,
        'caption' => $caption,
    );
    return _zapi_post($url, $headers, $body);
}

/**
 * Envia documento via Z-API.
 * $doc pode ser URL HTTPS pública OU base64 (data URI).
 */
function zapi_send_document($ddd, $telefone, $doc, $fileName, $caption = '') {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada');
    }
    $cfg = zapi_get_config();
    // Z-API endpoint varia: /send-document/{ext} — derivamos do fileName
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'pdf';
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/send-document/' . $ext;

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $body = array(
        'phone'    => zapi_normaliza_telefone($telefone),
        'document' => $doc,
        'fileName' => $fileName,
        'caption'  => $caption,
    );
    return _zapi_post($url, $headers, $body);
}

/**
 * Envia áudio (nota de voz) via Z-API.
 * $audio: URL HTTPS pública OU base64 (data URI). Formatos: mp3, ogg, m4a, wav, webm.
 * $asPtt: true = aparece como mensagem de voz (PTT), false = áudio comum.
 */
function zapi_send_audio($ddd, $telefone, $audio, $asPtt = true) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada');
    }
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/send-audio';

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $body = array(
        'phone'    => zapi_normaliza_telefone($telefone),
        'audio'    => $audio,
        'waveform' => (bool)$asPtt,
        'viewOnce' => false,
    );
    return _zapi_post($url, $headers, $body);
}

/**
 * Envia sticker via Z-API.
 * $sticker: URL HTTPS de um .webp OU base64 (data URI).
 * Stickers do WhatsApp são sempre .webp 512x512.
 */
function zapi_send_sticker($ddd, $telefone, $sticker) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada');
    }
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/send-sticker';

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $body = array(
        'phone'   => zapi_normaliza_telefone($telefone),
        'sticker' => $sticker,
    );
    return _zapi_post($url, $headers, $body);
}

/**
 * Envia reação (emoji) a uma mensagem específica via Z-API.
 * $emoji pode ser '' pra remover a reação.
 */
function zapi_send_reaction($ddd, $telefone, $zapiMessageId, $emoji) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada');
    }
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/send-reaction';

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $body = array(
        'phone'     => zapi_normaliza_telefone($telefone),
        'messageId' => $zapiMessageId,
        'reaction'  => $emoji,
    );
    return _zapi_post($url, $headers, $body);
}

/**
 * Deleta uma mensagem no WhatsApp via Z-API (remove para todos).
 */
function zapi_delete_message($ddd, $telefone, $zapiMessageId) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada');
    }
    $cfg = zapi_get_config();
    $telefone_norm = zapi_normaliza_telefone($telefone);
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token']
         . '/messages?phone=' . urlencode($telefone_norm)
         . '&messageId=' . urlencode($zapiMessageId)
         . '&owner=true';

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array(
        'ok'        => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'data'      => json_decode($resp, true) ?: $resp,
        'erro'      => $err,
    );
}

/**
 * Edita uma mensagem de texto já enviada (WhatsApp permite até 15 min).
 * Z-API: tenta /send-message-edit com vários nomes de campo comuns até funcionar.
 */
function zapi_edit_message($ddd, $telefone, $zapiMessageId, $novoTexto) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada');
    }
    $cfg = zapi_get_config();
    $base = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'];

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $phoneNorm = zapi_normaliza_telefone($telefone);
    $logFile = APP_ROOT . '/files/zapi_edit.log';

    // Lista de endpoints + payloads pra tentar (Z-API varia por versão)
    $variantes = array(
        array('url' => $base . '/send-text-edit',     'body' => array('phone' => $phoneNorm, 'messageId' => $zapiMessageId, 'message' => $novoTexto)),
        array('url' => $base . '/edit-message',        'body' => array('phone' => $phoneNorm, 'messageId' => $zapiMessageId, 'message' => $novoTexto)),
        array('url' => $base . '/messages/edit',       'body' => array('phone' => $phoneNorm, 'messageId' => $zapiMessageId, 'message' => $novoTexto)),
        array('url' => $base . '/edit-text',           'body' => array('phone' => $phoneNorm, 'messageId' => $zapiMessageId, 'message' => $novoTexto)),
        array('url' => $base . '/send-message-edit',   'body' => array('phone' => $phoneNorm, 'messageId' => $zapiMessageId, 'text'    => $novoTexto)),
        array('url' => $base . '/send-message-edit',   'body' => array('phone' => $phoneNorm, 'messageId' => $zapiMessageId, 'message' => $novoTexto)),
    );

    $ultimo = null;
    foreach ($variantes as $v) {
        $r = _zapi_post($v['url'], $headers, $v['body']);
        $ultimo = $r;
        $respStr = substr(json_encode($r['data'] ?? ''), 0, 300);
        @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] URL=' . $v['url'] . ' body=' . json_encode($v['body']) . ' http=' . ($r['http_code'] ?? '?') . ' resp=' . $respStr . "\n", FILE_APPEND);

        // Descartar NOT_FOUND explicitamente
        if (is_array($r['data']) && isset($r['data']['error']) && stripos($r['data']['error'], 'NOT_FOUND') !== false) continue;

        if (!empty($r['ok'])) {
            $data = $r['data'];
            if (is_array($data) && (isset($data['id']) || isset($data['messageId']) || isset($data['zaapId']) || isset($data['value']))) {
                @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] SUCESSO com URL=' . $v['url'] . "\n", FILE_APPEND);
                return $r;
            }
        }
    }
    return $ultimo ?: array('ok' => false, 'erro' => 'Edit não suportado por nenhum endpoint Z-API testado');
}

function _zapi_post($url, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $json = json_decode($resp, true);
    return array(
        'ok'        => ($code >= 200 && $code < 300),
        'http_code' => $code,
        'data'      => $json ?: $resp,
        'erro'      => $err,
    );
}

/**
 * Normaliza telefone para formato internacional Z-API (5521999999999).
 * Se for ID de grupo (@g.us), preserva o sufixo pra distinguir.
 */
function zapi_normaliza_telefone($telefone) {
    $raw = (string)$telefone;
    $ehGrupo = strpos($raw, '@g.us') !== false || strpos($raw, '@broadcast') !== false;
    $num = preg_replace('/[^0-9]/', '', $raw);
    if (!$ehGrupo) {
        // Se começa com 0, remove
        if (strlen($num) > 11 && substr($num, 0, 1) === '0') $num = ltrim($num, '0');
        // Se não começa com 55 (Brasil), adiciona
        if (strlen($num) === 10 || strlen($num) === 11) $num = '55' . $num;
        return $num;
    }
    // Grupo: preserva @g.us pra diferenciar de telefones reais
    return $num . '@g.us';
}

/**
 * Detecta se o payload da Z-API representa uma conversa de grupo.
 */
function zapi_eh_grupo($telefone_raw, $payload = null) {
    if (is_string($telefone_raw) && (strpos($telefone_raw, '@g.us') !== false || strpos($telefone_raw, '@broadcast') !== false)) {
        return true;
    }
    if (is_array($payload)) {
        if (!empty($payload['isGroup'])) return true;
        if (!empty($payload['participantPhone'])) return true; // só existe em grupos
    }
    return false;
}

/**
 * Extrai DDD a partir de telefone (assume número BR).
 */
function zapi_extrai_ddd($telefone) {
    $num = preg_replace('/[^0-9]/', '', $telefone);
    if (strlen($num) >= 12 && substr($num, 0, 2) === '55') {
        return substr($num, 2, 2);
    }
    if (strlen($num) === 11 || strlen($num) === 10) {
        return substr($num, 0, 2);
    }
    return '';
}

/**
 * Busca ou cria uma conversa com base em telefone + DDD da instância.
 * @return array linha da conversa com id
 */
function zapi_buscar_ou_criar_conversa($telefone, $ddd_instancia, $nome_contato = null, $ehGrupo = false) {
    $pdo = db();
    $inst = zapi_get_instancia($ddd_instancia);
    if (!$inst) return null;

    // Detecção de grupo — preserva sufixo @g.us no normaliza e evita vincular cliente por coincidência de dígitos.
    if (!$ehGrupo) $ehGrupo = zapi_eh_grupo($telefone);
    $telefone_norm = zapi_normaliza_telefone($telefone);

    // 1) Match exato pelo telefone normalizado
    $stmt = $pdo->prepare("SELECT * FROM zapi_conversas WHERE telefone = ? AND instancia_id = ? LIMIT 1");
    $stmt->execute(array($telefone_norm, $inst['id']));
    $conv = $stmt->fetch();
    if ($conv) return $conv;

    // 2) Se NÃO é grupo, tenta achar conversa existente do mesmo contato que
    //    tenha sido gravada com outro formato (telefone real vs @lid do Multi-Device).
    //    Match pelos últimos 10 dígitos é forte APENAS pra telefones reais entre si
    //    — pra casos @lid vs número real, os dígitos NÃO batem (o @lid é ID interno
    //    do Multi-Device, não derivado do telefone). Por isso, se esse match falhar
    //    e o nome_contato foi passado, cai na estratégia 3 (match por nome).
    if (!$ehGrupo) {
        $digitsOnly = preg_replace('/\D/', '', str_replace(array('@lid','@g.us'), '', $telefone_norm));
        if (strlen($digitsOnly) >= 10) {
            $ult10 = substr($digitsOnly, -10);
            $q2 = $pdo->prepare("SELECT * FROM zapi_conversas
                                 WHERE instancia_id = ?
                                   AND RIGHT(REPLACE(REPLACE(telefone,'@lid',''),'@g.us',''), 10) = ?
                                 ORDER BY ultima_msg_em DESC LIMIT 1");
            $q2->execute(array($inst['id'], $ult10));
            $conv = $q2->fetch();
            if ($conv) return $conv;
        }
    }

    // Estratégia 3 (match por NOME_CONTATO) REMOVIDA em 24/Abr/2026.
    // Motivo: match por nome causava cruzamento entre clientes diferentes
    // (msgs de pessoa A caíam na conversa de pessoa B se o primeiro nome
    // coincidisse). Identificadores canônicos são telefone real e chatLid —
    // nome é label visual, NÃO chave. Se não achou por telefone (estratégia 1
    // ou 2), criar nova conversa é mais seguro que arriscar um match errado.

    // Criar nova
    // Só vincula cliente/lead se NÃO for grupo (grupos têm ID com 18+ dígitos e
    // substr(-9) casaria por coincidência com telefones de cliente reais).
    $clientId = null;
    $leadId   = null;
    if (!$ehGrupo) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM clients WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'(',''),')',''),'-','') LIKE ? LIMIT 1");
            $stmt->execute(array('%' . substr($telefone_norm, -9)));
            $clientId = $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {}
        if (!$clientId) {
            try {
                $stmt = $pdo->prepare("SELECT id FROM pipeline_leads WHERE REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'(',''),')',''),'-','') LIKE ? LIMIT 1");
                $stmt->execute(array('%' . substr($telefone_norm, -9)));
                $leadId = $stmt->fetchColumn() ?: null;
            } catch (Exception $e) {}
        }
    }

    // Bot IA auto-ativado em novas conversas DDD 21 se setting ligado (nunca em grupos).
    $botAtivo = 0;
    if (!$ehGrupo && $ddd_instancia === '21'
        && zapi_auto_cfg('zapi_bot_ia_ativo',     '0') === '1'
        && zapi_auto_cfg('zapi_bot_ia_auto_novas','0') === '1') {
        $botAtivo = 1;
    }

    // Self-heal coluna eh_grupo (idempotente)
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN eh_grupo TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    $pdo->prepare(
        "INSERT INTO zapi_conversas (instancia_id, telefone, nome_contato, client_id, lead_id, canal, bot_ativo, status, eh_grupo)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'aguardando', ?)"
    )->execute(array(
        $inst['id'], $telefone_norm, $nome_contato, $clientId, $leadId, $ddd_instancia, $botAtivo, $ehGrupo ? 1 : 0
    ));
    $newId = (int)$pdo->lastInsertId();

    // Busca foto de perfil automaticamente ao criar nova conversa (exceto grupos)
    // Falha silenciosa: se a Z-API não responder, a conversa existe e recebe foto depois
    if (!$ehGrupo) {
        try { zapi_sync_foto_contato($newId); } catch (Exception $e) {}
    }

    return $pdo->query("SELECT * FROM zapi_conversas WHERE id = $newId")->fetch();
}

/**
 * Detecta tipo de mensagem a partir do payload Z-API.
 * Cobre variações do Multi Device.
 */
function zapi_detecta_tipo($payload) {
    // Variantes Multi Device: image, imageMessage, isImage
    if (isset($payload['image']) || isset($payload['imageMessage']) || !empty($payload['isImage'])) return 'imagem';
    if (isset($payload['document']) || isset($payload['documentMessage']) || !empty($payload['isDocument'])) return 'documento';
    if (isset($payload['audio']) || isset($payload['audioMessage']) || isset($payload['pushToTalk']) || isset($payload['ptt']) || !empty($payload['isAudio']) || !empty($payload['isPtt'])) return 'audio';
    if (isset($payload['video']) || isset($payload['videoMessage']) || !empty($payload['isVideo'])) return 'video';
    if (isset($payload['sticker']) || isset($payload['stickerMessage'])) return 'sticker';
    if (isset($payload['location']) || isset($payload['locationMessage'])) return 'localizacao';
    if (isset($payload['contact']) || isset($payload['contacts']) || isset($payload['contactMessage'])) return 'contato';
    if (isset($payload['reaction']) || isset($payload['reactionMessage'])) return 'reacao';
    if (isset($payload['poll']) || isset($payload['pollCreationMessage'])) return 'enquete';
    if (isset($payload['buttonsResponseMessage']) || isset($payload['listResponseMessage'])) return 'botao';
    // PIX copia-e-cola ou código de pagamento — trata como texto (payload BR Code é string)
    if (isset($payload['paymentMessage']) || isset($payload['pixMessage']) || isset($payload['pixCodeMessage'])
        || isset($payload['interactiveMessage']) || isset($payload['orderMessage'])
        || isset($payload['sendPaymentMessage']) || isset($payload['requestPaymentMessage'])) return 'texto';
    // Texto em várias formas possíveis (Multi Device)
    if (isset($payload['text']['message'])) return 'texto';
    if (isset($payload['text']) && is_string($payload['text'])) return 'texto';
    if (isset($payload['body']) && is_string($payload['body'])) return 'texto';
    if (isset($payload['message']) && is_string($payload['message'])) return 'texto';
    if (isset($payload['conversation']) && is_string($payload['conversation'])) return 'texto';
    if (isset($payload['extendedTextMessage']['text'])) return 'texto';
    // Último recurso: varre o payload procurando string que parece BR Code (PIX copia-e-cola começa com 00020126)
    $asStr = json_encode($payload);
    if ($asStr && strpos($asStr, '00020126') !== false) return 'texto';
    if ($asStr && strpos($asStr, 'br.gov.bcb.pix') !== false) return 'texto';
    return 'outro';
}

/**
 * Extrai texto/caption do payload.
 */
function zapi_extrai_conteudo($payload, $tipo) {
    if ($tipo === 'texto') {
        if (isset($payload['text']['message'])) return $payload['text']['message'];
        if (isset($payload['text']) && is_string($payload['text'])) return $payload['text'];
        if (isset($payload['body']))    return $payload['body'];
        if (isset($payload['message']) && is_string($payload['message'])) return $payload['message'];
        if (isset($payload['conversation']))  return $payload['conversation'];
        if (isset($payload['extendedTextMessage']['text'])) return $payload['extendedTextMessage']['text'];
        return '';
    }
    if ($tipo === 'imagem') {
        $cap = $payload['image']['caption'] ?? ($payload['imageMessage']['caption'] ?? '');
        return $cap !== '' ? $cap : '[imagem]';
    }
    if ($tipo === 'video') {
        $cap = $payload['video']['caption'] ?? ($payload['videoMessage']['caption'] ?? '');
        return $cap !== '' ? $cap : '[vídeo]';
    }
    if ($tipo === 'documento') {
        $cap = $payload['document']['caption'] ?? ($payload['documentMessage']['caption'] ?? '');
        $nome = $payload['document']['fileName'] ?? ($payload['documentMessage']['fileName'] ?? '');
        if ($cap !== '') return $cap;
        if ($nome !== '') return $nome;
        return '[documento]';
    }
    if ($tipo === 'audio')  return '[áudio]';
    if ($tipo === 'sticker') return '[figurinha]';
    if ($tipo === 'localizacao') {
        $lat = $payload['location']['latitude'] ?? '';
        $lng = $payload['location']['longitude'] ?? '';
        return "[localização] $lat, $lng";
    }
    if ($tipo === 'contato') return '[contato]';
    if ($tipo === 'reacao') {
        $emoji = $payload['reaction']['value'] ?? ($payload['reaction']['reaction'] ?? '');
        return '[reagiu com ' . $emoji . ']';
    }
    if ($tipo === 'enquete') return '[enquete] ' . ($payload['poll']['name'] ?? $payload['poll']['question'] ?? '');
    if ($tipo === 'botao')   return $payload['buttonsResponseMessage']['message'] ?? ($payload['listResponseMessage']['message'] ?? '[resposta de botão]');
    // Fallback pra 'outro' — tenta qualquer campo de texto
    foreach (array('text', 'body', 'message', 'conversation', 'caption') as $k) {
        if (isset($payload[$k])) {
            if (is_string($payload[$k])) return $payload[$k];
            if (is_array($payload[$k]) && isset($payload[$k]['message'])) return $payload[$k]['message'];
        }
    }
    return '';
}

/**
 * Extrai URL do arquivo anexado (tenta múltiplas chaves possíveis da Z-API).
 */
function zapi_extrai_arquivo($payload, $tipo) {
    // Tenta múltiplas chaves (Multi Device usa nomes variados)
    $tryKeys = array(
        'imagem'    => array('image', 'imageMessage'),
        'video'     => array('video', 'videoMessage'),
        'documento' => array('document', 'documentMessage'),
        'audio'     => array('audio', 'audioMessage', 'pushToTalk', 'ptt'),
        'sticker'   => array('sticker', 'stickerMessage'),
    );
    if (!isset($tryKeys[$tipo])) return null;

    $blk = null;
    foreach ($tryKeys[$tipo] as $k) {
        if (isset($payload[$k]) && is_array($payload[$k])) { $blk = $payload[$k]; break; }
    }

    // Fallback: pode estar no nível raiz (isImage=true, imageUrl na raiz)
    if (!$blk) {
        $raizUrlKeys = array('imageUrl','videoUrl','documentUrl','audioUrl','stickerUrl','url','fileUrl','mediaUrl','downloadUrl');
        $urlRaiz = null;
        foreach ($raizUrlKeys as $k) {
            if (isset($payload[$k]) && is_string($payload[$k])) { $urlRaiz = $payload[$k]; break; }
        }
        if ($urlRaiz) {
            return array(
                'url'     => $urlRaiz,
                'nome'    => $payload['fileName'] ?? ($payload['filename'] ?? null),
                'mime'    => $payload['mimeType'] ?? ($payload['mime'] ?? null),
                'tamanho' => $payload['fileSize'] ?? ($payload['size'] ?? null),
            );
        }
        return null;
    }

    $url = $blk['imageUrl']    ?? $blk['videoUrl']    ?? $blk['documentUrl']
        ?? $blk['audioUrl']    ?? $blk['stickerUrl']
        ?? $blk['url']         ?? $blk['fileUrl']     ?? $blk['mediaUrl']
        ?? $blk['downloadUrl'] ?? null;

    return array(
        'url'     => $url,
        'nome'    => $blk['fileName'] ?? ($blk['filename'] ?? null),
        'mime'    => $blk['mimeType'] ?? ($blk['mime'] ?? null),
        'tamanho' => $blk['fileSize'] ?? ($blk['size'] ?? null),
    );
}

/**
 * Horário comercial configurável via configuracoes (zapi_horario_*).
 * Defaults: seg-sex 10h às 18h.
 */
function zapi_fora_horario() {
    static $cache = null;
    if ($cache === null) {
        $cache = array('inicio' => 10, 'fim' => 18, 'dias' => array('1','2','3','4','5'));
        try {
            $rows = db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'zapi_horario_%' OR chave = 'zapi_dias_uteis'")->fetchAll();
            foreach ($rows as $r) {
                if ($r['chave'] === 'zapi_horario_inicio') $cache['inicio'] = (int)$r['valor'];
                if ($r['chave'] === 'zapi_horario_fim')    $cache['fim']    = (int)$r['valor'];
                if ($r['chave'] === 'zapi_dias_uteis')     $cache['dias']   = explode(',', $r['valor']);
            }
        } catch (Exception $e) {}
    }
    $hora = (int)date('H');
    $dia  = (string)(int)date('N');
    if (!in_array($dia, $cache['dias'], true)) return true;
    if ($hora < $cache['inicio'] || $hora >= $cache['fim']) return true;
    return false;
}

/**
 * Lê toggle de automação (chave) do banco.
 */
function zapi_auto_cfg($chave, $default = '') {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = array();
        try {
            // Carrega TODAS as chaves zapi_* — assinatura (zapi_signature_*), bot (zapi_bot_*),
            // automações (zapi_auto_*) e afins estão no mesmo cache.
            foreach (db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'zapi_%'")->fetchAll() as $r) {
                $cfg[$r['chave']] = $r['valor'];
            }
        } catch (Exception $e) {}
    }
    return $cfg[$chave] ?? $default;
}

/**
 * Busca template pelo nome (case-insensitive) e expande variáveis {{nome}}, etc.
 */
function zapi_get_template($nome, $vars = array()) {
    $stmt = db()->prepare("SELECT conteudo FROM zapi_templates WHERE nome = ? AND ativo = 1 LIMIT 1");
    $stmt->execute(array($nome));
    $tpl = $stmt->fetchColumn();
    if (!$tpl) return '';
    foreach ($vars as $k => $v) {
        $tpl = str_replace('{{' . $k . '}}', $v, $tpl);
    }
    return $tpl;
}

/**
 * Salva mensagem recebida no banco.
 */
function zapi_salvar_mensagem_recebida($conversaId, $payload, $tipo, $conteudo, $arquivo, $zapiMsgId) {
    // Mapear tipos internos para ENUM válido
    $tiposValidos = array('texto','imagem','documento','audio','video','sticker','localizacao','contato','outro');
    if (!in_array($tipo, $tiposValidos, true)) $tipo = 'outro';

    db()->prepare(
        "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo,
            arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho, status)
         VALUES (?, ?, 'recebida', ?, ?, ?, ?, ?, ?, 'recebida')"
    )->execute(array(
        $conversaId,
        $zapiMsgId,
        $tipo,
        $conteudo,
        $arquivo['url']     ?? null,
        $arquivo['nome']    ?? null,
        $arquivo['mime']    ?? null,
        $arquivo['tamanho'] ?? null,
    ));
    return (int)db()->lastInsertId();
}

// NOTA: a função zapi_fetch_chat_messages foi REMOVIDA em 2026-04-23.
// Z-API confirmou oficialmente que o endpoint /chat-messages não funciona em
// Multi-Device (única versão disponível hoje). Mensagens antigas só ficam no
// WhatsApp do celular ou Web — não é possível reimportar via API.
// Doc: https://developer.z-api.io/en/chats/get-message-chats

/**
 * Lista todos os chats da instância (telefones com mensagens).
 */
function zapi_fetch_chats($ddd, $page = 1, $size = 50) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) return null;
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/chats?page=' . (int)$page . '&pageSize=' . (int)$size;

    $headers = array();
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

/**
 * Busca a URL da foto de perfil do contato via Z-API.
 * Retorna a URL (string) ou null se não houver foto / erro.
 */
function zapi_fetch_profile_picture($ddd, $telefone) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) return null;
    $cfg = zapi_get_config();
    // Z-API aceita tanto número real quanto @lid no profile-picture.
    // Ref: https://developer.z-api.io/en/tips/lid — "@lid is already supported
    // by Z-API in most endpoints" e recomendam usar @lid como identificador estável.
    // Se for número, normaliza (remove caracteres). Se for @lid, envia como veio.
    $phone = (strpos((string)$telefone, '@lid') !== false)
        ? trim($telefone)
        : zapi_normaliza_telefone($telefone);
    if (!$phone) return null;
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token']
         . '/profile-picture?phone=' . urlencode($phone);

    $headers = array();
    if (!empty($cfg['client_token'])) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    $json = json_decode($resp, true);
    // Z-API retorna em 'link' normalmente; alguns endpoints variam ('url', 'imgUrl')
    foreach (array('link', 'url', 'imgUrl', 'image') as $k) {
        if (!empty($json[$k]) && is_string($json[$k])) return $json[$k];
    }
    return null;
}

/**
 * Sincroniza a foto de perfil de uma conversa.
 * - Atualiza zapi_conversas.foto_perfil_url + foto_perfil_atualizada.
 * - Se conversa tem client_id e clients.foto_path está vazio, baixa a imagem
 *   e salva em salavip_src/uploads/ + atualiza clients.foto_path.
 *   Quando o cliente atualizar a foto pela Central VIP (foto_path já existe),
 *   esta função NÃO sobrescreve.
 */
/**
 * Salva foto de perfil vinda do WEBHOOK (campo `photo` ou `senderPhoto`) —
 * chamada inline quando webhook processa mensagem. Sem custo extra de API.
 *
 * - Atualiza `zapi_conversas.foto_perfil_url`
 * - Baixa e salva cópia local em /files/wa_fotos/ (link WhatsApp expira em 48h)
 * - Se conversa vinculada a cliente sem foto, salva também em salavip/uploads/
 */
function zapi_salvar_foto_webhook($convId, $fotoUrl) {
    $pdo = db();
    if (!$fotoUrl || strpos($fotoUrl, 'http') !== 0) return false;
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN foto_perfil_local VARCHAR(255) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN foto_perfil_atualizada DATETIME NULL"); } catch (Exception $e) {}

    $st = $pdo->prepare("SELECT id, client_id, eh_grupo FROM zapi_conversas WHERE id = ?");
    $st->execute(array($convId));
    $conv = $st->fetch();
    if (!$conv) return false;

    // Atualiza URL no banco
    try {
        $pdo->prepare("UPDATE zapi_conversas SET foto_perfil_url = ?, foto_perfil_atualizada = NOW() WHERE id = ?")
            ->execute(array($fotoUrl, $convId));
    } catch (Exception $e) {}

    // Baixa imagem
    $ch = curl_init($fotoUrl);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $imgData = curl_exec($ch);
    $imgCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $imgCT   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if (!$imgData || $imgCode < 200 || $imgCode >= 300 || strlen($imgData) < 100) return false;
    $ext = 'jpg';
    if (stripos($imgCT, 'png') !== false) $ext = 'png';
    elseif (stripos($imgCT, 'webp') !== false) $ext = 'webp';

    // Salva cópia local da conversa (sempre)
    $dir = APP_ROOT . '/files/wa_fotos/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fn = 'conv_' . (int)$conv['id'] . '_' . time() . '.' . $ext;
    if (@file_put_contents($dir . $fn, $imgData)) {
        // Apaga arquivo antigo pra não acumular
        try {
            $stAnt = $pdo->prepare("SELECT foto_perfil_local FROM zapi_conversas WHERE id = ?");
            $stAnt->execute(array($convId));
            $antigo = $stAnt->fetchColumn();
            if ($antigo && $antigo !== $fn) @unlink($dir . $antigo);
        } catch (Exception $e) {}
        $pdo->prepare("UPDATE zapi_conversas SET foto_perfil_local = ? WHERE id = ?")
            ->execute(array($fn, $convId));
    }

    // Se for cliente e ainda não tem foto no cadastro, salva lá também
    if (!empty($conv['client_id']) && empty($conv['eh_grupo'])) {
        try {
            $cl = $pdo->prepare("SELECT foto_path FROM clients WHERE id = ?");
            $cl->execute(array($conv['client_id']));
            $existing = $cl->fetchColumn();
            if (empty($existing)) {
                $vipDir = dirname(APP_ROOT) . '/salavip/uploads/';
                if (!is_dir($vipDir)) @mkdir($vipDir, 0755, true);
                $vipFn = 'foto_wa_' . (int)$conv['client_id'] . '_' . time() . '.' . $ext;
                if (@file_put_contents($vipDir . $vipFn, $imgData)) {
                    $pdo->prepare("UPDATE clients SET foto_path = ? WHERE id = ? AND (foto_path IS NULL OR foto_path = '')")
                        ->execute(array($vipFn, $conv['client_id']));
                }
            }
        } catch (Exception $e) {}
    }
    return true;
}

function zapi_sync_foto_contato($convId) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT co.id, co.telefone, co.client_id, co.eh_grupo, i.ddd FROM zapi_conversas co JOIN zapi_instancias i ON i.id = co.instancia_id WHERE co.id = ?");
    $stmt->execute(array($convId));
    $conv = $stmt->fetch();
    if (!$conv) return array('ok' => false, 'erro' => 'Conversa não encontrada');
    // Grupos não têm foto de perfil individual e, pior, poderiam ser
    // vinculados erroneamente a um cliente. Pula completamente.
    if (!empty($conv['eh_grupo']) || strpos((string)$conv['telefone'], '@g.us') !== false) {
        return array('ok' => true, 'foto_url' => null, 'client_updated' => false, 'skipped' => 'grupo');
    }

    $link = zapi_fetch_profile_picture($conv['ddd'], $conv['telefone']);
    try {
        $pdo->prepare("UPDATE zapi_conversas SET foto_perfil_url = ?, foto_perfil_atualizada = NOW() WHERE id = ?")
            ->execute(array($link, $conv['id']));
    } catch (Exception $e) {}

    // Self-heal: coluna pra salvar foto local de conversas (não só clientes)
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN foto_perfil_local VARCHAR(255) NULL"); } catch (Exception $e) {}

    $clientUpdated = false;
    $convFotoSalva = false;

    // Baixa a imagem UMA vez — usada tanto pra client_id quanto pra conversa avulsa
    $imgData = null; $ext = 'jpg';
    if ($link) {
        $imgCh = curl_init($link);
        curl_setopt_array($imgCh, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $imgData = curl_exec($imgCh);
        $imgCode = curl_getinfo($imgCh, CURLINFO_HTTP_CODE);
        $imgCT   = curl_getinfo($imgCh, CURLINFO_CONTENT_TYPE);
        curl_close($imgCh);
        if (!$imgData || $imgCode < 200 || $imgCode >= 300 || strlen($imgData) < 100) {
            $imgData = null;
        } else {
            if (stripos($imgCT, 'png') !== false) $ext = 'png';
            elseif (stripos($imgCT, 'webp') !== false) $ext = 'webp';
        }
    }

    // 1. Se vinculado a cliente e cliente não tem foto, salva em salavip/uploads + clients.foto_path
    if ($imgData && $conv['client_id']) {
        $cl = $pdo->prepare("SELECT foto_path FROM clients WHERE id = ?");
        $cl->execute(array($conv['client_id']));
        $existingFoto = $cl->fetchColumn();
        if (empty($existingFoto)) {
            $filename = 'foto_wa_' . (int)$conv['client_id'] . '_' . time() . '.' . $ext;
            $uploadDir = dirname(APP_ROOT) . '/salavip/uploads/';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
            $destPath = $uploadDir . $filename;
            if (@file_put_contents($destPath, $imgData)) {
                $pdo->prepare("UPDATE clients SET foto_path = ? WHERE id = ? AND (foto_path IS NULL OR foto_path = '')")
                    ->execute(array($filename, $conv['client_id']));
                $clientUpdated = true;
            }
        }
    }

    // 2. Salva cópia local da foto da conversa SEMPRE (independente de ser cliente).
    //    Evita foto sumir quando link Z-API expira (48h).
    if ($imgData) {
        $filename2 = 'conv_' . (int)$conv['id'] . '_' . time() . '.' . $ext;
        $uploadDir2 = APP_ROOT . '/files/wa_fotos/';
        if (!is_dir($uploadDir2)) @mkdir($uploadDir2, 0755, true);
        $destPath2 = $uploadDir2 . $filename2;
        if (@file_put_contents($destPath2, $imgData)) {
            // Apaga foto anterior da conversa (se houver) pra não acumular lixo
            try {
                $stmtAnt = $pdo->prepare("SELECT foto_perfil_local FROM zapi_conversas WHERE id = ?");
                $stmtAnt->execute(array($conv['id']));
                $antigo = $stmtAnt->fetchColumn();
                if ($antigo && $antigo !== $filename2) {
                    @unlink($uploadDir2 . $antigo);
                }
            } catch (Exception $e) {}
            $pdo->prepare("UPDATE zapi_conversas SET foto_perfil_local = ? WHERE id = ?")
                ->execute(array($filename2, $conv['id']));
            $convFotoSalva = true;
        }
    }

    return array('ok' => true, 'foto_url' => $link, 'client_updated' => $clientUpdated, 'conv_foto_salva' => $convFotoSalva);
}

/**
 * Garante que exista a etiqueta "🔓 AT DESBLOQUEADO" no banco.
 * Criada automaticamente na primeira chamada.
 */
function _zapi_etiqueta_at_desbloqueado_id() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $pdo = db();
    try {
        $s = $pdo->prepare("SELECT id FROM zapi_etiquetas WHERE nome = ? LIMIT 1");
        $s->execute(array('🔓 AT DESBLOQUEADO'));
        $id = $s->fetchColumn();
        if (!$id) {
            $maxOrd = (int)$pdo->query("SELECT COALESCE(MAX(ordem),0) FROM zapi_etiquetas")->fetchColumn();
            $pdo->prepare("INSERT INTO zapi_etiquetas (nome, cor, ordem, ativo) VALUES (?, ?, ?, 1)")
                ->execute(array('🔓 AT DESBLOQUEADO', '#dc2626', $maxOrd + 1));
            $id = (int)$pdo->lastInsertId();
        }
        $cached = (int)$id;
        return $cached;
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Aplica/remove a etiqueta "🔓 AT DESBLOQUEADO" em conversas do canal 21
 * onde o atendente responsável sumiu (sem envio há X min).
 *
 * - APLICA em conversas em_atendimento com atendente_id, sem envio do atendente
 *   há X min, e com mensagem do cliente como última interação (ou seja, cliente
 *   está esperando resposta).
 * - REMOVE quando a conversa volta (novo envio do atendente), muda pra
 *   resolvido/arquivado, ou perde o atendente_id.
 *
 * Chamado lazily no listar_conversas — roda rápido via UPDATE/DELETE em lote.
 *
 * @return array contagem de mudanças
 */
function zapi_atualizar_at_desbloqueado($minutos = null) {
    $pdo = db();
    $etqId = _zapi_etiqueta_at_desbloqueado_id();
    if (!$etqId) return array('tagged' => 0, 'untagged' => 0);

    $horasCliente = ZAPI_TRAVA_CLIENTE_ESPERANDO_HORAS_UTEIS;
    $fupHrs = ZAPI_TRAVA_FOLLOWUP_HORAS;
    $limSegUteis = $horasCliente * 3600;
    $limSegReal  = $fupHrs * 3600;
    $agora = time();

    $tagged = 0;
    $untagged = 0;

    try {
        // 1) Lista todas as conversas canal 21 em atendimento com atendente
        //    e traz a direção/created_at da última msg.
        $rows = $pdo->query(
            "SELECT co.id,
                    (SELECT direcao FROM zapi_mensagens WHERE id = (SELECT MAX(id) FROM zapi_mensagens WHERE conversa_id = co.id)) AS direcao,
                    (SELECT created_at FROM zapi_mensagens WHERE id = (SELECT MAX(id) FROM zapi_mensagens WHERE conversa_id = co.id)) AS created_at
             FROM zapi_conversas co
             WHERE co.canal = '21' AND co.atendente_id IS NOT NULL AND co.status = 'em_atendimento'"
        )->fetchAll();

        $deveTagar = array();
        foreach ($rows as $r) {
            if (empty($r['created_at'])) continue;
            $tsUlt = strtotime($r['created_at']);
            $liberou = false;
            if ($r['direcao'] === 'recebida') {
                $liberou = zapi_segundos_uteis_entre($tsUlt, $agora) >= $limSegUteis;
            } else {
                $liberou = ($agora - $tsUlt) >= $limSegReal;
            }
            if ($liberou) $deveTagar[] = (int)$r['id'];
        }

        // 2) APLICA etiqueta onde a trava liberou
        if ($deveTagar) {
            $ph = implode(',', array_fill(0, count($deveTagar), '?'));
            $params = array_merge(array($etqId), $deveTagar);
            $sql = "INSERT IGNORE INTO zapi_conversa_etiquetas (conversa_id, etiqueta_id, aplicada_por)
                    SELECT id, ?, NULL FROM zapi_conversas WHERE id IN ($ph)";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $tagged = $st->rowCount();
        }

        // 3) REMOVE etiqueta das conversas que NÃO estão em $deveTagar
        //    ou mudaram de status / perderam atendente
        $params = array($etqId);
        $extra = '';
        if ($deveTagar) {
            $phKeep = implode(',', array_fill(0, count($deveTagar), '?'));
            $extra = " AND ce.conversa_id NOT IN ($phKeep)";
            $params = array_merge($params, $deveTagar);
        }
        $sqlRemove = "DELETE ce FROM zapi_conversa_etiquetas ce
                      JOIN zapi_conversas co ON co.id = ce.conversa_id
                      WHERE ce.etiqueta_id = ?
                        AND (
                            co.status IN ('resolvido', 'arquivado')
                            OR co.atendente_id IS NULL
                            {$extra}
                        )";
        $st2 = $pdo->prepare($sqlRemove);
        $st2->execute($params);
        $untagged = $st2->rowCount();
    } catch (Exception $e) {
        // ignore
    }

    return array('tagged' => $tagged, 'untagged' => $untagged);
}

/**
 * Tempo que cliente pode ficar esperando resposta antes da trava liberar.
 * Contado em HORAS ÚTEIS (seg-sex, 9h-18h).
 * Ex: 8h úteis significa que se o cliente mandou msg sexta 15h, a trava
 * libera só na segunda 14h (3h sexta + 5h segunda).
 */
define('ZAPI_TRAVA_CLIENTE_ESPERANDO_HORAS_UTEIS', 8);

/**
 * Tempo que uma conversa fica "presa" ao atendente após ele mandar a última
 * msg. Depois disso, libera pra follow-up de qualquer atendente.
 * Ex: 36h depois da última msg da equipe (com cliente sem responder), libera.
 * Obs: esse é em HORAS CORRIDAS (não úteis) — follow-up opera em tempo real.
 */
define('ZAPI_TRAVA_FOLLOWUP_HORAS', 36);

/**
 * Horário comercial usado nos cálculos de "horas úteis".
 */
define('ZAPI_HORARIO_COMERCIAL_INI', 9);   // 9h
define('ZAPI_HORARIO_COMERCIAL_FIM', 18);  // 18h

/**
 * Calcula quantas "horas úteis" passaram entre dois timestamps.
 * Hora útil = seg-sex, entre 9h e 18h.
 *
 * @param int $tsA início (timestamp unix)
 * @param int $tsB fim (timestamp unix)
 * @return int segundos úteis entre os dois timestamps
 */
function zapi_segundos_uteis_entre($tsA, $tsB) {
    if ($tsB <= $tsA) return 0;
    $HINI = ZAPI_HORARIO_COMERCIAL_INI;
    $HFIM = ZAPI_HORARIO_COMERCIAL_FIM;

    $total = 0;
    // Itera dia a dia entre tsA e tsB
    $diaCursor = strtotime(date('Y-m-d', $tsA));
    $diaLimite = strtotime(date('Y-m-d', $tsB));

    while ($diaCursor <= $diaLimite) {
        $dow = (int)date('N', $diaCursor); // 1=seg, 7=dom
        if ($dow < 6) { // seg-sex
            $iniDia = strtotime(date('Y-m-d', $diaCursor) . ' ' . sprintf('%02d:00:00', $HINI));
            $fimDia = strtotime(date('Y-m-d', $diaCursor) . ' ' . sprintf('%02d:00:00', $HFIM));
            $a = max($iniDia, $tsA);
            $b = min($fimDia, $tsB);
            if ($b > $a) $total += ($b - $a);
        }
        $diaCursor = strtotime('+1 day', $diaCursor);
    }
    return $total;
}

/**
 * Adiciona N horas úteis a um timestamp e devolve o timestamp real resultante.
 * Usada pra calcular QUANDO (em tempo real) a trava vai liberar.
 *
 * @param int $tsInicio timestamp unix de partida
 * @param float $horas quantas horas úteis adicionar
 * @return int timestamp unix quando as horas úteis completam
 */
function zapi_adicionar_horas_uteis($tsInicio, $horas) {
    $HINI = ZAPI_HORARIO_COMERCIAL_INI;
    $HFIM = ZAPI_HORARIO_COMERCIAL_FIM;
    $segFaltam = (int)round($horas * 3600);
    $cursor = (int)$tsInicio;
    $safety = 0; // loop guard

    while ($segFaltam > 0 && $safety++ < 100) {
        $dow = (int)date('N', $cursor);
        $iniDia = strtotime(date('Y-m-d', $cursor) . ' ' . sprintf('%02d:00:00', $HINI));
        $fimDia = strtotime(date('Y-m-d', $cursor) . ' ' . sprintf('%02d:00:00', $HFIM));

        // Fim de semana: pula pra próxima segunda 9h
        if ($dow >= 6) {
            $cursor = strtotime('next monday ' . sprintf('%02d:00:00', $HINI), $cursor);
            continue;
        }
        // Antes do expediente: pula pra 9h
        if ($cursor < $iniDia) {
            $cursor = $iniDia;
            continue;
        }
        // Depois do expediente: pula pra 9h do próximo dia útil
        if ($cursor >= $fimDia) {
            $cursor = strtotime('+1 day', $iniDia);
            continue;
        }
        // Dentro do expediente — consome até fim do dia ou até fechar conta
        $disponiveis = $fimDia - $cursor;
        if ($segFaltam <= $disponiveis) {
            $cursor += $segFaltam;
            $segFaltam = 0;
        } else {
            $segFaltam -= $disponiveis;
            $cursor = $fimDia;
        }
    }
    return $cursor;
}

/**
 * Verifica se um usuário pode enviar mensagem numa conversa.
 *
 * REGRA (canal 21 Comercial apenas):
 *   Se a conversa tem atendente_id e não é o usuário atual nem admin,
 *   a trava LIBERA em 2 situações:
 *     a) Última msg foi do CLIENTE há mais de 8h ÚTEIS (seg-sex 9-18h) → atendente sumiu, libera
 *     b) Última msg foi da EQUIPE há mais de 36h (corridas) → follow-up, libera
 *   Caso contrário: bloqueado.
 *
 * Canal 24: sempre livre (colaborativo).
 * Admin (Amanda/Luiz): sempre livre (bypass).
 *
 * @return array {
 *   'pode' => bool,
 *   'atendente_id', 'atendente_name'  → só se bloqueado
 *   'motivo' => 'cliente_esperando' | 'atendente_ativo'  → só se bloqueado
 *   'segundos_ate_liberar' => int     → só se bloqueado (pra cronômetro)
 *   'idade_ultima_segundos' => int    → só se bloqueado (quanto tempo já passou)
 * }
 */
function zapi_pode_enviar_conversa($convId, $userId, $minutos = null) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT co.canal, co.atendente_id, u.name FROM zapi_conversas co
                           LEFT JOIN users u ON u.id = co.atendente_id
                           WHERE co.id = ?");
    $stmt->execute(array($convId));
    $row = $stmt->fetch();
    if (!$row) return array('pode' => true);

    if ((string)$row['canal'] === '24') return array('pode' => true);

    $atendente = (int)$row['atendente_id'];
    if ($atendente === 0) return array('pode' => true);
    if ($atendente === (int)$userId) return array('pode' => true);

    if (function_exists('can_delegar_whatsapp') && can_delegar_whatsapp()) return array('pode' => true);

    // Nova regra: checa última msg da conversa
    $u = $pdo->prepare("SELECT direcao, created_at FROM zapi_mensagens
                        WHERE conversa_id = ? ORDER BY id DESC LIMIT 1");
    $u->execute(array($convId));
    $ult = $u->fetch();
    if (!$ult) return array('pode' => true); // conversa vazia, libera

    $tsUlt = strtotime($ult['created_at']);
    $idade = time() - $tsUlt;

    if ($ult['direcao'] === 'recebida') {
        // Cliente mandou a última — libera após 8h ÚTEIS (seg-sex 9-18h)
        $horas = ZAPI_TRAVA_CLIENTE_ESPERANDO_HORAS_UTEIS;
        $segUteis = zapi_segundos_uteis_entre($tsUlt, time());
        $limiteSegUteis = $horas * 3600;
        if ($segUteis >= $limiteSegUteis) return array('pode' => true);
        // Quando (em tempo real) vai liberar?
        $tsLibera = zapi_adicionar_horas_uteis($tsUlt, $horas);
        $segAteLiberar = max(0, $tsLibera - time());
        return array(
            'pode' => false,
            'atendente_id' => $atendente,
            'atendente_name' => $row['name'] ?: 'outro atendente',
            'motivo' => 'cliente_esperando',
            'segundos_ate_liberar' => $segAteLiberar,
            'idade_ultima_segundos' => $idade,
        );
    }

    // Equipe mandou a última — libera após 36h (follow-up)
    $limite = ZAPI_TRAVA_FOLLOWUP_HORAS * 3600;
    if ($idade >= $limite) return array('pode' => true);
    return array(
        'pode' => false,
        'atendente_id' => $atendente,
        'atendente_name' => $row['name'] ?: 'outro atendente',
        'motivo' => 'atendente_ativo',
        'segundos_ate_liberar' => $limite - $idade,
        'idade_ultima_segundos' => $idade,
    );
}

/**
 * Expira automaticamente delegações sem interação há mais de X minutos.
 *
 * Uma delegação expira quando:
 *   - foi feita há mais de $minutos minutos (delegada_em antigo) E
 *   - não houve mensagem nova na conversa no mesmo período
 *
 * Ou seja: se o cliente mandou nova msg ou o atendente respondeu recentemente,
 * a delegação se mantém. Se tudo parou há mais de 30min, libera.
 *
 * Chamado lazily no listar_conversas e assumir_atendimento — não precisa cron.
 */
function zapi_expirar_delegacoes_estale($minutos = null) {
    $pdo = db();
    $fupHrs = ZAPI_TRAVA_FOLLOWUP_HORAS;   // 36h se equipe é última
    try {
        // Pré-filtra candidatos via SQL (último msg antigo o suficiente pra
        // talvez ter liberado) e confirma em PHP usando a regra de horas úteis.
        $sql = "SELECT co.id, m2.direcao, m2.created_at
                FROM zapi_conversas co
                LEFT JOIN zapi_mensagens m2 ON m2.id = (
                    SELECT MAX(id) FROM zapi_mensagens WHERE conversa_id = co.id
                )
                WHERE co.delegada = 1
                  AND (
                      m2.id IS NULL
                      OR m2.created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                  )";
        $candidatos = $pdo->query($sql)->fetchAll();
        if (!$candidatos) return 0;

        $horasCliente = ZAPI_TRAVA_CLIENTE_ESPERANDO_HORAS_UTEIS;
        $limSegUteis = $horasCliente * 3600;
        $limSegReal  = $fupHrs * 3600;
        $agora = time();
        $idsLiberar = array();
        foreach ($candidatos as $c) {
            if (empty($c['created_at'])) { $idsLiberar[] = (int)$c['id']; continue; }
            $tsUlt = strtotime($c['created_at']);
            if ($c['direcao'] === 'recebida') {
                // Cliente esperando: regra de horas úteis
                $segUteis = zapi_segundos_uteis_entre($tsUlt, $agora);
                if ($segUteis >= $limSegUteis) $idsLiberar[] = (int)$c['id'];
            } else {
                // Equipe mandou última: 36h corridas
                if (($agora - $tsUlt) >= $limSegReal) $idsLiberar[] = (int)$c['id'];
            }
        }
        if (!$idsLiberar) return 0;

        $ph = implode(',', array_fill(0, count($idsLiberar), '?'));
        $upd = $pdo->prepare(
            "UPDATE zapi_conversas SET delegada = 0, delegada_por = NULL, delegada_em = NULL
             WHERE id IN ($ph)"
        );
        $upd->execute($idsLiberar);
        return $upd->rowCount();
    } catch (Exception $e) {
        return 0;
    }
}

// zapi_sincronizar_historico_conversa REMOVIDA em 2026-04-23 —
// dependia de /chat-messages que Z-API não suporta em Multi-Device.
// Ver nota em zapi_fetch_chat_messages (também removida).

/**
 * Consulta status da instância na Z-API e atualiza o DB local.
 * @return bool|null  true=conectado, false=desconectado, null=erro
 */
function zapi_verificar_status($ddd) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) return null;
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/status';

    $headers = array();
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    if (!is_array($data)) return null;
    $connected = !empty($data['connected']) || !empty($data['session']);
    db()->prepare("UPDATE zapi_instancias SET conectado = ?, ultima_verificacao = NOW() WHERE ddd = ?")
        ->execute(array($connected ? 1 : 0, $ddd));
    return (bool)$connected;
}

/**
 * Conta mensagens não lidas para badge da sidebar (por usuário).
 */
function zapi_total_nao_lidas($userId = null) {
    try {
        $sql = "SELECT COALESCE(SUM(nao_lidas), 0) FROM zapi_conversas WHERE status != 'arquivado'";
        return (int)db()->query($sql)->fetchColumn();
    } catch (Exception $e) { return 0; }
}
