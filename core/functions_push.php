<?php
/**
 * Web Push notifications — implementação nativa (RFC 8292 VAPID + RFC 8291 aes128gcm).
 *
 * Dependências: apenas extensões PHP padrão (openssl, curl, hash_hkdf).
 * Nenhuma biblioteca externa/Composer.
 *
 * API pública:
 *   push_notify($user_id, $titulo, $corpo, $url, $urgente)
 *   push_notify_role(array $roles, $titulo, $corpo, $url, $urgente)
 *   push_notify_todos($titulo, $corpo, $url)
 *   push_gerar_vapid()  — uso one-shot na migração
 */

if (!defined('PUSH_TTL_DEFAULT')) define('PUSH_TTL_DEFAULT', 2419200); // 4 semanas

// ── Helpers de codificação ──

function _push_b64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _push_b64url_decode($data) {
    $pad = (4 - strlen($data) % 4) % 4;
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', $pad));
}

// DER ECDSA (seq{r,s}) → raw r||s (64 bytes para P-256)
function _push_der_to_raw($der) {
    $off = 0;
    if (!isset($der[$off]) || ord($der[$off]) !== 0x30) return false; $off++;
    $seqLenByte = ord($der[$off]);
    if ($seqLenByte > 0x80) { $off += 1 + ($seqLenByte & 0x7f); }
    else { $off++; }
    if (!isset($der[$off]) || ord($der[$off]) !== 0x02) return false; $off++;
    $rlen = ord($der[$off]); $off++;
    $r = substr($der, $off, $rlen); $off += $rlen;
    $r = ltrim($r, "\x00");
    if (!isset($der[$off]) || ord($der[$off]) !== 0x02) return false; $off++;
    $slen = ord($der[$off]); $off++;
    $s = substr($der, $off, $slen);
    $s = ltrim($s, "\x00");
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

// Raw P-256 uncompressed (65 bytes, começa com 0x04) → PEM SubjectPublicKeyInfo
function _push_p256_raw_to_pem($raw65) {
    if (strlen($raw65) !== 65 || $raw65[0] !== "\x04") return false;
    // SPKI: SEQUENCE { algorithm { OID id-ecPublicKey, OID prime256v1 }, BIT STRING (raw) }
    $algorithm = "\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    // BIT STRING com 0 unused bits, contendo o ponto uncompressed de 65 bytes
    $bitStringContent = "\x00" . $raw65;
    $bitString = "\x03" . _push_asn1_len(strlen($bitStringContent)) . $bitStringContent;
    $spkiInner = $algorithm . $bitString;
    $spki = "\x30" . _push_asn1_len(strlen($spkiInner)) . $spkiInner;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function _push_asn1_len($n) {
    if ($n < 128) return chr($n);
    $bytes = '';
    while ($n > 0) { $bytes = chr($n & 0xff) . $bytes; $n >>= 8; }
    return chr(0x80 | strlen($bytes)) . $bytes;
}

// ── Chaves VAPID (lazy-load de configuracoes) ──

function _push_vapid_keys() {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = db()->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('vapid_public','vapid_private','vapid_subject')")->fetchAll();
    } catch (Exception $e) { return array(); }
    $cache = array();
    foreach ($rows as $r) { $cache[$r['chave']] = $r['valor']; }
    return $cache;
}

function push_vapid_public_b64url() {
    $k = _push_vapid_keys();
    return $k['vapid_public'] ?? '';
}

// Gera novo par VAPID (uso one-shot na migração)
function push_gerar_vapid() {
    $res = openssl_pkey_new(array(
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ));
    if (!$res) return false;
    openssl_pkey_export($res, $pem);
    $details = openssl_pkey_get_details($res);
    $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    return array(
        'private_pem'   => $pem,
        'public_b64url' => _push_b64url_encode($publicRaw),
    );
}

// ── VAPID JWT (ES256) ──

function _push_vapid_jwt($audience) {
    $k = _push_vapid_keys();
    if (empty($k['vapid_private'])) return false;
    $subject = $k['vapid_subject'] ?? 'mailto:contato@ferreiraesa.com.br';

    $header = _push_b64url_encode(json_encode(array('typ'=>'JWT','alg'=>'ES256')));
    $claims = _push_b64url_encode(json_encode(array(
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => $subject,
    )));
    $signingInput = $header . '.' . $claims;

    $pk = openssl_pkey_get_private($k['vapid_private']);
    if (!$pk) return false;
    $derSig = '';
    if (!openssl_sign($signingInput, $derSig, $pk, OPENSSL_ALGO_SHA256)) return false;
    $rawSig = _push_der_to_raw($derSig);
    if (!$rawSig) return false;

    return $signingInput . '.' . _push_b64url_encode($rawSig);
}

// ── Encryption aes128gcm (RFC 8291) ──

function _push_encrypt($payload, $p256dhB64url, $authB64url) {
    $recipPub  = _push_b64url_decode($p256dhB64url);  // 65 bytes
    $recipAuth = _push_b64url_decode($authB64url);    // 16 bytes
    if (strlen($recipPub) !== 65 || strlen($recipAuth) !== 16) return false;

    // Ephemeral ECDH P-256
    $ephPair = openssl_pkey_new(array('curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC));
    if (!$ephPair) return false;
    $ephDetails = openssl_pkey_get_details($ephPair);
    $serverPub = "\x04" . $ephDetails['ec']['x'] . $ephDetails['ec']['y'];

    // Shared secret via ECDH
    $recipPem = _push_p256_raw_to_pem($recipPub);
    if (!$recipPem) return false;
    $recipKey = openssl_pkey_get_public($recipPem);
    if (!$recipKey) return false;
    if (!function_exists('openssl_pkey_derive')) return false;
    $shared = openssl_pkey_derive($recipKey, $ephPair, 256);
    if (!$shared) return false;

    // HKDF: IKM = HKDF-extract(auth, shared) + HKDF-expand(key_info)
    // key_info = "WebPush: info\x00" + recipient_pubkey + server_pubkey
    $keyInfo = "WebPush: info\x00" . $recipPub . $serverPub;
    $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $recipAuth);

    $salt = random_bytes(16);

    $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00",     $salt);

    // Último record → último byte = 0x02
    $paddedPayload = $payload . "\x02";

    $tag = '';
    $cipher = openssl_encrypt($paddedPayload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($cipher === false) return false;

    // Header aes128gcm (RFC 8188): salt(16) || rs(4 BE) || idlen(1) || keyid(server_pub 65 bytes)
    $rs = 4096;
    $header = $salt . pack('N', $rs) . chr(65) . $serverPub;

    return $header . $cipher . $tag;
}

// ── HTTP send ──

function _push_send_one($sub, $payload, $ttl = PUSH_TTL_DEFAULT) {
    $endpoint = $sub['endpoint'];
    $u = parse_url($endpoint);
    if (!$u || empty($u['host']) || empty($u['scheme'])) return array('ok'=>false,'status'=>0,'error'=>'endpoint inválido');
    $audience = $u['scheme'] . '://' . $u['host'];

    $jwt = _push_vapid_jwt($audience);
    if (!$jwt) return array('ok'=>false,'status'=>0,'error'=>'VAPID JWT falhou (chaves não configuradas?)');

    $vapidPub = push_vapid_public_b64url();
    if (!$vapidPub) return array('ok'=>false,'status'=>0,'error'=>'VAPID public key ausente');

    $body = '';
    $headers = array(
        'TTL: ' . (int)$ttl,
        'Authorization: vapid t=' . $jwt . ', k=' . $vapidPub,
    );

    if ($payload !== null && $payload !== '') {
        $enc = _push_encrypt($payload, $sub['p256dh'], $sub['auth']);
        if ($enc === false) return array('ok'=>false,'status'=>0,'error'=>'encrypt falhou');
        $body = $enc;
        $headers[] = 'Content-Type: application/octet-stream';
        $headers[] = 'Content-Encoding: aes128gcm';
        $headers[] = 'Content-Length: ' . strlen($body);
    }

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return array(
        'ok'     => ($code >= 200 && $code < 300),
        'status' => (int)$code,
        'error'  => $err ?: null,
    );
}

// ── API pública ──

/**
 * Envia push notification a TODAS as subscriptions ativas de um usuário.
 * Em caso de 404/410 (subscription expirada), desativa no banco.
 */
function push_notify($user_id, $titulo, $corpo, $url = null, $urgente = false) {
    $k = _push_vapid_keys();
    if (empty($k['vapid_public']) || empty($k['vapid_private'])) return;  // não configurado
    $pdo = db();
    try {
        $stmt = $pdo->prepare("SELECT id, endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ? AND ativo = 1");
        $stmt->execute(array((int)$user_id));
        $subs = $stmt->fetchAll();
    } catch (Exception $e) { return; }

    if (!$subs) return;

    $payload = json_encode(array(
        'title'   => $titulo,
        'body'    => $corpo,
        'url'     => $url ?: '/conecta/',
        'urgente' => (bool)$urgente,
    ), JSON_UNESCAPED_UNICODE);

    foreach ($subs as $sub) {
        $r = _push_send_one($sub, $payload);
        if (!$r['ok'] && in_array($r['status'], array(404, 410), true)) {
            try { $pdo->prepare("UPDATE push_subscriptions SET ativo = 0 WHERE id = ?")->execute(array($sub['id'])); } catch (Exception $e) {}
        }
    }
}

/**
 * Notifica todos os usuários ativos de um conjunto de roles.
 * Ex: push_notify_role(array('admin','gestao'), 'Novo lead', '...');
 */
function push_notify_role($roles, $titulo, $corpo, $url = null, $urgente = false) {
    if (empty($roles) || !is_array($roles)) return;
    $pdo = db();
    $ph = implode(',', array_fill(0, count($roles), '?'));
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND role IN ($ph)");
        $stmt->execute($roles);
        foreach ($stmt->fetchAll() as $u) {
            push_notify((int)$u['id'], $titulo, $corpo, $url, $urgente);
        }
    } catch (Exception $e) {}
}

function push_notify_todos($titulo, $corpo, $url = null) {
    $pdo = db();
    try {
        foreach ($pdo->query("SELECT id FROM users WHERE is_active = 1")->fetchAll() as $u) {
            push_notify((int)$u['id'], $titulo, $corpo, $url);
        }
    } catch (Exception $e) {}
}
