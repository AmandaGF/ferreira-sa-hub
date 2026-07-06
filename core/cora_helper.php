<?php
/**
 * Ferreira & Sá Hub — Integração Cora (Banco PJ)
 * Leitura ao vivo: saldo + extrato. (Pagamento fica pra fase 2.)
 *
 * Autenticação: mTLS (certificado + chave) obtido no painel da Cora +
 * client_id. Token client_credentials dura 24h. Doc: developers.cora.com.br
 *
 * Config (via tabela `configuracoes` OU constantes no config.php):
 *   cora_env        -> 'prod' (default) ou 'stage'
 *   cora_client_id  -> client_id gerado na Cora
 *   Certificado/chave: arquivos em core/certs/ (fora do git):
 *     core/certs/cora_certificate.pem  e  core/certs/cora_private-key.key
 *   (ou defina CORA_CERT_PATH / CORA_KEY_PATH no config.php)
 *
 * Amanda 06/07/2026.
 */

/** Lê um valor de config: 1º constante do config.php, 2º tabela configuracoes. */
function _cora_cfg_val($chave, $const, $default = '')
{
    if ($const && defined($const) && constant($const) !== '') return constant($const);
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            foreach (db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'cora_%'")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cache[$r['chave']] = $r['valor'];
            }
        } catch (Exception $e) {}
    }
    return isset($cache[$chave]) && $cache[$chave] !== '' ? $cache[$chave] : $default;
}

/** Config consolidada da Cora. */
function cora_cfg()
{
    $env = _cora_cfg_val('cora_env', 'CORA_ENV', 'prod');
    $env = ($env === 'stage') ? 'stage' : 'prod';
    $base = ($env === 'stage')
        ? 'https://matls-clients.api.stage.cora.com.br'
        : 'https://matls-clients.api.cora.com.br';
    $certDefault = APP_ROOT . '/core/certs/cora_certificate.pem';
    $keyDefault  = APP_ROOT . '/core/certs/cora_private-key.key';
    return array(
        'env'       => $env,
        'base'      => $base,
        'client_id' => _cora_cfg_val('cora_client_id', 'CORA_CLIENT_ID', ''),
        'cert'      => (defined('CORA_CERT_PATH') && CORA_CERT_PATH) ? CORA_CERT_PATH : $certDefault,
        'key'       => (defined('CORA_KEY_PATH') && CORA_KEY_PATH) ? CORA_KEY_PATH : $keyDefault,
    );
}

/** A Cora está pronta pra usar? (client_id + certificado + chave presentes) */
function cora_configurado()
{
    $c = cora_cfg();
    return $c['client_id'] !== '' && is_file($c['cert']) && is_file($c['key']);
}

/** Grava um valor em configuracoes (usado pra cachear o token). */
function _cora_cfg_set($chave, $valor)
{
    try {
        db()->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute(array($chave, $valor));
    } catch (Exception $e) {}
}

/**
 * Requisição mTLS genérica à Cora. Retorna array('status'=>int,'body'=>mixed,'error'=>str|null).
 * $form=true envia como x-www-form-urlencoded (usado no /token).
 */
function _cora_req($method, $url, $headers = array(), $data = null, $form = false)
{
    $c = cora_cfg();
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSLCERT, $c['cert']);
    curl_setopt($ch, CURLOPT_SSLKEY, $c['key']);
    if (defined('CORA_KEY_PASSWORD') && CORA_KEY_PASSWORD !== '') {
        curl_setopt($ch, CURLOPT_SSLKEYPASSWD, CORA_KEY_PASSWORD);
    }
    if ($data !== null) {
        if ($form) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return array('status' => 0, 'body' => null, 'error' => $err ?: 'Falha de conexão com a Cora');
    $json = json_decode($resp, true);
    return array('status' => $status, 'body' => ($json === null ? $resp : $json), 'error' => null);
}

/** Obtém (e cacheia por ~24h) o token OAuth da Cora. Retorna string|null. */
function cora_token($force = false)
{
    if (!$force) {
        $tok = _cora_cfg_val('cora_token', null, '');
        $exp = (int)_cora_cfg_val('cora_token_exp', null, '0');
        // margem de 5 min
        if ($tok !== '' && $exp > (time() + 300)) return $tok;
    }
    $c = cora_cfg();
    if ($c['client_id'] === '') return null;
    $r = _cora_req('POST', $c['base'] . '/token', array(), array(
        'grant_type' => 'client_credentials',
        'client_id'  => $c['client_id'],
    ), true);
    if ($r['status'] === 200 && is_array($r['body']) && !empty($r['body']['access_token'])) {
        $tok = $r['body']['access_token'];
        $ttl = isset($r['body']['expires_in']) ? (int)$r['body']['expires_in'] : 86400;
        _cora_cfg_set('cora_token', $tok);
        _cora_cfg_set('cora_token_exp', (string)(time() + $ttl));
        return $tok;
    }
    return null;
}

/** GET autenticado na Cora. */
function _cora_get($path, $query = array())
{
    $tok = cora_token();
    if (!$tok) return array('status' => 401, 'body' => null, 'error' => 'Não autenticou na Cora (cheque client_id/certificado).');
    $c = cora_cfg();
    $url = $c['base'] . $path;
    if ($query) $url .= '?' . http_build_query($query);
    $r = _cora_req('GET', $url, array('Authorization: Bearer ' . $tok, 'Accept: application/json'));
    // token pode ter expirado no servidor — tenta 1x renovando
    if ($r['status'] === 401) {
        $tok = cora_token(true);
        if ($tok) {
            $r = _cora_req('GET', $url, array('Authorization: Bearer ' . $tok, 'Accept: application/json'));
        }
    }
    return $r;
}

/** Saldo atual da conta Cora em centavos. Retorna int, ou null em erro. */
function cora_saldo()
{
    $r = _cora_get('/third-party/account/balance');
    if ($r['status'] === 200 && is_array($r['body']) && isset($r['body']['balance'])) {
        return (int)$r['body']['balance'];
    }
    return null;
}

/**
 * Extrato da Cora normalizado no MESMO formato do OFX (pra reusar a conciliação):
 * cada item = array(fitid, data 'Y-m-d', tipo 'entrada|saida', valor_cents, descricao).
 * Retorna array('ok'=>bool, 'txs'=>[], 'error'=>str|null).
 */
function cora_extrato($start, $end)
{
    $txs = array();
    $page = 1;
    $perPage = 500;
    for ($i = 0; $i < 40; $i++) { // teto de segurança: 40 páginas
        $r = _cora_get('/bank-statement/statement', array(
            'start' => $start, 'end' => $end, 'page' => $page, 'perPage' => $perPage,
        ));
        if ($r['status'] !== 200 || !is_array($r['body'])) {
            $msg = 'Erro ao consultar extrato na Cora (HTTP ' . $r['status'] . ')';
            if (!empty($r['error'])) $msg .= ': ' . $r['error'];
            return array('ok' => false, 'txs' => $txs, 'error' => $msg);
        }
        $entries = isset($r['body']['entries']) && is_array($r['body']['entries']) ? $r['body']['entries'] : array();
        foreach ($entries as $e) {
            if (!isset($e['amount'])) continue;
            $trx = isset($e['transaction']) && is_array($e['transaction']) ? $e['transaction'] : array();
            $desc = trim((string)($trx['description'] ?? ''));
            $cp = isset($trx['counterParty']['name']) ? trim((string)$trx['counterParty']['name']) : '';
            if ($cp !== '') $desc = ($desc !== '' ? $desc . ' — ' : '') . $cp;
            if ($desc === '') $desc = 'Movimentação Cora';
            $txs[] = array(
                'fitid'       => isset($e['id']) ? (string)$e['id'] : md5(($e['createdAt'] ?? '') . $e['amount'] . $desc),
                'data'        => substr((string)($e['createdAt'] ?? ''), 0, 10),
                'tipo'        => (($e['type'] ?? '') === 'CREDIT') ? 'entrada' : 'saida',
                'valor_cents' => (int)$e['amount'],
                'descricao'   => mb_substr($desc, 0, 255),
            );
        }
        if (count($entries) < $perPage) break; // última página
        $page++;
    }
    return array('ok' => true, 'txs' => $txs, 'error' => null);
}
