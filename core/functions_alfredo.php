<?php
/**
 * Alfredo — Assistente automático por conversa (Amanda 17/07/2026).
 * Fase 1: aprovação obrigatória. Cliente manda msg em conversa com
 * alfredo_ativo=1 → cron gera sugestão → balão flutuante no chat pra
 * Naiara aprovar/editar/enviar. Msg aprovada vira exemplo few-shot pra
 * IA aprender o tom.
 *
 * Sem sinalização "sou IA" — msg sai como do Alfredo Neves normal.
 * Amanda decidiu (17/07): risco assumido.
 */

require_once __DIR__ . '/functions_ia.php';

function alfredo_self_heal($pdo) {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN alfredo_ativo TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN alfredo_ativado_em DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN alfredo_ativado_por INT NULL"); } catch (Exception $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS alfredo_sugestoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            conversa_id INT NOT NULL,
            msg_gatilho_id INT NULL,
            sugestao_texto TEXT NOT NULL,
            texto_enviado TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pendente',
            eh_sos TINYINT(1) NOT NULL DEFAULT 0,
            sos_resolvido_em DATETIME NULL,
            sos_resolvido_por INT NULL,
            aprovada_por INT NULL,
            aprovada_em DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (conversa_id, status),
            INDEX (status, created_at),
            INDEX (eh_sos, sos_resolvido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {}
    // Self-heal pra bases criadas antes desses campos
    try { $pdo->exec("ALTER TABLE alfredo_sugestoes ADD COLUMN eh_sos TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE alfredo_sugestoes ADD COLUMN sos_resolvido_em DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE alfredo_sugestoes ADD COLUMN sos_resolvido_por INT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("INSERT IGNORE INTO configuracoes (chave, valor) VALUES ('alfredo_ativo_global', '1')"); } catch (Exception $e) {}
}

/**
 * Gera sugestão de resposta pra mensagem do cliente.
 * $ctx = array com: conversa (row zapi_conversas), msg_cliente (texto), historico_msgs (array das ultimas 10),
 *                   client_id, case (row cases), andamentos_recentes (array), exemplos_aprovados (array texto)
 */
function alfredo_gerar_sugestao($ctx) {
    if (!defined('ANTHROPIC_API_KEY') || !ANTHROPIC_API_KEY) return null;

    $primNome = trim(explode(' ', (string)($ctx['client_name'] ?? ''))[0]) ?: 'você';
    $primNome = ucfirst(mb_strtolower($primNome, 'UTF-8'));

    $assinante = 'Alfredo Neves';
    $hora = (int)date('G');
    if     ($hora >= 5 && $hora < 12) $periodo = 'manhã (bom dia)';
    elseif ($hora < 18)                $periodo = 'tarde (boa tarde)';
    else                                $periodo = 'noite (boa noite)';

    // Bloco histórico da conversa (últimas 10 msgs)
    $histTxt = '';
    foreach ((array)$ctx['historico_msgs'] as $h) {
        $quem = $h['direcao'] === 'enviada' ? 'ESCRITÓRIO' : 'CLIENTE';
        $t = mb_substr(preg_replace('/\s+/', ' ', (string)$h['texto']), 0, 200, 'UTF-8');
        $histTxt .= "\n[{$quem}]: {$t}";
    }

    // Bloco caso + andamentos
    $caseTxt = '';
    if (!empty($ctx['case'])) {
        $c = $ctx['case'];
        $caseTxt = "PROCESSO: " . ($c['title'] ?? '?') . "\n";
        if (!empty($c['case_number'])) $caseTxt .= "CNJ: " . $c['case_number'] . "\n";
        if (!empty($c['status'])) $caseTxt .= "Status: " . $c['status'] . "\n";
    }
    $andTxt = '';
    foreach ((array)($ctx['andamentos_recentes'] ?? array()) as $a) {
        $d = $a['data_andamento'] ? date('d/m/Y', strtotime($a['data_andamento'])) : '';
        $desc = mb_substr(preg_replace('/\s+/', ' ', (string)$a['descricao']), 0, 300, 'UTF-8');
        $andTxt .= "\n- {$d}: {$desc}";
    }

    // Bloco few-shot com msgs aprovadas
    $ffTxt = '';
    foreach ((array)($ctx['exemplos_aprovados'] ?? array()) as $ex) {
        $ffTxt .= "\n--- exemplo aprovado ---\n" . mb_substr((string)$ex, 0, 500, 'UTF-8') . "\n";
    }

    $system = "⛔ REGRAS ABSOLUTAS:\n"
            . "1. NUNCA dê orientação jurídica pessoal ('vc tem direito a X', 'você deve fazer Y', 'melhor entrar com recurso').\n"
            . "2. NUNCA fale de valores, honorários, acordo.\n"
            . "3. NUNCA prometa resultado ('vamos ganhar', 'juiz vai autorizar', 'em breve resolve').\n"
            . "4. NUNCA use travessão '—'. Nem separador '---'. Use ponto ou vírgula.\n"
            . "5. PROIBIDO palavras: 'autorizar/autorização', 'distribuição/distribuir', 'perda de prazo', 'preclusão', 'deferir', 'juízo' (troque por 'conta do processo').\n"
            . "6. NUNCA INVENTE INFORMAÇÃO. Se você NÃO SABE a resposta com base no CONTEXTO fornecido, ou se cliente pediu algo que exige análise humana (orientação, negociação, reclamação séria, dúvida jurídica), NÃO ARRISQUE. Gere APENAS esta resposta padrão de SOS (nada mais, nada menos):\n"
            . "   '*_{$assinante}_*:\nOi, {$primNome}! Recebi sua mensagem. Vou verificar essa situação com a equipe e te retorno o mais rápido possível.'\n"
            . "   E adicione no fim (na sua saída, apenas UMA VEZ), como marcador escondido do sistema (não vai pro cliente):\n"
            . "   [[SOS]]\n"
            . "   Ao ver [[SOS]] o sistema alerta a equipe humana pra assumir a conversa. Use SEMPRE que houver dúvida.\n\n"

            . "PADRÃO DE MENSAGEM (obrigatório):\n"
            . "Linha 1: *_{$assinante}_*:\n"
            . "Linha 2 em diante: saudação + resposta.\n"
            . "Momento atual: {$periodo}. Cliente: {$primNome}.\n"
            . "Assinatura no fim NÃO. Bloco 'Dica' NÃO (sistema appenda depois).\n"
            . "Máximo 400 caracteres na sua saída (o bloco Dica que appendo tem mais 250).\n\n"

            . "SUA TAREFA: Você está simulando um atendente humano do escritório Ferreira & Sá que responde clientes no WhatsApp sobre o processo deles. Cliente acabou de escrever uma mensagem. Você gera UMA resposta apropriada, curta, empática, sem jargão jurídico, com base em:\n"
            . "- Contexto do caso\n"
            . "- Últimos andamentos\n"
            . "- Histórico da conversa (o que já foi conversado antes)\n"
            . "- Exemplos de tom aprovado do escritório (few-shot)\n\n"

            . ($ffTxt ? "EXEMPLOS DE TOM APROVADO DO ESCRITÓRIO (imita o padrão):\n{$ffTxt}\n\n" : "")

            . "═══════════════════════════════════════\n"
            . "CONTEXTO ATUAL:\n"
            . "═══════════════════════════════════════\n\n"

            . ($caseTxt ? "{$caseTxt}\n" : "SEM PROCESSO VINCULADO\n\n")
            . ($andTxt ? "ANDAMENTOS RECENTES DO PROCESSO:{$andTxt}\n\n" : "")

            . "HISTÓRICO DA CONVERSA (do mais antigo pro mais recente):{$histTxt}\n\n"

            . "ÚLTIMA MENSAGEM DO CLIENTE (que você deve responder):\n"
            . ">>> " . trim((string)$ctx['msg_cliente']);

    $user = "Gere a resposta agora, seguindo TODAS as regras.";

    $resp = ia_chamar(
        'alfredo_resposta',
        'claude-sonnet-4-5-20250929',
        $system,
        array(array('role' => 'user', 'content' => $user)),
        array('max_tokens' => 500, 'temperature' => 0.4, 'bypass_killswitch' => true, 'bypass_user_whitelist' => true)
    );
    if (empty($resp['ok']) || empty($resp['texto'])) return null;

    $txt = trim($resp['texto']);
    if (mb_strlen($txt) > 1200) $txt = mb_substr($txt, 0, 1200);

    // Detecta marcador SOS deixado pela IA quando nao tinha resposta segura.
    $ehSos = (bool)preg_match('/\[\[SOS\]\]/i', $txt);
    // Remove o marcador do texto (nao vai pro cliente)
    $txt = trim(preg_replace('/\[\[SOS\]\]/i', '', $txt));

    // Post-processing: mesmo do aviso_cliente
    // 1) Normaliza cabecalho
    $assinantePreg = preg_quote($assinante, '/');
    $txt = preg_replace('/^\s*\*+_*' . $assinantePreg . '_*\*+\s*:\s*/i', '', $txt);
    $txt = '*_' . $assinante . '_*:' . "\n" . ltrim($txt);
    // 2) Tira travessões e separador ---
    $txt = preg_replace('/\s*—\s*/', ', ', $txt);
    $txt = preg_replace('/\n---+\n/', "\n\n", $txt);
    // 3) Guard-rail de palavras proibidas — se escapar, ainda envia (nao vale
    //    descartar sugestao inteira, Naiara pode editar)
    return array('texto' => $txt, 'eh_sos' => $ehSos);
}

/**
 * Busca as N ultimas msgs APROVADAS/EDITADAS (few-shot).
 * Prioriza as editadas com o texto_enviado (versao final que a Naiara mandou).
 */
function alfredo_buscar_exemplos($pdo, $limit = 8) {
    try {
        $st = $pdo->prepare(
            "SELECT COALESCE(texto_enviado, sugestao_texto) AS msg
               FROM alfredo_sugestoes
              WHERE status IN ('aprovada', 'editada')
              ORDER BY aprovada_em DESC
              LIMIT ?"
        );
        $st->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $st->execute();
        $out = array();
        foreach ($st as $r) if (!empty($r['msg'])) $out[] = $r['msg'];
        return $out;
    } catch (Exception $e) { return array(); }
}

/**
 * Processa msgs pendentes: pra cada msg recebida em conversa com alfredo_ativo=1
 * sem sugestao ainda, gera sugestao.
 */
function alfredo_processar_pendentes($pdo, $limit = 5) {
    alfredo_self_heal($pdo);
    $out = array('processadas' => 0, 'geradas' => 0, 'erros' => 0, 'detalhes' => array());
    // Killswitch global
    $ativoGlobal = (string)$pdo->query("SELECT valor FROM configuracoes WHERE chave='alfredo_ativo_global'")->fetchColumn();
    if ($ativoGlobal !== '1') { $out['killswitch'] = true; return $out; }

    $st = $pdo->prepare(
        "SELECT m.id AS msg_id, m.conversa_id, m.conteudo AS msg_cliente, m.created_at,
                co.client_id, co.nome_contato, co.telefone
           FROM zapi_mensagens m
           JOIN zapi_conversas co ON co.id = m.conversa_id
          WHERE co.canal = '24'
            AND co.alfredo_ativo = 1
            AND m.direcao = 'recebida'
            AND m.created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            AND NOT EXISTS (
                SELECT 1 FROM alfredo_sugestoes s
                 WHERE s.conversa_id = m.conversa_id
                   AND s.msg_gatilho_id = m.id
            )
            AND NOT EXISTS (
                SELECT 1 FROM zapi_mensagens m2
                 WHERE m2.conversa_id = m.conversa_id
                   AND m2.direcao = 'enviada'
                   AND m2.created_at > m.created_at
            )
          ORDER BY m.id DESC
          LIMIT ?"
    );
    $st->bindValue(1, (int)$limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out['processadas'] = count($rows);
    if (!$rows) return $out;

    $exemplos = alfredo_buscar_exemplos($pdo, 8);

    foreach ($rows as $r) {
        try {
            // Histórico da conversa (últimas 10 msgs)
            $stH = $pdo->prepare("SELECT direcao, conteudo AS texto, created_at FROM zapi_mensagens
                                  WHERE conversa_id=? ORDER BY id DESC LIMIT 10");
            $stH->execute(array((int)$r['conversa_id']));
            $hist = array_reverse($stH->fetchAll(PDO::FETCH_ASSOC));

            // Case ativo do cliente
            $case = null; $ands = array();
            if (!empty($r['client_id'])) {
                $stC = $pdo->prepare("SELECT id, title, case_number, status FROM cases
                                      WHERE client_id=? AND status NOT IN ('arquivado','cancelado','renunciamos','concluido','finalizado')
                                      ORDER BY updated_at DESC LIMIT 1");
                $stC->execute(array((int)$r['client_id']));
                $case = $stC->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($case) {
                    $stA = $pdo->prepare("SELECT descricao, data_andamento FROM case_andamentos
                                          WHERE case_id=? AND COALESCE(visivel_cliente,0)=1
                                          ORDER BY data_andamento DESC LIMIT 5");
                    $stA->execute(array((int)$case['id']));
                    $ands = $stA->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            $ctx = array(
                'conversa_id' => (int)$r['conversa_id'],
                'client_id' => (int)$r['client_id'],
                'client_name' => $r['nome_contato'],
                'msg_cliente' => $r['msg_cliente'],
                'historico_msgs' => $hist,
                'case' => $case,
                'andamentos_recentes' => $ands,
                'exemplos_aprovados' => $exemplos,
            );

            $sug = alfredo_gerar_sugestao($ctx);
            if (!$sug || empty($sug['texto'])) { $out['erros']++; continue; }

            $pdo->prepare("INSERT INTO alfredo_sugestoes (conversa_id, msg_gatilho_id, sugestao_texto, status, eh_sos)
                           VALUES (?,?,?,?,?)")
                ->execute(array((int)$r['conversa_id'], (int)$r['msg_id'], $sug['texto'], 'pendente', $sug['eh_sos'] ? 1 : 0));
            $out['geradas']++;
            $tag = $sug['eh_sos'] ? ' 🚨 SOS' : '';
            $out['detalhes'][] = 'conv#' . $r['conversa_id'] . ': sugestao gerada' . $tag;
        } catch (Exception $e) {
            $out['erros']++;
        }
    }
    return $out;
}
