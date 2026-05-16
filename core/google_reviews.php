<?php
/**
 * Ferreira & Sá — Avaliações do Google (Places API) com cache em arquivo.
 *
 * Chave fica em configuracoes.google_places_api_key (não vai pro git).
 * place_id é resolvido 1x por texto e gravado em configuracoes.google_place_id.
 * Cache em /files/google_reviews.json (TTL 6h) — a página pública lê só o JSON;
 * o refresh é lazy (1 request azarado paga ~5s, raríssimo) ou via cron.
 *
 * Uso: $g = google_reviews_get();  // ['ok'=>bool,'rating','total','url','reviews'=>[...]]
 */

if (!defined('GREV_CACHE_FILE')) {
    define('GREV_CACHE_FILE', __DIR__ . '/../files/google_reviews.json');
    define('GREV_TTL', 6 * 3600);          // 6h
    define('GREV_QUERY_PADRAO', 'Ferreira e Sá Advocacia Especializada Barra Mansa RJ');
}

function _grev_cfg($chave) {
    try {
        $st = db()->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
        $st->execute(array($chave));
        $v = $st->fetchColumn();
        return $v !== false ? trim($v) : '';
    } catch (Exception $e) { return ''; }
}

function _grev_cfg_set($chave, $valor) {
    try {
        db()->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute(array($chave, $valor));
    } catch (Exception $e) { /* silencioso */ }
}

function _grev_http($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 6,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/**
 * Resolve o place_id pela busca textual (1x) e cacheia em configuracoes.
 */
function google_reviews_place_id($apiKey, $forcarQuery = null) {
    $pid = $forcarQuery ? '' : _grev_cfg('google_place_id');
    if ($pid !== '') return $pid;

    $q = $forcarQuery ?: GREV_QUERY_PADRAO;
    $url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json'
         . '?input=' . rawurlencode($q)
         . '&inputtype=textquery&fields=place_id,name'
         . '&language=pt-BR&key=' . rawurlencode($apiKey);
    $r = _grev_http($url);
    if (!$r || empty($r['candidates'][0]['place_id'])) return '';
    $pid = $r['candidates'][0]['place_id'];
    _grev_cfg_set('google_place_id', $pid);
    return $pid;
}

/**
 * Chama a Places API, normaliza e grava o cache. Retorna o array ou null.
 */
function google_reviews_refresh($forcarQuery = null) {
    $apiKey = _grev_cfg('google_places_api_key');
    if ($apiKey === '') return null;

    $placeId = google_reviews_place_id($apiKey, $forcarQuery);
    if ($placeId === '') return null;

    $url = 'https://maps.googleapis.com/maps/api/place/details/json'
         . '?place_id=' . rawurlencode($placeId)
         . '&fields=rating,user_ratings_total,url,reviews'
         . '&reviews_sort=newest&language=pt-BR&key=' . rawurlencode($apiKey);
    $r = _grev_http($url);
    if (!$r || ($r['status'] ?? '') !== 'OK' || empty($r['result'])) return null;

    $res = $r['result'];
    $reviews = array();
    foreach (($res['reviews'] ?? array()) as $rv) {
        $txt = trim($rv['text'] ?? '');
        if ($txt === '') continue;
        $reviews[] = array(
            'author'   => $rv['author_name'] ?? 'Cliente',
            'rating'   => (int)($rv['rating'] ?? 5),
            'text'     => $txt,
            'relative' => $rv['relative_time_description'] ?? '',
            'photo'    => $rv['profile_photo_url'] ?? '',
        );
    }
    $dados = array(
        'ok'         => !empty($reviews),
        'rating'     => isset($res['rating']) ? (float)$res['rating'] : null,
        'total'      => isset($res['user_ratings_total']) ? (int)$res['user_ratings_total'] : null,
        'url'        => $res['url'] ?? '',
        'reviews'    => $reviews,
        'fetched_at' => time(),
    );

    $dir = dirname(GREV_CACHE_FILE);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @file_put_contents(GREV_CACHE_FILE, json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $dados;
}

/**
 * Lê o cache; se faltando/velho, tenta 1 refresh (com lock anti-stampede).
 * Sempre devolve algo seguro: dados frescos, cache velho, ou ['ok'=>false].
 */
function google_reviews_get() {
    $cache = null;
    if (is_file(GREV_CACHE_FILE)) {
        $cache = json_decode(@file_get_contents(GREV_CACHE_FILE), true);
    }
    $velho = !$cache || (time() - (int)($cache['fetched_at'] ?? 0)) > GREV_TTL;

    if ($velho) {
        $lock = GREV_CACHE_FILE . '.lock';
        $podeRefrescar = !is_file($lock) || (time() - @filemtime($lock)) > 120;
        if ($podeRefrescar) {
            @touch($lock);
            $novo = google_reviews_refresh();
            @unlink($lock);
            if ($novo) return $novo;
        }
    }
    return is_array($cache) ? $cache : array('ok' => false);
}
