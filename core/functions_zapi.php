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
 * Envia texto via Z-API.
 * @return array ['ok' => bool, 'data' => mixed, 'http_code' => int]
 */
function zapi_send_text($ddd, $telefone, $mensagem) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) {
        return array('ok' => false, 'erro' => 'Instância não configurada para DDD ' . $ddd);
    }
    $cfg = zapi_get_config();
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/send-text';
    $telefone_norm = zapi_normaliza_telefone($telefone);

    $headers = array('Content-Type: application/json');
    if ($cfg['client_token']) $headers[] = 'Client-Token: ' . $cfg['client_token'];

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode(array('phone' => $telefone_norm, 'message' => $mensagem)),
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
 */
function zapi_normaliza_telefone($telefone) {
    $num = preg_replace('/[^0-9]/', '', $telefone);
    // Se começa com 0, remove
    if (strlen($num) > 11 && substr($num, 0, 1) === '0') $num = ltrim($num, '0');
    // Se não começa com 55 (Brasil), adiciona
    if (strlen($num) === 10 || strlen($num) === 11) $num = '55' . $num;
    return $num;
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
function zapi_buscar_ou_criar_conversa($telefone, $ddd_instancia, $nome_contato = null) {
    $pdo = db();
    $inst = zapi_get_instancia($ddd_instancia);
    if (!$inst) return null;

    $telefone_norm = zapi_normaliza_telefone($telefone);

    $stmt = $pdo->prepare("SELECT * FROM zapi_conversas WHERE telefone = ? AND instancia_id = ? LIMIT 1");
    $stmt->execute(array($telefone_norm, $inst['id']));
    $conv = $stmt->fetch();
    if ($conv) return $conv;

    // Criar nova
    // Tenta casar com client/lead existente pelo telefone
    $clientId = null;
    $leadId   = null;
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

    // Bot IA desligado por default — só ativa quando Checkpoint 1.4 estiver pronto
    $pdo->prepare(
        "INSERT INTO zapi_conversas (instancia_id, telefone, nome_contato, client_id, lead_id, canal, bot_ativo, status)
         VALUES (?, ?, ?, ?, ?, ?, 0, 'aguardando')"
    )->execute(array(
        $inst['id'], $telefone_norm, $nome_contato, $clientId, $leadId, $ddd_instancia
    ));
    $newId = (int)$pdo->lastInsertId();
    return $pdo->query("SELECT * FROM zapi_conversas WHERE id = $newId")->fetch();
}

/**
 * Detecta tipo de mensagem a partir do payload Z-API.
 */
function zapi_detecta_tipo($payload) {
    if (isset($payload['image'])) return 'imagem';
    if (isset($payload['document'])) return 'documento';
    if (isset($payload['audio'])) return 'audio';
    if (isset($payload['video'])) return 'video';
    if (isset($payload['sticker'])) return 'sticker';
    if (isset($payload['location'])) return 'localizacao';
    if (isset($payload['contact']) || isset($payload['contacts'])) return 'contato';
    if (isset($payload['text']['message'])) return 'texto';
    return 'outro';
}

/**
 * Extrai texto/caption do payload.
 */
function zapi_extrai_conteudo($payload, $tipo) {
    if ($tipo === 'texto') return $payload['text']['message'] ?? '';
    if ($tipo === 'imagem') return $payload['image']['caption'] ?? '[imagem]';
    if ($tipo === 'video')  return $payload['video']['caption'] ?? '[vídeo]';
    if ($tipo === 'documento') return $payload['document']['caption'] ?? ($payload['document']['fileName'] ?? '[documento]');
    if ($tipo === 'audio')  return '[áudio]';
    if ($tipo === 'sticker') return '[figurinha]';
    if ($tipo === 'localizacao') {
        $lat = $payload['location']['latitude'] ?? '';
        $lng = $payload['location']['longitude'] ?? '';
        return "[localização] $lat, $lng";
    }
    if ($tipo === 'contato') return '[contato]';
    return '';
}

/**
 * Extrai URL do arquivo anexado (tenta múltiplas chaves possíveis da Z-API).
 */
function zapi_extrai_arquivo($payload, $tipo) {
    $map = array(
        'imagem'    => 'image',
        'video'     => 'video',
        'documento' => 'document',
        'audio'     => 'audio',
        'sticker'   => 'sticker',
    );
    if (!isset($map[$tipo])) return null;
    $key = $map[$tipo];
    if (!isset($payload[$key]) || !is_array($payload[$key])) return null;
    $blk = $payload[$key];

    // Z-API usa diferentes nomes conforme a versão: imageUrl, url, fileUrl, mediaUrl, etc.
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
 * Horário comercial: seg-sex 10h às 18h.
 */
function zapi_fora_horario() {
    $hora = (int)date('H');
    $dia  = (int)date('N'); // 1=seg .. 7=dom
    if ($dia >= 6) return true;               // sáb/dom
    if ($hora < 10 || $hora >= 18) return true;
    return false;
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

/**
 * Busca histórico de mensagens de um telefone na Z-API.
 * @return array|null lista de mensagens (raw) ou null em erro
 */
function zapi_fetch_chat_messages($ddd, $telefone, $limit = 50, &$debugInfo = null) {
    $inst = zapi_get_instancia($ddd);
    if (!$inst || !$inst['instancia_id'] || !$inst['token']) return null;
    $cfg = zapi_get_config();
    $phone = zapi_normaliza_telefone($telefone);
    $url = rtrim($cfg['base_url'], '/') . '/' . $inst['instancia_id'] . '/token/' . $inst['token'] . '/chat-messages/' . $phone . '?size=' . (int)$limit;

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
    $err  = curl_error($ch);
    curl_close($ch);

    $debugInfo = array('url' => $url, 'http_code' => $code, 'curl_err' => $err, 'body_preview' => substr($resp, 0, 500));

    if ($code < 200 || $code >= 300) return null;
    $data = json_decode($resp, true);
    return is_array($data) ? $data : null;
}

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
 * Importa mensagens do histórico Z-API para uma conversa existente.
 * Retorna contagem de mensagens importadas (não duplica pelo zapi_message_id).
 */
function zapi_sincronizar_historico_conversa($convId, $limit = 50) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT co.*, i.ddd FROM zapi_conversas co JOIN zapi_instancias i ON i.id = co.instancia_id WHERE co.id = ?");
    $stmt->execute(array($convId));
    $conv = $stmt->fetch();
    if (!$conv) return array('erro' => 'Conversa não encontrada', 'importadas' => 0);

    $raw = zapi_fetch_chat_messages($conv['ddd'], $conv['telefone'], $limit);
    if ($raw === null) return array('erro' => 'Falha ao consultar Z-API', 'importadas' => 0);

    // A resposta pode ser array direto OU {messages: [...]} OU {data: [...]}
    $msgs = $raw;
    if (isset($raw['messages']) && is_array($raw['messages'])) $msgs = $raw['messages'];
    elseif (isset($raw['data']) && is_array($raw['data'])) $msgs = $raw['data'];
    elseif (!isset($raw[0]) && is_array($raw)) {
        // Objeto único — envolver em array
        $msgs = array($raw);
    }

    $importadas = 0;
    $detalhes   = array();
    foreach ($msgs as $m) {
        if (!is_array($m)) continue;
        // Z-API usa diferentes nomes: messageId, id, _id, zaapId, keyId
        $zapiId = $m['messageId'] ?? ($m['id'] ?? ($m['_id'] ?? ($m['zaapId'] ?? ($m['keyId'] ?? ''))));
        if (!$zapiId) {
            // Fallback: usar hash estável do payload pra evitar perda total
            $zapiId = 'h_' . substr(md5(json_encode($m)), 0, 20);
        }

        // Evitar duplicata
        $chk = $pdo->prepare("SELECT id FROM zapi_mensagens WHERE zapi_message_id = ? LIMIT 1");
        $chk->execute(array($zapiId));
        if ($chk->fetchColumn()) continue;

        $fromMe  = !empty($m['fromMe']) || !empty($m['isFromMe']);
        $direcao = $fromMe ? 'enviada' : 'recebida';
        $tipo    = zapi_detecta_tipo($m);
        $cont    = zapi_extrai_conteudo($m, $tipo);
        $arq     = zapi_extrai_arquivo($m, $tipo);

        // Fallback: se não achou texto, tentar outras chaves comuns
        if (!$cont) {
            $cont = $m['body'] ?? ($m['message'] ?? ($m['text'] ?? ($m['conversation'] ?? '')));
            if (is_array($cont)) $cont = $cont['message'] ?? ($cont['body'] ?? '');
        }

        // Timestamp (segundos ou ms)
        $ts = $m['momment'] ?? ($m['timestamp'] ?? ($m['messageTimestamp'] ?? ($m['t'] ?? null)));
        if ($ts) {
            $tsInt = (int)$ts;
            if ($tsInt > 10000000000) $tsInt = (int)($tsInt / 1000); // ms → s
            $dateStr = date('Y-m-d H:i:s', $tsInt);
        } else {
            $dateStr = date('Y-m-d H:i:s');
        }

        $pdo->prepare(
            "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo,
                arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'historico', ?)"
        )->execute(array(
            $convId, $zapiId, $direcao, $tipo, $cont,
            $arq['url'] ?? null, $arq['nome'] ?? null, $arq['mime'] ?? null, $arq['tamanho'] ?? null,
            $dateStr
        ));
        $importadas++;
    }
    return array(
        'importadas'      => $importadas,
        'total_recebido'  => count($msgs),
        'primeiro_sample' => isset($msgs[0]) ? $msgs[0] : null,  // pra debug quando importadas=0
    );
}

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
