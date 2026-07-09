<?php
/**
 * Ferreira & Sá Hub — Jorjão (expansão do sino de comemoração)
 *
 * Tocadas suportadas:
 *   1. contrato_assinado     — trigger no pipeline (JÁ existente, mantém)
 *   2. peticao_distribuida   — cron/jorjao_sinos.php varre cases novos
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
 * Verifica se a tocada está com modo IA ligado (config jorjao_{tocada}_modo_ia).
 */
function jorjao_modo_ia_ativo($tocada) {
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            $st = db()->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE 'jorjao_%_modo_ia'");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cache[$r['chave']] = (string)$r['valor'];
        } catch (Exception $e) {}
    }
    return ($cache['jorjao_' . $tocada . '_modo_ia'] ?? '0') === '1';
}

/**
 * Coleta rastros de trabalho recente no case (últimos N dias) pra IA deduzir
 * quem realmente trabalhou. Retorna string formatada pra prompt.
 * Amanda 07/07/2026: como o pessoal se ajuda, não dá pra confiar em
 * responsible_user_id — a IA vê os rastros e decide.
 */
function _jorjao_coletar_rastros_case($caseId, $limiteDias = 15) {
    if (!$caseId) return '';
    $partes = array();
    try {
        $pdo = db();
        // Andamentos recentes com autor
        $st = $pdo->prepare("SELECT a.data_andamento, a.descricao, u.name AS autor
                             FROM case_andamentos a
                             LEFT JOIN users u ON u.id = a.usuario_id
                             WHERE a.case_id = ? AND a.data_andamento >= DATE_SUB(NOW(), INTERVAL ? DAY)
                               AND a.usuario_id IS NOT NULL
                             ORDER BY a.data_andamento DESC LIMIT 12");
        $st->execute(array((int)$caseId, (int)$limiteDias));
        $ands = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($ands) {
            $linhas = array();
            foreach ($ands as $a) {
                $data = date('d/m', strtotime($a['data_andamento']));
                $autor = $a['autor'] ? (preg_split('/\s+/', $a['autor'])[0]) : 'sistema';
                $desc = mb_substr(preg_replace('/\s+/', ' ', (string)$a['descricao']), 0, 100);
                $linhas[] = "- {$data} · {$autor}: {$desc}";
            }
            $partes[] = "ANDAMENTOS RECENTES:\n" . implode("\n", $linhas);
        }
        // Tarefas concluídas recentemente
        $st = $pdo->prepare("SELECT ct.completed_at, ct.title, u.name AS autor
                             FROM case_tasks ct
                             LEFT JOIN users u ON u.id = ct.assigned_to
                             WHERE ct.case_id = ? AND ct.completed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                               AND ct.status = 'concluido'
                             ORDER BY ct.completed_at DESC LIMIT 8");
        $st->execute(array((int)$caseId, (int)$limiteDias));
        $tks = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($tks) {
            $linhas = array();
            foreach ($tks as $t) {
                $data = date('d/m', strtotime($t['completed_at']));
                $autor = $t['autor'] ? (preg_split('/\s+/', $t['autor'])[0]) : '?';
                $tit = mb_substr(preg_replace('/\s+/', ' ', (string)$t['title']), 0, 80);
                $linhas[] = "- {$data} · {$autor}: {$tit}";
            }
            $partes[] = "TAREFAS CONCLUÍDAS:\n" . implode("\n", $linhas);
        }
        // Comentários recentes (se tabela existe)
        try {
            $st = $pdo->prepare("SELECT cc.created_at, cc.comentario, u.name AS autor
                                 FROM case_comments cc
                                 LEFT JOIN users u ON u.id = cc.usuario_id
                                 WHERE cc.case_id = ? AND cc.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                                   AND cc.usuario_id IS NOT NULL
                                 ORDER BY cc.created_at DESC LIMIT 5");
            $st->execute(array((int)$caseId, (int)$limiteDias));
            $cs = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($cs) {
                $linhas = array();
                foreach ($cs as $c) {
                    $data = date('d/m', strtotime($c['created_at']));
                    $autor = $c['autor'] ? (preg_split('/\s+/', $c['autor'])[0]) : '?';
                    $com = mb_substr(preg_replace('/\s+/', ' ', (string)$c['comentario']), 0, 80);
                    $linhas[] = "- {$data} · {$autor}: {$com}";
                }
                $partes[] = "COMENTÁRIOS RECENTES:\n" . implode("\n", $linhas);
            }
        } catch (Exception $e) {}
    } catch (Exception $e) {}
    return $partes ? implode("\n\n", $partes) : '';
}

/**
 * Gera mensagem única via Claude Haiku no estilo do Jorjão.
 * Retorna string com a mensagem, ou null em caso de falha (chamador cai no template).
 */
function _jorjao_gerar_via_ia($tocada, $vars) {
    require_once __DIR__ . '/functions_ia.php';
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) return null;

    $system = <<<PROMPT
Você é o mascote do escritório Ferreira & Sá Advocacia — um senhor simpático,
brincalhão, que celebra vitórias do time no grupo do WhatsApp.

Você tem VÁRIOS apelidos que a equipe usa carinhosamente. Escolha UM DIFERENTE
a cada mensagem (variação é obrigatória — jamais repita o mesmo apelido em sequência):
- Jorjão
- O veio dos prazos
- Tio do Ferreira & Sá
- Seu Jorge
- Jorjinho
- Vovô Jorge
- O senhor dos autos
- Tio das causas

Você pode se autorreferenciar como esse apelido quando fizer sentido (não é obrigatório
em toda mensagem — só quando couber com naturalidade, tipo "É o veio dos prazos aqui, ó!").

PERSONALIDADE:
- Tio brincalhão, animador de festas, narrador de futebol.
- Gírias: "Bora!", "Craque!", "Fecha, campeão!", "Golaço!", "Preclusão que nada!".
- Emojis fartos: 🎉🔔🏆🚀⚖️💪🎯🥳🐻🔥.
- Brincadeiras com rotina jurídica ("Bata o martelo!", "Papel voando no PJe!").

SUA TAREFA: gerar UMA mensagem CURTA (3 a 6 linhas, máximo 400 caracteres) pra
postar no grupo WhatsApp do escritório celebrando o evento abaixo.

REGRAS:
- Use APENAS os dados fornecidos (não invente cliente, valor, tipo de caso).
- Comece com emoji + frase de impacto (ex: "🎉 GOLAÇO!" ou "⚖️ PETIÇÃO NO MUNDO!").
- Sobre CITAR NOMES: LEIA COM ATENÇÃO os "RASTROS DE TRABALHO" (se fornecidos).
  Se UMA pessoa se destacar claramente (várias ações recentes), cite ELA.
  Se o rastro for AMBÍGUO (várias pessoas com atividade parecida) ou VAZIO,
  NÃO invente nome — celebre o TIME OPERACIONAL, o time de peticionadores, o
  time que fez acontecer, etc. Prefira celebrar o time a errar o nome.
- Termine com uma frase animada e VARIE o apelido a cada mensagem.
- Formato WhatsApp: use *negrito* pra destaque, quebras de linha simples.
- Sem hashtags. Sem "clique aqui". Sem enrolação.
PROMPT;

    // Coleta rastros do case (se contexto tem case_id) — pra IA deduzir quem trabalhou
    $rastros = '';
    if (!empty($vars['_case_id'])) {
        $rastros = _jorjao_coletar_rastros_case((int)$vars['_case_id'], 15);
    }
    $blocoRastros = $rastros ? "\n\nRASTROS DE TRABALHO NO CASE (últimos 15 dias — deduza quem trabalhou):\n" . $rastros : '';

    // User prompt específico por tocada
    switch ($tocada) {
        case 'contrato_assinado':
            $userMsg = "EVENTO: Contrato assinado hoje ({$vars['hoje']}).\n"
                     . "Cliente: {$vars['cliente']}\n"
                     . "Tipo de caso: {$vars['tipo_caso']}\n"
                     . "Vendedor(a) que fechou (dado do sistema): {$vars['comercial']}\n"
                     . (isset($vars['valor']) && $vars['valor'] !== 'a combinar' ? "Valor: R$ {$vars['valor']}\n" : "")
                     . $blocoRastros
                     . "\nGere a mensagem celebrando esse contrato fechado.";
            break;
        case 'peticao_distribuida':
            $userMsg = "EVENTO: Petição inicial distribuída hoje ({$vars['hoje']}).\n"
                     . "Cliente: {$vars['cliente']}\n"
                     . "Tipo de caso: {$vars['tipo_caso']}\n"
                     . "Nº do processo: {$vars['numero_processo']}\n"
                     . "Responsável do case (dado do sistema, pode não bater com quem realmente distribuiu): {$vars['operacional']}\n"
                     . $blocoRastros
                     . "\nGere a mensagem celebrando essa petição distribuída.";
            break;
        case 'prazo_cumprido':
            $userMsg = "EVENTO: Prazo processual cumprido hoje ({$vars['hoje']}).\n"
                     . "Cliente: {$vars['cliente']}\n"
                     . "Tipo do prazo: {$vars['tipo_prazo']}\n"
                     . "Processo: {$vars['processo']}\n"
                     . "Responsável do case (dado do sistema, pode não bater com quem realmente cumpriu): {$vars['operacional']}\n"
                     . $blocoRastros
                     . "\nGere a mensagem parabenizando quem cumpriu o prazo (use rastros pra decidir se cita pessoa ou o time).";
            break;
        case 'novidade_hub':
            $userMsg = "EVENTO: Anúncio de novidade no Hub Conecta (sistema interno).\n"
                     . "Título: {$vars['titulo']}\n"
                     . "Descrição: {$vars['descricao']}\n"
                     . "Link do treinamento: {$vars['link']}\n"
                     . "\nGere a mensagem apresentando a novidade e pedindo pra galera fazer o treinamento (vale ponto no ranking).";
            break;
        default:
            return null;
    }

    $resp = ia_chamar(
        'jorjao_tocada',
        'claude-haiku-4-5-20251001',
        $system,
        array(array('role' => 'user', 'content' => $userMsg)),
        array('max_tokens' => 300, 'temperature' => 0.95, 'bypass_killswitch' => true, 'bypass_user_whitelist' => true)
    );

    if (empty($resp['ok']) || empty($resp['texto'])) return null;
    $txt = trim($resp['texto']);
    // Guarda contra IA devolver algo grande demais (não deveria com max_tokens=300)
    return mb_strlen($txt) > 800 ? mb_substr($txt, 0, 800) : $txt;
}

/**
 * Envio genérico: pega template sorteado (ou o especificado), aplica vars, manda no grupo.
 * $tocada: contrato_assinado|peticao_distribuida|prazo_cumprido|novidade_hub
 * $vars: array associativo com as variáveis do template.
 * $templateId: opcional. Se informado, usa essa variação específica em vez de sortear.
 *
 * Se a config jorjao_{tocada}_modo_ia estiver ligada, gera com Claude Haiku em vez
 * de sortear template. Se a IA falhar, cai no template (fallback seguro).
 *
 * Retorna ['ok'=>bool, 'erro'=>?, 'mensagem'=>?, 'template_id'=>?, 'via_ia'=>bool].
 */
function jorjao_enviar($tocada, $vars, $templateId = null) {
    if (!jorjao_tocada_ativa($tocada)) {
        return array('ok' => false, 'erro' => 'Tocada desativada: ' . $tocada);
    }
    $g = jorjao_grupo_config();
    if (!$g['grupo_id']) return array('ok' => false, 'erro' => 'Grupo não configurado');
    if (!in_array($g['canal'], array('21','24'), true)) return array('ok' => false, 'erro' => 'Canal inválido');

    $mensagem = null;
    $viaIa = false;
    $tpl = null;

    // 1) Se modo IA ligado E não foi especificada uma variação, tenta gerar via IA
    if (!$templateId && jorjao_modo_ia_ativo($tocada)) {
        $mensagem = _jorjao_gerar_via_ia($tocada, $vars);
        if ($mensagem) $viaIa = true;
    }

    // 2) Se IA falhou ou não estava ligada, usa template (sorteia ou usa o especificado)
    if (!$mensagem) {
        if ($templateId) {
            try {
                $st = db()->prepare("SELECT id, template FROM jorjao_templates WHERE id = ? AND tocada = ? AND ativo = 1");
                $st->execute(array((int)$templateId, $tocada));
                $tpl = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Exception $e) {}
        }
        if (!$tpl) $tpl = jorjao_pick_template($tocada);
        if (!$tpl) return array('ok' => false, 'erro' => 'Nenhum template ativo pra tocada ' . $tocada);
        $mensagem = jorjao_render($tpl['template'], $vars);
    }
    $r = zapi_send_text($g['canal'], $g['grupo_id'], $mensagem);

    if (!empty($r['ok']) && $tpl) {
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
            'tpl'  => $tpl ? (int)$tpl['id'] : 0,
            'via_ia' => $viaIa,
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
        'template_id' => $tpl ? (int)$tpl['id'] : null,
        'via_ia'      => $viaIa,
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
    $vars['_case_id']        = $case['id'] ?? null; // pra IA vasculhar rastros

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
    $vars['_case_id']   = $prazo['case_id'] ?? null; // pra IA vasculhar rastros

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

    // Amanda 07/07/2026: pra saber QUEM realmente cumpriu (não quem clicou pra marcar
    // concluído no Painel do Dia), busca em ordem de precisão:
    // 1) case_tasks.assigned_to da tarefa vinculada ao prazo (mais preciso — quem foi designado)
    // 2) cases.responsible_user_id do case (responsável pelo processo)
    // 3) prazo.usuario_id (fallback — quem criou/marcou como cumprido)
    // Cenário do bug: Amanda clica pra marcar concluído mas quem trabalhou foi a Carina.
    // A Carina é assigned_to da task OU responsible_user_id do case; Amanda só apertou o botão.
    $opNome = 'time operacional';
    $opUserId = null;
    if (!empty($prazo['case_id'])) {
        try {
            // 1) Tenta assigned_to da task vinculada ao prazo (title contém "PRAZO: {desc}")
            $desc = (string)($prazo['descricao_acao'] ?? '');
            if ($desc !== '') {
                $st = db()->prepare("SELECT assigned_to FROM case_tasks
                                     WHERE case_id = ? AND assigned_to IS NOT NULL
                                       AND title LIKE ?
                                     ORDER BY id DESC LIMIT 1");
                $st->execute(array((int)$prazo['case_id'], '%PRAZO: ' . $desc . '%'));
                $tid = (int)$st->fetchColumn();
                if ($tid > 0) $opUserId = $tid;
            }
            // 2) Se não achou por task, pega responsible_user_id do case
            if (!$opUserId) {
                $st = db()->prepare("SELECT responsible_user_id FROM cases WHERE id = ?");
                $st->execute(array((int)$prazo['case_id']));
                $rid = (int)$st->fetchColumn();
                if ($rid > 0) $opUserId = $rid;
            }
        } catch (Exception $e) {}
    }
    // 3) Fallback: usuario_id do prazo
    if (!$opUserId && !empty($prazo['usuario_id'])) {
        $opUserId = (int)$prazo['usuario_id'];
    }
    if ($opUserId) {
        try {
            $st = db()->prepare("SELECT name FROM users WHERE id = ?");
            $st->execute(array($opUserId));
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
        // Amanda 07/07/2026: NÃO sobrescreve com quem clicou — deixa
        // jorjao_prazo_cumprido() achar quem realmente fez o trabalho via
        // case_tasks.assigned_to → cases.responsible_user_id → usuario_id.
        // O param $usuarioId fica só como último fallback se nada mais achar.
        if ($usuarioId && empty($prazo['usuario_id'])) {
            $prazo['usuario_id'] = (int)$usuarioId;
        }
        jorjao_prazo_cumprido($prazo);
    } catch (Exception $e) {}
}

/**
 * Tocada 4: novidade no Hub (disparada manualmente pelo admin).
 * $templateId opcional — se informado, usa essa variação específica em vez de sortear.
 */
function jorjao_novidade_hub($titulo, $descricao, $link, $templateId = null) {
    $vars = array(
        'titulo'    => $titulo,
        'descricao' => $descricao,
        'link'      => $link ?: '(sem link)',
        'hoje'      => date('d/m/Y'),
    );
    return jorjao_enviar('novidade_hub', $vars, $templateId);
}
