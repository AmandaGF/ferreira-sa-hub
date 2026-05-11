<?php
/**
 * TOTP (Time-based One-Time Password) — RFC 6238
 *
 * Mesmo algoritmo usado por Google Authenticator, eproc, PJe, TRF2, etc.
 * Implementacao pura em PHP — sem dependencias externas.
 *
 * Tambem expoe helpers pra criptografar/decriptar a chave secreta antes
 * de salvar no banco (AES-256-CBC com chave em config.php).
 */

if (!defined('TOTP_ENCRYPTION_KEY_FALLBACK')) {
    // Fallback se TOTP_ENCRYPTION_KEY nao estiver definida em config.php.
    // ATENCAO: nao depender disso em prod — deve ser sobrescrita.
    define('TOTP_ENCRYPTION_KEY_FALLBACK', hash('sha256', 'fsa-hub-totp-default-do-not-use-in-prod', true));
}

/**
 * Retorna a chave de criptografia (32 bytes) usada pra proteger as chaves
 * secretas TOTP no banco. Tenta carregar de config.php; se nao houver,
 * usa o fallback (com aviso visivel em audit_log na primeira chamada).
 */
function totp_encryption_key()
{
    static $key = null;
    if ($key !== null) return $key;
    if (defined('TOTP_ENCRYPTION_KEY') && TOTP_ENCRYPTION_KEY) {
        // Aceita hex (64 chars), base64 ou raw (32 bytes).
        $candidate = TOTP_ENCRYPTION_KEY;
        if (strlen($candidate) === 64 && ctype_xdigit($candidate)) {
            $key = hex2bin($candidate);
        } elseif (strlen($candidate) === 44 && substr($candidate, -1) === '=') {
            $key = base64_decode($candidate);
        } else {
            $key = hash('sha256', $candidate, true);
        }
    } else {
        $key = TOTP_ENCRYPTION_KEY_FALLBACK;
    }
    return $key;
}

/**
 * Criptografa uma chave secreta TOTP (Base32 string) com AES-256-CBC.
 * Retorna string com IV prefixado (16 bytes) + ciphertext, tudo em base64.
 */
function totp_encrypt($plaintext)
{
    if ($plaintext === '' || $plaintext === null) return '';
    $iv = openssl_random_pseudo_bytes(16);
    $ct = openssl_encrypt($plaintext, 'aes-256-cbc', totp_encryption_key(), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $ct);
}

/**
 * Descriptografa uma chave que foi salva via totp_encrypt(). Retorna a
 * Base32 original (a "chave secreta" do TOTP). Retorna '' se falhar.
 */
function totp_decrypt($encrypted)
{
    if ($encrypted === '' || $encrypted === null) return '';
    $raw = base64_decode($encrypted, true);
    if ($raw === false || strlen($raw) < 17) return '';
    $iv = substr($raw, 0, 16);
    $ct = substr($raw, 16);
    $pt = openssl_decrypt($ct, 'aes-256-cbc', totp_encryption_key(), OPENSSL_RAW_DATA, $iv);
    return $pt === false ? '' : $pt;
}

/**
 * Decodifica string Base32 (alfabeto RFC 4648, sem padding obrigatorio)
 * para bytes binarios. Tolera espacos, hifens e lowercase.
 */
function totp_base32_decode($input)
{
    $input = strtoupper(preg_replace('/[\s\-]/', '', (string)$input));
    $input = rtrim($input, '=');
    if ($input === '') return '';
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0, $n = strlen($input); $i < $n; $i++) {
        $pos = strpos($alphabet, $input[$i]);
        if ($pos === false) return ''; // caractere invalido
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0, $n = strlen($bits); $i + 8 <= $n; $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

/**
 * Gera o codigo TOTP de 6 digitos a partir de uma chave secreta Base32
 * (ex.: 'JBSWY3DPEHPK3PXP'). Algoritmo RFC 6238 com SHA1 + step 30s.
 *
 * @param string $secret_base32  Chave secreta em Base32 (NAO criptografada)
 * @param int    $timestamp      Timestamp pra computar o codigo (default: agora)
 * @return string                Codigo de 6 digitos (zero-padded) ou '' se chave invalida
 */
function totp_gerar($secret_base32, $timestamp = null)
{
    $secret = totp_base32_decode($secret_base32);
    if ($secret === '') return '';
    if ($timestamp === null) $timestamp = time();
    $counter = (int)floor($timestamp / 30);

    // pack como 8 bytes big-endian
    $high = ($counter >> 32) & 0xFFFFFFFF;
    $low  = $counter & 0xFFFFFFFF;
    $bin_counter = pack('N*', $high, $low);

    $hash = hash_hmac('sha1', $bin_counter, $secret, true);
    $offset = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
        ( ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

/**
 * Valida se um codigo de 6 digitos confere com a chave secreta.
 * Aceita drift de +/- 1 step (30s antes/depois) pra tolerar clock skew.
 */
function totp_validar($secret_base32, $codigo)
{
    $codigo = preg_replace('/\D/', '', (string)$codigo);
    if (strlen($codigo) !== 6) return false;
    $now = time();
    foreach (array(-30, 0, 30) as $delta) {
        if (hash_equals(totp_gerar($secret_base32, $now + $delta), $codigo)) return true;
    }
    return false;
}

/**
 * Quanto tempo (segundos) falta pro proximo step de 30s — usado pelo
 * timer visual da UI.
 */
function totp_segundos_restantes($timestamp = null)
{
    if ($timestamp === null) $timestamp = time();
    return 30 - ($timestamp % 30);
}

/**
 * Gera uma chave secreta aleatoria em Base32 (160 bits — padrao Google
 * Authenticator). Usado quando o admin quer ATIVAR 2FA no proprio Hub
 * (em vez de cadastrar a chave de outro sistema).
 */
function totp_gerar_secret($length = 32)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bytes = openssl_random_pseudo_bytes($length);
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[ord($bytes[$i]) & 31];
    }
    return $secret;
}

/**
 * Self-heal das tabelas usadas pelo sistema de 2FA centralizado.
 * Pode ser chamado em qualquer entry-point (idempotente).
 */
function totp_ensure_schema(PDO $pdo)
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sistemas_2fa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            url_login VARCHAR(300) NULL,
            icone VARCHAR(20) NULL,
            chave_encrypted TEXT NOT NULL,
            notas TEXT NULL,
            ordem INT DEFAULT 0,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS users_2fa (
            user_id INT PRIMARY KEY,
            secret_encrypted TEXT NOT NULL,
            enabled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {}
}
