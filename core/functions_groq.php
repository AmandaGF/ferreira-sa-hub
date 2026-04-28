<?php
/**
 * Ferreira & Sá Hub — Transcrição de áudio via Groq Whisper.
 */

function groq_api_key() {
    static $key = null;
    if ($key !== null) return $key;
    try {
        $stmt = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'groq_api_key'");
        $stmt->execute();
        $key = $stmt->fetchColumn() ?: '';
    } catch (Exception $e) { $key = ''; }
    return $key;
}

function groq_transcribe_enabled() {
    try {
        $stmt = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'groq_transcribe_on'");
        $stmt->execute();
        return $stmt->fetchColumn() === '1';
    } catch (Exception $e) { return false; }
}

/**
 * Transcreve um arquivo de áudio local via Groq Whisper.
 * @return array ['ok' => bool, 'text' => string, 'erro' => string]
 */
function groq_transcribe_file($filePath, $mime = null) {
    $apiKey = groq_api_key();
    if (!$apiKey) return array('ok' => false, 'erro' => 'Chave Groq não configurada');
    if (!is_readable($filePath)) return array('ok' => false, 'erro' => 'Arquivo não legível: ' . $filePath);

    // Pré-check: arquivos muito pequenos não passam no Whisper (mín 0.01s).
    $tamanho = @filesize($filePath);
    if ($tamanho !== false && $tamanho < 2048) {
        return array('ok' => false, 'erro' => 'Áudio muito curto pra transcrever (' . $tamanho . ' bytes). Provavelmente foi enviado sem gravar conteúdo, ou metadata corrompido.');
    }

    $mime = $mime ?: (mime_content_type($filePath) ?: 'audio/webm');
    $cfile = new CURLFile($filePath, $mime, basename($filePath));

    $post = array(
        'file'            => $cfile,
        'model'           => 'whisper-large-v3-turbo',
        'language'        => 'pt',
        'response_format' => 'json',
        'temperature'     => '0',
    );

    $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => array('Authorization: Bearer ' . $apiKey),
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return array('ok' => false, 'erro' => 'cURL: ' . $err);
    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && isset($data['text'])) {
        return array('ok' => true, 'text' => trim($data['text']));
    }
    // Tradução de erro técnico do Groq pra mensagem humana
    if ($code === 400 && isset($data['error']['message']) && stripos($data['error']['message'], 'too short') !== false) {
        return array('ok' => false, 'erro' => 'Áudio sem duração definida no metadata (bug comum do Chrome em quem gravou). Reproduza ouvindo direto pelo player ▶️ — a transcrição automática não consegue processar.');
    }
    return array('ok' => false, 'erro' => 'HTTP ' . $code . ': ' . mb_substr($resp, 0, 300));
}

/**
 * Baixa um áudio da URL e transcreve.
 */
function groq_transcribe_url($url, $mime = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $bytes = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !$bytes) {
        return array('ok' => false, 'erro' => 'Download falhou HTTP ' . $code);
    }

    $ext = 'ogg';
    if ($mime || $ctype) {
        $m = strtolower((string)($mime ?: $ctype));
        if (strpos($m, 'mpeg') !== false || strpos($m, 'mp3') !== false) $ext = 'mp3';
        elseif (strpos($m, 'wav') !== false) $ext = 'wav';
        elseif (strpos($m, 'm4a') !== false || strpos($m, 'mp4') !== false) $ext = 'm4a';
        elseif (strpos($m, 'webm') !== false) $ext = 'webm';
        elseif (strpos($m, 'ogg') !== false) $ext = 'ogg';
    }

    $tmpPath = sys_get_temp_dir() . '/groq_audio_' . uniqid('', true) . '.' . $ext;
    file_put_contents($tmpPath, $bytes);
    $r = groq_transcribe_file($tmpPath, $mime ?: $ctype);
    @unlink($tmpPath);
    return $r;
}

/**
 * Transcreve uma mensagem do banco (pela id) e salva no registro.
 * Funciona pra áudios locais (coluna arquivo_url aponta pra nossa URL) ou Z-API (URL externa).
 */
function groq_transcribe_mensagem($msgId) {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT id, tipo, arquivo_url, arquivo_mime, transcricao FROM zapi_mensagens WHERE id = ?");
    $stmt->execute(array($msgId));
    $m = $stmt->fetch();
    if (!$m) return array('ok' => false, 'erro' => 'Mensagem não encontrada');
    if ($m['tipo'] !== 'audio') return array('ok' => false, 'erro' => 'Não é áudio');
    if (!empty($m['transcricao'])) return array('ok' => true, 'text' => $m['transcricao'], 'cached' => true);
    if (!$m['arquivo_url']) return array('ok' => false, 'erro' => 'Sem URL do arquivo');

    // Tenta primeiro como arquivo local (se URL do Hub)
    $local = null;
    if (strpos($m['arquivo_url'], '/conecta/files/whatsapp/') !== false) {
        $nome = basename(parse_url($m['arquivo_url'], PHP_URL_PATH));
        $cand = APP_ROOT . '/files/whatsapp/' . rawurldecode($nome);
        if (is_readable($cand)) $local = $cand;
    }

    $r = $local
        ? groq_transcribe_file($local, $m['arquivo_mime'])
        : groq_transcribe_url($m['arquivo_url'], $m['arquivo_mime']);

    if (!empty($r['ok'])) {
        $pdo->prepare("UPDATE zapi_mensagens SET transcricao = ?, transcricao_em = NOW() WHERE id = ?")
            ->execute(array($r['text'], $msgId));
    }
    return $r;
}
