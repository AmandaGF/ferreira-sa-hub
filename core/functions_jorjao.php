<?php
/**
 * Ferreira & Sá Hub — Jorjão (expansão do sino de comemoração)
 *
 * Tocadas suportadas:
 *   1. contrato_assinado     — trigger no pipeline (JÁ existente, mantém)
 *   2. peticao_distribuida   — cron/jorjao_tick.php varre cases novos
 *   3. prazo_cumprido        — hook no UPDATE prazos_processuais.concluido=1
 *   4. novidade_hub          — botão manual em /admin/comemorar_contrato.php
 *   5. resumo_diario         — cron 19h, gera com Claude Haiku
 *
 * Cada tocada usa mesmo canal + grupo do WhatsApp (config unica).
 * Templates são variados: jorjao_templates.tocada tem N variações,
 * sistema sorteia uma na hora do envio (com anti-repetição imediata).
 *
 * Amanda 06/07/2026.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions_zapi.php';
require_once __DIR__ . '/functions_comemoracao.php'; // reusa comemoracao_get_config()

/**
 * Retorna canal + grupo do WhatsApp usados pelo Jorjão (mesmo do contrato).
 */
function jorjao_grupo_config() {
    $c = comemoracao_get_config();
    return array('canal' => $c['canal'], 'grupo_id' => $c['grupo_id']);
}

/**
 * Verifica se a tocada está ativa (killswitch em configuracoes).
 */
function jorjao_tocada_ativa($tocada) {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            $st = db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'jorjao_%_ativo'");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cache[$r['chave']] = (string)$r['valor'];
            }
        } catch (Exception $e) {}
    }
    $chave = 'jorjao_' . $tocada . '_ativo';
    return isset($cache[$chave]) && $cache[$chave] === '1';
}

/**
 * Sorteia um template ativo dessa tocada com anti-repetição imediata.
 * Prefere o menos usado recentemente (ORDER BY ultima_vez_usado ASC NULLS FIRST).
 * Retorna array ['id', 'template'] ou null.
 */
function jorjao_pick_template($tocada) {
    try {
        $pdo = db();
        $st = $pdo->prepare("SELECT id, template FROM jorjao_templates
                             WHERE tocada = ? AND ativo = 1
                             ORDER BY (ultima_vez_usado IS NULL) DESC, ultima_vez_usado ASC, RAND()
                             LIMIT 1");
        $st->execute(array($tocada));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Exception $e) { return null; }
}

/**
 * Marca o template como usado agora (pra rotacionar).
 */
function jorjao_marcar_usado($templateId) {
    try {
        db()->prepare("UPDATE jorjao_templates SET ultima_vez_usado = NOW() WHERE id = ?")
            ->execute(array($templateId));
    } catch (Exception $e) {}
}

/**
 * Substitui variáveis no template.
 * $vars é array associativo tipo ['cliente' => 'Maria', 'operacional' => 'Duda']
 * Substitui [chave] pelo valor. Chaves não fornecidas ficam como estão.
 */
function jorjao_render($template, $vars) {
    $rep = array();
    foreach ($vars as $k => $v) {
        $rep['[' . $k . ']'] = (string)$v;
    }
    return strtr($template, $rep);
}

/**
 * Envio genérico: pega template sorteado, aplica vars, manda no grupo.
 * $tocada: contrato_assinado|peticao_distribuida|prazo_cumprido|novidade_hub
 * $vars: array associativo com as variáveis do template.
 * Retorna ['ok'=>bool, 'erro'=>?, 'mensagem'=>?, 'template_id'=>?].
 */
function jorjao_enviar($tocada, $vars) {
    if (!jorjao_tocada_ativa($tocada)) {
        return array('ok' => false, 'erro' => 'Tocada desativada: ' . $tocada);
    }
    $g = jorjao_grupo_config();
    if (!$g['grupo_id']) return array('ok' => false, 'erro' => 'Grupo não configurado');
    if (!in_array($g['canal'], array('21','24'), true)) return array('ok' => false, 'erro' => 'Canal inválido');

    $tpl = jorjao_pick_template($tocada);
    if (!$tpl) return array('ok' => false, 'erro' => 'Nenhum template ativo pra tocada ' . $tocada);

    $mensagem = jorjao_render($tpl['template'], $vars);
    $r = zapi_send_text($g['canal'], $g['grupo_id'], $mensagem);

    if (!empty($r['ok'])) {
        jorjao_marcar_usado((int)$tpl['id']);
    }

    // Log leve (últimas 20 tentativas por tocada)
    try {
        $pdo = db();
        $chaveLog = 'jorjao_log_' . $tocada;
        $atual = json_decode((string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='" . $chaveLog . "'")->fetchColumn(), true) ?: array();
        array_unshift($atual, array(
            'em'   => date('Y-m-d H:i:s'),
            'ok'   => !empty($r['ok']),
            'tpl'  => (int)$tpl['id'],
            'erro' => $r['ok'] ? null : ($r['erro'] ?? 'HTTP ' . ($r['http_code'] ?? '?')),
            'ctx'  => array_slice($vars, 0, 3, true),
        ));
        $atual = array_slice($atual, 0, 20);
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
            ->execute(array($chaveLog, json_encode($atual, JSON_UNESCAPED_UNICODE)));
    } catch (Exception $e) {}

    return array(
        'ok'          => !empty($r['ok']),
        'erro'        => $r['ok'] ? null : ($r['erro'] ?? 'erro desconhecido'),
        'mensagem'    => $mensagem,
        'template_id' => (int)$tpl['id'],
    );
}

// ─────────────────────────────────────────────────────────
// HELPERS ESPECÍFICOS POR TOCADA
// ─────────────────────────────────────────────────────────

/**
 * Tocada 2: petição distribuída.
 * $case: array com chaves id, title, client_id, case_number, assigned_to (responsible_user_id)
 */
function jorjao_peticao_distribuida($case) {
    $vars = array();
    $vars['cliente']         = $case['client_name'] ?? 'Cliente';
    $vars['tipo_caso']       = $case['case_type'] ?? 'não informado';
    $vars['numero_processo'] = $case['case_number'] ?: 'sem CNJ ainda';
    $vars['hoje']            = date('d/m/Y');

    // Nome do operacional (responsável) — primeiro nome só
    $opNome = 'time operacional';
    if (!empty($case['responsible_user_id'])) {
        try {
            $st = db()->prepare("SELECT name FROM users WHERE id = ?");
            $st->execute(array((int)$case['responsible_user_id']));
            $n = (string)$st->fetchColumn();
            if ($n) {
                $parts = preg_split('/\s+/', $n);
                $opNome = $parts[0] ?: $n;
            }
        } catch (Exception $e) {}
    }
    $vars['operacional'] = $opNome;

    return jorjao_enviar('peticao_distribuida', $vars);
}

/**
 * Tocada 3: prazo cumprido.
 * $prazo: array com case_id, descricao_acao, numero_processo, prazo_fatal + client_id + usuario_id
 */
function jorjao_prazo_cumprido($prazo) {
    $vars = array();
    $vars['tipo_prazo'] = $prazo['descricao_acao'] ?? 'Prazo processual';
    $vars['processo']   = $prazo['numero_processo'] ?: '(sem CNJ)';
    $vars['hoje']       = date('d/m/Y');

    // Nome do cliente
    $clienteNome = 'cliente';
    if (!empty($prazo['client_id'])) {
        try {
            $st = db()->prepare("SELECT name FROM clients WHERE id = ?");
            $st->execute(array((int)$prazo['client_id']));
            $n = (string)$st->fetchColumn();
            if ($n) $clienteNome = $n;
        } catch (Exception $e) {}
    } elseif (!empty($prazo['case_id'])) {
        // Fallback: busca via case
        try {
            $st = db()->prepare("SELECT c.name FROM cases cs JOIN clients c ON c.id = cs.client_id WHERE cs.id = ?");
            $st->execute(array((int)$prazo['case_id']));
            $n = (string)$st->fetchColumn();
            if ($n) $clienteNome = $n;
        } catch (Exception $e) {}
    }
    $vars['cliente'] = $clienteNome;

    // Nome do operacional que cumpriu o prazo
    $opNome = 'time operacional';
    if (!empty($prazo['usuario_id'])) {
        try {
            $st = db()->prepare("SELECT name FROM users WHERE id = ?");
            $st->execute(array((int)$prazo['usuario_id']));
            $n = (string)$st->fetchColumn();
            if ($n) {
                $parts = preg_split('/\s+/', $n);
                $opNome = $parts[0] ?: $n;
            }
        } catch (Exception $e) {}
    }
    $vars['operacional'] = $opNome;

    return jorjao_enviar('prazo_cumprido', $vars);
}

/**
 * Wrapper: recebe o prazoId (que acabou de ser concluído) + userId de quem
 * concluiu, busca os dados e dispara o Jorjão. Use em CADA endpoint que
 * marca prazo como concluído. Silencioso — não faz o fluxo principal falhar.
 */
function jorjao_prazo_cumprido_by_id($prazoId, $usuarioId = null) {
    if (!$prazoId) return;
    if (!jorjao_tocada_ativa('prazo_cumprido')) return; // atalho cedo
    try {
        $st = db()->prepare("SELECT p.id, p.case_id, p.client_id, p.descricao_acao,
                                    p.numero_processo, p.prazo_fatal, p.usuario_id
                             FROM prazos_processuais p WHERE p.id = ?");
        $st->execute(array((int)$prazoId));
        $prazo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$prazo) return;
        // Prefere quem CUMPRIU (usuarioId da action) sobre quem CRIOU (prazo.usuario_id)
        if ($usuarioId) $prazo['usuario_id'] = (int)$usuarioId;
        jorjao_prazo_cumprido($prazo);
    } catch (Exception $e) {}
}

/**
 * Tocada 4: novidade no Hub (disparada manualmente pelo admin).
 */
function jorjao_novidade_hub($titulo, $descricao, $link) {
    $vars = array(
        'titulo'    => $titulo,
        'descricao' => $descricao,
        'link'      => $link ?: '(sem link)',
        'hoje'      => date('d/m/Y'),
    );
    return jorjao_enviar('novidade_hub', $vars);
}
