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
            ('aviso_cliente_max_por_run', '15'),
            ('aviso_cliente_assinante', 'Alfredo Neves'),
            ('aviso_cliente_janela_novidade_dias', '7'),
            ('aviso_cliente_janela_longa_espera_dias', '30')");
    } catch (Exception $e) {}
}

/**
 * Determina o MODO da mensagem antes de gerar (Amanda 17/07/2026):
 *   - NOVIDADE     : andamento < janela_novidade E cliente NAO perguntou desde entao
 *                    → pode celebrar "boa noticia"
 *   - RELEMBRAR    : andamento > janela_novidade OU cliente ja perguntou desde entao
 *                    → comeca "ainda nao tivemos atualizacao, mas estamos acompanhando",
 *                      e ai reexplica o ultimo andamento
 *   - LONGA_ESPERA : andamento > janela_longa_espera (default 30d)
 *                    → comeca "sabemos que a espera esta longa, ja fizemos contato com
 *                      o cartorio, mas segue ordem cronologica de julgamento"
 *
 * $andamentoData no formato Y-m-d ou Y-m-d H:i:s.
 * Retorna: ['modo' => 'NOVIDADE'|'RELEMBRAR'|'LONGA_ESPERA', 'dias' => int,
 *           'cliente_perguntou_apos' => bool, 'ultima_pergunta' => 'Y-m-d H:i:s'|null]
 */
function aviso_cliente_determinar_modo($pdo, $clientId, $andamentoData) {
    $cfg = aviso_cliente_cfg($pdo);
    $janelaNovidade    = max(1, (int)($cfg['aviso_cliente_janela_novidade_dias'] ?? 7));
    $janelaLongaEspera = max($janelaNovidade + 1, (int)($cfg['aviso_cliente_janela_longa_espera_dias'] ?? 30));

    $ts = strtotime((string)$andamentoData);
    $dias = $ts ? max(0, (int)floor((time() - $ts) / 86400)) : 0;

    // Cliente perguntou depois do andamento? Qualquer msg recebida canal 24
    // apos a data do andamento conta como "ele ja procurou saber".
    $perguntou = false;
    $ultimaPergunta = null;
    try {
        $st = $pdo->prepare(
            "SELECT MAX(m.created_at)
               FROM zapi_mensagens m
               JOIN zapi_conversas co ON co.id = m.conversa_id
              WHERE co.client_id = ?
                AND co.canal = '24'
                AND m.direcao = 'recebida'
                AND m.created_at > ?"
        );
        $st->execute(array((int)$clientId, (string)$andamentoData));
        $r = (string)$st->fetchColumn();
        if ($r) { $perguntou = true; $ultimaPergunta = $r; }
    } catch (Exception $e) {}

    $modo = 'NOVIDADE';
    if ($dias >= $janelaLongaEspera) {
        $modo = 'LONGA_ESPERA';
    } elseif ($dias >= $janelaNovidade || $perguntou) {
        $modo = 'RELEMBRAR';
    }

    return array(
        'modo' => $modo,
        'dias' => $dias,
        'cliente_perguntou_apos' => $perguntou,
        'ultima_pergunta' => $ultimaPergunta,
        'janela_novidade' => $janelaNovidade,
        'janela_longa_espera' => $janelaLongaEspera,
    );
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
    // Amanda 17/07/2026: marca andamentos internos (cadeado) como 'interno' —
    // NUNCA vao pra cliente, nao ficam na fila.
    try {
        $pdo->prepare("UPDATE case_andamentos
                       SET notif_cliente_status = 'interno'
                       WHERE notif_cliente_status IS NULL
                         AND COALESCE(visivel_cliente, 0) = 0")->execute();
    } catch (Exception $e) {}
    // Amanda 17/07/2026: blacklist de conteudo — palavras que NUNCA devem
    // ser expostas ao cliente (distribuicao, perda de prazo). Marca 'blacklist_conteudo'.
    try {
        $pdo->prepare("UPDATE case_andamentos
                       SET notif_cliente_status = 'blacklist_conteudo'
                       WHERE notif_cliente_status IS NULL
                         AND (descricao REGEXP 'distribui[çc][ãa]o|distribu[ií]d[ao]|distribu[ií]mos|distribu[ií]ram|distribuir'
                              OR descricao REGEXP 'prazo esgotado|perda de prazo|fim de prazo|prazo perdido|precluso|preclusao')")->execute();
    } catch (Exception $e) {}

    // Debounce: so processa andamentos com created_at + janela_seg <= agora
    // (agrega multiplos andamentos que caem em sequencia numa msg so).
    // FILTRO CRITICO: SO andamentos com visivel_cliente=1. Andamentos internos
    // (cadeado) NUNCA vao pra cliente (Amanda 17/07/2026).
    // Amanda 17/07/2026: NAO avisa casos arquivados/cancelados/renunciados/
    // concluidos/finalizados — clientes desses casos ja sabem que acabou.
    $st = $pdo->prepare(
        "SELECT ca.id, ca.case_id, ca.tipo, ca.tipo_origem, ca.descricao, ca.data_andamento,
                ca.created_at, ca.visivel_cliente, cs.title AS case_title, cs.case_number,
                cs.aviso_cliente_silenciado, cs.status AS case_status,
                cs.client_id, cl.name AS client_name, cl.phone AS client_phone
           FROM case_andamentos ca
           LEFT JOIN cases cs ON cs.id = ca.case_id
           LEFT JOIN clients cl ON cl.id = cs.client_id
          WHERE ca.notif_cliente_status IS NULL
            AND ca.created_at <= DATE_SUB(NOW(), INTERVAL ? SECOND)
            AND COALESCE(ca.visivel_cliente, 0) = 1
            AND cs.status NOT IN ('arquivado','cancelado','renunciamos','concluido','finalizado')
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

        // Amanda 17/07/2026: busca ultimas 3 msgs enviadas pro MESMO cliente
        // pra IA variar formulacao (nao repetir texto).
        $ultimasMsgs = array();
        try {
            $stUlt = $pdo->prepare(
                "SELECT ca2.notif_cliente_texto
                   FROM case_andamentos ca2
                   JOIN cases cs2 ON cs2.id = ca2.case_id
                  WHERE cs2.client_id = ?
                    AND ca2.notif_cliente_status = 'enviado'
                    AND ca2.notif_cliente_texto IS NOT NULL
                  ORDER BY ca2.notif_cliente_enviada_em DESC
                  LIMIT 3");
            $stUlt->execute(array((int)$primeiro['client_id']));
            foreach ($stUlt as $r) {
                if (!empty($r['notif_cliente_texto'])) $ultimasMsgs[] = $r['notif_cliente_texto'];
            }
        } catch (Exception $e) {}

        // Modo (NOVIDADE / RELEMBRAR / LONGA_ESPERA) — usa a data do
        // andamento MAIS RECENTE do batch pra decidir.
        $andMaisRecente = end($andsRelevantes);
        $modoInfo = aviso_cliente_determinar_modo($pdo, (int)$primeiro['client_id'], (string)$andMaisRecente['data_andamento']);

        // Gera resumo IA
        $resumo = aviso_cliente_resumir_via_ia($andsRelevantes, $primeiro['client_name'], $primeiro['case_title'], $ultimasMsgs, $modoInfo);
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
function aviso_cliente_resumir_via_ia($ands, $clientName, $caseTitle, $ultimasMsgs = array(), $modoInfo = null) {
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

    // Hora atual pra variar saudacao (bom dia/boa tarde/boa noite)
    $hora = (int)date('G');
    if     ($hora >= 5  && $hora < 12) $periodoDia = 'manhã (use "bom dia")';
    elseif ($hora >= 12 && $hora < 18) $periodoDia = 'tarde (use "boa tarde")';
    else                                $periodoDia = 'noite (use "boa noite")';

    // Assinante configuravel (default: Alfredo Neves — persona CX humana).
    // Amanda 17/07/2026 (testado com o time): marcador 'IA' em superscript
    // foi removido a pedido da equipe — nome do assinante sai limpo.
    $cfgLocal = aviso_cliente_cfg(db());
    $assinante = trim((string)($cfgLocal['aviso_cliente_assinante'] ?? 'Alfredo Neves')) ?: 'Alfredo Neves';

    // ── MODO DA MENSAGEM (Amanda 17/07/2026) ──
    // NOVIDADE     : notícia recente, cliente ainda não perguntou → celebra
    // RELEMBRAR    : cliente já perguntou OU andamento > 7d → nao finge que e novidade
    // LONGA_ESPERA : sem andamento há > 30d → contextualiza espera + cartorio
    $modo = is_array($modoInfo) ? ($modoInfo['modo'] ?? 'NOVIDADE') : 'NOVIDADE';
    $diasSem = is_array($modoInfo) ? (int)($modoInfo['dias'] ?? 0) : 0;

    if ($modo === 'LONGA_ESPERA') {
        $blocoModo = "🚨 MODO **LONGA_ESPERA** ({$diasSem} dias sem movimento — cliente cansado, provavelmente perguntou várias vezes).\n\n"
                   . "❌ PROIBIDO: 'ótima notícia', 'boa notícia', emojis 🎉🎊🥳🙌✨🚀, tom celebrativo.\n\n"
                   . "✅ COPIE ESTA ESTRUTURA (adapte o texto do andamento, mas mantenha as 4 partes):\n\n"
                   . "```\n"
                   . "*_{$assinante}_*:\n"
                   . "Bom dia, [primeiro nome do cliente]!\n"
                   . "\n"
                   . "Sabemos que a espera está longa — e agradecemos sua paciência.\n"
                   . "\n"
                   . "*Já fizemos contato com o cartório* e a resposta continua sendo a mesma: os processos seguem ordem cronológica de julgamento, então precisamos aguardar chegar a vez do seu. Estamos *monitorando de perto* e assim que houver qualquer novidade, avisamos aqui.\n"
                   . "\n"
                   . "Só reforçando: o último andamento foi em [DD/MM/YYYY]: [1 frase simples sobre o que foi].\n"
                   . "```\n\n"
                   . "Tom: empático, honesto, sem falso otimismo. Cliente precisa se sentir OUVIDO, não enganado.\n";
    } elseif ($modo === 'RELEMBRAR') {
        $razao = is_array($modoInfo) && !empty($modoInfo['cliente_perguntou_apos'])
            ? "cliente JÁ PERGUNTOU sobre esse andamento"
            : "já se passaram {$diasSem} dias — não é fresco";
        $blocoModo = "🚨 MODO **RELEMBRAR** ({$razao}).\n\n"
                   . "❌ PROIBIDO: 'ótima notícia', 'boa notícia', emojis 🎉🎊🥳🙌✨🚀, fingir que é primeira vez.\n\n"
                   . "✅ COPIE ESTA ESTRUTURA:\n\n"
                   . "```\n"
                   . "*_{$assinante}_*:\n"
                   . "Bom dia, [primeiro nome]!\n"
                   . "\n"
                   . "Ainda não tivemos nenhuma atualização nova, mas continuamos acompanhando de perto.\n"
                   . "\n"
                   . "Só relembrando: o último andamento aconteceu em [DD/MM/YYYY]. Basicamente [explicação simples do andamento em 1-2 frases]. Estamos aguardando a próxima movimentação.\n"
                   . "```\n\n"
                   . "Tom: acolhedor, presente. NÃO é novidade — é lembrete.\n";
    } else {
        $blocoModo = "🎯 MODO **NOVIDADE** — andamento recente e cliente ainda não perguntou.\n"
                   . "Pode celebrar quando for boa notícia ('Ótima notícia!'). Tom natural, alegre quando couber.\n";
    }

    $system = $blocoModo . "\n\n"
            . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n"
            . "⛔ PALAVRAS ABSOLUTAMENTE PROIBIDAS — se você usar alguma, sua resposta é DESCARTADA e recomeçamos:\n"
            . "• 'autorizar' / 'autorização' / 'autorizado' / 'autoriza' — o juiz PODE não autorizar.\n"
            . "• 'distribuição' / 'distribuir' / 'distribuído' / 'distribuiu' / 'distribuímos' — palavra sensível internamente, não expor ao cliente.\n"
            . "• 'perda de prazo' / 'prazo esgotado' / 'prazo perdido' / 'fim de prazo' / 'preclusão' / 'precluso' — nunca comunicar isso ao cliente.\n"
            . "• 'deferir' / 'indeferir' / 'homologar' — o juiz PODE fazer o oposto.\n"
            . "• 'juízo' — palavra técnica, pouco entendida. TROQUE POR: 'conta do processo' (quando falar de depósito judicial) ou reformule sem usar. Ex: em vez de 'depositou em juízo', escreva 'depositou o valor na conta do processo'. Em vez de 'valor em juízo', escreva 'valor bloqueado na conta do processo'.\n\n"
            . "Você é a comunicação do escritório Ferreira & Sá Advocacia com clientes leigos. "
            . "Vai receber 1 ou mais andamentos jurídicos técnicos do processo de um cliente "
            . "e deve gerar UMA mensagem CURTA de WhatsApp explicando o que aconteceu, em "
            . "linguagem que qualquer pessoa entende, sem jargão jurídico.\n\n"
            . "MOMENTO ATUAL: {$periodoDia}\n\n"
            . "FORMATO OBRIGATÓRIO DA MENSAGEM (padrão de assinatura do escritório — não fuja disso):\n"
            . "- Linha 1: EXATAMENTE '*_{$assinante}_*:' (nome em NEGRITO + ITÁLICO com underline, dois-pontos, NADA depois).\n"
            . "- Linha 2 em diante: QUEBRA DE LINHA e aí começa a saudação + o corpo da mensagem.\n"
            . "- VARIE a saudação a cada mensagem, combinando com o momento do dia informado acima. Exemplos válidos (repare na quebra de linha entre o cabeçalho e a saudação):\n"
            . "  * '*_{$assinante}_*:\nBom dia, {$primNome}!'\n"
            . "  * '*_{$assinante}_*:\nBoa tarde, {$primNome}!'\n"
            . "  * '*_{$assinante}_*:\nBoa noite, {$primNome}!'\n"
            . "  * '*_{$assinante}_*:\nOi, {$primNome}!' (informal)\n"
            . "  * '*_{$assinante}_*:\n{$primNome}, tudo bem?'\n"
            . "  NÃO comece sempre igual — varie a saudação. Mas a estrutura do cabeçalho é fixa.\n"
            . "- Depois da saudação, explique CADA andamento em 1 frase simples.\n"
            . "- Se for boa notícia (depósito em juízo, sentença favorável, acordo homologado), pode comemorar em tom leve — SEM afirmar o que ainda não aconteceu.\n"
            . "- Se for prazo/pendência do CLIENTE, deixe CLARO o que ele precisa fazer (e ate quando).\n"
            . "- NÃO coloque despedida no final ('Qualquer dúvida, estamos aqui', 'Um abraço', 'Atenciosamente', assinatura de novo). A mensagem termina após a explicação do andamento — o sistema APPENDA um bloco fixo depois com convite pra Central VIP.\n"
            . "- Formato WhatsApp (usa *negrito* pra destacar palavras-chave, quebra linhas curtas).\n"
            . "- Máximo 500 caracteres. Sem hashtag, no máximo 1 emoji quando fizer sentido natural.\n\n"
            . "REGRAS CRÍTICAS (não errar essas — comunicação sobre processo NÃO pode induzir cliente ao erro):\n"
            . "1. 'Parte autora' / 'exequente' / 'requerente' = o CLIENTE representado por NÓS. Quando o texto diz que 'a parte autora peticionou/juntou/informou/confirmou/manifestou/conferiu' — quem fez foi O ESCRITÓRIO atuando por ele, não o cliente pessoalmente. NUNCA escreva 'você confirmou', 'você peticionou', 'você juntou'. Prefira: 'nós juntamos', 'nós peticionamos', 'a advogada informou nos autos', ou apenas descreva o fato ('foi juntada nos autos a confirmação').\n"
            . "2. Depósito judicial NÃO é dinheiro na conta do cliente. Se o texto diz 'depósito efetuado', diga 'o valor foi depositado em juízo' — nunca 'você recebeu o dinheiro' nem 'em breve você recebe'. O cliente só recebe depois que o juiz autoriza o levantamento E o alvará é expedido/pago (pode levar semanas).\n"
            . "3. NUNCA AFIRME O QUE O JUIZ VAI FAZER. Ele PODE autorizar, negar, pedir esclarecimento, adiar. Você não sabe.\n"
            . "   ❌ ERRADO: 'agora os autos foram pro juiz pra autorizar você a sacar o dinheiro'\n"
            . "   ❌ ERRADO: 'agora o juiz vai autorizar você a sacar'\n"
            . "   ❌ ERRADO: 'em breve você recebe' / 'em breve você saca'\n"
            . "   ❌ ERRADO: 'assim que sair a autorização, avisamos' (assume que VAI sair autorização)\n"
            . "   ✅ CERTO: 'agora os autos foram pro juiz DECIDIR sobre a liberação do valor'\n"
            . "   ✅ CERTO: 'agora o juiz vai analisar o pedido de liberação do valor'\n"
            . "   ✅ CERTO: 'assim que sair decisão, avisamos'\n"
            . "4. 'Faço conclusos' / 'conclusos para decisão' = os autos foram enviados pro juiz analisar (o processo saiu do cartório e foi pra mesa do juiz). Traduza como 'os autos foram pro juiz analisar' ou 'o processo está com o juiz pra decisão'.\n"
            . "5. 'Extinção do cumprimento de sentença' = a fase de cobrança termina quando o cliente receber o valor. Traduza como 'a fase de cobrança será encerrada' ou 'o processo caminha pro encerramento dessa fase' — sem afirmar que já acabou.\n"
            . "6. NUNCA invente prazo, valor, data, ou fato que não esteja no texto. Se não tem no andamento, não escreva.\n"
            . "7. Se em dúvida entre afirmar algo forte ou algo suave, escolha o SUAVE. Melhor 'assim que sair decisão, avisamos' que 'em breve você recebe'.\n"
            . "8. Não use 'processual', 'sucumbência', 'ipsis literis', 'ex vi', 'exordial', 'litisconsorte', 'preclusão', 'requerido', 'requerente', 'exequente', 'executado', 'trânsito em julgado', 'consectários', 'conclusos', 'expedição de mandado', 'levantamento', 'alvará', 'sucumbência', 'expedição', 'certificação', 'homologação', 'deferir', 'indeferir'. Troque por palavras do dia a dia.";

    $blocoUltimas = '';
    if (!empty($ultimasMsgs)) {
        $blocoUltimas = "\n\nMENSAGENS ANTERIORES QUE VOCÊ JÁ ENVIOU PRO MESMO CLIENTE (varie a formulação, mude estrutura, use sinônimos, NUNCA repita frases):\n";
        foreach ($ultimasMsgs as $i => $m) {
            $blocoUltimas .= "\n[msg #" . ($i+1) . "]:\n" . mb_substr((string)$m, 0, 400, 'UTF-8') . "\n";
        }
    }

    $user = "Cliente: {$clientName}\nProcesso: {$caseTitle}\nAndamentos (do mais antigo pro mais recente):{$listaAnds}{$blocoUltimas}\n\nGere a mensagem seguindo TODAS as regras acima.";

    // Palavras absolutamente proibidas na saida — se aparecer, retry com temp
    // mais baixa. Se ainda persistir na 2a tentativa, DESCARTA (retorna null).
    // Isso e mais seguro que enviar msg com palavra ruim.
    $palavrasProibidas = '/autoriz|distribui[çc]|distribu[ií]d|distribu[ií]mos|distribu[ií]ram|distribuir|perda de prazo|prazo esgotado|fim de prazo|prazo perdido|preclus|deferi|indeferi|homolog|ju[íi]zo/i';

    // Similaridade: compara com mensagens anteriores (se veio) via similar_text.
    // > 60% e considerada repeticao — regenera.
    $limiarSimilar = 60.0;

    $tentativas = 0;
    $temp = 0.5;
    $txt = null;
    while ($tentativas < 5) {
        $tentativas++;
        $resp = ia_chamar(
            'aviso_cliente_andamento',
            'claude-haiku-4-5-20251001',
            $system,
            array(array('role' => 'user', 'content' => $user)),
            array('max_tokens' => 400, 'temperature' => $temp, 'bypass_killswitch' => true, 'bypass_user_whitelist' => true)
        );
        if (empty($resp['ok']) || empty($resp['texto'])) return null;
        $candidato = trim($resp['texto']);
        if (mb_strlen($candidato) > 900) $candidato = mb_substr($candidato, 0, 900) . '…';

        // Guard: IA saiu do personagem (pediu dado, fez pergunta)
        $ruins = array('/preciso de/i', '/voc[eê] tem/i', '/me envie/i', '/vou gerar a mensagem/i', '/como assistente/i');
        $descartada = false;
        foreach ($ruins as $rx) { if (preg_match($rx, $candidato)) { $descartada = true; break; } }
        if ($descartada) return null;

        // Guard: palavras proibidas → retry
        if (preg_match($palavrasProibidas, $candidato)) {
            $temp = max(0.1, $temp - 0.2);
            continue;
        }

        // Guard: tom de novidade quando modo != NOVIDADE — Amanda 17/07/2026.
        // Se e RELEMBRAR ou LONGA_ESPERA, a IA nao pode escrever "otima noticia",
        // "boa noticia", emojis comemorativos, etc. Retry com temp menor.
        if ($modo !== 'NOVIDADE') {
            $tomFestivo = '/[óo]tima not[íi]cia|boa not[íi]cia|excelente not[íi]cia|que not[íi]cia boa|🎉|🎊|🥳|🙌|✨|🚀/iu';
            if (preg_match($tomFestivo, $candidato)) {
                $temp = max(0.1, $temp - 0.2);
                continue;
            }
        }
        // Guard: modo LONGA_ESPERA precisa conter palavras-chave da estrutura
        // exigida (cartorio / ordem cronologica / espera / aguardar). Se nao
        // menciona nenhuma, a msg nao segue o roteiro — retry.
        if ($modo === 'LONGA_ESPERA') {
            $obrigatoriasLonga = '/cart[oó]rio|ordem cronol[oó]gica|espera|aguardar|acompanhando de perto|monitorando/iu';
            if (!preg_match($obrigatoriasLonga, $candidato)) {
                $temp = max(0.1, $temp - 0.2);
                continue;
            }
        }
        // Guard: modo RELEMBRAR precisa dizer que NAO tivemos atualizacao nova.
        if ($modo === 'RELEMBRAR') {
            $obrigatoriasRelembrar = '/(?:n[ãa]o|sem)\s+(?:tivemos|teve|houve|houve[uv])?\s*(?:nova|nenhuma|atualiza[çc][ãa]o|novidade)|relembrando|reforçando|continuamos acompanhando/iu';
            if (!preg_match($obrigatoriasRelembrar, $candidato)) {
                $temp = max(0.1, $temp - 0.2);
                continue;
            }
        }

        // Guard: similar a mensagem anterior → retry com temp maior (mais variacao)
        if (!empty($ultimasMsgs)) {
            $tooSimilar = false;
            foreach ($ultimasMsgs as $m) {
                $pct = 0;
                similar_text(mb_strtolower($candidato, 'UTF-8'), mb_strtolower((string)$m, 'UTF-8'), $pct);
                if ($pct >= $limiarSimilar) { $tooSimilar = true; break; }
            }
            if ($tooSimilar) {
                $temp = min(1.0, $temp + 0.25);
                continue;
            }
        }

        $txt = $candidato;
        break;
    }

    if (!$txt) return null;

    // Post-processing: forca formato exato do cabecalho (padrao FeS):
    // '*_Nome_*:\n' — negrito+italico com quebra de linha depois do dois-pontos.
    // Amanda 17/07/2026: prompt as vezes vinha 'Nome*:' ou sem quebra de linha.
    $assinantePreg = preg_quote($assinante, '/');
    // 1) Remove qualquer cabecalho antigo (variacoes de negrito) do inicio
    $txt = preg_replace('/^\s*\*+_*' . $assinantePreg . '_*\*+\s*:\s*/i', '', $txt);
    // 2) Prependa o cabecalho padrao + quebra de linha
    $txt = '*_' . $assinante . '_*:' . "\n" . ltrim($txt);

    // Append fixo — bloco convite Central VIP (nao gerado pela IA pra garantir
    // uniformidade + link correto). Amanda 17/07/2026 (redacao ajustada 17/07).
    $bloco = "\n\n---\n"
           . "*Dica:* 📱 Você sabia que também pode acompanhar tudo o que aconteceu no seu processo pelo sistema exclusivo do Ferreira & Sá?! "
           . "Não deixe de entrar sempre que tiver dúvidas! Isso vai agilizar seus atendimentos: "
           . "https://ferreiraesa.com.br/salavip";
    return $txt . $bloco;
}
