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
              WHERE c.id = ? AND cs.status NOT IN ('arquivado','renunciamos','finalizado') AND cs.kanban_oculto = 0
              LIMIT 1"
        );
        $st->execute(array((int)$clientId));
    } else {
        $st = $pdo->query(
            "SELECT DISTINCT c.id, c.name
               FROM clients c
               INNER JOIN cases cs ON cs.client_id = c.id
              WHERE cs.status NOT IN ('arquivado','renunciamos','finalizado') AND cs.kanban_oculto = 0"
        );
    }
    $clientes = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->closeCursor();

    $stUpd = $pdo->prepare("UPDATE clients SET esfriando_score = ?, esfriando_motivos = ?, esfriando_em = NOW() WHERE id = ?");
    $stMsg = $pdo->prepare("SELECT MAX(m.created_at) FROM zapi_mensagens m INNER JOIN zapi_conversas co ON co.id = m.conversa_id WHERE co.client_id = ?");
    $stAnd = $pdo->prepare("SELECT MAX(ca.created_at) FROM case_andamentos ca INNER JOIN cases cs ON cs.id = ca.case_id WHERE cs.client_id = ? AND cs.status NOT IN ('arquivado','renunciamos','finalizado')");
    $stCob = $pdo->prepare("SELECT COUNT(*) FROM honorarios_cobranca h WHERE h.client_id = ? AND h.status NOT IN ('pago','cancelado') AND h.vencimento < DATE_SUB(CURDATE(), INTERVAL 5 DAY)");
    $stTar = $pdo->prepare("SELECT COUNT(*) FROM case_tasks t INNER JOIN cases cs ON cs.id = t.case_id WHERE cs.client_id = ? AND t.tipo IS NOT NULL AND t.status != 'concluido' AND t.due_date IS NOT NULL AND t.due_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)");

    $contagem = array('esfriando' => 0, 'atencao' => 0, 'ok' => 0);
    $top = array();

    foreach ($clientes as $c) {
        $score = 0; $motivos = array();

        // 1) WhatsApp
        $stMsg->execute(array((int)$c['id']));
        $ultMsg = $stMsg->fetchColumn(); $stMsg->closeCursor();
        if (!$ultMsg) { $score += 10; $motivos[] = 'Sem conversa WhatsApp registrada'; }
        else {
            $diasMsg = (int)((time() - strtotime($ultMsg)) / 86400);
            if     ($diasMsg > 14) { $score += 30; $motivos[] = "Sem msg WhatsApp há {$diasMsg}d"; }
            elseif ($diasMsg > 7)  { $score += 10; $motivos[] = "Sem msg WhatsApp há {$diasMsg}d"; }
        }
        // 2) Andamento
        $stAnd->execute(array((int)$c['id']));
        $ultAnd = $stAnd->fetchColumn(); $stAnd->closeCursor();
        if ($ultAnd) {
            $diasAnd = (int)((time() - strtotime($ultAnd)) / 86400);
            if     ($diasAnd > 60) { $score += 30; $motivos[] = "Processo parado há {$diasAnd}d"; }
            elseif ($diasAnd > 30) { $score += 20; $motivos[] = "Processo parado há {$diasAnd}d"; }
        }
        // 3) Cobrança
        $stCob->execute(array((int)$c['id']));
        $qtdCob = (int)$stCob->fetchColumn(); $stCob->closeCursor();
        if ($qtdCob > 0) { $score += 20; $motivos[] = "{$qtdCob} cobrança(s) vencida(s)"; }
        // 4) Tarefa
        $stTar->execute(array((int)$c['id']));
        $qtdTar = (int)$stTar->fetchColumn(); $stTar->closeCursor();
        if ($qtdTar > 0) { $score += 15; $motivos[] = "{$qtdTar} tarefa(s) atrasada(s)"; }

        if ($score > 100) $score = 100;
        $motivoStr = $score > 0 ? implode(' · ', $motivos) : '';
        $stUpd->execute(array($score, $motivoStr, (int)$c['id']));

        if      ($score >= 60) { $contagem['esfriando']++; $top[] = array('id' => $c['id'], 'name' => $c['name'], 'score' => $score, 'motivos' => $motivoStr); }
        elseif  ($score >= 30) { $contagem['atencao']++; }
        else                   { $contagem['ok']++; }
    }

    // Zera score de quem saiu do universo ativo (só no recalc global)
    if ($clientId <= 0) {
        $pdo->exec("UPDATE clients c
                    LEFT JOIN cases cs ON cs.client_id = c.id AND cs.status NOT IN ('arquivado','renunciamos','finalizado') AND cs.kanban_oculto = 0
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

/** Orçamento mensal configurado (R$). */
function ia_orcamento_mes() {
    try {
        $st = db()->prepare("SELECT valor FROM configuracoes WHERE chave = 'ia_orcamento_mensal_reais'");
        $st->execute();
        return (float)$st->fetchColumn() ?: 300.0;
    } catch (Exception $e) { return 300.0; }
}
