<?php
/**
 * Ferreira & Sá Hub — Bot IA do WhatsApp Comercial (DDD 21)
 * Usa Anthropic Claude Haiku 3.5 pra recepcionar clientes.
 */

/**
 * System prompt padrão (editável em Automações).
 */
function bot_ia_prompt_default() {
    return "Você é a assistente virtual do escritório Ferreira & Sá Advocacia Especializada, focado em Direito de Família, Consumidor e Cível.

Sua missão é recepcionar potenciais clientes com empatia, coletar informações básicas sobre o caso e preparar a conversa para a advogada humana dar seguimento.

REGRAS OBRIGATÓRIAS:
- NUNCA dê orientação jurídica específica (não diga se a pessoa tem direito, o que fazer no caso, valores, prazos legais etc.).
- NUNCA finja ser humana. Se perguntarem, diga que é a assistente virtual do escritório.
- Seja calorosa, empática e profissional. Use linguagem acolhedora e português brasileiro natural.
- Respostas curtas: no máximo 3 parágrafos curtos ou 4 linhas.
- Use o primeiro nome da pessoa quando souber.
- Objetivo da conversa: coletar (1) nome completo, (2) área do direito (família/consumidor/cível), (3) breve descrição do que precisa. Depois diga que a Dra. Amanda ou a equipe vai entrar em contato em breve.
- Não prometa prazos de retorno específicos, apenas 'em breve'.
- Se a pessoa pedir para falar com humano ou advogado, responda brevemente que vai transferir e está tudo bem (o sistema faz isso automaticamente).
- Se houver urgência real (violência, criança em risco, prisão, ameaça, emergência), diga que vai transferir imediatamente.

INFORMAÇÕES DO ESCRITÓRIO (use se perguntarem):
- Nome: Ferreira & Sá Advocacia Especializada
- Advogada responsável: Dra. Amanda Guedes Ferreira — OAB/RJ 163.260
- Endereço: Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom — Barra Mansa/RJ
- Site: ferreiraesa.com.br
- Horário presencial: segunda a sexta, 10h às 18h
- Atendimento remoto: disponível por este WhatsApp e pela Central VIP

ÁREAS DE ATUAÇÃO:
- Direito de Família (divórcio, guarda, pensão alimentícia, convivência, inventário)
- Direito do Consumidor
- Direito Cível em geral

Se a pessoa perguntar sobre outras áreas (criminal, trabalhista, tributário), diga educadamente que o escritório é especializado nessas três e ofereça indicação para um colega especializado.

Jamais use o termo 'menor' para criança ou adolescente — use 'criança' (até 12 anos) ou 'adolescente' (12-18 anos).";
}

/**
 * Verifica se a mensagem contém gatilhos de transferência imediata.
 */
function bot_ia_deve_transferir($msg) {
    if (!$msg) return false;
    $lower = mb_strtolower($msg, 'UTF-8');
    $gatilhos = array(
        'urgente', 'urgência', 'urgencia', 'emergência', 'emergencia',
        'violência', 'violencia', 'agressão', 'agressao', 'agrediu', 'apanhei',
        'ameaça', 'ameaca', 'ameaçando', 'ameacando',
        'preso', 'presa', 'detido', 'detida', 'cadeia',
        'criança em risco', 'crianca em risco', 'criança apanhou', 'crianca apanhou',
        'socorro', 'me ajuda', 'por favor ajuda',
        'falar com humano', 'humano', 'atendente', 'pessoa real', 'alguém de verdade',
        'advogada', 'advogado', 'amanda', 'dra amanda', 'dra. amanda',
        'falar com alguém', 'falar com alguem',
    );
    foreach ($gatilhos as $p) {
        if (mb_strpos($lower, $p) !== false) return true;
    }
    return false;
}

/**
 * Processa uma mensagem recebida. Se o bot estiver ativo nessa conversa,
 * chama Claude, envia resposta, detecta transferência.
 * @return bool true se processou, false se ignorou
 */
function bot_ia_processar($convId, $msgRecebida) {
    $pdo = db();

    // Config global
    if (zapi_auto_cfg('zapi_bot_ia_ativo', '0') !== '1') return false;

    // Busca conversa
    $stmt = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $stmt->execute(array($convId));
    $conv = $stmt->fetch();
    if (!$conv) return false;
    if (!$conv['bot_ativo']) return false;
    if ($conv['canal'] !== '21') return false; // Bot só no 21 por design
    // Pula se fora do horário (auto-fora-do-horário já cuida)
    if (zapi_fora_horario()) return false;

    // 1) Gatilho de transferência urgente?
    if (bot_ia_deve_transferir($msgRecebida)) {
        $pdo->prepare("UPDATE zapi_conversas SET bot_ativo = 0, status = 'aguardando' WHERE id = ?")->execute(array($convId));
        $msg = "Perfeitamente. Vou transferir você agora para nossa equipe humana. Um momento. 🙌";
        bot_ia_enviar_resposta($convId, $conv, $msg);
        return true;
    }

    // 2) Montar histórico (últimas 12 mensagens, mais antigas primeiro)
    $hist = $pdo->prepare("SELECT direcao, conteudo, enviado_por_bot FROM zapi_mensagens
                           WHERE conversa_id = ? AND conteudo != '' AND tipo = 'texto'
                           ORDER BY id DESC LIMIT 12");
    $hist->execute(array($convId));
    $rows = array_reverse($hist->fetchAll());

    $messages = array();
    foreach ($rows as $m) {
        $role = ($m['direcao'] === 'recebida') ? 'user' : 'assistant';
        $content = trim($m['conteudo']);
        if ($content === '') continue;
        // Consolida com último se mesma role (Claude precisa alternar)
        if (!empty($messages) && $messages[count($messages)-1]['role'] === $role) {
            $messages[count($messages)-1]['content'] .= "\n" . $content;
        } else {
            $messages[] = array('role' => $role, 'content' => $content);
        }
    }
    // Garantir que termina com 'user'
    if (empty($messages) || end($messages)['role'] !== 'user') {
        $messages[] = array('role' => 'user', 'content' => trim($msgRecebida));
    }

    // 3) System prompt (config ou default)
    $systemPrompt = zapi_auto_cfg('zapi_bot_ia_prompt', '');
    if (!$systemPrompt) $systemPrompt = bot_ia_prompt_default();

    // 4) Chamar Claude
    $resposta = bot_ia_chamar_claude($systemPrompt, $messages);
    if (!$resposta) {
        // Falhou — desativa bot e deixa pra humano
        $pdo->prepare("UPDATE zapi_conversas SET bot_ativo = 0 WHERE id = ?")->execute(array($convId));
        return false;
    }

    bot_ia_enviar_resposta($convId, $conv, $resposta);

    // Pós-processamento: verificar se a resposta indica que chegou a hora de transferir
    if (bot_ia_deve_transferir($resposta)) {
        $pdo->prepare("UPDATE zapi_conversas SET bot_ativo = 0, status = 'aguardando' WHERE id = ?")->execute(array($convId));
    }

    return true;
}

function bot_ia_chamar_claude($systemPrompt, $messages) {
    $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
    if (!$apiKey) return null;

    $body = array(
        'model'      => 'claude-haiku-4-5',
        'max_tokens' => 500,
        'system'     => $systemPrompt,
        'messages'   => $messages,
    );

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => array(
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ),
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_SSL_VERIFYPEER => false,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        @file_put_contents(APP_ROOT . '/files/bot_ia.log', '[' . date('Y-m-d H:i:s') . "] ERRO HTTP {$code}: " . substr($resp, 0, 500) . "\n", FILE_APPEND);
        return null;
    }
    $data = json_decode($resp, true);
    if (!isset($data['content'][0]['text'])) return null;
    return trim($data['content'][0]['text']);
}

function bot_ia_enviar_resposta($convId, $conv, $msg) {
    $pdo = db();
    $r = zapi_send_text($conv['canal'], $conv['telefone'], $msg);
    if (empty($r['ok'])) {
        @file_put_contents(APP_ROOT . '/files/bot_ia.log', '[' . date('Y-m-d H:i:s') . "] FALHA ENVIO conv={$convId} HTTP=" . ($r['http_code'] ?? '?') . "\n", FILE_APPEND);
        return;
    }
    $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, direcao, tipo, conteudo, enviado_por_bot, status)
                   VALUES (?, 'enviada', 'texto', ?, 1, 'enviada')")
        ->execute(array($convId, $msg));
    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW() WHERE id = ?")
        ->execute(array(mb_substr($msg, 0, 500), $convId));
}
