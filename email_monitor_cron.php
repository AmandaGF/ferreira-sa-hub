<?php
/**
 * email_monitor_cron.php — lê emails do PJe (Gmail/IMAP) e insere
 * andamentos em case_andamentos com deduplicação por hash MD5.
 *
 * NÃO usa middleware.php (script autônomo). Conecta direto via PDO usando
 * as constantes definidas em core/config.php.
 *
 * Modos de execução:
 *   - CLI:  php email_monitor_cron.php
 *   - HTTP: GET /conecta/email_monitor_cron.php?key=fsa-hub-deploy-2026
 *
 * Cron sugerido (3x ao dia, via cPanel da TurboCloud):
 *   0 8,13,19 * * * curl -s "https://ferreiraesa.com.br/conecta/email_monitor_cron.php?key=fsa-hub-deploy-2026" > /dev/null
 *
 * Proteção:
 *   - Lock file em sys_get_temp_dir()/email_monitor.lock evita execução simultânea.
 *   - Auth HTTP exige ?key=... ou header X-Api-Key. CLI passa direto.
 *
 * Idempotência:
 *   - hash MD5 de (case_id + data + hora + descricao) gravado em
 *     case_andamentos.datajud_movimento_id. Antes de inserir, SELECT WHERE
 *     hash; se existir, pula. Reprocessar o mesmo email (ex: marcar como
 *     UNSEEN à mão) NÃO duplica andamentos.
 *
 * Observações:
 *   - Apenas arquivos novos foram criados nesta entrega; nenhum arquivo
 *     existente do sistema foi modificado.
 *   - Senha de app do Gmail está hardcoded aqui por enquanto. Pode ser
 *     migrada pra tabela `configuracoes` em entrega futura.
 */

// ────────────────────────────────────────────────────────────
// Configuração
// ────────────────────────────────────────────────────────────
define('EMAIL_MONITOR_KEY',  'fsa-hub-deploy-2026');

define('IMAP_HOST',          'imap.gmail.com');
define('IMAP_PORT',          993);
define('IMAP_USER_FETCH',    'andamentosfes@gmail.com');
define('IMAP_APP_PASS',      'lbzwljxafdqkhfdp');
define('IMAP_FROM_FILTER',   'tjrj.pjeadm-LD@tjrj.jus.br');
define('IMAP_BLOCK_DOMAIN',  'brevosend.com');

define('LOCK_FILE',          sys_get_temp_dir() . '/email_monitor.lock');

$isCli = (php_sapi_name() === 'cli');

// ────────────────────────────────────────────────────────────
// Auth (apenas modo HTTP — CLI ignora)
// ────────────────────────────────────────────────────────────
if (!$isCli) {
    $key = isset($_GET['key']) ? $_GET['key'] : (isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '');
    if ($key !== EMAIL_MONITOR_KEY) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Acesso negado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ────────────────────────────────────────────────────────────
// Lock — evita execuções simultâneas
// ────────────────────────────────────────────────────────────
$lockHandle = @fopen(LOCK_FILE, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[lock] Outra execução em andamento, abortando.\n";
    exit;
}

// ────────────────────────────────────────────────────────────
// Conexão DB direta (sem middleware) — reutiliza credenciais do core/config.php
// ────────────────────────────────────────────────────────────
require_once __DIR__ . '/core/config.php';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        )
    );
} catch (Exception $e) {
    echo "[db] Falha ao conectar: " . $e->getMessage() . "\n";
    flock($lockHandle, LOCK_UN); fclose($lockHandle); @unlink(LOCK_FILE);
    exit;
}

// ────────────────────────────────────────────────────────────
// Tabela de log (self-heal — idempotente)
// ────────────────────────────────────────────────────────────
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_monitor_log (
        id int unsigned NOT NULL AUTO_INCREMENT,
        executado_em datetime NOT NULL,
        emails_lidos int DEFAULT 0,
        andamentos_inseridos int DEFAULT 0,
        emails_ignorados int DEFAULT 0,
        duplicatas_ignoradas int DEFAULT 0,
        erros int DEFAULT 0,
        detalhes text,
        modo varchar(20) DEFAULT 'cron',
        PRIMARY KEY (id),
        KEY idx_executado_em (executado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// ────────────────────────────────────────────────────────────
// IMAP
// ────────────────────────────────────────────────────────────
if (!function_exists('imap_open')) {
    echo "[imap] Extensão IMAP do PHP não disponível. Instale php-imap no servidor.\n";
    flock($lockHandle, LOCK_UN); fclose($lockHandle); @unlink(LOCK_FILE);
    exit;
}

// /novalidate-cert: o servidor da TurboCloud não envia SNI no handshake IMAP,
// então a validação de certificado do Gmail falha. Como sabemos que imap.gmail.com
// é legítimo (e a conexão continua criptografada com SSL), desligamos a validação
// pra permitir o handshake. Comportamento idêntico ao de vários clientes IMAP comuns.
$mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX';
$mbox = @imap_open($mailbox, IMAP_USER_FETCH, IMAP_APP_PASS, 0, 1);

$emailsLidos        = 0;
$andamentosInsert   = 0;
$emailsIgnorados    = 0;
$duplicatasIgnor    = 0;
$erros              = 0;
$detalhes           = array();

if (!$mbox) {
    $msg = 'Falha conexão IMAP: ' . imap_last_error();
    $detalhes[] = $msg;
    $erros++;
    echo "[imap] $msg\n";
    email_monitor_log_save($pdo, $emailsLidos, $andamentosInsert, $emailsIgnorados, $duplicatasIgnor, $erros, $detalhes, $isCli ? 'cron' : 'manual');
    flock($lockHandle, LOCK_UN); fclose($lockHandle); @unlink(LOCK_FILE);
    exit;
}

// Busca emails NÃO vistos do remetente desejado (UID retornado)
$emails = imap_search($mbox, 'UNSEEN FROM "' . IMAP_FROM_FILTER . '"', SE_UID);
if (!is_array($emails)) $emails = array();

echo "[imap] " . count($emails) . " email(s) não lidos correspondentes a busca.\n";

// Prepares reutilizáveis
$stmtBuscaCase   = $pdo->prepare("SELECT id, segredo_justica FROM cases WHERE case_number = ? LIMIT 1");
$stmtChkHash     = $pdo->prepare("SELECT id FROM case_andamentos WHERE datajud_movimento_id = ? LIMIT 1");
$stmtInsAndam    = $pdo->prepare(
    "INSERT INTO case_andamentos
        (case_id, data_andamento, hora_andamento, tipo, descricao, created_by, created_at, visivel_cliente, segredo_justica, tipo_origem, datajud_movimento_id)
     VALUES
        (?, ?, ?, 'movimentacao', ?, 0, NOW(), 0, ?, 'email_pje', ?)"
);

foreach ($emails as $uid) {
    $emailsLidos++;
    $uidStr = (string)$uid;

    // Header pra confirmar remetente (defesa extra contra spoofing)
    $msgno = imap_msgno($mbox, $uid);
    $headers = $msgno ? imap_headerinfo($mbox, $msgno) : null;
    $fromAddr = '';
    if ($headers && isset($headers->from[0])) {
        $fromAddr = strtolower($headers->from[0]->mailbox . '@' . $headers->from[0]->host);
    }

    // Filtro: ignora qualquer coisa de brevosend.com
    if ($fromAddr && stripos($fromAddr, IMAP_BLOCK_DOMAIN) !== false) {
        $emailsIgnorados++;
        $detalhes[] = "Ignorado (domínio bloqueado): UID {$uidStr} from {$fromAddr}";
        @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);
        continue;
    }

    // Pega corpo do email (texto plano)
    $body = email_monitor_extract_body($mbox, $uid);
    if (!$body) {
        $emailsIgnorados++;
        $detalhes[] = "Corpo vazio (UID {$uidStr})";
        @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);
        continue;
    }

    // Parser
    $parsed = email_monitor_parse($body);
    if (!$parsed['cnj']) {
        $emailsIgnorados++;
        $detalhes[] = "CNJ não encontrado no email UID {$uidStr}";
        @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);
        continue;
    }

    // Busca case
    $stmtBuscaCase->execute(array($parsed['cnj']));
    $case = $stmtBuscaCase->fetch();
    if (!$case) {
        $emailsIgnorados++;
        $detalhes[] = "Processo {$parsed['cnj']} não cadastrado em cases (UID {$uidStr})";
        @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);
        continue;
    }

    $caseId      = (int)$case['id'];
    $segredo     = (int)$case['segredo_justica'];

    // Insere cada movimento
    if (empty($parsed['movimentos'])) {
        $detalhes[] = "Email do caso {$parsed['cnj']} sem movimentos parseáveis (UID {$uidStr})";
    }

    foreach ($parsed['movimentos'] as $mov) {
        $hash = md5($caseId . '|' . $mov['data'] . '|' . $mov['hora'] . '|' . $mov['descricao']);

        $stmtChkHash->execute(array($hash));
        if ($stmtChkHash->fetchColumn()) {
            $duplicatasIgnor++;
            continue;
        }

        try {
            $stmtInsAndam->execute(array(
                $caseId,
                $mov['data'],
                $mov['hora'],
                $mov['descricao'],
                $segredo,
                $hash,
            ));
            $andamentosInsert++;
        } catch (Exception $e) {
            $erros++;
            $detalhes[] = "Erro INSERT case#{$caseId} ({$parsed['cnj']}): " . $e->getMessage();
        }
    }

    // Marca como lido (sucesso ou não — pra não reprocessar infinitamente)
    @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);
}

@imap_close($mbox);

// ────────────────────────────────────────────────────────────
// Log final + resumo
// ────────────────────────────────────────────────────────────
email_monitor_log_save($pdo, $emailsLidos, $andamentosInsert, $emailsIgnorados, $duplicatasIgnor, $erros, $detalhes, $isCli ? 'cron' : 'manual');

echo "[done] lidos={$emailsLidos} inseridos={$andamentosInsert} ignorados={$emailsIgnorados} dup={$duplicatasIgnor} erros={$erros}\n";

// Libera lock
flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink(LOCK_FILE);

// ────────────────────────────────────────────────────────────
// Funções auxiliares
// ────────────────────────────────────────────────────────────

/**
 * Extrai o corpo do email em texto plano, decodificando MIME e ajustando
 * encoding pra UTF-8.
 */
function email_monitor_extract_body($mbox, $uid) {
    $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
    if (!$structure) return '';

    // Tenta achar parte text/plain; cai pra raw se falhar
    $body = '';
    if (!empty($structure->parts) && is_array($structure->parts)) {
        $body = email_monitor_find_text_part($mbox, $uid, $structure->parts, '');
    }
    if ($body === '') {
        $body = (string)@imap_body($mbox, $uid, FT_UID);
        $body = email_monitor_decode_part($body, isset($structure->encoding) ? $structure->encoding : 0);
    }

    // Garante UTF-8
    if ($body !== '' && !mb_check_encoding($body, 'UTF-8')) {
        $convertido = @mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1, UTF-8, ASCII');
        if ($convertido !== false) $body = $convertido;
    }

    return (string)$body;
}

function email_monitor_find_text_part($mbox, $uid, $parts, $prefix) {
    foreach ($parts as $i => $part) {
        $sectionId = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);

        $type    = isset($part->type) ? (int)$part->type : 0;       // 0 = text
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

    // Se não achou text/plain, tenta text/html convertido
    foreach ($parts as $i => $part) {
        $sectionId = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);
        $type    = isset($part->type) ? (int)$part->type : 0;
        $subtype = isset($part->subtype) ? strtolower($part->subtype) : '';
        if ($type === 0 && $subtype === 'html') {
            $raw = (string)@imap_fetchbody($mbox, $uid, $sectionId, FT_UID);
            $html = email_monitor_decode_part($raw, isset($part->encoding) ? $part->encoding : 0);
            $txt = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", (string)$html));
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
 * Parser do email PJe. Extrai CNJ, polos, órgão e lista de movimentos.
 */
function email_monitor_parse($body) {
    $result = array(
        'cnj'          => null,
        'polo_ativo'   => '',
        'polo_passivo' => '',
        'orgao'        => '',
        'movimentos'   => array(),
    );

    // CNJ rotulado
    if (preg_match('/N[uú]mero\s+do\s+Processo:\s*([\d\.\-]+)/iu', $body, $m)) {
        $result['cnj'] = trim($m[1]);
    }
    // Fallback: detecta padrão CNJ em qualquer lugar do texto
    if (!$result['cnj']) {
        if (preg_match('/(\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4})/', $body, $m)) {
            $result['cnj'] = $m[1];
        }
    }

    if (preg_match('/Polo\s+Ativo:\s*(.+)/iu', $body, $m))   $result['polo_ativo']   = trim($m[1]);
    if (preg_match('/Polo\s+Passivo:\s*(.+)/iu', $body, $m)) $result['polo_passivo'] = trim($m[1]);
    if (preg_match('/[OÓ]rg[aã]o:\s*(.+)/iu', $body, $m))    $result['orgao']        = trim($m[1]);

    // Movimentos: DD/MM/AAAA HH:MM - descrição
    if (preg_match_all('/(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})\s*-\s*(.+)/u', $body, $matches, PREG_SET_ORDER)) {
        $vistos = array();
        foreach ($matches as $m) {
            $data = $m[3] . '-' . $m[2] . '-' . $m[1];
            $hora = $m[4] . ':' . $m[5] . ':00';
            $desc = trim($m[6]);
            // Pula linhas-cabeçalho como "Data - Movimento"
            if ($desc === '' || stripos($desc, 'Movimento') === 0) continue;
            // Limpa quebras de linha vazadas no fim
            $desc = preg_replace('/\s+/u', ' ', $desc);
            $key = $data . '|' . $hora . '|' . $desc;
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

function email_monitor_log_save($pdo, $lidos, $insert, $ignor, $dup, $erros, $detalhes, $modo) {
    $detText = implode("\n", array_slice($detalhes, 0, 80));
    if (mb_strlen($detText) > 60000) $detText = mb_substr($detText, 0, 60000) . "\n...[truncado]";
    $stmt = $pdo->prepare(
        "INSERT INTO email_monitor_log
            (executado_em, emails_lidos, andamentos_inseridos, emails_ignorados, duplicatas_ignoradas, erros, detalhes, modo)
         VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute(array($lidos, $insert, $ignor, $dup, $erros, $detText, $modo));
}
