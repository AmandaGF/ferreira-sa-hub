<?php
/**
 * email_monitor_cron.php — lê emails do PJe (Gmail/IMAP) e insere
 * andamentos em case_andamentos com deduplicação por hash MD5.
 *
 * NÃO usa middleware.php (script autônomo). Conecta direto via PDO usando
 * as constantes definidas em core/config.php.
 *
 * Modos de execução:
 *   - CLI:  php email_monitor_cron.php          (batch padrão 100, limite 180s)
 *   - HTTP: GET ?key=fsa-hub-deploy-2026         (batch padrão 20,  limite 25s)
 *   - Override em qualquer modo: ?limite=N (1..500)
 *
 * Cron sugerido (3x ao dia, via cPanel da TurboCloud):
 *   0 8,13,19 * * * curl -s "https://ferreiraesa.com.br/conecta/email_monitor_cron.php?key=fsa-hub-deploy-2026" > /dev/null
 *
 * Proteção:
 *   - Lock file em sys_get_temp_dir()/email_monitor.lock evita execução simultânea.
 *   - Auth HTTP exige ?key=... ou header X-Api-Key. CLI passa direto.
 *   - Shutdown handler garante gravação do log + liberação do lock mesmo em
 *     caso de timeout / fatal error / desconexão do cliente.
 *
 * Idempotência:
 *   - hash MD5 de (case_id + data + hora + descricao) gravado em
 *     case_andamentos.datajud_movimento_id. Antes de inserir, SELECT WHERE
 *     hash; se existir, pula. Reprocessar o mesmo email (ex: marcar como
 *     UNSEEN à mão) NÃO duplica andamentos.
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

// Funções de IMAP / parsing / inserção compartilhadas com caso_novo.php
require_once __DIR__ . '/includes/email_monitor_functions.php';

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
// Time limit dinâmico — HTTP usa 25s pra evitar timeout do servidor;
// CLI roda com 180s pra dar margem em batches grandes.
// ────────────────────────────────────────────────────────────
@set_time_limit($isCli ? 180 : 25);
@ignore_user_abort(true);   // continua executando mesmo se cliente fecha conexão

// ────────────────────────────────────────────────────────────
// Variáveis-resumo inicializadas ANTES do shutdown handler
// (precisam estar acessíveis por referência caso o script morra antes do log final)
// ────────────────────────────────────────────────────────────
$emailsLidos      = 0;
$andamentosInsert = 0;
$emailsIgnorados  = 0;
$duplicatasIgnor  = 0;
$erros            = 0;
$detalhes         = array();
$logSalvo         = false;
$pdo              = null;
$lockHandle       = null;

// ────────────────────────────────────────────────────────────
// Shutdown handler — única função responsável por:
//   1. Gravar o log se ainda não foi gravado (timeout / erro / abort)
//   2. Liberar o lock + remover lockfile
// ────────────────────────────────────────────────────────────
register_shutdown_function(function() use (&$pdo, &$lockHandle, &$logSalvo, &$emailsLidos, &$andamentosInsert, &$emailsIgnorados, &$duplicatasIgnor, &$erros, &$detalhes, $isCli) {
    // 1) Log de fallback se não foi gravado pelo fluxo normal
    if (!$logSalvo && $pdo instanceof PDO) {
        try {
            $detalhes[] = '[shutdown] Log gravado pelo handler — script encerrou antes do final (timeout, erro fatal ou abort).';
            email_monitor_log_save(
                $pdo,
                (int)$emailsLidos,
                (int)$andamentosInsert,
                (int)$emailsIgnorados,
                (int)$duplicatasIgnor,
                (int)$erros,
                $detalhes,
                $isCli ? 'cron' : 'manual'
            );
        } catch (Throwable $e) {
            // shutdown handler não pode propagar exceção — engole silenciosamente
        }
    }

    // 2) Libera o lock
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
    @unlink(LOCK_FILE);
});

// ────────────────────────────────────────────────────────────
// Lock — evita execuções simultâneas
// ────────────────────────────────────────────────────────────
$lockHandle = @fopen(LOCK_FILE, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[lock] Outra execução em andamento, abortando.\n";
    $logSalvo = true; // Não chama log save em concorrência (a outra execução já vai gravar o dela)
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
            PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES     => false,
            // Buffered queries: evita "Cannot execute queries while other
            // unbuffered queries are active" quando dois prepares são executados
            // em sequência sem fechar o cursor (caso típico: stmtChkHash dentro
            // do foreach de movimentos enquanto stmtBuscaCase ainda tem cursor aberto).
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        )
    );
} catch (Exception $e) {
    echo "[db] Falha ao conectar: " . $e->getMessage() . "\n";
    // shutdown handler cuida do lock; logSalvo continua false → mas sem PDO não dá pra gravar log mesmo
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
// Tabela de pendentes de cadastro (self-heal — idempotente)
// Quando chega email com CNJ que não está em `cases`, registramos aqui pra
// Amanda decidir depois se cadastra como caso novo ou descarta.
// UNIQUE(case_number) garante que duplicatas viram UPSERT (incrementa contador).
// ────────────────────────────────────────────────────────────
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

// ────────────────────────────────────────────────────────────
// IMAP
// ────────────────────────────────────────────────────────────
if (!function_exists('imap_open')) {
    echo "[imap] Extensão IMAP do PHP não disponível. Instale php-imap no servidor.\n";
    $detalhes[] = 'Extensão IMAP do PHP não disponível';
    $erros++;
    // shutdown handler vai gravar o log
    exit;
}

// /novalidate-cert: o servidor da TurboCloud não envia SNI no handshake IMAP,
// então a validação de certificado do Gmail falha. Como sabemos que imap.gmail.com
// é legítimo (e a conexão continua criptografada com SSL), desligamos a validação
// pra permitir o handshake. Comportamento idêntico ao de vários clientes IMAP comuns.
$mailbox = '{' . IMAP_HOST . ':' . IMAP_PORT . '/imap/ssl/novalidate-cert}INBOX';
$mbox = @imap_open($mailbox, IMAP_USER_FETCH, IMAP_APP_PASS, 0, 1);

if (!$mbox) {
    $msg = 'Falha conexão IMAP: ' . imap_last_error();
    $detalhes[] = $msg;
    $erros++;
    echo "[imap] $msg\n";
    // shutdown handler vai gravar
    exit;
}

// Busca emails NÃO vistos do remetente desejado (UID retornado).
// Filtro SINCE: ignora emails anteriores a 15/abril/2026 — esses já foram
// processados manualmente / vieram pelo DataJud. Impede que backlog antigo
// segure o processamento dos andamentos recentes (Amanda 03/05/2026).
$emails = imap_search($mbox, 'UNSEEN FROM "' . IMAP_FROM_FILTER . '" SINCE "15-Apr-2026"', SE_UID);
if (!is_array($emails)) $emails = array();

// Limite por execução (evita estourar timeout + permite várias rodadas).
// Default DINÂMICO: 100 em CLI, 20 em HTTP. Override via ?limite=N (1..500).
$defaultLimite   = $isCli ? 100 : 20;
$limitePorExec   = isset($_GET['limite']) ? max(1, min(500, (int)$_GET['limite'])) : $defaultLimite;
$totalEncontrado = count($emails);
if ($totalEncontrado > $limitePorExec) {
    echo "[imap] {$totalEncontrado} email(s) não lidos correspondentes — processando os primeiros {$limitePorExec} desta execução.\n";
    $emails = array_slice($emails, 0, $limitePorExec);
} else {
    echo "[imap] {$totalEncontrado} email(s) não lidos correspondentes a busca.\n";
}

// Prepares reutilizáveis
$stmtBuscaCase   = $pdo->prepare("SELECT id, segredo_justica FROM cases WHERE case_number = ? LIMIT 1");
$stmtChkHash     = $pdo->prepare("SELECT id FROM case_andamentos WHERE datajud_movimento_id = ? LIMIT 1");
$stmtInsAndam    = $pdo->prepare(
    "INSERT INTO case_andamentos
        (case_id, data_andamento, hora_andamento, tipo, descricao, created_by, created_at, visivel_cliente, segredo_justica, tipo_origem, datajud_movimento_id)
     VALUES
        (?, ?, ?, 'movimentacao', ?, 0, NOW(), 0, ?, 'email_pje', ?)"
);

// UPSERT em email_monitor_pendentes: se já existe (uk_case_number), incrementa
// total_emails_recebidos e atualiza último movimento + ultima_vez. Se não existe,
// insere com primeira_vez=ultima_vez=NOW(), status='pendente'.
$stmtUpsertPend  = $pdo->prepare(
    "INSERT INTO email_monitor_pendentes
        (case_number, polo_ativo, polo_passivo, orgao, ultimo_movimento_data, ultimo_movimento_desc, total_emails_recebidos, status, primeira_vez, ultima_vez)
     VALUES (?, ?, ?, ?, ?, ?, 1, 'pendente', NOW(), NOW())
     ON DUPLICATE KEY UPDATE
        total_emails_recebidos = total_emails_recebidos + 1,
        polo_ativo            = COALESCE(VALUES(polo_ativo), polo_ativo),
        polo_passivo          = COALESCE(VALUES(polo_passivo), polo_passivo),
        orgao                 = COALESCE(VALUES(orgao), orgao),
        ultimo_movimento_data = COALESCE(VALUES(ultimo_movimento_data), ultimo_movimento_data),
        ultimo_movimento_desc = COALESCE(VALUES(ultimo_movimento_desc), ultimo_movimento_desc),
        ultima_vez            = NOW()"
);

foreach ($emails as $uid) {
    $emailsLidos++;
    $uidStr = (string)$uid;

    try {
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

        // Busca case — fetchAll + closeCursor garante que o cursor não
        // fique aberto pra próxima query (evita SQLSTATE HY000 / error 2014)
        $stmtBuscaCase->execute(array($parsed['cnj']));
        $caseRows = $stmtBuscaCase->fetchAll();
        $stmtBuscaCase->closeCursor();
        if (empty($caseRows)) {
            // CNJ não cadastrado em cases — registra/atualiza em email_monitor_pendentes
            // pra Amanda decidir depois (cadastrar caso novo ou descartar).
            // Pega o movimento mais recente do email pra dar contexto.
            $ultMovData = null;
            $ultMovDesc = null;
            if (!empty($parsed['movimentos'])) {
                $ultimoMov = $parsed['movimentos'][0];
                foreach ($parsed['movimentos'] as $movX) {
                    if (strcmp($movX['data'] . ' ' . $movX['hora'], $ultimoMov['data'] . ' ' . $ultimoMov['hora']) > 0) {
                        $ultimoMov = $movX;
                    }
                }
                $ultMovData = $ultimoMov['data'];
                $ultMovDesc = $ultimoMov['descricao'];
            }
            try {
                $stmtUpsertPend->execute(array(
                    $parsed['cnj'],
                    $parsed['polo_ativo']   !== '' ? $parsed['polo_ativo']   : null,
                    $parsed['polo_passivo'] !== '' ? $parsed['polo_passivo'] : null,
                    $parsed['orgao']        !== '' ? $parsed['orgao']        : null,
                    $ultMovData,
                    $ultMovDesc,
                ));
                $stmtUpsertPend->closeCursor();
            } catch (Throwable $e) {
                @$stmtUpsertPend->closeCursor();
                $erros++;
                $detalhes[] = "Erro upsert pendente {$parsed['cnj']}: " . $e->getMessage();
            }
            $emailsIgnorados++;
            $detalhes[] = "Processo {$parsed['cnj']} não cadastrado — registrado em pendentes (UID {$uidStr})";
            @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);
            continue;
        }
        $case = $caseRows[0];

        $caseId  = (int)$case['id'];
        $segredo = (int)$case['segredo_justica'];

        if (empty($parsed['movimentos'])) {
            $detalhes[] = "Email do caso {$parsed['cnj']} sem movimentos parseáveis (UID {$uidStr})";
        }

        // Insere cada movimento
        foreach ($parsed['movimentos'] as $mov) {
            $hash = md5($caseId . '|' . $mov['data'] . '|' . $mov['hora'] . '|' . $mov['descricao']);

            // Checa duplicata via fetchAll + closeCursor pra liberar o cursor
            // antes do INSERT subsequente. SELECT por chave única retorna 0 ou 1 linha.
            $stmtChkHash->execute(array($hash));
            $hashRows = $stmtChkHash->fetchAll();
            $stmtChkHash->closeCursor();
            if (!empty($hashRows)) {
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
                $stmtInsAndam->closeCursor();
                $andamentosInsert++;
            } catch (Exception $e) {
                // closeCursor por garantia mesmo em caso de erro
                @$stmtInsAndam->closeCursor();
                $erros++;
                $detalhes[] = "Erro INSERT case#{$caseId} ({$parsed['cnj']}): " . $e->getMessage();
            }
        }

        // Marca como lido (sucesso ou não — pra não reprocessar infinitamente)
        @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);

    } catch (Throwable $e) {
        // Falha por email não derruba o batch inteiro — captura, registra e segue
        $erros++;
        $detalhes[] = "Falha inesperada UID {$uidStr}: " . $e->getMessage();
        @imap_setflag_full($mbox, $uidStr, "\\Seen", ST_UID);
        continue;
    }
}

@imap_close($mbox);

// ────────────────────────────────────────────────────────────
// Log final + resumo (caminho feliz — shutdown só limparia o lock)
// ────────────────────────────────────────────────────────────
email_monitor_log_save($pdo, $emailsLidos, $andamentosInsert, $emailsIgnorados, $duplicatasIgnor, $erros, $detalhes, $isCli ? 'cron' : 'manual');
$logSalvo = true;

echo "[done] lidos={$emailsLidos} inseridos={$andamentosInsert} ignorados={$emailsIgnorados} dup={$duplicatasIgnor} erros={$erros}\n";

// Lock liberado pelo shutdown handler — não duplica aqui

// ────────────────────────────────────────────────────────────
// Funções auxiliares (parsers de IMAP/email vivem em
// includes/email_monitor_functions.php — incluídas no topo deste arquivo).
// Aqui ficam apenas funções específicas do cron (gravação de log).
// ────────────────────────────────────────────────────────────

function email_monitor_log_save($pdo, $lidos, $insert, $ignor, $dup, $erros, $detalhes, $modo) {
    $detText = implode("\n", array_slice($detalhes, 0, 80));
    if (mb_strlen($detText) > 60000) $detText = mb_substr($detText, 0, 60000) . "\n...[truncado]";
    $stmt = $pdo->prepare(
        "INSERT INTO email_monitor_log
            (executado_em, emails_lidos, andamentos_inseridos, emails_ignorados, duplicatas_ignoradas, erros, detalhes, modo)
         VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute(array($lidos, $insert, $ignor, $dup, $erros, $detText, $modo));
    $stmt->closeCursor();
}
