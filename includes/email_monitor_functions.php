<?php
/**
 * includes/email_monitor_functions.php
 *
 * Funções compartilhadas pelo Email Monitor:
 *   - email_monitor_cron.php   → cron de leitura de emails do PJe
 *   - modules/operacional/caso_novo.php → import automático de andamentos
 *     pendentes ao cadastrar um caso novo a partir da aba Pendentes do
 *     Email Monitor
 *
 * O arquivo NÃO estabelece sessão nem usa middleware. Apenas IMAP + PDO.
 *
 * Constantes esperadas no escopo (definidas aqui caso quem inclua não tenha):
 *   IMAP_HOST, IMAP_PORT, IMAP_USER_FETCH, IMAP_APP_PASS, IMAP_FROM_FILTER
 */

if (!defined('IMAP_HOST'))         define('IMAP_HOST',         'imap.gmail.com');
if (!defined('IMAP_PORT'))         define('IMAP_PORT',         993);
if (!defined('IMAP_USER_FETCH'))   define('IMAP_USER_FETCH',   'andamentosfes@gmail.com');
if (!defined('IMAP_APP_PASS'))     define('IMAP_APP_PASS',     'lbzwljxafdqkhfdp');
if (!defined('IMAP_FROM_FILTER'))  define('IMAP_FROM_FILTER',  'tjrj.pjeadm-LD@tjrj.jus.br');

// ════════════════════════════════════════════════════════════
// API pública
// ════════════════════════════════════════════════════════════

/**
 * Abre conexão IMAP com a caixa do PJe.
 * /novalidate-cert é necessário porque o servidor da TurboCloud não envia SNI
 * no handshake — sem isso a validação de certificado do Gmail falha.
 *
 * @return resource|false Resource IMAP em caso de sucesso, false se a extensão
 *                        php-imap não estiver disponível ou a conexão falhar.
 */
function email_monitor_conectar_imap() {
    if (!function_exists('imap_open')) {
        return false;
    }
    $mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX';
    $mbox = @imap_open($mailbox, IMAP_USER_FETCH, IMAP_APP_PASS, 0, 1);
    return $mbox ? $mbox : false;
}

/**
 * Parseia 1 email (UID) e devolve estrutura padronizada.
 *
 * @return array{
 *   cnj: ?string,
 *   polo_ativo: string,
 *   polo_passivo: string,
 *   orgao: string,
 *   movimentos: array<int, array{data: string, hora: string, descricao: string}>
 * }
 */
function email_monitor_parsear_email($mbox, $uid) {
    $body = email_monitor_extract_body($mbox, $uid);
    return email_monitor_parse((string)$body);
}

/**
 * Insere 1 movimento em case_andamentos com deduplicação por hash MD5.
 * Hash = md5(case_id|data|hora|descricao) gravado em datajud_movimento_id.
 *
 * Não levanta exceção em caso de duplicata ou erro — devolve false.
 *
 * @return bool true = inserido novo registro, false = duplicado, dado inválido ou erro
 */
function email_monitor_inserir_andamento($pdo, $caseId, $movimento, $segredo, $createdBy = 0) {
    $caseId    = (int)$caseId;
    $segredo   = (int)$segredo;
    $createdBy = (int)$createdBy;
    if ($caseId <= 0) return false;
    if (empty($movimento['data']) || empty($movimento['hora']) || empty($movimento['descricao'])) {
        return false;
    }

    $hash = md5($caseId . '|' . $movimento['data'] . '|' . $movimento['hora'] . '|' . $movimento['descricao']);

    try {
        $stmtChk = $pdo->prepare("SELECT id FROM case_andamentos WHERE datajud_movimento_id = ? LIMIT 1");
        $stmtChk->execute(array($hash));
        $rows = $stmtChk->fetchAll();
        $stmtChk->closeCursor();
        if (!empty($rows)) return false; // já inserido — dedup

        $stmtIns = $pdo->prepare(
            "INSERT INTO case_andamentos
                (case_id, data_andamento, hora_andamento, tipo, descricao,
                 created_by, created_at, visivel_cliente, segredo_justica,
                 tipo_origem, datajud_movimento_id)
             VALUES
                (?, ?, ?, 'movimentacao', ?,
                 ?, NOW(), 0, ?,
                 'email_pje', ?)"
        );
        $stmtIns->execute(array(
            $caseId,
            $movimento['data'],
            $movimento['hora'],
            $movimento['descricao'],
            $createdBy,
            $segredo,
            $hash,
        ));
        $stmtIns->closeCursor();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

// ════════════════════════════════════════════════════════════
// Helpers internos de parsing (também usados pelo cron diretamente)
// ════════════════════════════════════════════════════════════

/**
 * Extrai o corpo do email em texto plano, decodificando MIME e ajustando
 * encoding pra UTF-8 (com fallback ISO-8859-1).
 */
function email_monitor_extract_body($mbox, $uid) {
    $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
    if (!$structure) return '';

    $body = '';
    if (!empty($structure->parts) && is_array($structure->parts)) {
        $body = email_monitor_find_text_part($mbox, $uid, $structure->parts, '');
    }
    if ($body === '') {
        $body = (string)@imap_body($mbox, $uid, FT_UID);
        $body = email_monitor_decode_part($body, isset($structure->encoding) ? $structure->encoding : 0);
    }
    if ($body !== '' && !mb_check_encoding($body, 'UTF-8')) {
        $convertido = @mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1, UTF-8, ASCII');
        if ($convertido !== false) $body = $convertido;
    }
    return (string)$body;
}

function email_monitor_find_text_part($mbox, $uid, $parts, $prefix) {
    // Primeiro tenta achar text/plain
    foreach ($parts as $i => $part) {
        $sectionId = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);
        $type    = isset($part->type) ? (int)$part->type : 0;
        $subtype = isset($part->subtype) ? strtolower($part->subtype) : '';
        if ($type === 0 && $subtype === 'plain') {
            $raw = (string)@imap_fetchbody($mbox, $uid, $sectionId, FT_UID);
            return email_monitor_decode_part($raw, isset($part->encoding) ? $part->encoding : 0);
        }
        if (!empty($part->parts) && is_array($part->parts)) {
            $sub = email_monitor_find_text_part($mbox, $uid, $part->parts, $sectionId);
            if ($sub !== '') return $sub;
        }
    }
    // Se não achou, tenta text/html convertido pra texto
    foreach ($parts as $i => $part) {
        $sectionId = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);
        $type    = isset($part->type) ? (int)$part->type : 0;
        $subtype = isset($part->subtype) ? strtolower($part->subtype) : '';
        if ($type === 0 && $subtype === 'html') {
            $raw  = (string)@imap_fetchbody($mbox, $uid, $sectionId, FT_UID);
            $html = email_monitor_decode_part($raw, isset($part->encoding) ? $part->encoding : 0);
            $txt  = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", (string)$html));
            return html_entity_decode($txt, ENT_QUOTES, 'UTF-8');
        }
    }
    return '';
}

function email_monitor_decode_part($raw, $encoding) {
    switch ((int)$encoding) {
        case 3: return base64_decode($raw);
        case 4: return quoted_printable_decode($raw);
        default: return $raw;
    }
}

/**
 * Parser do corpo do email PJe. Extrai CNJ, polos, órgão e movimentos.
 */
function email_monitor_parse($body) {
    $result = array(
        'cnj'          => null,
        'polo_ativo'   => '',
        'polo_passivo' => '',
        'orgao'        => '',
        'movimentos'   => array(),
    );

    if (preg_match('/N[uú]mero\s+do\s+Processo:\s*([\d\.\-]+)/iu', $body, $m)) {
        $result['cnj'] = trim($m[1]);
    }
    if (!$result['cnj']) {
        if (preg_match('/(\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4})/', $body, $m)) {
            $result['cnj'] = $m[1];
        }
    }
    if (preg_match('/Polo\s+Ativo:\s*(.+)/iu', $body, $m))   $result['polo_ativo']   = trim($m[1]);
    if (preg_match('/Polo\s+Passivo:\s*(.+)/iu', $body, $m)) $result['polo_passivo'] = trim($m[1]);
    if (preg_match('/[OÓ]rg[aã]o:\s*(.+)/iu', $body, $m))    $result['orgao']        = trim($m[1]);

    if (preg_match_all('/(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})\s*-\s*(.+)/u', $body, $matches, PREG_SET_ORDER)) {
        $vistos = array();
        foreach ($matches as $m) {
            $data = $m[3] . '-' . $m[2] . '-' . $m[1];
            $hora = $m[4] . ':' . $m[5] . ':00';
            $desc = trim($m[6]);
            if ($desc === '' || stripos($desc, 'Movimento') === 0) continue;
            $desc = preg_replace('/\s+/u', ' ', $desc);
            $key  = $data . '|' . $hora . '|' . $desc;
            if (isset($vistos[$key])) continue;
            $vistos[$key] = true;
            $result['movimentos'][] = array(
                'data'      => $data,
                'hora'      => $hora,
                'descricao' => $desc,
            );
        }
    }
    return $result;
}
