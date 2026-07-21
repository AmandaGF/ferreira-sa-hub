<?php
/**
 * Ferreira & Sá Hub — Linha do Tempo do Cliente
 * Helpers compartilhados entre o editor (modules/operacional/), a API e a
 * página pública (publico/linha/).
 *
 * ATENÇÃO: este arquivo é carregado também pela página PÚBLICA, que não tem
 * sessão de equipe. Não pode depender de middleware.php nem de current_user_id().
 */

if (!function_exists('lt_tipos_validos')) {

/** Tipos de marco aceitos (espelham o ENUM de case_timeline_eventos). */
function lt_tipos_validos() {
    return array('nos', 'decisao', 'audiencia', 'recurso', 'marco', 'alerta', 'agora', 'outro');
}

/** Rótulo humano de cada tipo, pro editor. */
function lt_tipos_labels() {
    return array(
        'nos'       => 'Ato nosso (do escritório)',
        'decisao'   => 'Decisão do juiz',
        'audiencia' => 'Audiência / sessão',
        'recurso'   => 'Recurso',
        'marco'     => 'Virada importante',
        'alerta'    => 'Ponto de atenção',
        'agora'     => 'Onde estamos hoje',
        'outro'     => 'Outro',
    );
}

/**
 * Self-heal das tabelas — o deploy não roda migração sozinho, e código antigo
 * pode chegar ao servidor antes do migrar_linha_tempo.php ser chamado.
 */
function lt_self_heal($pdo) {
    static $feito = false;
    if ($feito) return;
    $feito = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS case_timeline (
            id INT AUTO_INCREMENT PRIMARY KEY,
            case_id INT NOT NULL,
            token CHAR(32) NOT NULL,
            publicado TINYINT(1) NOT NULL DEFAULT 0,
            titulo VARCHAR(200) NULL,
            lede TEXT NULL,
            gate ENUM('cpf','aberto') NOT NULL DEFAULT 'cpf',
            gate_cpf VARCHAR(14) NULL,
            gate_label VARCHAR(120) NULL,
            painel_ok TEXT NULL,
            painel_atencao TEXT NULL,
            painel_acao TEXT NULL,
            pedidos TEXT NULL,
            pedidos_auto TINYINT(1) NOT NULL DEFAULT 1,
            proximos_passos TEXT NULL,
            fecho TEXT NULL,
            midia_url VARCHAR(500) NULL,
            midia_tipo ENUM('video','audio') NULL,
            midia_titulo VARCHAR(200) NULL,
            visualizacoes INT NOT NULL DEFAULT 0,
            ultima_visualizacao DATETIME NULL,
            publicado_em DATETIME NULL,
            criado_por INT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL,
            UNIQUE KEY uq_case (case_id),
            UNIQUE KEY uq_token (token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* já existe */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS case_timeline_eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            timeline_id INT NOT NULL,
            data_evento DATE NULL,
            data_label VARCHAR(60) NULL,
            titulo VARCHAR(200) NOT NULL,
            texto TEXT NULL,
            nota TEXT NULL,
            tipo ENUM('nos','decisao','audiencia','recurso','marco','alerta','agora','outro') NOT NULL DEFAULT 'outro',
            destaque TINYINT(1) NOT NULL DEFAULT 0,
            visivel TINYINT(1) NOT NULL DEFAULT 1,
            ordem INT NOT NULL DEFAULT 0,
            andamento_id INT NULL,
            gerado_ia TINYINT(1) NOT NULL DEFAULT 0,
            editado_manual TINYINT(1) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NULL,
            KEY idx_timeline (timeline_id, ordem),
            KEY idx_andamento (andamento_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* já existe */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS case_timeline_tentativas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            token CHAR(32) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            sucesso TINYINT(1) NOT NULL DEFAULT 0,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_token_ip (token, ip, criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* já existe */ }
}

/** Token de 32 hex garantidamente livre. */
function lt_novo_token($pdo) {
    for ($i = 0; $i < 8; $i++) {
        $t = bin2hex(random_bytes(16));
        $st = $pdo->prepare("SELECT COUNT(*) FROM case_timeline WHERE token = ?");
        $st->execute(array($t));
        if (!(int)$st->fetchColumn()) return $t;
    }
    // 8 colisões seguidas em 128 bits é impossível na prática — mas não travamos.
    return bin2hex(random_bytes(16));
}

/** URL pública absoluta da linha do tempo. */
function lt_url_publica($token) {
    return 'https://ferreiraesa.com.br' . BASE_URL . '/publico/linha/?t=' . urlencode($token);
}

/**
 * Devolve a linha do tempo do caso, criando o registro (rascunho) na primeira vez.
 * O CPF da trava já nasce preenchido com o do cliente principal — a Amanda troca
 * no editor quando quem acompanha é um representante legal.
 */
function lt_get_or_create($pdo, $caseId, $userId = 0) {
    $caseId = (int)$caseId;

    $st = $pdo->prepare("SELECT * FROM case_timeline WHERE case_id = ?");
    $st->execute(array($caseId));
    $tl = $st->fetch();
    if ($tl) return $tl;

    $cpf = null;
    // O rótulo aparece na tela de entrada, ANTES da autenticação — por isso
    // nunca pode conter o nome de ninguém. Diz de quem é o CPF, não quem é.
    $label = 'do cliente cadastrado no processo';
    try {
        $stC = $pdo->prepare(
            "SELECT cl.cpf FROM cases c
             LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id = ?"
        );
        $stC->execute(array($caseId));
        $cli = $stC->fetch();
        if ($cli) {
            $d = preg_replace('/\D/', '', (string)$cli['cpf']);
            if (strlen($d) === 11) $cpf = $d;
        }
    } catch (Throwable $e) { /* segue sem prefill */ }

    $token = lt_novo_token($pdo);
    $pdo->prepare(
        "INSERT INTO case_timeline (case_id, token, gate, gate_cpf, gate_label, criado_por, criado_em)
         VALUES (?, ?, 'cpf', ?, ?, ?, NOW())"
    )->execute(array($caseId, $token, $cpf, $label, $userId > 0 ? (int)$userId : null));

    $st->execute(array($caseId));
    return $st->fetch();
}

/**
 * Renumera `ordem` em sequência cronológica (marcos sem data vão pro fim,
 * mantendo a ordem anterior entre eles).
 */
function lt_renumerar($pdo, $timelineId) {
    $st = $pdo->prepare(
        "SELECT id FROM case_timeline_eventos WHERE timeline_id = ?
         ORDER BY (data_evento IS NULL), data_evento ASC, ordem ASC, id ASC"
    );
    $st->execute(array((int)$timelineId));
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);

    $up = $pdo->prepare("UPDATE case_timeline_eventos SET ordem = ? WHERE id = ?");
    $i = 0;
    foreach ($ids as $id) $up->execute(array(++$i, (int)$id));
}

/** Marcos da linha do tempo, na ordem de exibição. */
function lt_marcos($pdo, $timelineId, $somenteVisiveis = false) {
    $sql = "SELECT * FROM case_timeline_eventos WHERE timeline_id = ?"
         . ($somenteVisiveis ? " AND visivel = 1" : "")
         . " ORDER BY ordem ASC, id ASC";
    $st = $pdo->prepare($sql);
    $st->execute(array((int)$timelineId));
    return $st->fetchAll();
}

/**
 * Documentos que ainda faltam do cliente.
 * Schema real de documentos_pendentes: `descricao` + `status` ('pendente'/'recebido').
 */
function lt_docs_pendentes($pdo, $caseId) {
    try {
        $st = $pdo->prepare(
            "SELECT descricao FROM documentos_pendentes
             WHERE case_id = ? AND status = 'pendente' ORDER BY id"
        );
        $st->execute(array((int)$caseId));
        $out = array();
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $d = trim((string)$d);
            if ($d !== '') $out[] = $d;
        }
        return $out;
    } catch (Throwable $e) {
        return array();
    }
}

/**
 * Lista final do bloco "o que precisamos de você": texto manual quando houver,
 * senão os documentos pendentes do caso (se pedidos_auto estiver ligado).
 */
function lt_pedidos($pdo, $tl) {
    $manual = trim((string)$tl['pedidos']);
    if ($manual !== '') {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $manual)), 'strlen'));
    }
    if (!(int)$tl['pedidos_auto']) return array();
    return lt_docs_pendentes($pdo, (int)$tl['case_id']);
}

/** Quebra um bloco de texto em linhas não vazias (usado em próximos passos). */
function lt_linhas($texto) {
    $texto = trim((string)$texto);
    if ($texto === '') return array();
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $texto)), 'strlen'));
}

/**
 * Data por extenso em pt-BR, sem depender de locale do servidor.
 * Ex: 2026-03-14 → "14 de março de 2026".
 */
function lt_data_extenso($ymd) {
    $ts = strtotime((string)$ymd);
    if (!$ts) return '';
    $meses = array('', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
                   'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro');
    return (int)date('j', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

/**
 * Duração em linguagem humana, a partir de um total de dias.
 * Ex: 12 → "12 dias" · 95 → "3 meses" · 400 → "1 ano e 1 mês".
 */
function lt_intervalo_humano($dias) {
    $dias = (int)$dias;
    if ($dias <= 1)  return $dias === 1 ? '1 dia' : 'no mesmo dia';
    if ($dias < 45)  return $dias . ' dias';

    $meses = (int)round($dias / 30.44);
    if ($meses < 12) return $meses . ' meses';

    $anos = intdiv($meses, 12);
    $rest = $meses % 12;
    $txt  = $anos . ($anos === 1 ? ' ano' : ' anos');
    if ($rest > 0) $txt .= ' e ' . $rest . ($rest === 1 ? ' mês' : ' meses');
    return $txt;
}

/** Dias corridos entre duas datas Y-m-d (0 se alguma faltar). */
function lt_dias_entre($de, $ate) {
    $a = strtotime((string)$de);
    $b = strtotime((string)$ate);
    if (!$a || !$b) return 0;
    return (int)floor(abs($b - $a) / 86400);
}

/**
 * Altura do vão entre dois marcos, proporcional ao tempo real de espera.
 * Curva raiz quadrada com teto — um caso de 5 anos não pode gerar uma
 * página de 40 metros, mas a diferença entre "11 dias" e "8 meses"
 * precisa ser sentida na rolagem.
 */
function lt_vao_px($dias) {
    $dias = max(0, (int)$dias);
    return 44 + (int)min(150, round(sqrt($dias) * 9));
}

// ═══════════════════════════════════════════════════════════════════
//  Gate de CPF da página pública
// ═══════════════════════════════════════════════════════════════════

/** IP do visitante (respeita proxy do LiteSpeed quando houver). */
function lt_ip() {
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $k) {
        if (empty($_SERVER[$k])) continue;
        $ip = trim(explode(',', (string)$_SERVER[$k])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

/** Quantas tentativas ERRADAS este IP fez neste token nos últimos 15 min. */
function lt_tentativas_recentes($pdo, $token, $ip) {
    try {
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM case_timeline_tentativas
             WHERE token = ? AND ip = ? AND sucesso = 0
               AND criado_em > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
        );
        $st->execute(array($token, $ip));
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function lt_registrar_tentativa($pdo, $token, $ip, $sucesso) {
    try {
        $pdo->prepare("INSERT INTO case_timeline_tentativas (token, ip, sucesso, criado_em) VALUES (?, ?, ?, NOW())")
            ->execute(array($token, $ip, $sucesso ? 1 : 0));
        // Limpeza oportunista pra tabela não crescer pra sempre
        if (random_int(1, 50) === 1) {
            $pdo->exec("DELETE FROM case_timeline_tentativas WHERE criado_em < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        }
    } catch (Throwable $e) { /* log não pode derrubar a página */ }
}

} // fim do guard function_exists
