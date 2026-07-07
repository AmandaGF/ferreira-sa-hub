<?php
/**
 * Shortlinks: encurta URLs em msgs WhatsApp e rastreia quando o cliente clica.
 * Amanda 07/07/2026.
 */

if (!function_exists('_sl_shortlinks_ativo')) {
    function _sl_shortlinks_ativo() {
        static $c = null;
        if ($c === null) {
            try {
                $v = (string)db()->query("SELECT valor FROM configuracoes WHERE chave='shortlinks_ativo'")->fetchColumn();
                $c = ($v === '1');
            } catch (Exception $e) { $c = false; }
        }
        return $c;
    }
}

if (!function_exists('_sl_gerar_codigo')) {
    /**
     * Gera código curto único (6 chars alfanuméricos = 62^6 = ~57bi combinações).
     * Retry até 5x em caso de colisão.
     */
    function _sl_gerar_codigo($pdo) {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'; // sem I/l/O/0/1 (ambíguos)
        $n = strlen($chars);
        $st = $pdo->prepare("SELECT 1 FROM short_links WHERE codigo = ? LIMIT 1");
        for ($try = 0; $try < 5; $try++) {
            $len = ($try < 3) ? 6 : 8; // se colidir 3x, aumenta o tamanho
            $codigo = '';
            for ($i = 0; $i < $len; $i++) $codigo .= $chars[random_int(0, $n - 1)];
            $st->execute(array($codigo));
            if (!$st->fetchColumn()) return $codigo;
        }
        // Fallback: timestamp + random (nunca deveria acontecer)
        return substr(bin2hex(random_bytes(6)), 0, 12);
    }
}

if (!function_exists('sl_criar_short_link')) {
    /**
     * Cria um shortlink e retorna a URL curta pública.
     * $ctx: array opcional com conversa_id, mensagem_id, client_id, lead_id, case_id, canal, criado_por.
     */
    function sl_criar_short_link($urlOriginal, $ctx = array()) {
        if (!_sl_shortlinks_ativo()) return $urlOriginal;
        $urlOriginal = trim((string)$urlOriginal);
        if ($urlOriginal === '') return $urlOriginal;
        try {
            $pdo = db();
            $codigo = _sl_gerar_codigo($pdo);
            $pdo->prepare(
                "INSERT INTO short_links
                    (codigo, url_original, conversa_id, mensagem_id, client_id, lead_id, case_id, canal, criado_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            )->execute(array(
                $codigo, $urlOriginal,
                $ctx['conversa_id'] ?? null,
                $ctx['mensagem_id'] ?? null,
                $ctx['client_id']   ?? null,
                $ctx['lead_id']     ?? null,
                $ctx['case_id']     ?? null,
                $ctx['canal']       ?? null,
                $ctx['criado_por']  ?? null,
            ));
            return 'https://ferreiraesa.com.br/conecta/l/' . $codigo;
        } catch (Exception $e) {
            // Se algo der errado, retorna URL original (não trava o envio)
            @error_log('[sl_criar_short_link] ' . $e->getMessage());
            return $urlOriginal;
        }
    }
}

if (!function_exists('sl_encurtar_urls_no_texto')) {
    /**
     * Detecta URLs num texto e substitui por shortlinks.
     * NÃO encurta URLs internas do próprio Hub (já são nossas) — só terceiros
     * e URLs públicas do escritório que fazem sentido rastrear (site, sala VIP, etc).
     *
     * Regra: encurta http/https, EXCETO se aponta pra ferreiraesa.com.br/conecta/
     * (evita loop de encurtar shortlink) mas ENCURTA ferreiraesa.com.br/lp,
     * ferreiraesa.com.br sozinho, e qualquer outra URL externa.
     */
    function sl_encurtar_urls_no_texto($texto, $ctx = array()) {
        if (!_sl_shortlinks_ativo() || !is_string($texto) || $texto === '') return $texto;
        // Regex de URL. Não perfeita, mas cobre casos práticos.
        $regex = '~\bhttps?://[^\s<>"\']+~';
        return preg_replace_callback($regex, function($m) use ($ctx) {
            $url = $m[0];
            // NÃO encurta URLs de ambiente interno (backend/hub logado) —
            // seria loop e cliente nem consegue clicar.
            if (preg_match('~^https?://ferreiraesa\.com\.br/conecta(/|$)~i', $url)) {
                // Exceção: link de treinamento é ÚTIL rastrear (colaborador clicou?)
                if (preg_match('~/conecta/modules/treinamento/~i', $url)) {
                    return sl_criar_short_link($url, $ctx);
                }
                return $url; // outros links do Hub logado, não encurta
            }
            return sl_criar_short_link($url, $ctx);
        }, $texto);
    }
}

if (!function_exists('sl_registrar_clique')) {
    /**
     * Registra clique num shortlink e retorna a URL original (pra redirect).
     * Retorna null se o código não existir (chamador deve 404).
     */
    function sl_registrar_clique($codigo) {
        try {
            $pdo = db();
            $st = $pdo->prepare("SELECT id, url_original FROM short_links WHERE codigo = ? LIMIT 1");
            $st->execute(array($codigo));
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;

            $linkId = (int)$row['id'];
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;

            // Insere clique
            $pdo->prepare("INSERT INTO link_clicks (link_id, ip, user_agent) VALUES (?, ?, ?)")
                ->execute(array($linkId, $ip, $ua));

            // Atualiza agregado no short_links
            $pdo->prepare("UPDATE short_links SET cliques_total = cliques_total + 1, ultimo_clique_em = NOW() WHERE id = ?")
                ->execute(array($linkId));

            return $row['url_original'];
        } catch (Exception $e) {
            @error_log('[sl_registrar_clique] ' . $e->getMessage());
            return null;
        }
    }
}
