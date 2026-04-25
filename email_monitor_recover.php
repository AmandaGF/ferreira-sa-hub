<?php
/**
 * email_monitor_recover.php — script ONE-SHOT pra recuperar histórico
 * de emails do PJe que chegaram ANTES da tabela `email_monitor_pendentes`
 * existir.
 *
 * O cron normal (`email_monitor_cron.php`) só processa UNSEEN. Tudo que já
 * estava lido no Gmail no momento do deploy ficou "perdido" — emails com
 * CNJ não cadastrado foram simplesmente ignorados sem deixar rastro.
 *
 * Este script reprocessa TODOS os emails do PJe (lidos + não lidos), sem
 * mexer no estado read/unread, e popula `email_monitor_pendentes` com os
 * CNJs que NÃO existem em `cases` nem ainda em `email_monitor_pendentes`.
 *
 * É IDEMPOTENTE — pode rodar quantas vezes quiser:
 *   - estado salvo em `configuracoes.email_monitor_recover_last_uid`
 *     (cada execução pega de onde a anterior parou)
 *   - INSERT só se CNJ ainda não existe em pendentes nem em cases
 *
 * Uso:
 *   - HTTP: GET ?key=fsa-hub-deploy-2026         (batch padrão 30, limite 25s)
 *   - CLI:  php email_monitor_recover.php         (batch padrão 200, limite 300s)
 *   - Override:  ?limite=N (1..1000)
 *   - Reset estado: ?reset=1  (zera last_uid pra reprocessar tudo)
 *
 * Quando todos os emails forem processados, este arquivo pode ser DELETADO.
 *
 * Auth e padrões idênticos ao email_monitor_cron.php.
 */

define('EMAIL_MONITOR_KEY',  'fsa-hub-deploy-2026');

define('IMAP_HOST',          'imap.gmail.com');
define('IMAP_PORT',          993);
define('IMAP_USER_FETCH',    'andamentosfes@gmail.com');
define('IMAP_APP_PASS',      'lbzwljxafdqkhfdp');
define('IMAP_FROM_FILTER',   'tjrj.pjeadm-LD@tjrj.jus.br');
define('IMAP_BLOCK_DOMAIN',  'brevosend.com');

define('LOCK_FILE',          sys_get_temp_dir() . '/email_monitor_recover.lock');
define('STATE_KEY',          'email_monitor_recover_last_uid');

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $key = isset($_GET['key']) ? $_GET['key'] : (isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '');
    if ($key !== EMAIL_MONITOR_KEY) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Acesso negado.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

@set_time_limit($isCli ? 300 : 25);
@ignore_user_abort(true);

$processados   = 0;
$jaEmCases     = 0;
$jaEmPend      = 0;
$semCnj        = 0;
$novos         = 0;
$erros         = 0;
$pdo           = null;
$lockHandle    = null;

register_shutdown_function(function() use (&$lockHandle) {
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
    @unlink(LOCK_FILE);
});

$lockHandle = @fopen(LOCK_FILE, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[lock] Outra execução em andamento, abortando.\n";
    exit;
}

require_once __DIR__ . '/core/config.php';
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        array(
            PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES     => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        )
    );
} catch (Exception $e) {
    echo "[db] Falha ao conectar: " . $e->getMessage() . "\n";
    exit;
}

// Self-heal das tabelas dependentes (idempotente)
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS email_monitor_pendentes (
        id int unsigned NOT NULL AUTO_INCREMENT,
        case_number varchar(30) NOT NULL,
        polo_ativo text,
        polo_passivo text,
        orgao varchar(200),
        ultimo_movimento_data date,
        ultimo_movimento_desc text,
        total_emails_recebidos int DEFAULT 1,
        status enum('pendente','descartado','cadastrado') DEFAULT 'pendente',
        primeira_vez datetime NOT NULL,
        ultima_vez datetime NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_case_number (case_number)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Reset opcional do cursor (?reset=1) — reprocessa tudo do zero
if (!$isCli && isset($_GET['reset']) && $_GET['reset'] === '1') {
    $stmtRes = $pdo->prepare("DELETE FROM configuracoes WHERE chave = ?");
    $stmtRes->execute(array(STATE_KEY));
    $stmtRes->closeCursor();
    echo "[reset] cursor zerado — próxima execução começa do UID 0.\n";
}

// Lê estado salvo
$stmtState = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ? LIMIT 1");
$stmtState->execute(array(STATE_KEY));
$rowsState = $stmtState->fetchAll();
$stmtState->closeCursor();
$lastUid = !empty($rowsState) ? (int)$rowsState[0]['valor'] : 0;

if (!function_exists('imap_open')) {
    echo "[imap] Extensão IMAP não disponível.\n";
    exit;
}

$mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX';
$mbox = @imap_open($mailbox, IMAP_USER_FETCH, IMAP_APP_PASS, 0, 1);
if (!$mbox) {
    echo "[imap] Falha conexão: " . imap_last_error() . "\n";
    exit;
}

// Busca TODOS os emails do PJe (sem UNSEEN) — incluindo já lidos
$emails = imap_search($mbox, 'FROM "' . IMAP_FROM_FILTER . '"', SE_UID);
if (!is_array($emails)) $emails = array();

// Ordena UIDs ascending — assim podemos avançar o cursor de forma monótona
sort($emails, SORT_NUMERIC);

// Filtra: só UIDs > lastUid (continua de onde parou)
$pendentesUid = array();
foreach ($emails as $uid) {
    if ((int)$uid > $lastUid) $pendentesUid[] = (int)$uid;
}

$totalRestante = count($pendentesUid);
$defaultLimite = $isCli ? 200 : 30;
$limite        = isset($_GET['limite']) ? max(1, min(1000, (int)$_GET['limite'])) : $defaultLimite;

if ($totalRestante > $limite) {
    $pendentesUid = array_slice($pendentesUid, 0, $limite);
}

echo "[recover] Total na caixa: " . count($emails) . " · Restantes pra processar: {$totalRestante} · Esta execução: " . count($pendentesUid) . "\n";
echo "[recover] last_processed_uid (antes): {$lastUid}\n";

// Prepares
$stmtChkCase  = $pdo->prepare("SELECT id FROM cases WHERE case_number = ? LIMIT 1");
$stmtChkPend  = $pdo->prepare("SELECT id FROM email_monitor_pendentes WHERE case_number = ? LIMIT 1");
$stmtInsPend  = $pdo->prepare(
    "INSERT INTO email_monitor_pendentes
        (case_number, polo_ativo, polo_passivo, orgao, ultimo_movimento_data, ultimo_movimento_desc, total_emails_recebidos, status, primeira_vez, ultima_vez)
     VALUES (?, ?, ?, ?, ?, ?, 1, 'pendente', NOW(), NOW())"
);
$stmtUpdState = $pdo->prepare(
    "INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE valor = ?"
);

$ultimoUidProc = $lastUid;

foreach ($pendentesUid as $uid) {
    $processados++;

    try {
        // Filtro defesa: ignora brevosend.com
        $msgno   = imap_msgno($mbox, $uid);
        $headers = $msgno ? imap_headerinfo($mbox, $msgno) : null;
        $fromAddr = '';
        if ($headers && isset($headers->from[0])) {
            $fromAddr = strtolower($headers->from[0]->mailbox . '@' . $headers->from[0]->host);
        }
        if ($fromAddr && stripos($fromAddr, IMAP_BLOCK_DOMAIN) !== false) {
            $ultimoUidProc = max($ultimoUidProc, $uid);
            continue;
        }

        $body = email_monitor_recover_extract_body($mbox, $uid);
        if (!$body) {
            $ultimoUidProc = max($ultimoUidProc, $uid);
            continue;
        }

        $parsed = email_monitor_recover_parse($body);
        if (!$parsed['cnj']) {
            $semCnj++;
            $ultimoUidProc = max($ultimoUidProc, $uid);
            continue;
        }

        // CNJ existe em cases? → cron normal já tratou — pula
        $stmtChkCase->execute(array($parsed['cnj']));
        $rowsCase = $stmtChkCase->fetchAll();
        $stmtChkCase->closeCursor();
        if (!empty($rowsCase)) {
            $jaEmCases++;
            $ultimoUidProc = max($ultimoUidProc, $uid);
            continue;
        }

        // CNJ já em pendentes? → recuperação anterior ou cron já criou — pula
        $stmtChkPend->execute(array($parsed['cnj']));
        $rowsPend = $stmtChkPend->fetchAll();
        $stmtChkPend->closeCursor();
        if (!empty($rowsPend)) {
            $jaEmPend++;
            $ultimoUidProc = max($ultimoUidProc, $uid);
            continue;
        }

        // Movimento mais recente
        $ultMovData = null;
        $ultMovDesc = null;
        if (!empty($parsed['movimentos'])) {
            $ultimo = $parsed['movimentos'][0];
            foreach ($parsed['movimentos'] as $movX) {
                if (strcmp($movX['data'] . ' ' . $movX['hora'], $ultimo['data'] . ' ' . $ultimo['hora']) > 0) {
                    $ultimo = $movX;
                }
            }
            $ultMovData = $ultimo['data'];
            $ultMovDesc = $ultimo['descricao'];
        }

        try {
            $stmtInsPend->execute(array(
                $parsed['cnj'],
                $parsed['polo_ativo']   !== '' ? $parsed['polo_ativo']   : null,
                $parsed['polo_passivo'] !== '' ? $parsed['polo_passivo'] : null,
                $parsed['orgao']        !== '' ? $parsed['orgao']        : null,
                $ultMovData,
                $ultMovDesc,
            ));
            $stmtInsPend->closeCursor();
            $novos++;
        } catch (Throwable $e) {
            @$stmtInsPend->closeCursor();
            $erros++;
        }

        $ultimoUidProc = max($ultimoUidProc, $uid);

    } catch (Throwable $e) {
        $erros++;
        $ultimoUidProc = max($ultimoUidProc, $uid);
        continue;
    }
}

// Atualiza cursor
if ($ultimoUidProc > $lastUid) {
    try {
        $stmtUpdState->execute(array(STATE_KEY, (string)$ultimoUidProc, (string)$ultimoUidProc));
        $stmtUpdState->closeCursor();
    } catch (Throwable $e) {
        echo "[state] Erro ao salvar cursor: " . $e->getMessage() . "\n";
    }
}

@imap_close($mbox);

$restantesAposEsta = max(0, $totalRestante - count($pendentesUid));
echo "\n[done] processados={$processados} novos={$novos} ja_em_cases={$jaEmCases} ja_em_pend={$jaEmPend} sem_cnj={$semCnj} erros={$erros}\n";
echo "[state] last_processed_uid (depois): {$ultimoUidProc}\n";
echo "[state] emails AINDA restantes pra processar: {$restantesAposEsta}\n";
if ($restantesAposEsta > 0) {
    $sugClicks = (int)ceil($restantesAposEsta / max(1, $limite));
    echo "[hint]  Falta(m) ~{$sugClicks} execução(ões) no batch atual de {$limite} pra terminar.\n";
} else {
    echo "[hint]  Recuperação COMPLETA — todos os emails históricos foram analisados.\n";
}

// ────────────────────────────────────────────────────────────
// Funções auxiliares — idênticas ao email_monitor_cron.php
// ────────────────────────────────────────────────────────────

function email_monitor_recover_extract_body($mbox, $uid) {
    $structure = @imap_fetchstructure($mbox, $uid, FT_UID);
    if (!$structure) return '';

    $body = '';
    if (!empty($structure->parts) && is_array($structure->parts)) {
        $body = email_monitor_recover_find_text_part($mbox, $uid, $structure->parts, '');
    }
    if ($body === '') {
        $body = (string)@imap_body($mbox, $uid, FT_UID);
        $body = email_monitor_recover_decode_part($body, isset($structure->encoding) ? $structure->encoding : 0);
    }
    if ($body !== '' && !mb_check_encoding($body, 'UTF-8')) {
        $convertido = @mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1, UTF-8, ASCII');
        if ($convertido !== false) $body = $convertido;
    }
    return (string)$body;
}

function email_monitor_recover_find_text_part($mbox, $uid, $parts, $prefix) {
    foreach ($parts as $i => $part) {
        $sectionId = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);
        $type    = isset($part->type) ? (int)$part->type : 0;
        $subtype = isset($part->subtype) ? strtolower($part->subtype) : '';
        if ($type === 0 && $subtype === 'plain') {
            $raw = (string)@imap_fetchbody($mbox, $uid, $sectionId, FT_UID);
            return email_monitor_recover_decode_part($raw, isset($part->encoding) ? $part->encoding : 0);
        }
        if (!empty($part->parts) && is_array($part->parts)) {
            $sub = email_monitor_recover_find_text_part($mbox, $uid, $part->parts, $sectionId);
            if ($sub !== '') return $sub;
        }
    }
    foreach ($parts as $i => $part) {
        $sectionId = ($prefix === '') ? (string)($i + 1) : $prefix . '.' . ($i + 1);
        $type    = isset($part->type) ? (int)$part->type : 0;
        $subtype = isset($part->subtype) ? strtolower($part->subtype) : '';
        if ($type === 0 && $subtype === 'html') {
            $raw  = (string)@imap_fetchbody($mbox, $uid, $sectionId, FT_UID);
            $html = email_monitor_recover_decode_part($raw, isset($part->encoding) ? $part->encoding : 0);
            $txt  = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", (string)$html));
            return html_entity_decode($txt, ENT_QUOTES, 'UTF-8');
        }
    }
    return '';
}

function email_monitor_recover_decode_part($raw, $encoding) {
    switch ((int)$encoding) {
        case 3: return base64_decode($raw);
        case 4: return quoted_printable_decode($raw);
        default: return $raw;
    }
}

function email_monitor_recover_parse($body) {
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
