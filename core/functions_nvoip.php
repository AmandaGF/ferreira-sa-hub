<?php
/**
 * Ferreira & Sá Hub — Helper Nvoip (telefonia VoIP)
 *
 * Docs: https://doc.nvoip.com.br/
 * Fluxo: OAuth password grant → gera access_token (23h) + refresh_token.
 *        Realiza chamada via /v2/calls → polling /v2/calls?callId=X
 *        até state=finished → baixa gravação (linkAudio) → transcreve + resumo IA.
 */

define('NVOIP_BASE_URL', 'https://api.nvoip.com.br/v2');
define('NVOIP_OAUTH_BASIC', 'TnZvaXBBcGlWMjpUblp2YVhCQmNHbFdNakl3TWpFPQ==');
define('NVOIP_POLLING_MAX_SEG', 300); // 5min — encerra automaticamente se não finalizar

// ─── Config helpers (acesso centralizado à tabela configuracoes) ────────

function nvoip_cfg_get($chave) {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            $rows = db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'nvoip_%'")->fetchAll();
            foreach ($rows as $r) $cache[$r['chave']] = $r['valor'];
        } catch (Exception $e) {}
    }
    return isset($cache[$chave]) ? $cache[$chave] : '';
}

function nvoip_cfg_set($chave, $valor) {
    try {
        db()->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute(array($chave, $valor));
        // Invalida cache
        $GLOBALS['_nvoip_cfg_reset'] = true;
    } catch (Exception $e) {}
}

function nvoip_configurada() {
    return nvoip_cfg_get('nvoip_napikey') !== '' && nvoip_cfg_get('nvoip_numbersip') !== '';
}

// ─── OAuth ─────────────────────────────────────────────────────────────

function nvoip_get_token() {
    $expiry = nvoip_cfg_get('nvoip_token_expiry');
    $atual  = nvoip_cfg_get('nvoip_access_token');
    // Token válido por mais de 5 min: usa ele
    if ($atual && $expiry && strtotime($expiry) > time() + 300) return $atual;

    $refresh = nvoip_cfg_get('nvoip_refresh_token');
    if ($refresh) {
        $novo = nvoip_refresh_token($refresh);
        if ($novo) return $novo;
    }
    return nvoip_generate_token();
}

function nvoip_generate_token() {
    $user = nvoip_cfg_get('nvoip_numbersip');
    $pass = nvoip_cfg_get('nvoip_user_token');
    if (!$user || !$pass) return null;
    $ch = curl_init(NVOIP_BASE_URL . '/oauth/token');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query(array(
            'username'   => $user,
            'password'   => $pass,
            'grant_type' => 'password',
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . NVOIP_OAUTH_BASIC,
        ),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    $resp = json_decode($raw, true);
    if (is_array($resp) && !empty($resp['access_token'])) {
        nvoip_cfg_set('nvoip_access_token',  $resp['access_token']);
        nvoip_cfg_set('nvoip_refresh_token', $resp['refresh_token'] ?? '');
        nvoip_cfg_set('nvoip_token_expiry',  date('Y-m-d H:i:s', time() + 82800)); // 23h
        return $resp['access_token'];
    }
    return null;
}

function nvoip_refresh_token($refresh_token) {
    $ch = curl_init(NVOIP_BASE_URL . '/oauth/token');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => http_build_query(array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        )),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . NVOIP_OAUTH_BASIC,
        ),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    $resp = json_decode($raw, true);
    if (is_array($resp) && !empty($resp['access_token'])) {
        nvoip_cfg_set('nvoip_access_token',  $resp['access_token']);
        nvoip_cfg_set('nvoip_refresh_token', $resp['refresh_token'] ?? $refresh_token);
        nvoip_cfg_set('nvoip_token_expiry',  date('Y-m-d H:i:s', time() + 82800));
        return $resp['access_token'];
    }
    return null;
}

// ─── Chamadas ──────────────────────────────────────────────────────────

function nvoip_get_ramal_usuario($userId) {
    try {
        $st = db()->prepare("SELECT nvoip_ramal FROM users WHERE id = ?");
        $st->execute(array($userId));
        $r = $st->fetchColumn();
        if ($r) return $r;
    } catch (Exception $e) {}
    // Fallback: numbersip da conta
    return nvoip_cfg_get('nvoip_numbersip');
}

function nvoip_realizar_chamada($telefoneDestino, $userId) {
    $token = nvoip_get_token();
    if (!$token) return array('state' => 'failed', 'error' => 'Token Nvoip não configurado');
    $ramal = nvoip_get_ramal_usuario($userId);
    $tel   = preg_replace('/\D/', '', $telefoneDestino);
    if (strlen($tel) < 10) return array('state' => 'failed', 'error' => 'Telefone inválido');

    $ch = curl_init(NVOIP_BASE_URL . '/calls/');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_POSTFIELDS     => json_encode(array('caller' => $ramal, 'called' => $tel)),
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $resp = json_decode($raw, true);
    if (!is_array($resp)) $resp = array('state' => 'failed', 'error' => 'Resposta inválida', 'http' => $code);
    return $resp;
}

function nvoip_consultar_chamada($callId) {
    $napikey = nvoip_cfg_get('nvoip_napikey');
    $url = NVOIP_BASE_URL . '/calls?callId=' . urlencode($callId) . '&napikey=' . urlencode($napikey);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    $resp = json_decode($raw, true);
    return is_array($resp) ? $resp : null;
}

function nvoip_encerrar_chamada($callId) {
    $token = nvoip_get_token();
    if (!$token) return null;
    $url = NVOIP_BASE_URL . '/endcall?callId=' . urlencode($callId);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array(
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw, true);
}

function nvoip_consultar_saldo() {
    $token = nvoip_get_token();
    if (!$token) return null;
    $ch = curl_init(NVOIP_BASE_URL . '/balance');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $token),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw, true);
}

// ─── Pós-chamada: gravação + transcrição + resumo IA ────────────────────

function nvoip_processar_gravacao($callId, $linkAudio) {
    if (!$callId || !$linkAudio) return array('ok' => false, 'erro' => 'params');
    $dir = APP_ROOT . '/files/ligacoes/';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $filename = 'ligacao_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $callId) . '.mp3';
    $destPath = $dir . $filename;

    // 1) Baixa MP3 da Nvoip (link público fornecido pela API)
    $ch = curl_init($linkAudio);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$data || $code < 200 || $code >= 300 || strlen($data) < 1000) {
        return array('ok' => false, 'erro' => 'falha download mp3 (http ' . $code . ')');
    }
    @file_put_contents($destPath, $data);

    // 2) Transcreve via Groq Whisper (reusa helper existente)
    $transcricao = '';
    try {
        require_once APP_ROOT . '/core/functions_groq.php';
        if (function_exists('groq_transcribe_file') && function_exists('groq_transcribe_enabled') && groq_transcribe_enabled()) {
            $r = groq_transcribe_file($destPath, 'audio/mpeg');
            if (!empty($r['ok']) && !empty($r['text'])) $transcricao = trim($r['text']);
        }
    } catch (Exception $e) {}

    // 3) Resumo via Claude Haiku (só se tiver transcrição e chave Anthropic)
    $resumo = '';
    if ($transcricao && defined('ANTHROPIC_API_KEY') && ANTHROPIC_API_KEY) {
        $resumo = nvoip_resumir_ligacao($transcricao);
    }

    // 4) Atualiza banco
    try {
        db()->prepare("UPDATE ligacoes_historico
                       SET gravacao_url = ?, gravacao_local = ?, transcricao = ?, resumo_ia = ?
                       WHERE call_id = ?")
            ->execute(array($linkAudio, $filename, $transcricao, $resumo, $callId));
    } catch (Exception $e) {}

    return array('ok' => true, 'transcricao' => $transcricao, 'resumo' => $resumo, 'filename' => $filename);
}

function nvoip_resumir_ligacao($transcricao) {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) return '';
    $model = defined('ANTHROPIC_MODEL') ? ANTHROPIC_MODEL : 'claude-haiku-4-5-20251001';
    $system = "Você resume ligações telefônicas de um escritório de advocacia (Ferreira & Sá).\n"
            . "Retorne um resumo em EXATAMENTE 3 linhas:\n"
            . "1. Assunto principal da ligação\n"
            . "2. Próximos passos identificados\n"
            . "3. Observações importantes\n"
            . "Seja objetivo e use linguagem profissional jurídica.";
    $body = json_encode(array(
        'model'      => $model,
        'max_tokens' => 350,
        'system'     => $system,
        'messages'   => array(array('role' => 'user', 'content' => mb_substr($transcricao, 0, 6000, 'UTF-8'))),
    ));
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return '';
    $data = json_decode($raw, true);
    if (is_array($data) && !empty($data['content'][0]['text'])) {
        return trim($data['content'][0]['text']);
    }
    return '';
}
