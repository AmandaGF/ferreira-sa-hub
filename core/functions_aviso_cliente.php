<?php
/**
 * Aviso automático ao cliente quando entra andamento novo no caso.
 * Amanda 16/07/2026 — resolver o assedio de CX ("houve movimentacao?").
 *
 * Fluxo:
 *   1. Cron chama aviso_cliente_processar_pendentes()
 *   2. Pega andamentos com notif_cliente_status IS NULL e created_at recente
 *   3. Agrupa por case_id (agrega multiplos andamentos numa msg so)
 *   4. Filtra: killswitch global, silenciado por caso, blacklist de tipo
 *   5. Chama Claude Haiku pra resumir em linguagem de leigo
 *   6. Envia via Z-API canal 24 (CX) pro telefone do cliente
 *   7. Marca notif_cliente_status='enviado' + notif_cliente_enviada_em
 *
 * Killswitches:
 *   - configuracoes.aviso_cliente_ativo = '0'|'1'  (global — DEFAULT '0')
 *   - configuracoes.aviso_cliente_tipos_ignorar    (csv de tipos que nao avisa)
 *   - cases.aviso_cliente_silenciado = 1           (por caso, checkbox)
 *
 * Self-heal: cria colunas na primeira execucao.
 */

require_once __DIR__ . '/functions_zapi.php';
require_once __DIR__ . '/functions_ia.php';

function aviso_cliente_self_heal($pdo) {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try { $pdo->exec("ALTER TABLE case_andamentos ADD COLUMN notif_cliente_status VARCHAR(20) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE case_andamentos ADD COLUMN notif_cliente_enviada_em DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE case_andamentos ADD COLUMN notif_cliente_texto TEXT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE case_andamentos ADD INDEX idx_notif_cliente_status (notif_cliente_status, created_at)"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE cases ADD COLUMN aviso_cliente_silenciado TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    // Defaults nas configuracoes
    try {
        $pdo->exec("INSERT IGNORE INTO configuracoes (chave, valor) VALUES
            ('aviso_cliente_ativo', '0'),
            ('aviso_cliente_tipos_ignorar', 'ato_ordinatorio,mero_expediente,juntada_ap,ciencia'),
            ('aviso_cliente_janela_seg', '180'),
            ('aviso_cliente_max_por_run', '15')");
    } catch (Exception $e) {}
}

function aviso_cliente_cfg($pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = array();
    try {
        foreach ($pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'aviso_cliente_%'") as $r) {
            $cache[$r['chave']] = $r['valor'];
        }
    } catch (Exception $e) {}
    return $cache;
}

function aviso_cliente_ativo($pdo) {
    $cfg = aviso_cliente_cfg($pdo);
    return ($cfg['aviso_cliente_ativo'] ?? '0') === '1';
}

/**
 * Processa lote de andamentos pendentes. Chamado pelo cron.
 * Retorna array com contadores pro relatorio.
 */
function aviso_cliente_processar_pendentes($pdo, $limite = 15) {
    aviso_cliente_self_heal($pdo);
    $cfg = aviso_cliente_cfg($pdo);
    $result = array(
        'ativo' => aviso_cliente_ativo($pdo),
        'pendentes_total' => 0,
        'processados' => 0,
        'enviados' => 0,
        'ignorados_silenciado' => 0,
        'ignorados_tipo' => 0,
        'ignorados_sem_fone' => 0,
        'ignorados_sem_cliente' => 0,
        'ignorados_antigos' => 0,
        'erros' => 0,
        'detalhes' => array(),
    );

    if (!$result['ativo']) {
        // Feature desligada — marca como 'desligado' pra nao acumular fila infinita
        // (evita explodir depois que ligar)
        try {
            $pdo->exec("UPDATE case_andamentos
                        SET notif_cliente_status = 'desligado'
                        WHERE notif_cliente_status IS NULL
                          AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        } catch (Exception $e) {}
        return $result;
    }

    $janelaSeg = max(60, (int)($cfg['aviso_cliente_janela_seg'] ?? 180));
    $tiposIgnorar = array_map('trim', explode(',', strtolower($cfg['aviso_cliente_tipos_ignorar'] ?? '')));
    $tiposIgnorar = array_filter($tiposIgnorar);

    // Pega pendentes: andamentos com status NULL nas ULTIMAS 48h (evita disparar
    // avisos antigos apos ligar a feature). Andamentos mais antigos ficam
    // marcados como 'antigo' pra nao ficarem penurando.
    try {
        $pdo->prepare("UPDATE case_andamentos
                       SET notif_cliente_status = 'antigo'
                       WHERE notif_cliente_status IS NULL
                         AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)")->execute();
    } catch (Exception $e) {}

    // Debounce: so processa andamentos com created_at + janela_seg <= agora
    // (agrega multiplos andamentos que caem em sequencia numa msg so).
    $st = $pdo->prepare(
        "SELECT ca.id, ca.case_id, ca.tipo, ca.tipo_origem, ca.descricao, ca.data_andamento,
                ca.created_at, cs.title AS case_title, cs.case_number, cs.aviso_cliente_silenciado,
                cs.client_id, cl.name AS client_name, cl.phone AS client_phone
           FROM case_andamentos ca
           LEFT JOIN cases cs ON cs.id = ca.case_id
           LEFT JOIN clients cl ON cl.id = cs.client_id
          WHERE ca.notif_cliente_status IS NULL
            AND ca.created_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)
          ORDER BY ca.case_id, ca.id
          LIMIT ?"
    );
    $st->bindValue(1, $janelaSeg, PDO::PARAM_INT);
    $st->bindValue(2, (int)$limite, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $result['pendentes_total'] = count($rows);

    if (!$rows) return $result;

    // Agrupa por case_id
    $porCase = array();
    foreach ($rows as $r) {
        $porCase[(int)$r['case_id']][] = $r;
    }

    foreach ($porCase as $caseId => $ands) {
        $result['processados'] += count($ands);
        $primeiro = $ands[0];

        // Filtro: sem cliente cadastrado
        if (!$primeiro['client_id']) {
            aviso_cliente_marcar_lote($pdo, $ands, 'sem_cliente');
            $result['ignorados_sem_cliente'] += count($ands);
            continue;
        }
        // Filtro: sem telefone
        if (!$primeiro['client_phone']) {
            aviso_cliente_marcar_lote($pdo, $ands, 'sem_fone');
            $result['ignorados_sem_fone'] += count($ands);
            continue;
        }
        // Filtro: caso silenciado
        if ((int)$primeiro['aviso_cliente_silenciado'] === 1) {
            aviso_cliente_marcar_lote($pdo, $ands, 'silenciado_caso');
            $result['ignorados_silenciado'] += count($ands);
            continue;
        }
        // Filtro: todos os andamentos sao de tipo ignorado?
        $andsRelevantes = array();
        foreach ($ands as $a) {
            $tipo = strtolower((string)$a['tipo']);
            $ignora = false;
            foreach ($tiposIgnorar as $tignore) {
                if ($tignore !== '' && strpos($tipo, $tignore) !== false) { $ignora = true; break; }
            }
            if (!$ignora) $andsRelevantes[] = $a;
        }
        if (empty($andsRelevantes)) {
            aviso_cliente_marcar_lote($pdo, $ands, 'tipo_ignorado');
            $result['ignorados_tipo'] += count($ands);
            continue;
        }

        // Gera resumo IA
        $resumo = aviso_cliente_resumir_via_ia($andsRelevantes, $primeiro['client_name'], $primeiro['case_title']);
        if (!$resumo) {
            aviso_cliente_marcar_lote($pdo, $ands, 'erro_ia', null);
            $result['erros'] += count($ands);
            continue;
        }

        // Envia via Z-API canal 24
        $envio = zapi_send_text('24', $primeiro['client_phone'], $resumo);
        if (empty($envio['ok'])) {
            aviso_cliente_marcar_lote($pdo, $ands, 'erro_envio', $resumo);
            $result['erros'] += count($ands);
            $result['detalhes'][] = "case #$caseId erro envio: " . ($envio['erro'] ?? 'desconhecido');
            continue;
        }

        // Sucesso — marca todos os andamentos do batch como enviados
        aviso_cliente_marcar_lote($pdo, $ands, 'enviado', $resumo);
        $result['enviados'] += count($ands);
        $result['detalhes'][] = "case #$caseId (" . $primeiro['client_name'] . "): " . count($ands) . " andamento(s) enviado(s)";
    }

    return $result;
}

function aviso_cliente_marcar_lote($pdo, $ands, $status, $textoResumo = null) {
    $stmt = $pdo->prepare(
        "UPDATE case_andamentos
            SET notif_cliente_status = ?,
                notif_cliente_enviada_em = CASE WHEN ? = 'enviado' THEN NOW() ELSE notif_cliente_enviada_em END,
                notif_cliente_texto = COALESCE(?, notif_cliente_texto)
          WHERE id = ?"
    );
    foreach ($ands as $a) {
        $stmt->execute(array($status, $status, $textoResumo, (int)$a['id']));
    }
}

/**
 * Chama Claude Haiku pra transformar 1+ andamentos jurídicos em 1 mensagem
 * curta em linguagem de leigo, com nome do cliente + assinatura da equipe.
 */
function aviso_cliente_resumir_via_ia($ands, $clientName, $caseTitle) {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) return null;

    $primNome = trim(explode(' ', (string)$clientName)[0]) ?: 'você';
    $primNome = ucfirst(mb_strtolower($primNome, 'UTF-8'));

    $listaAnds = '';
    foreach ($ands as $i => $a) {
        $data = $a['data_andamento'] ? date('d/m/Y', strtotime($a['data_andamento'])) : '';
        $tipo = $a['tipo'] ?: '—';
        $desc = trim(preg_replace('/\s+/', ' ', (string)$a['descricao']));
        if (mb_strlen($desc) > 700) $desc = mb_substr($desc, 0, 700, 'UTF-8') . '…';
        $listaAnds .= "\n#" . ($i+1) . " — {$data} [{$tipo}]:\n{$desc}\n";
    }

    $system = "Você é a comunicação do escritório Ferreira & Sá Advocacia com clientes leigos. "
            . "Vai receber 1 ou mais andamentos jurídicos técnicos do processo de um cliente "
            . "e deve gerar UMA mensagem CURTA de WhatsApp explicando o que aconteceu, em "
            . "linguagem que qualquer pessoa entende, sem jargão jurídico.\n\n"
            . "REGRAS:\n"
            . "- Comece com '{$primNome}, tudo bem?' (nome ja em maiuscula/minuscula correta).\n"
            . "- Explique CADA andamento em 1 frase simples (sem 'exequente', 'requerido', 'ipsis literis' — troque por 'você', 'a outra parte').\n"
            . "- Se for boa notícia (depósito, sentença favorável, acordo homologado), CELEBRE em tom natural.\n"
            . "- Se for prazo/pendência do cliente, deixe CLARO o que ele precisa fazer (e ate quando).\n"
            . "- Termine com: 'Qualquer dúvida, estamos aqui. Equipe Ferreira & Sá.'\n"
            . "- Formato WhatsApp (usa *negrito* pra destacar palavras-chave, quebra linhas curtas).\n"
            . "- Máximo 500 caracteres. Sem hashtag, sem emoji excessivo (1 emoji so quando fizer sentido).\n"
            . "- Se o andamento for meramente burocrático e sem impacto pro cliente, ainda assim comunique de forma leve, sem alarmar.\n"
            . "- NUNCA invente prazo ou fato. Se nao tiver data, nao chuta.\n"
            . "- NUNCA use 'Dra. Amanda' — sempre assine 'Equipe Ferreira & Sá'.";

    $user = "Cliente: {$clientName}\nProcesso: {$caseTitle}\nAndamentos (do mais antigo pro mais recente):{$listaAnds}\n\nGere a mensagem.";

    $resp = ia_chamar(
        'aviso_cliente_andamento',
        'claude-haiku-4-5-20251001',
        $system,
        array(array('role' => 'user', 'content' => $user)),
        array('max_tokens' => 400, 'temperature' => 0.4, 'bypass_killswitch' => true, 'bypass_user_whitelist' => true)
    );
    if (empty($resp['ok']) || empty($resp['texto'])) return null;
    $txt = trim($resp['texto']);
    if (mb_strlen($txt) > 900) $txt = mb_substr($txt, 0, 900) . '…';

    // Guard: se a IA saiu do personagem (pediu dado, fez pergunta), descarta
    $ruins = array('/preciso de/i', '/voc[eê] tem/i', '/me envie/i', '/vou gerar a mensagem/i', '/como assistente/i');
    foreach ($ruins as $rx) {
        if (preg_match($rx, $txt)) return null;
    }
    return $txt;
}
