<?php
/**
 * core/functions_ia.php — Camada central do módulo de IA do Hub.
 *
 * Toda chamada à API Anthropic (Claude) passa por aqui pra:
 *  - Checar killswitch da feature
 *  - Checar whitelist de usuários autorizados
 *  - Logar tokens + custo estimado em ia_usage_log
 *  - Calcular custo em BRL
 *  - Capturar erro sem derrubar o fluxo
 *
 * NUNCA chame a API Anthropic diretamente em outras partes do código —
 * sempre via ia_chamar(). Isso garante telemetria, custo controlado e
 * possibilidade de desligar features individualmente.
 */

require_once __DIR__ . '/database.php';

// ─────────────────────────────────────────────────────────────
// Preços por modelo (USD por 1M tokens) — atualize conforme Anthropic
// muda a tabela. Cached input = preço com prompt caching hit.
// ─────────────────────────────────────────────────────────────
function ia_precos_modelo($modelo) {
    static $tabela = array(
        // Claude Haiku 4.5 — barato, rápido, bom pra classificação/resumo
        'claude-haiku-4-5'        => array('input' => 1.00, 'output' => 5.00,  'cached_input' => 0.10),
        'claude-haiku-4-5-20251001' => array('input' => 1.00, 'output' => 5.00,  'cached_input' => 0.10),
        // Claude Sonnet 4.6 — qualidade boa pra revisão jurídica, redação
        'claude-sonnet-4-6'       => array('input' => 3.00, 'output' => 15.00, 'cached_input' => 0.30),
        // Claude Opus 4.7 — top, usar só onde realmente importa
        'claude-opus-4-7'         => array('input' => 15.00, 'output' => 75.00, 'cached_input' => 1.50),
    );
    return isset($tabela[$modelo]) ? $tabela[$modelo] : $tabela['claude-haiku-4-5'];
}

/** True se a feature de IA está habilitada (killswitch em configuracoes). */
function ia_feature_ativa($feature) {
    static $cache = array();
    if (isset($cache[$feature])) return $cache[$feature];
    try {
        $st = db()->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
        $st->execute(array('ia_feature_' . $feature . '_enabled'));
        $v = $st->fetchColumn();
        return $cache[$feature] = ($v === '1' || $v === 1 || $v === true);
    } catch (Exception $e) { return $cache[$feature] = false; }
}

/** True se o usuário está na whitelist de quem pode disparar chamadas de IA. */
function ia_user_autorizado($userId) {
    if (!$userId) return false;
    try {
        $st = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'ia_users_autorizados'");
        $st->execute();
        $csv = (string)$st->fetchColumn();
        if ($csv === '') return false;
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv))));
        return in_array((int)$userId, $ids, true);
    } catch (Exception $e) { return false; }
}

/** Câmbio USD → BRL configurável (configuracoes.ia_cambio_brl, default 5.50). */
function ia_cambio_brl() {
    try {
        $st = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'ia_cambio_brl'");
        $st->execute();
        $v = (float)$st->fetchColumn();
        return $v > 0 ? $v : 5.50;
    } catch (Exception $e) { return 5.50; }
}

/** Calcula custo em USD a partir de tokens e modelo. */
function ia_calcular_custo_usd($modelo, $inputTokens, $outputTokens, $cachedInputTokens = 0) {
    $p = ia_precos_modelo($modelo);
    $nonCachedInput = max(0, (int)$inputTokens - (int)$cachedInputTokens);
    $custo = ($nonCachedInput * $p['input'] + (int)$cachedInputTokens * $p['cached_input'] + (int)$outputTokens * $p['output']) / 1000000.0;
    return round($custo, 6);
}

/** Loga a chamada em ia_usage_log. Idempotente (silencia erro). */
function ia_log_chamada($feature, $modelo, $userId, $inputTokens, $outputTokens, $cachedInputTokens, $duracaoMs, $status, $erro = null, $contexto = null) {
    try {
        $custoUsd = ia_calcular_custo_usd($modelo, $inputTokens, $outputTokens, $cachedInputTokens);
        $custoBrl = round($custoUsd * ia_cambio_brl(), 4);
        $st = db()->prepare(
            "INSERT INTO ia_usage_log
                (feature, modelo, user_id, input_tokens, output_tokens, cached_input_tokens, custo_usd, custo_brl, duracao_ms, status, erro, contexto, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?, NOW())"
        );
        $st->execute(array(
            $feature, $modelo, $userId ?: null,
            (int)$inputTokens, (int)$outputTokens, (int)$cachedInputTokens,
            $custoUsd, $custoBrl, (int)$duracaoMs,
            $status, $erro ? mb_substr((string)$erro, 0, 2000) : null,
            $contexto ? mb_substr((string)$contexto, 0, 200) : null,
        ));
    } catch (Exception $e) { /* nao bloqueia o fluxo principal */ }
}

/**
 * Chama a API Anthropic com telemetria + log.
 *
 * @param string $feature  Identificador da feature (ex: 'resumo_caso')
 * @param string $modelo   ID do modelo Anthropic (ex: 'claude-haiku-4-5')
 * @param string $system   System prompt (instruções de comportamento)
 * @param array  $messages Array de mensagens [['role'=>'user','content'=>'...']]
 * @param array  $opts     ['user_id'=>int, 'max_tokens'=>int, 'temperature'=>float,
 *                          'contexto'=>string, 'cache_system'=>bool (prompt caching)]
 * @return array ['ok'=>bool, 'texto'=>string|null, 'erro'=>string|null,
 *                'input_tokens'=>int, 'output_tokens'=>int, 'custo_brl'=>float]
 */
function ia_chamar($feature, $modelo, $system, $messages, $opts = array()) {
    $userId   = isset($opts['user_id']) ? (int)$opts['user_id'] : null;
    $maxTok   = isset($opts['max_tokens']) ? (int)$opts['max_tokens'] : 1024;
    $temp     = isset($opts['temperature']) ? (float)$opts['temperature'] : 0.3;
    $contexto = isset($opts['contexto']) ? (string)$opts['contexto'] : null;
    $cacheSys = !empty($opts['cache_system']);

    // 1) Killswitch da feature
    if (!ia_feature_ativa($feature)) {
        return array('ok' => false, 'erro' => 'Feature IA "' . $feature . '" desativada.', 'texto' => null,
                     'input_tokens' => 0, 'output_tokens' => 0, 'custo_brl' => 0);
    }
    // 2) Whitelist (se userId foi passado)
    if ($userId !== null && !ia_user_autorizado($userId)) {
        return array('ok' => false, 'erro' => 'Usuário não autorizado a usar IA.', 'texto' => null,
                     'input_tokens' => 0, 'output_tokens' => 0, 'custo_brl' => 0);
    }
    // 3) API key
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) {
        return array('ok' => false, 'erro' => 'ANTHROPIC_API_KEY não configurada.', 'texto' => null,
                     'input_tokens' => 0, 'output_tokens' => 0, 'custo_brl' => 0);
    }

    // Monta o payload
    $payload = array(
        'model'       => $modelo,
        'max_tokens'  => $maxTok,
        'temperature' => $temp,
        'messages'    => $messages,
    );
    // System prompt — com cache_control opcional pra prompt caching
    if ($cacheSys) {
        $payload['system'] = array(array('type' => 'text', 'text' => $system, 'cache_control' => array('type' => 'ephemeral')));
    } else {
        $payload['system'] = $system;
    }

    $headers = array(
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    );
    // Beta header pra prompt caching (libera o campo cache_control)
    if ($cacheSys) $headers[] = 'anthropic-beta: prompt-caching-2024-07-31';

    $t0 = microtime(true);
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $dur = (int)round((microtime(true) - $t0) * 1000);

    if ($body === false) {
        ia_log_chamada($feature, $modelo, $userId, 0, 0, 0, $dur, 'erro_curl', $curlErr, $contexto);
        return array('ok' => false, 'erro' => 'cURL: ' . $curlErr, 'texto' => null,
                     'input_tokens' => 0, 'output_tokens' => 0, 'custo_brl' => 0);
    }

    $j = json_decode($body, true);
    if ($code !== 200 || !is_array($j)) {
        $msg = is_array($j) && isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
        ia_log_chamada($feature, $modelo, $userId, 0, 0, 0, $dur, 'erro_http', $msg, $contexto);
        return array('ok' => false, 'erro' => 'API: ' . $msg, 'texto' => null,
                     'input_tokens' => 0, 'output_tokens' => 0, 'custo_brl' => 0);
    }

    $texto = '';
    if (!empty($j['content']) && is_array($j['content'])) {
        foreach ($j['content'] as $bloco) {
            if (isset($bloco['type']) && $bloco['type'] === 'text') $texto .= $bloco['text'];
        }
    }

    $u = isset($j['usage']) ? $j['usage'] : array();
    $inT  = (int)($u['input_tokens']               ?? 0);
    $outT = (int)($u['output_tokens']              ?? 0);
    $cInT = (int)($u['cache_read_input_tokens']    ?? 0);  // hits do cache
    $cWrT = (int)($u['cache_creation_input_tokens']?? 0);  // criação do cache (custa um pouco mais que input normal)
    // Pra simplificar, somamos cache_creation no input não-cached e cache_read em cached
    ia_log_chamada($feature, $modelo, $userId, $inT + $cWrT, $outT, $cInT, $dur, 'ok', null, $contexto);

    $custoUsd = ia_calcular_custo_usd($modelo, $inT + $cWrT, $outT, $cInT);
    return array(
        'ok'            => true,
        'texto'         => trim($texto),
        'erro'          => null,
        'input_tokens'  => $inT + $cWrT,
        'output_tokens' => $outT,
        'cached_tokens' => $cInT,
        'custo_brl'     => round($custoUsd * ia_cambio_brl(), 4),
    );
}

/** Resumo do gasto IA do mês corrente. Usado pelo dashboard e pelo alerta. */
function ia_gasto_mes_atual() {
    try {
        $st = db()->query("SELECT COALESCE(SUM(custo_brl),0) FROM ia_usage_log WHERE YEAR(created_at)=YEAR(NOW()) AND MONTH(created_at)=MONTH(NOW())");
        return round((float)$st->fetchColumn(), 2);
    } catch (Exception $e) { return 0.0; }
}

/**
 * Recalcula o score "esfriando" — todos os clientes ativos OU um cliente
 * específico (quando $clientId > 0). Mesmas regras do cron diário, SEM IA.
 *
 * @return array{processados:int, esfriando:int, atencao:int, ok:int, top:array}
 */
function ia_recalcular_esfriando_clientes(PDO $pdo, $clientId = 0) {
    if ($clientId > 0) {
        // Recalcula só 1 cliente — verifica se ainda está no universo ativo
        $st = $pdo->prepare(
            "SELECT DISTINCT c.id, c.name
               FROM clients c
               INNER JOIN cases cs ON cs.client_id = c.id
              WHERE c.id = ? AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado') AND cs.kanban_oculto = 0 AND COALESCE(cs.acompanhamento_externo, 0) = 0
              LIMIT 1"
        );
        $st->execute(array((int)$clientId));
    } else {
        $st = $pdo->query(
            "SELECT DISTINCT c.id, c.name
               FROM clients c
               INNER JOIN cases cs ON cs.client_id = c.id
              WHERE cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado') AND cs.kanban_oculto = 0 AND COALESCE(cs.acompanhamento_externo, 0) = 0"
        );
    }
    $clientes = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();

    $stUpd = $pdo->prepare("UPDATE clients SET esfriando_score = ?, esfriando_motivos = ?, esfriando_em = NOW() WHERE id = ?");
    $stMsg = $pdo->prepare("SELECT MAX(m.created_at) FROM zapi_mensagens m INNER JOIN zapi_conversas co ON co.id = m.conversa_id WHERE co.client_id = ?");
    // Andamento — pega data do andamento mais recente; se não houver, cai pra data de
    // distribuição/criação do case ativo (cliente cujo processo está parado desde sempre).
    // Sem esse fallback, processo sem andamento NUNCA pontuava (bug reportado pela Amanda).
    $stAnd = $pdo->prepare(
        "SELECT COALESCE(
             (SELECT MAX(ca.created_at) FROM case_andamentos ca
                INNER JOIN cases cs ON cs.id = ca.case_id
                WHERE cs.client_id = ?
                  AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                  AND COALESCE(cs.kanban_oculto,0) = 0
                  AND COALESCE(cs.acompanhamento_externo,0) = 0),
             (SELECT MAX(COALESCE(cs2.distribution_date, cs2.created_at)) FROM cases cs2
                WHERE cs2.client_id = ?
                  AND cs2.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado')
                  AND COALESCE(cs2.kanban_oculto,0) = 0
                  AND COALESCE(cs2.acompanhamento_externo,0) = 0)
         ) AS ult_movimento"
    );
    $stCob = $pdo->prepare("SELECT COUNT(*) FROM honorarios_cobranca h WHERE h.client_id = ? AND h.status NOT IN ('pago','cancelado') AND h.vencimento < DATE_SUB(CURDATE(), INTERVAL 5 DAY)");
    $stTar = $pdo->prepare("SELECT COUNT(*) FROM case_tasks t INNER JOIN cases cs ON cs.id = t.case_id WHERE cs.client_id = ? AND t.tipo IS NOT NULL AND t.status != 'concluido' AND t.due_date IS NOT NULL AND t.due_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)");

    $contagem = array('esfriando' => 0, 'atencao' => 0, 'ok' => 0);
    $top = array();

    foreach ($clientes as $c) {
        $score = 0;
        $motivosPontos = array();   // sinais que SOMAM pontos (decidem se aparece)
        $motivosInfo   = array();   // só contexto (não somam — aparecem como "(info)")

        // ── Sinais que SOMAM pontos (regra ajustada pela Amanda em 23/05/2026) ──
        // Só 2 sinais decidem se cliente aparece: WhatsApp parado E/OU processo parado.
        // Threshold subiu de 14d → 45d (msg) e 30d → 45d (andamento) — antes gerava
        // muito falso positivo (cliente com 8d sem msg + cobrança aparecia como atenção).

        // 1) WhatsApp parado há 45+ dias
        $stMsg->execute(array((int)$c['id']));
        $ultMsg = $stMsg->fetchColumn(); $stMsg->closeCursor();
        if ($ultMsg) {
            $diasMsg = (int)((time() - strtotime($ultMsg)) / 86400);
            if     ($diasMsg >= 90) { $score += 60; $motivosPontos[] = "Sem msg WhatsApp há {$diasMsg}d"; }
            elseif ($diasMsg >= 45) { $score += 40; $motivosPontos[] = "Sem msg WhatsApp há {$diasMsg}d"; }
        }
        // 2) Andamento no processo parado há 45+ dias (com fallback pra distribution_date
        //    quando não há andamento nenhum — captura processo parado desde sempre)
        $stAnd->execute(array((int)$c['id'], (int)$c['id']));
        $ultAnd = $stAnd->fetchColumn(); $stAnd->closeCursor();
        if ($ultAnd) {
            $diasAnd = (int)((time() - strtotime($ultAnd)) / 86400);
            if     ($diasAnd >= 90) { $score += 60; $motivosPontos[] = "Processo parado há {$diasAnd}d"; }
            elseif ($diasAnd >= 45) { $score += 40; $motivosPontos[] = "Processo parado há {$diasAnd}d"; }
        }

        // ── Sinais de contexto (só info — NÃO somam pontos, mas aparecem no card) ──
        // 3) Cobrança vencida
        $stCob->execute(array((int)$c['id']));
        $qtdCob = (int)$stCob->fetchColumn(); $stCob->closeCursor();
        if ($qtdCob > 0) { $motivosInfo[] = "{$qtdCob} cobrança(s) vencida(s) (info)"; }
        // 4) Tarefa atrasada
        $stTar->execute(array((int)$c['id']));
        $qtdTar = (int)$stTar->fetchColumn(); $stTar->closeCursor();
        if ($qtdTar > 0) { $motivosInfo[] = "{$qtdTar} tarefa(s) atrasada(s) (info)"; }

        if ($score > 100) $score = 100;
        // Junta motivos: pontos primeiro (relevantes), info depois (contexto)
        $todos = array_merge($motivosPontos, $motivosInfo);
        // Se score>0 (cliente vai aparecer), mostra TODOS os motivos. Senão limpa.
        $motivoStr = $score > 0 ? implode(' · ', $todos) : '';
        $stUpd->execute(array($score, $motivoStr, (int)$c['id']));

        // Novas faixas: ≥80 esfriando (2 sinais OU 1 sinal extremo), 40-79 atenção
        if      ($score >= 80) { $contagem['esfriando']++; $top[] = array('id' => $c['id'], 'name' => $c['name'], 'score' => $score, 'motivos' => $motivoStr); }
        elseif  ($score >= 40) { $contagem['atencao']++; }
        else                   { $contagem['ok']++; }
    }

    // Zera score de quem saiu do universo ativo (só no recalc global)
    if ($clientId <= 0) {
        $pdo->exec("UPDATE clients c
                    LEFT JOIN cases cs ON cs.client_id = c.id AND cs.status NOT IN ('arquivado','renunciamos','finalizado','concluido','cancelado') AND cs.kanban_oculto = 0 AND COALESCE(cs.acompanhamento_externo, 0) = 0
                    SET c.esfriando_score = 0, c.esfriando_motivos = NULL, c.esfriando_em = NOW()
                    WHERE c.esfriando_score > 0 AND cs.id IS NULL");
    }

    return array(
        'processados' => count($clientes),
        'esfriando'   => $contagem['esfriando'],
        'atencao'     => $contagem['atencao'],
        'ok'          => $contagem['ok'],
        'top'         => $top,
    );
}

/**
 * Gera (ou recupera do cache) o briefing diário personalizado de um usuário.
 * Cache em ia_briefings (1 por user_id + data). $forcar regenera.
 * Custo: ~R$ 0,05-0,10 por briefing. Modelo: Haiku.
 *
 * @return array{ok:bool, conteudo:?string, em:?string, cached:bool, erro:?string}
 */
function ia_gerar_briefing_usuario(PDO $pdo, $userId, $forcar = false) {
    $userId = (int)$userId;
    if ($userId <= 0) return array('ok' => false, 'erro' => 'user_id obrigatório', 'conteudo' => null, 'em' => null, 'cached' => false);

    // Self-heal
    try { $pdo->exec("CREATE TABLE IF NOT EXISTS ia_briefings (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        data DATE NOT NULL,
        conteudo MEDIUMTEXT NOT NULL,
        custo_brl DECIMAL(10,4) DEFAULT 0,
        gerado_em DATETIME NOT NULL,
        UNIQUE KEY uk_user_data (user_id, data),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

    if (!$forcar) {
        $st = $pdo->prepare("SELECT conteudo, gerado_em FROM ia_briefings WHERE user_id = ? AND data = CURDATE() LIMIT 1");
        $st->execute(array($userId));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return array('ok' => true, 'conteudo' => $row['conteudo'], 'em' => $row['gerado_em'], 'cached' => true, 'erro' => null);
    }

    // Pega nome + role do usuário
    $stU = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
    $stU->execute(array($userId));
    $usr = $stU->fetch(PDO::FETCH_ASSOC);
    if (!$usr) return array('ok' => false, 'erro' => 'usuário não encontrado', 'conteudo' => null, 'em' => null, 'cached' => false);

    // === Coleta de dados para o briefing ===
    $ctx = "Usuário: " . $usr['name'] . " (perfil " . $usr['role'] . ")\n";
    $ctx .= "Hoje: " . date('d/m/Y (l)') . " · " . date('H:i') . "\n\n";

    // Agenda de hoje
    try {
        $stAg = $pdo->prepare(
            "SELECT titulo, tipo, data_inicio, modalidade, local, cliente_presencial
             FROM agenda_eventos
             WHERE (responsavel_id = ? OR participantes_ids LIKE ?)
               AND DATE(data_inicio) = CURDATE() AND status NOT IN ('cancelado','realizado')
             ORDER BY data_inicio ASC LIMIT 10"
        );
        $stAg->execute(array($userId, '%"' . $userId . '"%'));
        $agenda = $stAg->fetchAll(PDO::FETCH_ASSOC);
        if ($agenda) {
            $ctx .= "📅 AGENDA DE HOJE:\n";
            foreach ($agenda as $e) {
                $hr = date('H:i', strtotime($e['data_inicio']));
                $cp = !empty($e['cliente_presencial']) ? ' [cliente comparece presencialmente]' : '';
                $ctx .= "  • {$hr} [{$e['tipo']}] {$e['titulo']}" . ($e['local'] ? " — {$e['local']}" : '') . $cp . "\n";
            }
            $ctx .= "\n";
        }
    } catch (Exception $e) {}

    // Prazos críticos (próximos 5 dias)
    try {
        $stPz = $pdo->prepare(
            "SELECT p.descricao_acao, p.prazo_fatal, cs.title, cs.id AS case_id
             FROM prazos_processuais p
             LEFT JOIN cases cs ON cs.id = p.case_id
             WHERE p.concluido = 0
               AND p.prazo_fatal BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 5 DAY)
               AND (cs.responsible_user_id = ? OR cs.responsible_user_id IS NULL)
             ORDER BY p.prazo_fatal ASC LIMIT 8"
        );
        $stPz->execute(array($userId));
        $prazos = $stPz->fetchAll(PDO::FETCH_ASSOC);
        if ($prazos) {
            $ctx .= "🚨 PRAZOS NOS PRÓXIMOS 5 DIAS:\n";
            foreach ($prazos as $p) {
                $dias = (int)((strtotime($p['prazo_fatal']) - strtotime(date('Y-m-d'))) / 86400);
                $when = $dias === 0 ? 'HOJE' : ($dias === 1 ? 'AMANHÃ' : 'em ' . $dias . 'd');
                $ctx .= "  • {$when} ({$p['prazo_fatal']}): {$p['descricao_acao']} — " . ($p['title'] ?? '?') . "\n";
            }
            $ctx .= "\n";
        }
    } catch (Exception $e) {}

    // Intimações pendentes (novidades pra revisar)
    try {
        $stI = $pdo->query(
            "SELECT cp.tipo_publicacao, cp.resumo_ia, cs.title, cp.data_disponibilizacao
             FROM case_publicacoes cp
             INNER JOIN cases cs ON cs.id = cp.case_id
             WHERE cp.status_prazo = 'pendente'
             ORDER BY cp.data_disponibilizacao DESC LIMIT 6"
        );
        $intim = $stI->fetchAll(PDO::FETCH_ASSOC);
        if ($intim) {
            $ctx .= "📢 INTIMAÇÕES PENDENTES NO ESCRITÓRIO:\n";
            foreach ($intim as $i) {
                $tx = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$i['resumo_ia'])), 0, 130);
                $ctx .= "  • {$i['data_disponibilizacao']} [{$i['tipo_publicacao']}] {$i['title']}: {$tx}\n";
            }
            $ctx .= "\n";
        }
    } catch (Exception $e) {}

    // Andamentos URGENTES (classificados pela IA na última varredura)
    try {
        $stU2 = $pdo->prepare(
            "SELECT ca.data_andamento, ca.descricao, cs.title
             FROM case_andamentos ca
             INNER JOIN cases cs ON cs.id = ca.case_id
             WHERE ca.urgencia_ia = 'urgente'
               AND ca.created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
               AND (cs.responsible_user_id = ? OR cs.responsible_user_id IS NULL)
             ORDER BY ca.created_at DESC LIMIT 6"
        );
        $stU2->execute(array($userId));
        $urgs = $stU2->fetchAll(PDO::FETCH_ASSOC);
        if ($urgs) {
            $ctx .= "🔴 ANDAMENTOS URGENTES (últimas 48h):\n";
            foreach ($urgs as $u) {
                $tx = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$u['descricao'])), 0, 130);
                $ctx .= "  • {$u['data_andamento']} {$u['title']}: {$tx}\n";
            }
            $ctx .= "\n";
        }
    } catch (Exception $e) {}

    // Clientes esfriando (só pra admin/gestão)
    if (in_array($usr['role'], array('admin','gestao'), true)) {
        try {
            $stE = $pdo->query(
                "SELECT name, esfriando_score, esfriando_motivos FROM clients
                 WHERE COALESCE(esfriando_score,0) >= 60
                   AND (esfriando_snooze_ate IS NULL OR esfriando_snooze_ate < CURDATE())
                 ORDER BY esfriando_score DESC LIMIT 5"
            );
            $esfri = $stE->fetchAll(PDO::FETCH_ASSOC);
            if ($esfri) {
                $ctx .= "❄️ CLIENTES EM RISCO DE PERDA (score >=60):\n";
                foreach ($esfri as $c) {
                    $ctx .= "  • {$c['name']} ({$c['esfriando_score']}pts): {$c['esfriando_motivos']}\n";
                }
                $ctx .= "\n";
            }
        } catch (Exception $e) {}
    }

    // Tarefas atrasadas
    try {
        $stT = $pdo->prepare(
            "SELECT t.title, t.due_date, cs.title AS case_title
             FROM case_tasks t
             INNER JOIN cases cs ON cs.id = t.case_id
             WHERE t.tipo IS NOT NULL AND t.status != 'concluido'
               AND t.due_date IS NOT NULL AND t.due_date < CURDATE()
               AND (t.assigned_to = ? OR FIND_IN_SET(?, t.assigned_extra_ids))
             ORDER BY t.due_date ASC LIMIT 8"
        );
        $stT->execute(array($userId, $userId));
        $tar = $stT->fetchAll(PDO::FETCH_ASSOC);
        if ($tar) {
            $ctx .= "📋 SUAS TAREFAS ATRASADAS:\n";
            foreach ($tar as $t) {
                $dias = (int)((time() - strtotime($t['due_date'])) / 86400);
                $ctx .= "  • atrasada {$dias}d: {$t['title']} ({$t['case_title']})\n";
            }
            $ctx .= "\n";
        }
    } catch (Exception $e) {}

    if (mb_strlen($ctx) < 200) {
        $ctx .= "(Sem eventos críticos detectados pra você hoje.)\n";
    }

    // === Prompt ===
    // Reforço anti-alucinação (Amanda 02/06/2026 - IA inventou "3 prazos vencidos 28/05" pra
    // clientes que nao existiam nos dados. Agora regras explicitas + temperature 0).
    $system = "Você é uma assistente jurídica do escritório Ferreira & Sá Advocacia. "
            . "Sua missão é dar um BRIEFING MATINAL PERSONALIZADO em até 5 bullets pra essa pessoa começar o dia já sabendo o que importa. "
            . "Receba o estado da agenda+prazos+intimações+andamentos urgentes+clientes em risco+tarefas atrasadas e produza:\n\n"
            . "FORMATO:\n"
            . "**Bom dia, {primeiroNome}!** Aqui está o que você precisa olhar hoje:\n\n"
            . "- 🔴/🟡/🟢/📅/📋 [bullet com ação direta + contexto curto. Use o emoji que melhor classifica a prioridade]\n"
            . "- ...\n\n"
            . "═══ REGRAS CRÍTICAS — LEIA COM ATENÇÃO ═══\n\n"
            . "1. ANTI-ALUCINAÇÃO (PRIORIDADE MÁXIMA):\n"
            . "   - **Cada nome, data, processo, valor, tipo de ação que você citar PRECISA aparecer LITERALMENTE no contexto fornecido**. \n"
            . "   - Se um item não está no contexto, ELE NÃO EXISTE. Não invente.\n"
            . "   - Não agregue itens parecidos numa frase só (ex: 'três prazos pra X, Y e Z') a menos que os TRÊS apareçam separados no contexto.\n"
            . "   - Não 'preencha lacunas' com nomes plausíveis. Não 'arredonde' datas. Não tente parecer útil inventando.\n"
            . "   - Se tiver dúvida se um item está no contexto, NÃO CITE. Prefira menos bullets verdadeiros do que mais bullets inventados.\n\n"
            . "2. DISTINÇÃO TAREFA × PRAZO PROCESSUAL:\n"
            . "   - PRAZO = só o que vem na seção '🚨 PRAZOS NOS PRÓXIMOS 5 DIAS' (prazos_processuais).\n"
            . "   - TAREFA = só o que vem na seção '📋 SUAS TAREFAS ATRASADAS' (case_tasks).\n"
            . "   - NUNCA chame tarefa de prazo nem vice-versa. NUNCA misture as duas seções num mesmo bullet.\n\n"
            . "3. FORMATAÇÃO:\n"
            . "   - Máximo 5 bullets, priorize por urgência (prazos vencendo > intimações > andamentos urgentes > tarefas atrasadas > esfriando).\n"
            . "   - Cada bullet em 1 frase de até 25 palavras.\n"
            . "   - Use o nome do cliente quando ajudar a identificar — COPIE EXATAMENTE como está no contexto.\n"
            . "   - Tom direto, profissional, sem floreio.\n"
            . "   - Se contexto está vazio ou só tem '(Sem eventos críticos...)', diga: 'Sua manhã está tranquila — sem prazos críticos nem intimações pendentes.'\n"
            . "   - NÃO repita 'urgente' em todo bullet — use só onde realmente é urgente.\n"
            . "   - Use markdown leve (**negrito** em nomes de cliente e prazos).";

    $r = ia_chamar(
        'briefing',
        'claude-haiku-4-5',
        $system,
        array(array('role' => 'user', 'content' => $ctx)),
        array(
            'user_id'      => $userId,
            'max_tokens'   => 600,
            'temperature'  => 0.0, // determinístico - reduz alucinação
            'contexto'     => 'briefing user#' . $userId,
            'cache_system' => true,
        )
    );

    if (!$r['ok']) return array('ok' => false, 'erro' => $r['erro'], 'conteudo' => null, 'em' => null, 'cached' => false);

    // Salva no cache
    try {
        $st = $pdo->prepare("INSERT INTO ia_briefings (user_id, data, conteudo, custo_brl, gerado_em) VALUES (?, CURDATE(), ?, ?, NOW()) ON DUPLICATE KEY UPDATE conteudo=VALUES(conteudo), custo_brl=VALUES(custo_brl), gerado_em=VALUES(gerado_em)");
        $st->execute(array($userId, $r['texto'], $r['custo_brl']));
    } catch (Exception $e) {}

    return array('ok' => true, 'conteudo' => $r['texto'], 'em' => date('Y-m-d H:i:s'), 'cached' => false, 'custo_brl' => $r['custo_brl'], 'erro' => null);
}

/**
 * Dispara recálculo do score de esfriando pra UM cliente após algum evento
 * que muda os sinais (msg enviada, andamento, tarefa concluída, cobrança paga).
 * Silencioso, fire-and-forget — NÃO bloqueia o fluxo principal se falhar.
 * Custo: ZERO (regras SQL puras, sem IA).
 */
function ia_disparar_recalc_esfriando(PDO $pdo, $clientId) {
    $clientId = (int)$clientId;
    if ($clientId <= 0) return;
    if (!ia_feature_ativa('cliente_esfriando')) return;
    try { ia_recalcular_esfriando_clientes($pdo, $clientId); }
    catch (Throwable $e) { /* nao bloqueia */ }
}

/** Orçamento mensal configurado (R$). */
function ia_orcamento_mes() {
    try {
        $st = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'ia_orcamento_mensal_reais'");
        $st->execute();
        return (float)$st->fetchColumn() ?: 300.0;
    } catch (Exception $e) { return 300.0; }
}

// ═══════════════════════════════════════════════════════════════════
//  FASE 3 — FEATURE 1: Tradução jurídico → leigo (Central VIP)
// ═══════════════════════════════════════════════════════════════════

/**
 * Traduz um andamento jurídico para linguagem comum (a cliente entende).
 * Cacheia o resultado em case_andamentos.traducao_leiga — cada andamento é
 * traduzido UMA VEZ. Próximas leituras vêm do banco sem custo.
 *
 * Self-heal da coluna na primeira chamada.
 *
 * @param int    $andamentoId  ID do registro em case_andamentos
 * @param string $descricao    Texto original do andamento (jurídico)
 * @param int    $userId       Usuario solicitante (0 = cliente da sala VIP)
 * @return array ['ok'=>bool, 'traducao'=>string, 'cached'=>bool, 'erro'=>?string]
 */
function ia_traduzir_andamento_leigo($andamentoId, $descricao, $userId = 0) {
    $pdo = db();
    $andamentoId = (int)$andamentoId;
    $descricao = trim((string)$descricao);

    // Self-heal
    try { $pdo->exec("ALTER TABLE case_andamentos ADD COLUMN traducao_leiga TEXT NULL"); } catch (Exception $e) {}

    if ($andamentoId <= 0 || $descricao === '') {
        return array('ok' => false, 'traducao' => '', 'cached' => false, 'erro' => 'Andamento ou descricao vazia');
    }

    // 1. Cache hit?
    try {
        $st = $pdo->prepare("SELECT traducao_leiga FROM case_andamentos WHERE id = ?");
        $st->execute(array($andamentoId));
        $existente = (string)$st->fetchColumn();
        if ($existente !== '') {
            return array('ok' => true, 'traducao' => $existente, 'cached' => true, 'erro' => null);
        }
    } catch (Exception $e) { /* tabela ainda sem a coluna, segue pra criacao */ }

    // 2. Killswitch — se feature desativada, devolve a propria descricao (graceful)
    if (!ia_feature_ativa('traducao_leiga')) {
        return array('ok' => false, 'traducao' => $descricao, 'cached' => false,
                     'erro' => 'Tradução por IA está desativada no momento.');
    }

    // 3. Chama IA (Haiku — barato, suficiente)
    $system = "Você é um tradutor jurídico que explica andamentos processuais para clientes leigos. "
            . "Regras:\n"
            . "1. Use português comum, sem juridiquês.\n"
            . "2. Seja curto (1-3 frases, máximo 200 caracteres).\n"
            . "3. Use voz ativa e tom acolhedor.\n"
            . "4. NÃO repita o texto original — explique o que aquilo significa na prática para o cliente.\n"
            . "5. NÃO dê conselho jurídico nem promessas. Só explique.\n"
            . "6. NÃO invente fatos: se o texto for ambíguo, fale só do que está claro.\n"
            . "7. Comece direto na explicação. Sem prefácio do tipo 'Isso significa que...'.";

    $userMsg = "Andamento processual:\n\n" . mb_substr($descricao, 0, 1500);

    // Cliente da sala VIP nao esta na whitelist de IA — passa null pra pular o check.
    // A trava aqui eh o killswitch da feature + cache (1 chamada por andamento, pra sempre).
    $userIdParam = $userId > 0 ? (int)$userId : null;
    $resp = ia_chamar(
        'traducao_leiga',
        'claude-haiku-4-5',
        $system,
        array(array('role' => 'user', 'content' => $userMsg)),
        array(
            'user_id'     => $userIdParam,
            'max_tokens'  => 300,
            'temperature' => 0.4,
            'contexto'    => 'andamento#' . $andamentoId,
        )
    );

    if (!$resp['ok']) {
        return array('ok' => false, 'traducao' => $descricao, 'cached' => false, 'erro' => $resp['erro']);
    }

    $traducao = trim((string)$resp['texto']);
    if ($traducao === '') {
        return array('ok' => false, 'traducao' => $descricao, 'cached' => false, 'erro' => 'IA retornou vazio');
    }

    // 4. Salva no cache
    try {
        $pdo->prepare("UPDATE case_andamentos SET traducao_leiga = ? WHERE id = ?")
            ->execute(array($traducao, $andamentoId));
    } catch (Exception $e) { /* falha de cache nao bloqueia retorno ao cliente */ }

    return array('ok' => true, 'traducao' => $traducao, 'cached' => false, 'erro' => null);
}

// ═══════════════════════════════════════════════════════════════════
//  FASE 3 — FEATURE 2: Revisão de petição por IA (Sonnet)
// ═══════════════════════════════════════════════════════════════════

/**
 * Pede pro Sonnet ler a petição gerada e apontar pontos fracos antes do
 * advogado enviar/imprimir. Aponta:
 *   • Argumento fraco ou pouco fundamentado
 *   • Citação legal faltando ou imprecisa
 *   • Ausência de jurisprudência relevante
 *   • Risco de preliminar / prescrição / decadência
 *   • Pedido mal formulado, conflito interno, contradição
 *
 * Tom: critico-mas-construtivo, com sugestoes concretas. NUNCA reescreve a
 * peca — so apresenta a critica (advogado decide se aceita).
 *
 * Modelo: Sonnet 4.6 (Haiku nao tem qualidade juridica suficiente).
 * Custo: ~R$ 0,30 por revisao.
 *
 * @param string $htmlPeticao  HTML da peticao gerada (vai ser limpo de tags)
 * @param string $tipoPeca     Ex: "Petição Inicial", "Contestação"
 * @param string $tipoAcao     Ex: "Alimentos", "Divórcio Consensual"
 * @param int    $userId       Usuario que solicitou
 * @return array ['ok'=>bool, 'analise'=>string (markdown), 'erro'=>?string,
 *                'custo_brl'=>float, 'tokens_in'=>int, 'tokens_out'=>int]
 */
function ia_revisar_peticao($htmlPeticao, $tipoPeca, $tipoAcao, $userId) {
    // 1. Killswitch
    if (!ia_feature_ativa('revisao_peticao')) {
        return array('ok' => false, 'analise' => '', 'erro' => 'Revisão por IA está desligada. Ative em Admin → IA.',
                     'custo_brl' => 0, 'tokens_in' => 0, 'tokens_out' => 0);
    }

    // 2. Limpa HTML pra texto puro (mantem quebras de linha)
    $texto = $htmlPeticao;
    $texto = preg_replace('/<br\s*\/?>/i', "\n", $texto);
    $texto = preg_replace('/<\/p>/i', "\n\n", $texto);
    $texto = preg_replace('/<\/li>/i', "\n", $texto);
    $texto = preg_replace('/<\/h[1-6]>/i', "\n\n", $texto);
    $texto = strip_tags($texto);
    $texto = preg_replace('/[ \t]+/', ' ', $texto);
    $texto = preg_replace("/\n{3,}/", "\n\n", $texto);
    $texto = trim($texto);

    if (mb_strlen($texto) < 80) {
        return array('ok' => false, 'analise' => '', 'erro' => 'Peticao muito curta pra revisao.',
                     'custo_brl' => 0, 'tokens_in' => 0, 'tokens_out' => 0);
    }
    // Trava de tamanho — Sonnet aguenta muito, mas peticao normal cabe em 20k chars
    if (mb_strlen($texto) > 60000) {
        $texto = mb_substr($texto, 0, 60000) . "\n\n[...peticao truncada para revisao...]";
    }

    $system = "Você é um(a) advogado(a) sênior brasileiro(a) revisando uma petição antes do protocolo.\n\n"
            . "Sua função é APONTAR PONTOS FRACOS e SUGERIR MELHORIAS — NÃO reescrever a peça.\n\n"
            . "Critique a partir destes ângulos (só mencione se houver problema real):\n"
            . "1. **Fundamentação fática**: faltam fatos? Há lacuna na narrativa? Algum fato sem prova mencionado?\n"
            . "2. **Fundamentação jurídica**: artigo de lei faltando, citado errado, ou genérico demais?\n"
            . "3. **Jurisprudência**: cabe alguma súmula/precedente do STJ/STF/TJ que reforce o pedido e não foi citado?\n"
            . "4. **Riscos processuais**: prescrição, decadência, ilegitimidade, falta de interesse, conexão, prevenção, valor da causa.\n"
            . "5. **Pedidos**: pedido principal claro? Tutela de urgência justificada? Honorários? Custas? Justiça gratuita?\n"
            . "6. **Coerência**: contradições internas, datas erradas, valores inconsistentes, partes confundidas.\n\n"
            . "FORMATO da resposta (markdown, máximo 600 palavras):\n"
            . "## 🚦 Avaliação geral\n"
            . "Uma frase. Tipo: 'Peça sólida, pequenos ajustes recomendados' ou 'Riscos importantes a corrigir antes do protocolo'.\n\n"
            . "## ⚠️ Pontos críticos\n"
            . "Lista de 1-5 itens DE VERDADE preocupantes. Se nao houver nenhum, escreva 'Nenhum ponto crítico identificado.' e siga.\n\n"
            . "## 💡 Sugestões de melhoria\n"
            . "Lista de 2-5 sugestões opcionais que tornariam a peça mais forte.\n\n"
            . "REGRAS DURAS:\n"
            . "- NÃO reescreva trechos da petição. Só aponte.\n"
            . "- NÃO invente jurisprudência: se mencionar súmula/REsp/precedente, tem que ser real (use só os que você TEM CERTEZA que existem).\n"
            . "- NÃO seja genérico ('poderia ter mais fundamentação'). Seja específico ('falta citar art. 1.694 do CC pra justificar o binômio necessidade-possibilidade').\n"
            . "- Se a peça está realmente boa, diga isso. Não invente problema pra preencher conteúdo.\n"
            . "- Direito brasileiro, vigência atual.";

    $userMsg = "Tipo de peça: " . $tipoPeca . "\n"
             . "Tipo de ação: " . $tipoAcao . "\n\n"
             . "PETIÇÃO A REVISAR:\n---\n" . $texto . "\n---";

    $resp = ia_chamar(
        'revisao_peticao',
        'claude-sonnet-4-6',
        $system,
        array(array('role' => 'user', 'content' => $userMsg)),
        array(
            'user_id'     => (int)$userId ?: null,
            'max_tokens'  => 1500,
            'temperature' => 0.3,
            'contexto'    => 'peticao(' . mb_substr($tipoPeca, 0, 40) . ')',
        )
    );

    if (!$resp['ok']) {
        return array('ok' => false, 'analise' => '', 'erro' => $resp['erro'],
                     'custo_brl' => 0, 'tokens_in' => 0, 'tokens_out' => 0);
    }

    return array(
        'ok'        => true,
        'analise'   => trim((string)$resp['texto']),
        'erro'      => null,
        'custo_brl' => (float)($resp['custo_brl'] ?? 0),
        'tokens_in' => (int)($resp['input_tokens'] ?? 0),
        'tokens_out'=> (int)($resp['output_tokens'] ?? 0),
    );
}

// ═══════════════════════════════════════════════════════════════════
//  FASE 3 — FEATURE 3: Sentiment WhatsApp (cliente irritado)
// ═══════════════════════════════════════════════════════════════════

/**
 * Lista de palavras-gatilho que indicam que a mensagem MERECE ser passada
 * pra IA classificar o tom. Filtro local pra economizar — 90% das msgs do
 * dia-a-dia ('oi', 'obrigado', 'ok') NEM chega na IA.
 *
 * Apenas substrings (matching case-insensitive sem acentos).
 */
function ia_sentiment_wa_gatilhos() {
    return array(
        // raiva direta
        'absurd', 'inaceitavel', 'ridicul', 'vergon', 'decepc', 'indignad',
        'descaso', 'humilha', 'palhac', 'enganara', 'engana ', 'enganou',
        // ameaca
        'procon', 'oab', 'reclama', 'processar voces', 'denunc', 'queixa',
        // cobranca urgente
        'cade', 'cadê', 'demora', 'atras', 'esperando', 'urgent', 'imediat',
        'ja era', 'já era', 'sem resposta', 'nao recebi', 'não recebi',
        // financeiro
        'devolver', 'estorn', 'reembolso', 'prejui', 'pagamento que',
        'paguei e', 'caro demais',
        // exit intent
        'cancelar', 'desistir', 'sair daqui', 'trocar de advog', 'outro advog',
    );
}

/**
 * Heuristica local: a mensagem dispara analise de IA?
 * Verdadeiro se contem >=1 palavra-gatilho OU se tem >=4 pontos de exclamacao
 * OU se tem palavras em CAIXA ALTA (ex: 'POR FAVOR', 'AGORA').
 *
 * NUNCA dispara em msgs muito curtas (<8 chars) ou sem texto.
 */
function ia_sentiment_wa_merece_analise($texto) {
    $t = trim((string)$texto);
    if (mb_strlen($t) < 8) return false;

    // Normaliza pra busca (lowercase + sem acentos basicos)
    $norm = mb_strtolower($t, 'UTF-8');
    $de = array('á','à','â','ã','ä','é','ê','è','í','î','ï','ó','ô','õ','ö','ú','û','ü','ç');
    $pa = array('a','a','a','a','a','e','e','e','i','i','i','o','o','o','o','u','u','u','c');
    $norm = str_replace($de, $pa, $norm);

    foreach (ia_sentiment_wa_gatilhos() as $g) {
        if (mb_strpos($norm, $g) !== false) return true;
    }

    // Sinais de raiva sem gatilho explicito
    if (substr_count($t, '!') >= 4) return true;
    // Palavras CAPS LOCK longas (3+ letras consecutivas em maiuscula que NAO seja sigla)
    if (preg_match('/\b[A-ZÁÉÍÓÚÂÊÔÃÕÇ]{6,}\b/u', $t)) return true;

    return false;
}

/**
 * Classifica o tom da mensagem do cliente. Retorna uma das categorias:
 *   - 'neutro'   : msg normal, nao precisa de alerta
 *   - 'cobranca' : cliente cobrando resposta/resolucao, sem agressividade
 *   - 'urgencia' : cliente com problema urgente / pediu socorro
 *   - 'raiva'    : cliente irritado, ameaca de cancelamento, PROCON, etc
 *
 * SEMPRE roda o filtro de gatilho antes — se filtro falha, retorna neutro
 * sem chamar IA (custo zero).
 *
 * @param string $texto       Mensagem do cliente
 * @param string $contexto    Contexto curto (ex: nome cliente, ult. msg do escritorio)
 * @return array ['categoria'=>string, 'razao'=>string, 'custo_brl'=>float, 'cached_hit'=>bool]
 */
function ia_sentiment_wa($texto, $contexto = '') {
    if (!ia_feature_ativa('sentiment_wa')) {
        return array('categoria' => 'neutro', 'razao' => 'feature desligada', 'custo_brl' => 0, 'cached_hit' => false);
    }

    // Filtro local — gratis e elimina 90% das msgs
    if (!ia_sentiment_wa_merece_analise($texto)) {
        return array('categoria' => 'neutro', 'razao' => 'sem sinal local', 'custo_brl' => 0, 'cached_hit' => true);
    }

    $system = "Você classifica o tom de uma mensagem que um CLIENTE enviou para um escritório de advocacia no WhatsApp.\n\n"
            . "Categorias possíveis (escolha UMA):\n"
            . "- neutro: mensagem normal, dúvida tranquila, agradecimento, confirmação.\n"
            . "- cobranca: cliente cobrando posição/resposta, ainda educado mas impaciente.\n"
            . "- urgencia: cliente com problema URGENTE (preso, intimação, prazo, acontecimento agora).\n"
            . "- raiva: cliente irritado, decepcionado, ameaçando cancelar/processar/PROCON/OAB.\n\n"
            . "Responda EXATAMENTE neste formato (uma linha):\n"
            . "CATEGORIA|razão curta em até 60 caracteres\n\n"
            . "Exemplos:\n"
            . "neutro|cumprimento e pergunta tranquila\n"
            . "cobranca|cliente cobrando retorno há vários dias\n"
            . "urgencia|cliente foi citado e precisa de orientação hoje\n"
            . "raiva|ameaça de PROCON e cancelamento de contrato\n\n"
            . "Seja CONSERVADOR: só classifique como raiva/urgencia se for CLARO. Na dúvida, use cobranca ou neutro.";

    $userMsg = ($contexto !== '' ? "Contexto: " . mb_substr($contexto, 0, 200) . "\n\n" : '')
             . "Mensagem do cliente:\n\"" . mb_substr($texto, 0, 800) . "\"";

    $resp = ia_chamar(
        'sentiment_wa',
        'claude-haiku-4-5',
        $system,
        array(array('role' => 'user', 'content' => $userMsg)),
        array(
            'user_id'     => null,
            'max_tokens'  => 60,
            'temperature' => 0.1,
            'contexto'    => 'msg_wa',
        )
    );

    if (!$resp['ok']) {
        return array('categoria' => 'neutro', 'razao' => 'erro IA: ' . $resp['erro'], 'custo_brl' => 0, 'cached_hit' => false);
    }

    $linha = trim((string)$resp['texto']);
    // Espera "categoria|razao"
    $partes = explode('|', $linha, 2);
    $cat = strtolower(trim($partes[0] ?? 'neutro'));
    if (!in_array($cat, array('neutro', 'cobranca', 'urgencia', 'raiva'), true)) $cat = 'neutro';
    $razao = trim($partes[1] ?? '');

    return array(
        'categoria'  => $cat,
        'razao'      => mb_substr($razao, 0, 80),
        'custo_brl'  => (float)($resp['custo_brl'] ?? 0),
        'cached_hit' => false,
    );
}
