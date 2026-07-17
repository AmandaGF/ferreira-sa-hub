<?php
/**
 * Ferreira & Sá Hub — API interna WhatsApp CRM
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_once APP_ROOT . '/core/functions_zapi.php';
require_once APP_ROOT . '/core/functions_ia.php';

header('Content-Type: application/json; charset=utf-8');

// ── Error handler global (24/Abr/2026) ──
// Garante que QUALQUER erro fatal ou exception devolve JSON, nunca HTML.
// Antes, alguns erros PHP escapavam como página <!DOCTYPE html>... quebrando
// o JSON.parse no frontend (erro "Unexpected token '<'").
set_exception_handler(function($e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    @error_log('[whatsapp/api.php EXCEPTION] ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(array(
        'error' => 'Erro interno: ' . $e->getMessage(),
        'where' => basename($e->getFile()) . ':' . $e->getLine(),
    ));
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR))) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        @error_log('[whatsapp/api.php FATAL] ' . $err['message'] . ' em ' . $err['file'] . ':' . $err['line']);
        echo json_encode(array(
            'error' => 'Erro fatal: ' . $err['message'],
            'where' => basename($err['file']) . ':' . $err['line'],
        ));
    }
});
$pdo = db();
$userId = current_user_id();
$action = $_REQUEST['action'] ?? '';

// Self-heal schema: colunas pra foto de perfil do contato (Z-API profile-picture)
try {
    $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN foto_perfil_url VARCHAR(500) DEFAULT NULL");
} catch (Exception $e) { /* coluna já existe */ }
try {
    $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN foto_perfil_atualizada DATETIME DEFAULT NULL");
} catch (Exception $e) { /* coluna já existe */ }
// Self-heal: colunas pra delegação de conversas
try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN delegada TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN delegada_por INT DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN delegada_em DATETIME DEFAULT NULL"); } catch (Exception $e) {}
// Self-heal: colunas pra reações a mensagens (emoji reaction)
try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN minha_reacao VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN reacao_cliente VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN reply_to_message_id VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
// Self-heal: wa_display_name em users (nome curto exibido nas mensagens WhatsApp)
try { $pdo->exec("ALTER TABLE users ADD COLUMN wa_display_name VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
// Self-heal: alias de telefone -> conversa (apos mesclar, registra os telefones
// antigos da origem apontando pro destino, pra impedir que o webhook RECRIE a
// conversa apagada quando o cliente manda msg pelo @lid (ou pelo telefone real,
// dependendo de qual conversa foi a origem da mesclagem).
// Bug reportado pela Amanda em 11/05/2026 — Naiara tentou mesclar conv da Lili
// 3x: visualmente funcionava, mas no proximo refresh as 2 convs reapareciam
// porque o webhook nao tinha como saber que aquele telefone ja tinha sido
// resolvido manualmente.
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_conversa_alias (
        alias_telefone VARCHAR(60) NOT NULL PRIMARY KEY,
        conversa_id INT NOT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_conv (conversa_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Amanda 12/06/2026: historico de perguntas IA por conversa
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_ia_perguntas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        conversa_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED DEFAULT NULL,
        pergunta TEXT NOT NULL,
        resposta TEXT,
        tokens INT UNSIGNED DEFAULT 0,
        custo_brl DECIMAL(10,4) DEFAULT 0,
        mensagens_analisadas INT UNSIGNED DEFAULT 0,
        modelo VARCHAR(50) DEFAULT 'claude-haiku-4-5',
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_conv (conversa_id, criado_em),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Self-heal: biblioteca de stickers compartilhada pela equipe
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS zapi_stickers (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        arquivo_path VARCHAR(255) NOT NULL,
        arquivo_mime VARCHAR(60) DEFAULT NULL,
        nome VARCHAR(100) DEFAULT NULL,
        tags VARCHAR(200) DEFAULT NULL,
        favorito TINYINT(1) NOT NULL DEFAULT 0,
        usos INT UNSIGNED NOT NULL DEFAULT 0,
        criado_por INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_favorito (favorito, usos)
    )");
} catch (Exception $e) {}

// CSRF só para ações que mutam
// ── Resumo da conversa WhatsApp por IA (pra abrir chamado com descrição pronta) ──
if (($_GET['action'] ?? $_POST['action'] ?? '') === 'resumir_conv_ia') {
    $action = 'resumir_conv_ia'; // pra log
    if (!ia_user_autorizado(current_user_id())) { echo json_encode(array('error' => 'Não autorizado a usar IA')); exit; }
    if (!ia_feature_ativa('resumo_wa_chamado')) { echo json_encode(array('error' => 'Feature desligada')); exit; }
    $convId = (int)($_POST['conversa_id'] ?? $_GET['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    try {
        $stC = db()->prepare("SELECT co.id, co.canal, co.telefone, co.nome_contato, co.client_id, c.name AS client_name
                              FROM zapi_conversas co LEFT JOIN clients c ON c.id = co.client_id WHERE co.id = ? LIMIT 1");
        $stC->execute(array($convId));
        $conv = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

        // BUG fix 26/05/2026: 'created_at' sem alias era ambíguo (zapi_mensagens.created_at
        // e users.created_at ambos existem). Qualificar com m.created_at.
        $stM = db()->prepare(
            "SELECT m.direcao, m.tipo, m.conteudo, m.created_at, u.name AS quem
             FROM zapi_mensagens m LEFT JOIN users u ON u.id = m.enviado_por_id
             WHERE m.conversa_id = ? AND m.conteudo IS NOT NULL AND m.conteudo != ''
             ORDER BY m.created_at DESC LIMIT 30"
        );
        $stM->execute(array($convId));
        $msgs = array_reverse($stM->fetchAll(PDO::FETCH_ASSOC));

        if (!$msgs) { echo json_encode(array('error' => 'Conversa sem mensagens')); exit; }

        $nomeCli = $conv['nome_contato'] ?: ($conv['client_name'] ?: 'Cliente');
        $ctx = "Cliente: " . $nomeCli . "\n";
        $ctx .= "Canal: WhatsApp " . ($conv['canal'] === '21' ? 'Comercial (21)' : 'CX (24)') . "\n";
        $ctx .= "Telefone: " . ($conv['telefone'] ?: '?') . "\n\n";
        $ctx .= "CONVERSA (cronológica):\n";
        foreach ($msgs as $m) {
            $autor = $m['direcao'] === 'enviada' ? ($m['quem'] ?: 'Atendente') : $nomeCli;
            $tx = trim(preg_replace('/\s+/', ' ', (string)$m['conteudo']));
            if (mb_strlen($tx) > 350) $tx = mb_substr($tx, 0, 350) . '…';
            $ctx .= "[" . date('d/m H:i', strtotime($m['created_at'])) . "] {$autor}: {$tx}\n";
        }

        $system = "Você é uma assistente do escritório Ferreira & Sá Advocacia. Vai receber a conversa WhatsApp "
                . "entre um atendente e um cliente, e deve produzir uma DESCRIÇÃO DE CHAMADO em texto corrido, "
                . "no formato exato abaixo, pra ser colada num sistema interno de helpdesk:\n\n"
                . "**Assunto:** [1 frase resumindo o que o cliente quer/relata]\n\n"
                . "**Resumo da conversa:**\n[2-4 frases corridas explicando o que aconteceu, sem listar cada msg. Quem disse o quê de relevante, decisões tomadas, prazos prometidos.]\n\n"
                . "**Pendência / próxima ação:** [o que precisa ser feito agora pelo escritório — 1 frase clara]\n\n"
                . "**Citação relevante:** [só se houver — uma fala IPSIS LITTERIS do cliente que importa, entre aspas]\n\n"
                . "REGRAS:\n"
                . "- Tom profissional, direto, em português brasileiro.\n"
                . "- Foco na ÚLTIMA situação não resolvida da conversa.\n"
                . "- Se o cliente está estressado/agressivo, sinalize com cuidado no Assunto.\n"
                . "- Se a conversa não tem pendência clara, escreva 'Sem ação pendente — registrar pra histórico'.\n"
                . "- Máximo 200 palavras no total.";

        $r = ia_chamar(
            'resumo_wa_chamado',
            'claude-haiku-4-5',
            $system,
            array(array('role' => 'user', 'content' => $ctx)),
            array(
                'user_id'      => current_user_id(),
                'max_tokens'   => 500,
                'temperature'  => 0.3,
                'contexto'     => 'conv#' . $convId,
                'cache_system' => true,
            )
        );

        if (!$r['ok']) { echo json_encode(array('error' => $r['erro'] ?: 'Falha IA')); exit; }

        // Acrescenta no fim o link da conversa pro contexto humano completo
        $base = function_exists('url') ? url('') : '';
        $rodape = "\n\n— — —\n💬 Link do chat: " . rtrim($base, '/') . "/modules/whatsapp/?canal=" . $conv['canal'] . "&abrir=" . $convId;

        @audit_log('IA_RESUMO_CONV_WA', 'zapi_conversas', $convId, 'tokens=' . $r['input_tokens'] . '/' . $r['output_tokens']);
        echo json_encode(array(
            'ok'         => true,
            'texto'      => $r['texto'] . $rodape,
            'custo_brl'  => $r['custo_brl'],
            'tokens'     => $r['input_tokens'] + $r['output_tokens'],
            'cliente'    => $nomeCli,
            'client_id'  => (int)($conv['client_id'] ?? 0),
            'telefone'   => $conv['telefone'],
            'canal'      => $conv['canal'],
        ));
    } catch (Throwable $e) {
        echo json_encode(array('error' => 'Erro: ' . $e->getMessage()));
    }
    exit;
}

// ── Amanda 11/06/2026: pergunta livre à IA sobre o conteúdo da conversa ──
// Ex: 'a cliente preencheu o formulario de gastos?', 'que documentos faltam?',
// 'ela informou o CPF do filho?', etc. A IA varre TODAS as mensagens (incluindo
// transcricoes de audio) e responde em texto curto e direto.
if (($_GET['action'] ?? $_POST['action'] ?? '') === 'perguntar_ia_chat') {
    $action = 'perguntar_ia_chat';
    // Amanda 11/06/2026: aumenta timeout e ignora desconexao do cliente pra
    // chamada IA terminar mesmo se o navegador desistir.
    @set_time_limit(180);
    @ignore_user_abort(true);
    header('Content-Type: application/json; charset=utf-8');

    if (!ia_user_autorizado(current_user_id())) { echo json_encode(array('error' => 'Não autorizado a usar IA')); exit; }
    $convId = (int)($_POST['conversa_id'] ?? $_GET['conversa_id'] ?? 0);
    $pergunta = trim((string)($_POST['pergunta'] ?? $_GET['pergunta'] ?? ''));
    if (!$convId)            { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    if (mb_strlen($pergunta) < 4) { echo json_encode(array('error' => 'Pergunta muito curta')); exit; }
    if (mb_strlen($pergunta) > 500) $pergunta = mb_substr($pergunta, 0, 500, 'UTF-8');

    try {
        $stC = db()->prepare("SELECT co.id, co.canal, co.telefone, co.nome_contato, co.client_id, c.name AS client_name
                              FROM zapi_conversas co LEFT JOIN clients c ON c.id = co.client_id WHERE co.id = ? LIMIT 1");
        $stC->execute(array($convId));
        $conv = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

        // Puxa as ultimas 400 msgs (texto + transcricao de audio + tipo de midia).
        // 400 cobre conversas longas sem estourar contexto do Haiku 4.5 (200k).
        $stM = db()->prepare(
            "SELECT m.direcao, m.tipo, m.conteudo, m.arquivo_nome, m.transcricao, m.created_at, u.name AS quem
             FROM zapi_mensagens m LEFT JOIN users u ON u.id = m.enviado_por_id
             WHERE m.conversa_id = ?
               AND (m.status IS NULL OR m.status != 'deletada')
             ORDER BY m.created_at DESC LIMIT 400"
        );
        $stM->execute(array($convId));
        $msgs = array_reverse($stM->fetchAll(PDO::FETCH_ASSOC));
        if (!$msgs) { echo json_encode(array('error' => 'Conversa sem mensagens')); exit; }

        $nomeCli = $conv['nome_contato'] ?: ($conv['client_name'] ?: 'Cliente');
        $ctx  = "Cliente: " . $nomeCli . "\n";
        $ctx .= "Canal: WhatsApp " . ($conv['canal'] === '21' ? 'Comercial (21)' : 'CX (24)') . "\n";
        $ctx .= "Total de mensagens: " . count($msgs) . "\n\n";
        $ctx .= "HISTÓRICO COMPLETO (cronológico, mais antigas primeiro):\n";
        foreach ($msgs as $m) {
            $autor = $m['direcao'] === 'enviada' ? ($m['quem'] ? 'Equipe (' . $m['quem'] . ')' : 'Equipe') : $nomeCli;
            $ts    = date('d/m/Y H:i', strtotime($m['created_at']));
            $tipo  = $m['tipo'] ?: 'texto';
            $conteudoBruto = trim((string)$m['conteudo']);

            // Para audio/imagem/video/documento sem texto util, mostra o tipo + nome de arquivo + transcricao se houver
            if (in_array($tipo, array('audio','imagem','video','documento','sticker'), true)) {
                $linha = "[$tipo";
                if (!empty($m['arquivo_nome'])) $linha .= ': ' . $m['arquivo_nome'];
                $linha .= ']';
                if (!empty($m['transcricao'])) $linha .= ' (transcricao: ' . trim($m['transcricao']) . ')';
                if ($conteudoBruto && $conteudoBruto !== '[' . $tipo . ']') $linha .= ' ' . $conteudoBruto;
                $tx = $linha;
            } else {
                $tx = $conteudoBruto;
            }

            $tx = preg_replace('/\s+/', ' ', $tx);
            if (mb_strlen($tx) > 600) $tx = mb_substr($tx, 0, 600, 'UTF-8') . '…';
            $ctx .= "[$ts] $autor: $tx\n";
        }

        $system = "Você é uma assistente jurídica do escritório Ferreira & Sá Advocacia. "
                . "Vai receber o histórico COMPLETO de uma conversa WhatsApp entre a equipe e um cliente, "
                . "e uma pergunta da advogada Amanda. Você deve responder a pergunta baseando-se EXCLUSIVAMENTE "
                . "nas mensagens do histórico — NÃO invente, NÃO suponha.\n\n"
                . "REGRAS:\n"
                . "- Resposta direta, em 1-3 frases curtas. Português brasileiro.\n"
                . "- Cite a DATA da mensagem relevante quando possível (formato dd/mm).\n"
                . "- Se houver citação textual importante, coloque entre aspas.\n"
                . "- Se a resposta NÃO ESTÁ no histórico, diga claramente: 'Não encontrei essa informação na conversa.'\n"
                . "- Se a resposta for parcial (cliente prometeu mas não enviou), diga isso explicitamente.\n"
                . "- Não use markdown. Texto corrido simples.\n"
                . "- Não cumprimente, vá direto ao ponto.";

        $userMsg = $ctx . "\n────────────────────────────────\nPERGUNTA da advogada: " . $pergunta;

        $r = ia_chamar(
            'perguntar_ia_chat',
            'claude-haiku-4-5',
            $system,
            array(array('role' => 'user', 'content' => $userMsg)),
            array(
                'user_id'      => current_user_id(),
                'max_tokens'   => 400,
                'temperature'  => 0.1,
                'contexto'     => 'conv#' . $convId . ' q=' . mb_substr($pergunta, 0, 60),
                'cache_system' => true,
            )
        );

        if (!$r['ok']) { echo json_encode(array('error' => $r['erro'] ?: 'Falha IA')); exit; }

        // Amanda 12/06/2026: grava no historico pra reabrir sem pagar IA de novo
        $perguntaId = 0;
        try {
            $stIns = db()->prepare("INSERT INTO zapi_ia_perguntas
                (conversa_id, user_id, pergunta, resposta, tokens, custo_brl, mensagens_analisadas, modelo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stIns->execute(array(
                $convId,
                current_user_id() ?: null,
                $pergunta,
                $r['texto'],
                (int)($r['input_tokens'] + $r['output_tokens']),
                (float)$r['custo_brl'],
                count($msgs),
                'claude-haiku-4-5',
            ));
            $perguntaId = (int)db()->lastInsertId();
        } catch (Throwable $eIns) { /* silencioso — historico nao deve quebrar a feature */ }

        @audit_log('IA_PERGUNTA_CHAT', 'zapi_conversas', $convId, mb_substr($pergunta, 0, 100));
        echo json_encode(array(
            'ok'         => true,
            'id'         => $perguntaId,
            'resposta'   => $r['texto'],
            'pergunta'   => $pergunta,
            'custo_brl'  => $r['custo_brl'],
            'tokens'     => $r['input_tokens'] + $r['output_tokens'],
            'mensagens_analisadas' => count($msgs),
        ));
    } catch (Throwable $e) {
        echo json_encode(array('error' => 'Erro: ' . $e->getMessage()));
    }
    exit;
}

// Amanda 12/06/2026: lista historico de perguntas IA desta conversa (sem custo)
if (($_GET['action'] ?? $_POST['action'] ?? '') === 'historico_ia_chat') {
    header('Content-Type: application/json; charset=utf-8');
    $convId = (int)($_GET['conversa_id'] ?? $_POST['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    try {
        $st = db()->prepare("SELECT p.id, p.pergunta, p.resposta, p.tokens, p.custo_brl,
                                    p.mensagens_analisadas, p.criado_em,
                                    u.name AS user_name
                             FROM zapi_ia_perguntas p
                             LEFT JOIN users u ON u.id = p.user_id
                             WHERE p.conversa_id = ?
                             ORDER BY p.criado_em DESC, p.id DESC
                             LIMIT 50");
        $st->execute(array($convId));
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array('ok' => true, 'historico' => $rows, 'total' => count($rows)));
    } catch (Throwable $e) {
        echo json_encode(array('error' => 'Erro: ' . $e->getMessage(), 'historico' => array()));
    }
    exit;
}

// Amanda 12/06/2026: apaga uma pergunta do historico
if (($_GET['action'] ?? $_POST['action'] ?? '') === 'apagar_pergunta_ia') {
    header('Content-Type: application/json; charset=utf-8');
    if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
    $pergId = (int)($_POST['pergunta_id'] ?? 0);
    if (!$pergId) { echo json_encode(array('error' => 'pergunta_id obrigatório')); exit; }
    try {
        // Só permite apagar perguntas que o próprio user fez (ou admin)
        $check = db()->prepare("SELECT user_id FROM zapi_ia_perguntas WHERE id = ?");
        $check->execute(array($pergId));
        $ownerId = (int)$check->fetchColumn();
        if ($ownerId !== (int)current_user_id() && (int)current_user_id() !== 1) {
            echo json_encode(array('error' => 'Sem permissão')); exit;
        }
        db()->prepare("DELETE FROM zapi_ia_perguntas WHERE id = ?")->execute(array($pergId));
        echo json_encode(array('ok' => true));
    } catch (Throwable $e) {
        echo json_encode(array('error' => 'Erro: ' . $e->getMessage()));
    }
    exit;
}

$mutantes = array('enviar_mensagem', 'enviar_arquivo', 'enviar_audio', 'enviar_rapido', 'assumir_atendimento', 'atribuir', 'resolver',
                  'ativar_bot', 'desativar_bot', 'marcar_lida', 'marcar_nao_lida', 'arquivar',
                  'sincronizar_conversa', 'importar_todos',
                  'editar_conversa', 'adicionar_etiqueta', 'remover_etiqueta',
                  'deletar_mensagem', 'editar_mensagem',
                  'salvar_drive', 'salvar_lote_pdf_drive',
                  'fila_marcar_enviada', 'fila_descartar', 'fila_editar', 'fila_bulk_descartar',
                  'gerar_link_salavip', 'gerar_link_salavip_por_cliente',
                  'delegar_conversa', 'remover_delegacao',
                  'enviar_sticker', 'enviar_reacao',
                  'sticker_biblioteca_add', 'sticker_biblioteca_enviar',
                  'sticker_biblioteca_remover', 'sticker_biblioteca_favoritar',
                  'sticker_biblioteca_add_from_msg',
                  'salvar_display_name',
                  'exportar_conversa',
                  'mesclar_conversas');
if (in_array($action, $mutantes, true)) {
    if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
}

// ── LISTAR CONVERSAS ─────────────────────────────────────
if ($action === 'listar_conversas') {
    // OTIMIZAÇÃO (2026-05-07): tarefas de manutenção (expirar delegações + atualizar
    // etiqueta AT DESBLOQUEADO) varriam todas as conversas em_atendimento a CADA poll
    // de 5s, deixando o módulo lento. Agora throttled pra 1× por minuto global via
    // flag-file (compartilhado entre usuários) — granularidade suficiente, já que
    // a regra é em horas (8h úteis / 36h).
    $manutFlag = APP_ROOT . '/files/wa_manutencao_polled_at.flag';
    $deveManter = !file_exists($manutFlag) || ((time() - filemtime($manutFlag)) > 60);
    if ($deveManter) {
        @touch($manutFlag);
        zapi_expirar_delegacoes_estale();
        zapi_atualizar_at_desbloqueado();
    }

    $canal   = $_GET['canal']   ?? '21';
    $status  = $_GET['status']  ?? '';
    // Paginação da lista: frontend manda limit crescente (botão "Carregar mais").
    // Default 200, teto 5000 pra não travar. 'X de Y' usa o COUNT real abaixo.
    $limite  = (int)($_GET['limit'] ?? 200);
    if ($limite < 1)    $limite = 200;
    if ($limite > 5000) $limite = 5000;
    $busca   = trim($_GET['q']  ?? '');
    $where   = array('co.canal = ?');
    $params  = array($canal);

    // Restrição de visibilidade — Simone (user#5) não pode ver conversas com
    // contatos chamados "Gisele" (qualquer Gisele). Pedido Amanda 04/05/2026.
    // Aplica em listagem + detalhe (action=obter abaixo) + abrir (zapi_buscar)
    if ($userId === 5) {
        $where[] = "(co.nome_contato IS NULL OR LOWER(co.nome_contato) NOT LIKE '%gisele%')";
    }

    if ($status && $status !== 'todos') {
        if ($status === 'bot')  $where[] = 'co.bot_ativo = 1';
        elseif ($status === 'nao_lidas') $where[] = 'co.nao_lidas > 0';
        else { $where[] = 'co.status = ?'; $params[] = $status; }
    } else {
        // Padrão (sem filtro OU "todos"): oculta arquivadas. Só aparecem se usuário filtrar explicitamente por status=arquivado
        $where[] = "co.status != 'arquivado'";
    }

    // Filtro por atendente (0 = sem atendente, -1 = minhas)
    if (isset($_GET['atendente']) && $_GET['atendente'] !== '') {
        $at = (int)$_GET['atendente'];
        if ($at === -1) {
            $where[] = 'co.atendente_id = ?';
            $params[] = $userId;
        } elseif ($at === 0) {
            $where[] = 'co.atendente_id IS NULL';
        } else {
            $where[] = 'co.atendente_id = ?';
            $params[] = $at;
        }
    }
    if ($busca !== '') {
        $where[] = '(co.nome_contato LIKE ? OR co.telefone LIKE ? OR cl.name LIKE ?)';
        $params[] = "%$busca%"; $params[] = "%$busca%"; $params[] = "%$busca%";
    }

    // Filtro adicional por etiqueta
    $etiquetaId = (int)($_GET['etiqueta'] ?? 0);
    $joinEtq = '';
    if ($etiquetaId) {
        $joinEtq = " INNER JOIN zapi_conversa_etiquetas ce_f ON ce_f.conversa_id = co.id AND ce_f.etiqueta_id = ? ";
        array_unshift($params, $etiquetaId);
    }

    // OTIMIZAÇÃO 12/05/2026: a query antiga tinha 4 subqueries correlacionadas
    // por linha (2 pra ultima_mensagem/data, 1 pra etiquetas, 1 NO ORDER BY) —
    // rodada a cada poll de 8s por cada atendente, com centenas de conversas e
    // dezenas de milhares de linhas em zapi_mensagens, isso deixava o modulo
    // BEM lento. Agora usa as colunas SALVAS co.ultima_mensagem / co.ultima_msg_em
    // (atualizadas ao enviar/receber/mesclar/deletar) — sem subquery no SELECT
    // nem no ORDER BY. So a de etiquetas ficou (GROUP_CONCAT em tabela pequena).
    // Self-heal das colunas só roda 1× por processo PHP (variável estática).
    static $_listarSelfHealFeito = false;
    if (!$_listarSelfHealFeito) {
        try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN fixada TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN fixada_em DATETIME NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN foto_perfil_local VARCHAR(255) NULL"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN chat_lid VARCHAR(50) NULL"); } catch (Exception $e) {}
        // Índice composto pra acelerar a subquery de "última msg" onde ela ainda é usada
        // (abrir_conversa, deletar_mensagem recalc, etc).
        try { $pdo->exec("ALTER TABLE zapi_mensagens ADD INDEX idx_conv_id (conversa_id, id)"); } catch (Exception $e) {}
        $_listarSelfHealFeito = true;
    }

    $sql = "SELECT co.id, co.telefone, co.nome_contato, co.status, co.nao_lidas,
                   co.bot_ativo, co.canal,
                   co.client_id, co.lead_id, co.atendente_id,
                   COALESCE(co.delegada, 0) AS delegada, co.delegada_por,
                   COALESCE(co.fixada, 0) AS fixada,
                   co.foto_perfil_url, co.foto_perfil_local, COALESCE(co.eh_grupo, 0) AS eh_grupo,
                   cl.foto_path AS client_foto_path,
                   cl.name AS client_name,
                   u.wa_display_name AS atendente_display_name,
                   pl.name AS lead_name,
                   u.name AS atendente_name,
                   co.ultima_mensagem AS ultima_mensagem,
                   co.ultima_msg_em AS ultima_msg_em,
                   (SELECT GROUP_CONCAT(CONCAT_WS('|', e.id, e.nome, e.cor) SEPARATOR '§')
                    FROM zapi_conversa_etiquetas ce JOIN zapi_etiquetas e ON e.id = ce.etiqueta_id
                    WHERE ce.conversa_id = co.id) AS etiquetas_raw
            FROM zapi_conversas co
            LEFT JOIN clients cl ON cl.id = co.client_id
            LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
            LEFT JOIN users u ON u.id = co.atendente_id
            {$joinEtq}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE(co.fixada, 0) DESC, COALESCE(co.ultima_msg_em, co.created_at) DESC
            LIMIT " . (int)$limite;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Nilce r13 31/05/2026: badge ficava travado em (200) em filtros com muitas
    // conversas (ex: 'Aguardando'). Causa: LIMIT 200 + frontend usando .length da
    // lista. Agora calcula COUNT real com o mesmo WHERE pra mostrar 'X de Y'.
    $totalReal = count($rows);
    if (count($rows) >= $limite) {
        try {
            $countSql = "SELECT COUNT(*) FROM zapi_conversas co
                         LEFT JOIN clients cl ON cl.id = co.client_id
                         LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
                         {$joinEtq}
                         WHERE " . implode(' AND ', $where);
            $stC = $pdo->prepare($countSql);
            $stC->execute($params);
            $totalReal = (int)$stC->fetchColumn();
        } catch (Exception $e) {}
    }

    // Parse etiquetas: "id|nome|cor§id|nome|cor" → array de {id,nome,cor}
    // + substitui atendente_name pelo display name curto (custom ou primeiro+último)
    foreach ($rows as &$r) {
        $r['etiquetas'] = array();
        if (!empty($r['etiquetas_raw'])) {
            foreach (explode('§', $r['etiquetas_raw']) as $piece) {
                $p = explode('|', $piece);
                if (count($p) === 3) $r['etiquetas'][] = array('id' => $p[0], 'nome' => $p[1], 'cor' => $p[2]);
            }
        }
        unset($r['etiquetas_raw']);
        // Display name curto
        if (!empty($r['atendente_name'])) {
            $r['atendente_name'] = user_display_name(array(
                'name' => $r['atendente_name'],
                'wa_display_name' => $r['atendente_display_name'] ?? null,
            ));
        }
        unset($r['atendente_display_name']);
    }

    // Status das instâncias
    $inst = array();
    foreach ($pdo->query("SELECT ddd, conectado, instancia_id FROM zapi_instancias")->fetchAll() as $i) {
        $inst[$i['ddd']] = array(
            'conectado'    => (int)$i['conectado'],
            'configurado'  => $i['instancia_id'] !== '',
        );
    }

    echo json_encode(array('ok' => true, 'conversas' => $rows, 'total' => $totalReal, 'instancias' => $inst));
    exit;
}

// ── SYNC FOTO DE PERFIL (1 conversa) ─────────────────────
if ($action === 'sync_foto_conversa') {
    $id = (int)($_REQUEST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID inválido')); exit; }
    $r = zapi_sync_foto_contato($id);
    echo json_encode($r);
    exit;
}

// ── SYNC FOTOS EM LOTE (batch de até 25 sem foto / stale) ─
if ($action === 'sync_fotos_todas') {
    $limit = min(25, (int)($_REQUEST['limit'] ?? 25));
    $canal = isset($_REQUEST['canal']) ? $_REQUEST['canal'] : '';
    $wh = "(co.foto_perfil_atualizada IS NULL OR co.foto_perfil_atualizada < DATE_SUB(NOW(), INTERVAL 7 DAY))";
    $params = array();
    if ($canal) { $wh .= " AND co.canal = ?"; $params[] = $canal; }
    $stmt = $pdo->prepare("SELECT co.id FROM zapi_conversas co WHERE $wh ORDER BY co.foto_perfil_atualizada ASC, co.id DESC LIMIT " . (int)$limit);
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $result = array('total' => count($ids), 'com_foto' => 0, 'clientes_atualizados' => 0);
    foreach ($ids as $id) {
        $r = zapi_sync_foto_contato((int)$id);
        if (!empty($r['foto_url'])) $result['com_foto']++;
        if (!empty($r['client_updated'])) $result['clientes_atualizados']++;
    }
    echo json_encode(array('ok' => true) + $result);
    exit;
}

// ── LISTAR DUPLICATAS POTENCIAIS (Amanda/Luiz) ────────────
// Retorna conversas candidatas a duplicata pra uma conversa de referência.
// - Sem ?q=... : critério automático (nome igual OU últimos 8 dígitos batem)
// - Com ?q=... : busca livre por nome OU telefone OU ID (#123)
if ($action === 'listar_duplicatas') {
    // Mesclar liberado para todos os usuarios (a propria UI exige o botao Mesclar
    // visivel, e o ato de unir duplicatas e' util pra qualquer atendente).
    $convId = (int)($_GET['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'ID inválido')); exit; }
    $base = $pdo->prepare("SELECT id, canal, telefone, nome_contato FROM zapi_conversas WHERE id = ?");
    $base->execute(array($convId));
    $b = $base->fetch();
    if (!$b) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $q    = trim($_GET['q'] ?? '');
    $todas = !empty($_GET['todas']); // se true, lista TODAS do canal (ignorando filtro)
    if ($todas) {
        $st = $pdo->prepare("SELECT id, telefone, nome_contato, ultima_mensagem,
                                    (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS qt_msgs
                             FROM zapi_conversas co
                             WHERE canal = ? AND id != ?
                             ORDER BY ultima_msg_em DESC LIMIT 100");
        $st->execute(array($b['canal'], $b['id']));
        $rows = $st->fetchAll();
    } elseif ($q !== '') {
        // Busca livre: nome OU telefone OU #ID — sem mínimo de caracteres
        if (preg_match('/^#?(\d+)$/', $q, $m) && strlen($m[1]) <= 9) {
            // Busca por ID se parece com ID (número curto)
            $st = $pdo->prepare("SELECT id, telefone, nome_contato, ultima_mensagem,
                                        (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS qt_msgs
                                 FROM zapi_conversas co WHERE canal = ? AND id = ? AND id != ? LIMIT 1");
            $st->execute(array($b['canal'], (int)$m[1], $b['id']));
        } else {
            $digitsQ = preg_replace('/\D/', '', $q);
            $st = $pdo->prepare("SELECT id, telefone, nome_contato, ultima_mensagem,
                                        (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS qt_msgs
                                 FROM zapi_conversas co
                                 WHERE canal = ? AND id != ?
                                   AND (nome_contato LIKE ? OR REPLACE(telefone,'@lid','') LIKE ?)
                                 ORDER BY ultima_msg_em DESC LIMIT 50");
            $st->execute(array($b['canal'], $b['id'], '%' . $q . '%', '%' . ($digitsQ ?: $q) . '%'));
        }
        $rows = $st->fetchAll();
    } else {
        // Critério automático
        $digits = preg_replace('/\D/', '', $b['telefone']);
        $ult8 = substr($digits, -8);
        $st = $pdo->prepare("SELECT id, telefone, nome_contato, ultima_mensagem,
                                    (SELECT COUNT(*) FROM zapi_mensagens m WHERE m.conversa_id = co.id) AS qt_msgs
                             FROM zapi_conversas co
                             WHERE canal = ? AND id != ?
                               AND (
                                   (nome_contato IS NOT NULL AND nome_contato != '' AND nome_contato = ?)
                                   OR (CHAR_LENGTH(?) >= 6 AND REPLACE(telefone,'@lid','') LIKE ?)
                               )
                             ORDER BY ultima_msg_em DESC LIMIT 20");
        $st->execute(array($b['canal'], $b['id'], $b['nome_contato'] ?? '', $ult8, '%' . $ult8));
        $rows = $st->fetchAll();
    }
    echo json_encode(array('ok' => true, 'base' => $b, 'candidatas' => $rows));
    exit;
}

// ── MESCLAR CONVERSAS (todos os usuarios) ─────────────────
// Migra todas as mensagens e etiquetas da origem pra destino, depois apaga
// a origem. Usado quando mesmo contato gerou duas conversas (ex: Multi-Device
// alternando entre @lid e telefone real). Liberado para todos os atendentes.
if ($action === 'mesclar_conversas') {
    $origemId  = (int)($_POST['origem_id'] ?? 0);
    $destinoId = (int)($_POST['destino_id'] ?? 0);
    if (!$origemId || !$destinoId || $origemId === $destinoId) {
        echo json_encode(array('error' => 'IDs inválidos')); exit;
    }
    // Valida mesmo canal
    $ck = $pdo->prepare("SELECT id, canal FROM zapi_conversas WHERE id IN (?, ?)");
    $ck->execute(array($origemId, $destinoId));
    $rows = $ck->fetchAll();
    if (count($rows) !== 2 || $rows[0]['canal'] !== $rows[1]['canal']) {
        echo json_encode(array('error' => 'Conversas inválidas ou de canais diferentes')); exit;
    }
    try {
        $pdo->beginTransaction();
        // Move mensagens
        $pdo->prepare("UPDATE zapi_mensagens SET conversa_id = ? WHERE conversa_id = ?")
            ->execute(array($destinoId, $origemId));
        // Move etiquetas evitando duplicata (etiqueta já aplicada no destino)
        $pdo->prepare("UPDATE IGNORE zapi_conversa_etiquetas SET conversa_id = ? WHERE conversa_id = ?")
            ->execute(array($destinoId, $origemId));
        $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ?")
            ->execute(array($origemId));
        // Antes de apagar a origem, registra o telefone dela como alias do destino —
        // assim o webhook nao recria a conversa quando chegar msg nova pelo telefone
        // antigo (ex: @lid do Multi-Device apos mesclar com numero real).
        $pdo->prepare("INSERT IGNORE INTO zapi_conversa_alias (alias_telefone, conversa_id)
                       SELECT telefone, ? FROM zapi_conversas WHERE id = ? AND telefone IS NOT NULL AND telefone != ''")
            ->execute(array($destinoId, $origemId));
        // Apaga a origem
        $pdo->prepare("DELETE FROM zapi_conversas WHERE id = ?")->execute(array($origemId));
        // Atualiza resumo do destino (última msg + contagem)
        $pdo->prepare("UPDATE zapi_conversas co
                       SET ultima_mensagem = (SELECT conteudo FROM zapi_mensagens WHERE conversa_id = co.id ORDER BY id DESC LIMIT 1),
                           ultima_msg_em   = (SELECT created_at FROM zapi_mensagens WHERE conversa_id = co.id ORDER BY id DESC LIMIT 1)
                       WHERE id = ?")->execute(array($destinoId));
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(array('error' => 'Falha ao mesclar: ' . $e->getMessage()));
        exit;
    }
    audit_log('zapi_mesclar', 'zapi_conversas', $destinoId, "Origem #{$origemId} mesclada em #{$destinoId}");
    echo json_encode(array('ok' => true, 'destino_id' => $destinoId));
    exit;
}

// ── LIMPAR DUPLICATAS POR CLIENT_ID (one-shot) ─────────────
// Varre TODAS as conversas com client_id setado e mescla as duplicatas
// (mesmo client_id + mesmo canal) na conversa MAIS RECENTE de cada cliente.
// Só mexe em conversas que NÃO são grupos. Roda zapi_auto_merge_por_client_id
// pra cada cliente que tem 2+ conversas no mesmo canal.
if ($action === 'limpar_duplicatas_canal') {
    $stmt = $pdo->query(
        "SELECT client_id, canal, COUNT(*) as cnt, MAX(id) AS id_destino
         FROM zapi_conversas
         WHERE client_id IS NOT NULL AND client_id > 0
           AND COALESCE(eh_grupo,0) = 0
         GROUP BY client_id, canal
         HAVING cnt > 1"
    );
    $grupos = $stmt->fetchAll();
    $totalMerged = 0;
    $clientesAfetados = 0;
    foreach ($grupos as $g) {
        $merged = zapi_auto_merge_por_client_id($pdo, (int)$g['id_destino'], (int)$g['client_id'], $g['canal']);
        if ($merged > 0) {
            $totalMerged += $merged;
            $clientesAfetados++;
        }
    }
    audit_log('zapi_limpar_dup', 'zapi_conversas', 0, "$clientesAfetados clientes — $totalMerged conversas mescladas");
    echo json_encode(array(
        'ok' => true,
        'clientes_afetados' => $clientesAfetados,
        'conversas_mescladas' => $totalMerged,
    ));
    exit;
}

// ── MEU NOME DE ATENDIMENTO (display name WhatsApp) ──────
// ── FAVORITOS DO MENU DE AÇÕES (Amanda 03/07) ──
// Ler: GET action=wa_favs_listar&canal=21|24  -> {ok, ids:[...]}
// Salvar: POST action=wa_favs_salvar&canal=21|24 favoritos=[JSON array]
if ($action === 'wa_favs_listar') {
    try { db()->exec("CREATE TABLE IF NOT EXISTS user_wa_favoritos (user_id INT NOT NULL, canal VARCHAR(4) NOT NULL, fav_id VARCHAR(48) NOT NULL, ordem INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, canal, fav_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Exception $e) {}
    $c = ($_GET['canal'] ?? '') === '21' ? '21' : '24';
    $st = $pdo->prepare("SELECT fav_id FROM user_wa_favoritos WHERE user_id = ? AND canal = ? ORDER BY ordem, fav_id");
    $st->execute(array($userId, $c));
    $ids = array_map(function($r){ return $r['fav_id']; }, $st->fetchAll(PDO::FETCH_ASSOC));
    echo json_encode(array('ok' => true, 'ids' => $ids));
    exit;
}
if ($action === 'wa_favs_salvar') {
    try { db()->exec("CREATE TABLE IF NOT EXISTS user_wa_favoritos (user_id INT NOT NULL, canal VARCHAR(4) NOT NULL, fav_id VARCHAR(48) NOT NULL, ordem INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, canal, fav_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"); } catch (Exception $e) {}
    $c = ($_POST['canal'] ?? '') === '21' ? '21' : '24';
    $raw = $_POST['favoritos'] ?? '[]';
    $ids = json_decode($raw, true);
    if (!is_array($ids)) $ids = array();
    $ids = array_slice(array_values(array_unique(array_filter(array_map('strval', $ids)))), 0, 12);
    try {
        $pdo->prepare("DELETE FROM user_wa_favoritos WHERE user_id = ? AND canal = ?")->execute(array($userId, $c));
        $ins = $pdo->prepare("INSERT INTO user_wa_favoritos (user_id, canal, fav_id, ordem) VALUES (?, ?, ?, ?)");
        foreach ($ids as $i => $fid) {
            if (strlen($fid) > 48) continue;
            $ins->execute(array($userId, $c, $fid, $i));
        }
        echo json_encode(array('ok' => true, 'count' => count($ids)));
    } catch (Exception $e) {
        echo json_encode(array('error' => $e->getMessage()));
    }
    exit;
}

if ($action === 'salvar_display_name') {
    $novo = trim($_POST['wa_display_name'] ?? '');
    if (mb_strlen($novo) > 100) { echo json_encode(array('error' => 'Nome muito longo (máx 100 caracteres).')); exit; }
    $pdo->prepare("UPDATE users SET wa_display_name = ? WHERE id = ?")
        ->execute(array($novo !== '' ? $novo : null, $userId));
    echo json_encode(array('ok' => true, 'display_name' => user_display_name()));
    exit;
}

// ── ABRIR CONVERSA (zera não lidas + retorna mensagens) ──
if ($action === 'abrir_conversa') {
    $id = (int)($_GET['id'] ?? 0);
    // Self-heal das colunas de nota fixa (idempotente) — primeira chamada cria.
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN nota_fixa TEXT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN nota_fixa_em DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN nota_fixa_por INT NULL"); } catch (Exception $e) {}

    // Self-heal pra coluna gender_pulado (usada quando atendente "Pular" no banner)
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN gender_pulado TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    // Self-heal is_internacional (Amanda 08/06/2026 — suprime aviso "numero estranho" pra clientes do exterior)
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN is_internacional TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    $stmt = $pdo->prepare("SELECT co.*, cl.name AS client_name, cl.gender AS client_gender,
                                  COALESCE(cl.gender_pulado, 0) AS client_gender_pulado,
                                  COALESCE(cl.is_internacional, 0) AS client_is_internacional,
                                  pl.name AS lead_name,
                                  u.name AS atendente_name, u.wa_display_name AS atendente_display_name,
                                  un.name AS nota_fixa_por_name
                           FROM zapi_conversas co
                           LEFT JOIN clients cl ON cl.id = co.client_id
                           LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
                           LEFT JOIN users u ON u.id = co.atendente_id
                           LEFT JOIN users un ON un.id = co.nota_fixa_por
                           WHERE co.id = ?");
    $stmt->execute(array($id));
    $conv = $stmt->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    // Defesa: Simone (user#5) não pode abrir conversa de contato chamado "Gisele"
    // (mesmo se descobrir o ID via URL direta). Pedido Amanda 04/05/2026.
    if ($userId === 5 && !empty($conv['nome_contato']) && stripos($conv['nome_contato'], 'gisele') !== false) {
        echo json_encode(array('error' => 'Conversa não encontrada')); exit;
    }

    // Display name curto do atendente
    if (!empty($conv['atendente_name'])) {
        $conv['atendente_name'] = user_display_name(array(
            'name' => $conv['atendente_name'],
            'wa_display_name' => $conv['atendente_display_name'] ?? null,
        ));
    }
    unset($conv['atendente_display_name']);

    // Zera não lidas
    $pdo->prepare("UPDATE zapi_conversas SET nao_lidas = 0 WHERE id = ?")->execute(array($id));

    // Estado da trava de atendimento pro usuário atual (bloqueio de envio)
    $lock = zapi_pode_enviar_conversa($id, $userId);
    $conv['lock_pode_enviar']     = !empty($lock['pode']) ? 1 : 0;
    $conv['lock_atendente_name']  = $lock['atendente_name'] ?? null;
    $conv['lock_motivo']          = $lock['motivo'] ?? null;               // 'cliente_esperando' | 'atendente_ativo'
    $conv['lock_segundos_ate']    = $lock['segundos_ate_liberar'] ?? null; // pro cronômetro
    $conv['lock_idade_ultima']    = $lock['idade_ultima_segundos'] ?? null;

    // Self-heal: garante colunas pinned/pinned_at
    try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN pinned_at DATETIME NULL"); } catch (Exception $e) {}

    // Mensagens: últimas 500 MAIS RECENTES, ordenadas pelo timestamp ORIGINAL
    // da Z-API (momment_ms — em ms desde epoch). Antes era ORDER BY id, mas
    // quando o cliente manda várias msgs em rajada a Z-API pode entregar fora
    // de ordem; ordenar por id mostrava elas embaralhadas. Fallback pra
    // UNIX_TIMESTAMP(created_at)*1000 quando momment_ms não veio (msgs antigas).
    // Desempate por id pra manter estabilidade.
    $msgs = $pdo->prepare("SELECT * FROM (
                                SELECT m.*, u.name AS enviado_por_name, u.wa_display_name AS enviado_por_display_name,
                                    COALESCE(m.momment_ms, UNIX_TIMESTAMP(m.created_at)*1000) AS _ord_ts,
                                    (SELECT m2.conteudo FROM zapi_mensagens m2 WHERE m2.conversa_id = m.conversa_id AND m2.zapi_message_id = m.reply_to_message_id LIMIT 1) AS reply_to_conteudo,
                                    (SELECT m2.direcao  FROM zapi_mensagens m2 WHERE m2.conversa_id = m.conversa_id AND m2.zapi_message_id = m.reply_to_message_id LIMIT 1) AS reply_to_direcao
                                FROM zapi_mensagens m
                                LEFT JOIN users u ON u.id = m.enviado_por_id
                                WHERE m.conversa_id = ?
                                ORDER BY _ord_ts DESC, m.id DESC
                                LIMIT 500
                           ) sub ORDER BY _ord_ts ASC, id ASC");
    $msgs->execute(array($id));
    $mensagens = $msgs->fetchAll();

    // Mensagens fixadas (pra mostrar no topo do chat)
    $pinnedStmt = $pdo->prepare("SELECT id, direcao, tipo, conteudo, pinned_at, created_at
                                 FROM zapi_mensagens
                                 WHERE conversa_id = ? AND pinned = 1 AND status != 'deletada'
                                 ORDER BY pinned_at DESC LIMIT 5");
    $pinnedStmt->execute(array($id));
    $fixadas = $pinnedStmt->fetchAll();
    // Display name curto por mensagem
    foreach ($mensagens as &$_m) {
        if (!empty($_m['enviado_por_name'])) {
            $_m['enviado_por_name'] = user_display_name(array(
                'name' => $_m['enviado_por_name'],
                'wa_display_name' => $_m['enviado_por_display_name'] ?? null,
            ));
        }
        unset($_m['enviado_por_display_name']);
    }
    unset($_m);

    // Etiquetas aplicadas nesta conversa
    $etqStmt = $pdo->prepare("SELECT e.id, e.nome, e.cor FROM zapi_etiquetas e
                              JOIN zapi_conversa_etiquetas ce ON ce.etiqueta_id = e.id
                              WHERE ce.conversa_id = ? ORDER BY e.ordem");
    $etqStmt->execute(array($id));
    $conv['etiquetas'] = $etqStmt->fetchAll();

    // Proxima audiencia vinculada ao cliente desta conversa (Amanda 14/05/2026):
    // mostrar no header do WhatsApp pra a usuaria nao precisar abrir a pasta pra
    // saber data/hora/local da audiencia ao conversar com o cliente.
    // Busca em cases.client_id OU case_partes.client_id (cobre clientes vinculados
    // como parte autora, representante legal, ou reu-que-virou-cliente via eh_nosso_cliente).
    $conv['proxima_audiencia'] = null;
    $cliId = (int)($conv['client_id'] ?? 0);
    if ($cliId > 0) {
        try {
            $stAud = $pdo->prepare(
                "SELECT e.id, e.titulo, e.tipo, e.data_inicio, e.data_fim, e.modalidade, e.local, e.meet_link,
                        COALESCE(e.cliente_presencial, 0) AS cliente_presencial,
                        cs.title AS case_title, cs.id AS case_id, cs.court AS case_vara, cs.comarca AS case_comarca
                 FROM agenda_eventos e
                 INNER JOIN cases cs ON cs.id = e.case_id
                 WHERE e.tipo = 'audiencia'
                   AND e.status NOT IN ('cancelado','realizado','nao_compareceu')
                   AND e.data_inicio >= DATE_SUB(NOW(), INTERVAL 6 HOUR)
                   AND (
                       cs.client_id = ?
                       OR e.case_id IN (SELECT case_id FROM case_partes WHERE client_id = ?)
                   )
                 ORDER BY e.data_inicio ASC LIMIT 1"
            );
            $stAud->execute(array($cliId, $cliId));
            $aud = $stAud->fetch();
            if ($aud) $conv['proxima_audiencia'] = $aud;
        } catch (Exception $e) { /* falha silenciosa — header sem audiencia */ }
    }

    echo json_encode(array('ok' => true, 'conversa' => $conv, 'mensagens' => $mensagens, 'fixadas' => $fixadas));
    exit;
}

// ── ALFREDO: gera prévia do último andamento do cliente em linguagem de leigo (Amanda 17/07/2026)
if ($action === 'alfredo_gerar_previa') {
    $convId   = (int)($_POST['conversa_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    if (!$convId || !$clientId) { echo json_encode(array('error' => 'Parâmetros inválidos', 'csrf' => $newCsrf)); exit; }

    // Pega o CASE ATIVO mais recente do cliente + ultimo andamento visivel.
    // Amanda 17/07/2026: exclui arquivado/cancelado/renunciamos/concluido/
    // finalizado (cliente ja sabe que acabou).
    $st = $pdo->prepare(
        "SELECT ca.id AS andamento_id, ca.descricao, ca.data_andamento, ca.tipo, ca.visivel_cliente,
                cs.id AS case_id, cs.title AS case_title, cl.name AS cliente
           FROM case_andamentos ca
           JOIN cases cs ON cs.id = ca.case_id
           LEFT JOIN clients cl ON cl.id = cs.client_id
          WHERE cs.client_id = ?
            AND cs.status NOT IN ('arquivado','cancelado','renunciamos','concluido','finalizado')
            AND COALESCE(ca.visivel_cliente, 0) = 1
          ORDER BY ca.data_andamento DESC, ca.id DESC
          LIMIT 1"
    );
    $st->execute(array($clientId));
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) {
        echo json_encode(array('error' => 'Esse cliente não tem processo ativo com andamento visível ao cliente.', 'csrf' => $newCsrf));
        exit;
    }
    // Blacklist de conteudo (mesma regra do cron): distribuicao / perda de prazo etc
    $blacklistRx = '/distribui[çc][ãa]o|distribu[ií]d[ao]|distribu[ií]mos|distribu[ií]ram|distribuir|prazo esgotado|perda de prazo|fim de prazo|prazo perdido|preclus/i';
    if (preg_match($blacklistRx, (string)$a['descricao'])) {
        echo json_encode(array('error' => 'O último andamento contém palavras que NÃO devem ser expostas ao cliente (distribuição / prazo / etc). Recuse-se a enviar automaticamente.', 'csrf' => $newCsrf));
        exit;
    }

    require_once __DIR__ . '/../../core/functions_aviso_cliente.php';
    // Ultimas 3 msgs enviadas pro MESMO cliente (pra IA variar)
    $ultimasMsgs = array();
    try {
        $stU = $pdo->prepare("SELECT ca2.notif_cliente_texto FROM case_andamentos ca2
                              JOIN cases cs2 ON cs2.id = ca2.case_id
                              WHERE cs2.client_id = ? AND ca2.notif_cliente_status = 'enviado'
                                AND ca2.notif_cliente_texto IS NOT NULL
                              ORDER BY ca2.notif_cliente_enviada_em DESC LIMIT 3");
        $stU->execute(array($clientId));
        foreach ($stU as $r) if (!empty($r['notif_cliente_texto'])) $ultimasMsgs[] = $r['notif_cliente_texto'];
    } catch (Exception $e) {}
    // Determina o modo (NOVIDADE / RELEMBRAR / LONGA_ESPERA) — muda a
    // abordagem do prompt. Amanda 17/07/2026.
    $modoInfo = aviso_cliente_determinar_modo($pdo, $clientId, (string)$a['data_andamento']);
    $msg = aviso_cliente_resumir_via_ia(array($a), $a['cliente'], $a['case_title'], $ultimasMsgs, $modoInfo);
    if (!$msg) {
        echo json_encode(array('error' => 'A IA não conseguiu gerar um resumo válido (pode ter escapado palavra proibida). Tenta de novo — se persistir, envia manual pelo chat.', 'csrf' => $newCsrf));
        exit;
    }
    echo json_encode(array(
        'ok' => true,
        'mensagem' => $msg,
        'case_id' => (int)$a['case_id'],
        'case_title' => $a['case_title'],
        'andamento_id' => (int)$a['andamento_id'],
        'andamento_data' => $a['data_andamento'] ? date('d/m/Y', strtotime($a['data_andamento'])) : '',
        'modo' => $modoInfo['modo'],
        'modo_dias' => $modoInfo['dias'],
        'modo_ja_perguntou' => (bool)$modoInfo['cliente_perguntou_apos'],
        'csrf' => $newCsrf,
    ));
    exit;
}

// ── ALFREDO: envia a mensagem revisada pelo canal 24 e marca o andamento como enviado
if ($action === 'alfredo_enviar') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $mensagem = trim((string)($_POST['mensagem'] ?? ''));
    if (!$convId || $mensagem === '') { echo json_encode(array('error' => 'Parâmetros inválidos', 'csrf' => $newCsrf)); exit; }
    $conv = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch(PDO::FETCH_ASSOC);
    if (!$conv || $conv['canal'] !== '24') { echo json_encode(array('error' => 'Conversa inválida ou não é canal 24', 'csrf' => $newCsrf)); exit; }

    require_once __DIR__ . '/../../core/functions_zapi.php';
    $r = zapi_send_text('24', $conv['telefone'], $mensagem);
    if (empty($r['ok'])) {
        echo json_encode(array('error' => $r['erro'] ?? 'Falha na Z-API', 'csrf' => $newCsrf));
        exit;
    }
    // Registra a msg na conversa pra aparecer no chat
    try {
        $mid = $r['messageId'] ?? $r['zaapId'] ?? '';
        $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, texto, tipo, direcao, enviado_por_id, status, created_at)
                       VALUES (?,?,?,?,?,?,?,NOW())")
            ->execute(array($convId, $mid ?: null, $mensagem, 'text', 'enviada', $userId, 'enviada'));
        $pdo->prepare("UPDATE zapi_conversas SET ultima_msg_em = NOW(), ultima_mensagem = ? WHERE id = ?")
            ->execute(array(mb_substr($mensagem, 0, 200), $convId));
    } catch (Exception $e) {}
    audit_log('alfredo_enviado', 'zapi_conversa', $convId, 'user=' . $userId . ' chars=' . mb_strlen($mensagem));
    echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    exit;
}

// ── ENVIAR MENSAGEM ──────────────────────────────────────
if ($action === 'enviar_mensagem') {
    $convId  = (int)($_POST['conversa_id'] ?? 0);
    $texto   = trim($_POST['mensagem'] ?? '');
    $replyTo = trim($_POST['reply_to_message_id'] ?? ''); // zapi_message_id pra responder
    if (!$convId || !$texto) { echo json_encode(array('error' => 'Parâmetros inválidos')); exit; }

    // Trava de atendimento: se outro usuário já assumiu e a trava ainda está ativa
    // (8h úteis seg-sex 9-18h se cliente é última msg, ou 36h se equipe é última),
    // bloqueia o envio. Amanda/Luiz sempre podem (bypass).
    $lock = zapi_pode_enviar_conversa($convId, $userId);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois da trava liberar (8h úteis sem resposta, ou 36h de follow-up), ou se assumir a conversa."));
        exit;
    }

    $conv = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    // Assinatura do atendente (configurável em Automações) — usa o nome curto
    // do usuário (wa_display_name ou 'primeiro + último' automático).
    $assinar = zapi_auto_cfg('zapi_signature_on', '0') === '1';
    $textoEnviar = $texto;
    if ($assinar) {
        $formato = zapi_auto_cfg('zapi_signature_format', '*_{{atendente}}_*:');
        $nomeUser = user_display_name();
        $assinatura = str_replace('{{atendente}}', $nomeUser, $formato);
        // Prefixa a assinatura em linha própria (formato "*Nome*:\nmensagem")
        $textoEnviar = $assinatura . "\n" . ltrim($texto);
    }

    // 🔗 Shortlinks: encurta URLs pro Hub rastrear clique do cliente.
    // Ignora URLs internas do Hub logado (exceto treinamento — vale rastrear).
    require_once APP_ROOT . '/core/functions_shortlinks.php';
    $textoEnviar = sl_encurtar_urls_no_texto($textoEnviar, array(
        'conversa_id' => (int)$conv['id'],
        'client_id'   => !empty($conv['client_id']) ? (int)$conv['client_id'] : null,
        'canal'       => $conv['canal'],
        'criado_por'  => $userId,
    ));

    $resp = zapi_send_text($conv['canal'], $conv['telefone'], $textoEnviar, $replyTo ?: null);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Falha ao enviar: ' . ($resp['erro'] ?? 'HTTP ' . ($resp['http_code'] ?? '?')) . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    // Self-heal da coluna (idempotente)
    try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN reply_to_message_id VARCHAR(100) DEFAULT NULL AFTER zapi_message_id"); } catch (Exception $e) {}

    $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, reply_to_message_id, direcao, tipo, conteudo, enviado_por_id, status)
                   VALUES (?, ?, ?, 'enviada', 'texto', ?, ?, 'enviada')")
        ->execute(array($convId, $zapiId, $replyTo ?: null, $textoEnviar, $userId));

    // Reabre conversas resolvidas quando volta a haver troca de mensagens —
    // aguardando/resolvido viram em_atendimento. Atendente: quem responde
    // conversa já liberada (canal 21) assume — ver zapi_sql_set_atendente_pos_envio.
    $setAtend = zapi_sql_set_atendente_pos_envio($conv['canal'], $conv['atendente_id'], $userId);
    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                   status = CASE WHEN status IN ('aguardando','resolvido') THEN 'em_atendimento' ELSE status END,
                   {$setAtend}
                   WHERE id = ?")
        ->execute(array(mb_substr($textoEnviar, 0, 500), $convId));

    // Atendente acabou de mandar msg — etiqueta "AT DESBLOQUEADO" perde o sentido
    $etqAt = _zapi_etiqueta_at_desbloqueado_id();
    if ($etqAt) {
        $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ? AND etiqueta_id = ?")
            ->execute(array($convId, $etqAt));
    }

    // Recalcula score de esfriando do cliente (sem IA, custo zero)
    if (!empty($conv['client_id'])) ia_disparar_recalc_esfriando($pdo, (int)$conv['client_id']);

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId));
    exit;
}

// ── ASSUMIR ATENDIMENTO (e desativar bot) ────────────────
if ($action === 'assumir_atendimento') {
    // Expira delegações paradas antes de checar bloqueio (regra de horas úteis)
    zapi_expirar_delegacoes_estale();

    $convId = (int)($_POST['conversa_id'] ?? 0);

    // Bloqueia se outro usuário já assumiu/foi delegada E a trava ainda está ativa.
    // Regra: 8h úteis seg-sex 9-18h (se cliente é última) ou 36h (se equipe é última).
    // Pra realocar, Amanda ou Luiz Eduardo precisam usar "Delegar". Amanda/Luiz têm bypass.
    $lock = zapi_pode_enviar_conversa($convId, $userId);
    if (empty($lock['pode'])) {
        echo json_encode(array(
            'error' => "Esta conversa está em atendimento com {$lock['atendente_name']}. Apenas Amanda ou Luiz Eduardo podem delegar para outra pessoa, ou aguarde a trava liberar (8h úteis sem resposta, ou 36h de follow-up)."
        ));
        exit;
    }
    $pdo->prepare("UPDATE zapi_conversas SET atendente_id = ?, bot_ativo = 0, status = 'em_atendimento' WHERE id = ?")
        ->execute(array($userId, $convId));
    // Remove etiqueta "🔓 AT DESBLOQUEADO" — quem assumiu agora tá ativo
    $etqAt = _zapi_etiqueta_at_desbloqueado_id();
    if ($etqAt) {
        $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ? AND etiqueta_id = ?")
            ->execute(array($convId, $etqAt));
    }
    audit_log('zapi_assumir', 'zapi_conversas', $convId);
    echo json_encode(array('ok' => true));
    exit;
}

// ── DELEGAR CONVERSA (só Amanda e Luiz) ──────────────────
if ($action === 'delegar_conversa') {
    if (!can_delegar_whatsapp()) {
        echo json_encode(array('error' => 'Apenas Amanda e Luiz Eduardo podem delegar conversas.'));
        exit;
    }
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $alvoId = (int)($_POST['atendente_id'] ?? 0);
    if (!$convId || !$alvoId) {
        echo json_encode(array('error' => 'Dados incompletos')); exit;
    }
    // Confirma que o alvo é usuário ativo
    $u = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND is_active = 1");
    $u->execute(array($alvoId));
    $alvo = $u->fetch();
    if (!$alvo) { echo json_encode(array('error' => 'Atendente alvo inválido ou inativo.')); exit; }

    $pdo->prepare("UPDATE zapi_conversas SET atendente_id = ?, delegada = 1, delegada_por = ?, delegada_em = NOW(), bot_ativo = 0, status = 'em_atendimento' WHERE id = ?")
        ->execute(array($alvoId, $userId, $convId));
    // Remove etiqueta "🔓 AT DESBLOQUEADO" — atendente novo entrou
    $etqAt = _zapi_etiqueta_at_desbloqueado_id();
    if ($etqAt) {
        $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ? AND etiqueta_id = ?")
            ->execute(array($convId, $etqAt));
    }

    // Notifica o atendente alvo
    try {
        notify($alvoId, 'Nova conversa delegada a você',
            'Você recebeu uma conversa do WhatsApp — abra o módulo pra atender.',
            'info', url('modules/whatsapp/?conversa=' . $convId), '📩');
    } catch (Exception $e) {}

    audit_log('zapi_delegar', 'zapi_conversas', $convId, "Delegada para {$alvo['name']} (user={$alvoId})");
    echo json_encode(array('ok' => true, 'alvo_name' => $alvo['name']));
    exit;
}

// ── REMOVER DELEGAÇÃO (libera pra qualquer um assumir) ───
if ($action === 'remover_delegacao') {
    if (!can_delegar_whatsapp()) {
        echo json_encode(array('error' => 'Apenas Amanda e Luiz Eduardo podem remover delegação.'));
        exit;
    }
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET delegada = 0, delegada_por = NULL, delegada_em = NULL WHERE id = ?")
        ->execute(array($convId));
    audit_log('zapi_remover_delegacao', 'zapi_conversas', $convId);
    echo json_encode(array('ok' => true));
    exit;
}

// ── ATRIBUIR PARA OUTRO USUÁRIO (legado — mantido pra retrocompatibilidade) ─
if ($action === 'atribuir') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $alvoId = (int)($_POST['atendente_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET atendente_id = ?, status = 'em_atendimento' WHERE id = ?")
        ->execute(array($alvoId ?: null, $convId));
    audit_log('zapi_atribuir', 'zapi_conversas', $convId, "Atribuido para user={$alvoId}");
    echo json_encode(array('ok' => true));
    exit;
}

// ── RESOLVER / ARQUIVAR ──────────────────────────────────
if ($action === 'resolver') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET status = 'resolvido' WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}
if ($action === 'arquivar') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET status = 'arquivado' WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}
// Marca conversa como NAO LIDA mesmo depois de ter sido aberta — pra
// usuária revisar/responder depois. GREATEST preserva contador se ja tinha
// msgs nao-lidas (caso raro), senao seta 1 (basta pra aparecer o badge vermelho
// e aparecer no filtro "Nao lidas").
if ($action === 'marcar_nao_lida') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET nao_lidas = GREATEST(nao_lidas, 1) WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}

// ── ATIVAR / DESATIVAR BOT ───────────────────────────────
if ($action === 'ativar_bot') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET bot_ativo = 1 WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}
if ($action === 'desativar_bot') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $pdo->prepare("UPDATE zapi_conversas SET bot_ativo = 0 WHERE id = ?")->execute(array($convId));
    echo json_encode(array('ok' => true));
    exit;
}

// ── TOGGLE MOSTRAR NOMES ATENDENTE (só gestão) ───────────
if ($action === 'toggle_mostrar_nomes') {
    if (!has_min_role('gestao')) { echo json_encode(array('error' => 'Sem permissão — só gestão pode alterar.')); exit; }
    $atual = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_mostrar_nome_interno'")->fetchColumn();
    $novo = ($atual === '1') ? '0' : '1';
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array('zapi_mostrar_nome_interno', $novo));
    audit_log('zapi_config_toggle', 'configuracoes', null, 'zapi_mostrar_nome_interno = ' . $novo);
    echo json_encode(array('ok' => true, 'novo' => $novo));
    exit;
}

// ── TOGGLE ASSINATURA AUTOMÁTICA (nome no WhatsApp tradicional do cliente) ──
if ($action === 'toggle_assinatura') {
    if (!has_min_role('gestao')) { echo json_encode(array('error' => 'Sem permissão — só gestão pode alterar.')); exit; }
    $atual = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_signature_on'")->fetchColumn();
    $novo = ($atual === '1') ? '0' : '1';
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?,?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute(array('zapi_signature_on', $novo));
    // Garante formato default se nunca foi configurado. Formato novo: *Nome*: (prefix)
    $fmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'zapi_signature_format'")->fetchColumn();
    if (!$fmt) {
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('zapi_signature_format', '*_{{atendente}}_*:')")->execute();
    } elseif ($fmt === '— {{atendente}}') {
        // Migra do formato antigo (suffix) pro novo (prefix) se ainda estava no default antigo
        $pdo->prepare("UPDATE configuracoes SET valor = '*_{{atendente}}_*:' WHERE chave = 'zapi_signature_format'")->execute();
    }
    audit_log('zapi_config_toggle', 'configuracoes', null, 'zapi_signature_on = ' . $novo);
    echo json_encode(array('ok' => true, 'novo' => $novo));
    exit;
}

// ── CORES DOS ATENDENTES (admin only) ────────────────────
if ($action === 'salvar_atendente_cor') {
    if (!has_min_role('admin')) { echo json_encode(array('error' => 'Só admin pode editar cores.')); exit; }
    $uid = (int)($_POST['user_id'] ?? 0);
    $cor = trim($_POST['cor'] ?? '');
    if (!$uid) { echo json_encode(array('error' => 'user_id obrigatório')); exit; }
    if ($cor !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) { echo json_encode(array('error' => 'Cor inválida (use formato #rrggbb)')); exit; }
    try { $pdo->exec("ALTER TABLE users ADD COLUMN wa_color VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
    $pdo->prepare("UPDATE users SET wa_color = ? WHERE id = ?")->execute(array($cor ?: null, $uid));
    audit_log('wa_cor_atendente', 'users', $uid, 'cor=' . ($cor ?: 'auto'));
    echo json_encode(array('ok' => true, 'cor' => $cor ?: null));
    exit;
}
if ($action === 'listar_atendentes_cores') {
    if (!has_min_role('admin')) { echo json_encode(array('error' => 'Só admin')); exit; }
    try { $pdo->exec("ALTER TABLE users ADD COLUMN wa_color VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
    $rows = $pdo->query("SELECT id, name, role, wa_color FROM users WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
    echo json_encode(array('ok' => true, 'usuarios' => $rows));
    exit;
}

// ── TEMPLATES ────────────────────────────────────────────
if ($action === 'listar_templates') {
    // Self-heal: coluna atalho pra slash autocomplete
    try { $pdo->exec("ALTER TABLE zapi_templates ADD COLUMN atalho VARCHAR(50) DEFAULT NULL"); } catch (Exception $e) {}
    $canal = $_GET['canal'] ?? '21';
    $stmt = $pdo->prepare("SELECT id, nome, atalho, conteudo, categoria FROM zapi_templates
                           WHERE ativo = 1 AND (canal = ? OR canal = 'ambos') ORDER BY nome ASC");
    $stmt->execute(array($canal));
    echo json_encode(array('ok' => true, 'templates' => $stmt->fetchAll()));
    exit;
}

// ── LISTAR USUÁRIOS (para atribuir) ──────────────────────
if ($action === 'listar_usuarios') {
    $rows = $pdo->query("SELECT id, name, role FROM users WHERE active = 1 ORDER BY name ASC")->fetchAll();
    echo json_encode(array('ok' => true, 'usuarios' => $rows));
    exit;
}

// ── EDITAR CONVERSA (nome, anotações) ───────────────────
if ($action === 'editar_conversa') {
    $convId   = (int)($_POST['conversa_id'] ?? 0);
    $nome     = trim($_POST['nome_contato'] ?? '');
    $telefone = trim($_POST['telefone'] ?? '');
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    if (mb_strlen($nome) > 150) $nome = mb_substr($nome, 0, 150);

    // Se telefone foi enviado, normaliza pra E.164 BR (5532994283065).
    // Aceita: "32 99428-3065", "(32)99428-3065", "32994283065", "55329...", etc.
    $telOk = null;
    if ($telefone !== '') {
        $tDigits = preg_replace('/\D/', '', $telefone);
        // Se vier com 0 inicial parasita (ex: 055xx...), remove
        $tDigits = ltrim($tDigits, '0');
        // Se nao comecar com 55 e tiver 10 ou 11 digitos (DDD+numero BR), prefixa 55
        if (preg_match('/^[1-9]\d{9,10}$/', $tDigits)) {
            $tDigits = '55' . $tDigits;
        }
        // Validacao final: 12-13 digitos (55 + DDD + 8/9 digitos)
        if (!preg_match('/^55\d{10,11}$/', $tDigits)) {
            echo json_encode(array('error' => 'Telefone inválido. Use formato BR: (32) 99428-3065 ou 5532994283065'));
            exit;
        }
        $telOk = $tDigits;
    }

    if ($telOk !== null) {
        // Verifica conflito: se ja existe outra conversa com esse telefone no mesmo canal
        $stmtVer = $pdo->prepare("SELECT canal FROM zapi_conversas WHERE id = ?");
        $stmtVer->execute(array($convId));
        $canalConv = $stmtVer->fetchColumn();
        $stmtConf = $pdo->prepare("SELECT id FROM zapi_conversas WHERE canal = ? AND telefone = ? AND id != ? LIMIT 1");
        $stmtConf->execute(array($canalConv, $telOk, $convId));
        $conflito = $stmtConf->fetchColumn();
        if ($conflito) {
            echo json_encode(array(
                'error' => 'Já existe outra conversa com esse número no mesmo canal (#' . (int)$conflito . '). Considere mesclar ao invés de duplicar.',
                'conflito_id' => (int)$conflito,
            ));
            exit;
        }

        $pdo->prepare("UPDATE zapi_conversas SET nome_contato = ?, telefone = ? WHERE id = ?")
            ->execute(array($nome ?: null, $telOk, $convId));
        audit_log('zapi_editar_conv', 'zapi_conversas', $convId, "nome={$nome};tel={$telOk}");
    } else {
        $pdo->prepare("UPDATE zapi_conversas SET nome_contato = ? WHERE id = ?")
            ->execute(array($nome ?: null, $convId));
        audit_log('zapi_editar_conv', 'zapi_conversas', $convId, "nome={$nome}");
    }
    echo json_encode(array('ok' => true, 'telefone' => $telOk));
    exit;
}

// ── LISTAR ETIQUETAS (com flag de aplicada em conversa) ──
if ($action === 'listar_etiquetas') {
    $convId = (int)($_GET['conversa_id'] ?? 0);
    $sql = "SELECT e.id, e.nome, e.cor, e.ordem,
                   " . ($convId ? "(SELECT 1 FROM zapi_conversa_etiquetas WHERE conversa_id = ? AND etiqueta_id = e.id) AS aplicada" : "0 as aplicada") . "
            FROM zapi_etiquetas e WHERE e.ativo = 1 ORDER BY e.ordem, e.nome";
    $stmt = $pdo->prepare($sql);
    if ($convId) $stmt->execute(array($convId));
    else $stmt->execute();
    echo json_encode(array('ok' => true, 'etiquetas' => $stmt->fetchAll()));
    exit;
}

// ── APLICAR ETIQUETA EM CONVERSA ─────────────────────────
if ($action === 'adicionar_etiqueta') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $etqId  = (int)($_POST['etiqueta_id'] ?? 0);
    if (!$convId || !$etqId) { echo json_encode(array('error' => 'Parâmetros inválidos')); exit; }
    $pdo->prepare("INSERT IGNORE INTO zapi_conversa_etiquetas (conversa_id, etiqueta_id, aplicada_por) VALUES (?, ?, ?)")
        ->execute(array($convId, $etqId, $userId));
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'remover_etiqueta') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $etqId  = (int)($_POST['etiqueta_id'] ?? 0);
    $pdo->prepare("DELETE FROM zapi_conversa_etiquetas WHERE conversa_id = ? AND etiqueta_id = ?")
        ->execute(array($convId, $etqId));
    echo json_encode(array('ok' => true));
    exit;
}

// ── FIXAR/DESFIXAR CONVERSA (no topo da lista de conversas do canal) ──
if ($action === 'pin_conversa') {
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN fixada TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN fixada_em DATETIME NULL"); } catch (Exception $e) {}

    $convId = (int)($_POST['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    $cur = $pdo->prepare("SELECT id, canal, fixada FROM zapi_conversas WHERE id = ?");
    $cur->execute(array($convId));
    $c = $cur->fetch();
    if (!$c) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $novoFixada = empty($c['fixada']) ? 1 : 0;

    if ($novoFixada === 1) {
        // Limite: até 3 fixadas por canal (igual WhatsApp)
        $count = $pdo->prepare("SELECT COUNT(*) FROM zapi_conversas WHERE canal = ? AND fixada = 1");
        $count->execute(array($c['canal']));
        if ((int)$count->fetchColumn() >= 3) {
            echo json_encode(array('error' => 'Limite de 3 conversas fixadas por canal. Desfixe alguma antes.'));
            exit;
        }
    }

    $pdo->prepare("UPDATE zapi_conversas SET fixada = ?, fixada_em = " . ($novoFixada ? 'NOW()' : 'NULL') . " WHERE id = ?")
        ->execute(array($novoFixada, $convId));

    audit_log($novoFixada ? 'zapi_pin_conv' : 'zapi_unpin_conv', 'zapi_conversas', $convId);
    echo json_encode(array('ok' => true, 'fixada' => $novoFixada));
    exit;
}

// ── FIXAR/DESFIXAR MENSAGEM (só no Hub, não sincroniza com WhatsApp real) ──
if ($action === 'pin_mensagem') {
    // Self-heal: coluna pinned
    try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN pinned TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN pinned_at DATETIME NULL"); } catch (Exception $e) {}

    $msgId = (int)($_POST['mensagem_id'] ?? 0);
    if (!$msgId) { echo json_encode(array('error' => 'mensagem_id obrigatório')); exit; }
    $cur = $pdo->prepare("SELECT m.id, m.conversa_id, m.pinned FROM zapi_mensagens m WHERE m.id = ?");
    $cur->execute(array($msgId));
    $m = $cur->fetch();
    if (!$m) { echo json_encode(array('error' => 'Mensagem não encontrada')); exit; }

    $novoPinned = empty($m['pinned']) ? 1 : 0;

    if ($novoPinned === 1) {
        // Limite: até 3 fixadas por conversa (igual WhatsApp)
        $count = $pdo->prepare("SELECT COUNT(*) FROM zapi_mensagens WHERE conversa_id = ? AND pinned = 1");
        $count->execute(array($m['conversa_id']));
        if ((int)$count->fetchColumn() >= 3) {
            echo json_encode(array('error' => 'Limite de 3 mensagens fixadas por conversa. Desfixe alguma antes.'));
            exit;
        }
    }

    $pdo->prepare("UPDATE zapi_mensagens SET pinned = ?, pinned_at = " . ($novoPinned ? 'NOW()' : 'NULL') . " WHERE id = ?")
        ->execute(array($novoPinned, $msgId));

    audit_log($novoPinned ? 'zapi_pin_msg' : 'zapi_unpin_msg', 'zapi_mensagens', $msgId);
    echo json_encode(array('ok' => true, 'pinned' => $novoPinned));
    exit;
}

// ── NOVA CONVERSA (cliente existente ou número novo) ──
if ($action === 'nova_conversa') {
    $canal      = ($_POST['canal'] ?? '') === '24' ? '24' : '21';
    $telefone   = preg_replace('/[^0-9]/', '', $_POST['telefone'] ?? '');
    $nome       = trim($_POST['nome'] ?? '');
    $clientId   = (int)($_POST['client_id'] ?? 0) ?: null;
    $mensagem   = trim($_POST['mensagem'] ?? '');

    if (!$telefone || strlen($telefone) < 10) { echo json_encode(array('error' => 'Telefone inválido (mínimo 10 dígitos)')); exit; }
    if (!$mensagem) { echo json_encode(array('error' => 'Digite a primeira mensagem pra iniciar a conversa')); exit; }

    // Normaliza telefone — adiciona 55 se não tem DDI
    if (strlen($telefone) === 10 || strlen($telefone) === 11) { $telefone = '55' . $telefone; }

    // Se tem client_id mas não mandou nome/telefone, puxa do banco
    if ($clientId && (!$nome || !$telefone)) {
        $cli = $pdo->prepare("SELECT name, phone FROM clients WHERE id = ?");
        $cli->execute(array($clientId));
        $c = $cli->fetch();
        if ($c) {
            if (!$nome) $nome = $c['name'];
            if (!$telefone || strlen($telefone) < 10) $telefone = preg_replace('/\D/', '', $c['phone']);
        }
    }

    // Envia mensagem via Z-API — isso cria a conversa no banco via zapi_buscar_ou_criar_conversa
    $resp = zapi_send_text($canal, $telefone, $mensagem);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($resp['http_code'] ?? '?') . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }
    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    // Busca/cria conversa
    $conv = zapi_buscar_ou_criar_conversa($telefone, $canal, $nome ?: null);
    if ($conv && $clientId && !$conv['client_id']) {
        $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")->execute(array($clientId, $conv['id']));
        // Auto-merge: se já existem outras conversas deste cliente no canal, mescla
        try { zapi_auto_merge_por_client_id($pdo, $conv['id'], (int)$clientId, $canal); } catch (Exception $e) {}
    }
    if ($conv) {
        // Grava a mensagem enviada
        $pdo->prepare(
            "INSERT INTO zapi_mensagens (conversa_id, direcao, tipo, conteudo, enviado_por_id, zapi_message_id, created_at)
             VALUES (?, 'enviada', 'texto', ?, ?, ?, NOW())"
        )->execute(array($conv['id'], $mensagem, current_user_id(), $zapiId));
        $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW() WHERE id = ?")
            ->execute(array(mb_substr($mensagem, 0, 500), $conv['id']));
        // Recalcula score de esfriando do cliente (sem IA, custo zero)
        $_cidConv = !empty($conv['client_id']) ? (int)$conv['client_id'] : (int)$clientId;
        if ($_cidConv) ia_disparar_recalc_esfriando($pdo, $_cidConv);
    }

    audit_log('zapi_nova_conversa', 'zapi_conversas', $conv ? $conv['id'] : 0, 'tel=' . $telefone . ' canal=' . $canal);
    echo json_encode(array('ok' => true, 'conversa_id' => $conv ? $conv['id'] : null, 'canal' => $canal));
    exit;
}

// ── DELETAR MENSAGEM (remove do WhatsApp e marca no banco) ──
if ($action === 'deletar_mensagem') {
    $msgId = (int)($_POST['mensagem_id'] ?? 0);
    if (!$msgId) { echo json_encode(array('error' => 'mensagem_id obrigatório')); exit; }
    $m = $pdo->prepare("SELECT m.*, co.telefone, co.canal
                        FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                        WHERE m.id = ?");
    $m->execute(array($msgId));
    $msg = $m->fetch();
    if (!$msg) { echo json_encode(array('error' => 'Mensagem não encontrada')); exit; }
    if ($msg['direcao'] !== 'enviada') { echo json_encode(array('error' => 'Só dá pra apagar mensagens enviadas pelo Hub')); exit; }
    if (!$msg['zapi_message_id']) { echo json_encode(array('error' => 'Mensagem sem ID Z-API — não foi efetivamente enviada')); exit; }

    $r = zapi_delete_message($msg['canal'], $msg['telefone'], $msg['zapi_message_id']);
    if (empty($r['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($r['http_code'] ?? '?') . ' — ' . json_encode($r['data'] ?? '')));
        exit;
    }
    // Marca como apagada no banco (preserva histórico)
    $pdo->prepare("UPDATE zapi_mensagens SET conteudo = '[mensagem apagada]', tipo = 'outro', status = 'deletada', arquivo_url = NULL WHERE id = ?")
        ->execute(array($msgId));

    // Atualizar preview da conversa (ultima_mensagem) pra mensagem ANTERIOR mais recente que não esteja apagada
    $prev = $pdo->prepare("SELECT conteudo, created_at FROM zapi_mensagens
                           WHERE conversa_id = ? AND status != 'deletada' AND id != ?
                           ORDER BY id DESC LIMIT 1");
    $prev->execute(array($msg['conversa_id'], $msgId));
    $prevMsg = $prev->fetch();
    if ($prevMsg) {
        $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = ? WHERE id = ?")
            ->execute(array(mb_substr($prevMsg['conteudo'], 0, 500), $prevMsg['created_at'], $msg['conversa_id']));
    } else {
        $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = '[mensagem apagada]' WHERE id = ?")
            ->execute(array($msg['conversa_id']));
    }

    audit_log('zapi_delete_msg', 'zapi_mensagens', $msgId);
    echo json_encode(array('ok' => true));
    exit;
}

// ── EDITAR MENSAGEM (reenvia via Z-API com flag edit) ──
if ($action === 'editar_mensagem') {
    $msgId = (int)($_POST['mensagem_id'] ?? 0);
    $novo  = trim($_POST['novo_texto'] ?? '');
    if (!$msgId || !$novo) { echo json_encode(array('error' => 'mensagem_id e novo_texto obrigatórios')); exit; }
    $m = $pdo->prepare("SELECT m.*, co.telefone, co.canal
                        FROM zapi_mensagens m JOIN zapi_conversas co ON co.id = m.conversa_id
                        WHERE m.id = ?");
    $m->execute(array($msgId));
    $msg = $m->fetch();
    if (!$msg) { echo json_encode(array('error' => 'Mensagem não encontrada')); exit; }
    if ($msg['direcao'] !== 'enviada') { echo json_encode(array('error' => 'Só dá pra editar mensagens enviadas pelo Hub')); exit; }
    if ($msg['tipo'] !== 'texto') { echo json_encode(array('error' => 'Só dá pra editar texto')); exit; }
    if (!$msg['zapi_message_id']) { echo json_encode(array('error' => 'Mensagem sem ID Z-API')); exit; }

    // WhatsApp só permite editar até 15 min
    $idadeMin = (time() - strtotime($msg['created_at'])) / 60;
    if ($idadeMin > 15) { echo json_encode(array('error' => 'Passou de 15 min — WhatsApp não permite mais editar. Apague e reenvie.')); exit; }

    $r = zapi_edit_message($msg['canal'], $msg['telefone'], $msg['zapi_message_id'], $novo);
    if (empty($r['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($r['http_code'] ?? '?') . ' — ' . json_encode($r['data'] ?? '')));
        exit;
    }
    $pdo->prepare("UPDATE zapi_mensagens SET conteudo = ? WHERE id = ?")->execute(array($novo, $msgId));
    audit_log('zapi_edit_msg', 'zapi_mensagens', $msgId);
    echo json_encode(array('ok' => true));
    exit;
}

// ── LISTAR CASOS DO CLIENTE (pra escolher pasta do Drive) ──
if ($action === 'casos_do_cliente') {
    $convId = (int)($_GET['conversa_id'] ?? 0);
    $conv = $pdo->prepare("SELECT client_id FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv || !$conv['client_id']) { echo json_encode(array('ok' => true, 'casos' => array(), 'erro' => 'Conversa sem cliente vinculado')); exit; }

    $cases = $pdo->prepare("SELECT id, title AS client_title, case_type, drive_folder_url, status
                            FROM cases WHERE client_id = ?
                            ORDER BY status = 'arquivado' ASC, created_at DESC");
    $cases->execute(array($conv['client_id']));
    echo json_encode(array('ok' => true, 'casos' => $cases->fetchAll()));
    exit;
}

// ── SALVAR ARQUIVO NO DRIVE ──────────────────────────────
// Amanda 07/06/2026: reescrita pra: (1) subpasta "01 - PARA DISTRIBUIR" dentro
// do caso; (2) select de tipo de documento com auto-numeracao (renda_1, _2, _3);
// (3) conversao JPG/PNG/WEBP -> PDF antes de subir; (4) audio/video/PDF sobem
// como estao.
//
// Compatibilidade: aceita 'nome_personalizado' (legado) OU 'tipo_doc' (novo).
// Se 'tipo_doc' enviado, usa fluxo novo (subpasta + auto-num + conversao).
// Amanda 16/06/2026: lista os cases do cliente que tem pasta no Drive
// (usado pelo modo de selecao de imagens pro PDF lote)
if ($action === 'listar_cases_cliente') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    if (!$clientId) { echo json_encode(array('error' => 'client_id obrigatório')); exit; }
    try {
        $st = $pdo->prepare("SELECT id, title FROM cases
                             WHERE client_id = ?
                               AND drive_folder_url IS NOT NULL AND drive_folder_url != ''
                               AND status NOT IN ('arquivado','cancelado')
                             ORDER BY id DESC LIMIT 50");
        $st->execute(array($clientId));
        echo json_encode(array('ok' => true, 'cases' => $st->fetchAll(PDO::FETCH_ASSOC)));
    } catch (Throwable $e) {
        echo json_encode(array('error' => $e->getMessage(), 'cases' => array()));
    }
    exit;
}

// Amanda 16/06/2026: salvar VARIAS imagens como UM PDF unico no Drive do caso.
// Recebe array de mensagem_ids (so imagens). Limite 9MB no PDF final
// (auto-comprime JPG progressivamente: 88 -> 75 -> 65 -> 55 -> 45).
if ($action === 'salvar_lote_pdf_drive') {
    @set_time_limit(240);
    @ignore_user_abort(true);
    @ini_set('memory_limit', '512M'); // imagens em base64 podem comer RAM
    header('Content-Type: application/json; charset=utf-8');
    require_once APP_ROOT . '/core/google_drive.php';
    require_once APP_ROOT . '/core/functions_doc_converter.php';

    $msgIdsRaw = $_POST['mensagem_ids'] ?? array();
    if (!is_array($msgIdsRaw)) $msgIdsRaw = explode(',', (string)$msgIdsRaw);
    $msgIds = array();
    foreach ($msgIdsRaw as $v) { $v = (int)$v; if ($v > 0) $msgIds[] = $v; }
    $msgIds = array_values(array_unique($msgIds));
    $caseId = (int)($_POST['case_id'] ?? 0);
    $nomePersonalizado = trim((string)($_POST['nome_personalizado'] ?? ''));

    if (empty($msgIds))  { echo json_encode(array('error' => 'Nenhuma imagem selecionada')); exit; }
    if (count($msgIds) > 50) { echo json_encode(array('error' => 'Máximo 50 imagens por PDF')); exit; }
    if (!$caseId)        { echo json_encode(array('error' => 'case_id obrigatório')); exit; }

    // Busca o caso
    $caseSt = $pdo->prepare("SELECT id, title, drive_folder_url, client_id FROM cases WHERE id = ?");
    $caseSt->execute(array($caseId));
    $case = $caseSt->fetch(PDO::FETCH_ASSOC);
    if (!$case) { echo json_encode(array('error' => 'Caso não encontrado')); exit; }
    if (!$case['drive_folder_url']) {
        echo json_encode(array('error' => 'Caso sem pasta no Drive. Crie a pasta primeiro pelo Kanban Operacional.'));
        exit;
    }

    // Busca as mensagens (precisam ser imagens da mesma conv)
    $placeholders = implode(',', array_fill(0, count($msgIds), '?'));
    $ms = $pdo->prepare("SELECT m.id, m.tipo, m.arquivo_url, m.arquivo_nome, m.arquivo_mime, m.created_at,
                                co.client_id
                         FROM zapi_mensagens m
                         JOIN zapi_conversas co ON co.id = m.conversa_id
                         WHERE m.id IN ($placeholders) AND co.client_id = ?
                         ORDER BY m.created_at ASC, m.id ASC");
    $ms->execute(array_merge($msgIds, array($case['client_id'])));
    $msgs = $ms->fetchAll(PDO::FETCH_ASSOC);

    if (count($msgs) !== count($msgIds)) {
        echo json_encode(array('error' => 'Algumas mensagens não pertencem ao mesmo cliente do caso.'));
        exit;
    }

    // Cada msg precisa ser imagem com arquivo_url
    foreach ($msgs as $m) {
        if ($m['tipo'] !== 'imagem' || empty($m['arquivo_url'])) {
            echo json_encode(array('error' => 'Mensagem #' . $m['id'] . ' não é uma imagem com arquivo.'));
            exit;
        }
    }

    // Baixa cada imagem pra disco temp
    $tempImgs = array();
    $cleanup = array();
    foreach ($msgs as $m) {
        $tmp = sys_get_temp_dir() . '/lote_' . uniqid('', true);
        $ch = curl_init($m['arquivo_url']);
        $fp = fopen($tmp, 'wb');
        curl_setopt_array($ch, array(
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ));
        $ok = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $http !== 200 || !filesize($tmp)) {
            @unlink($tmp);
            foreach ($cleanup as $c) @unlink($c);
            echo json_encode(array('error' => 'Falha ao baixar imagem da mensagem #' . $m['id'] . ' (HTTP ' . $http . ')'));
            exit;
        }
        $tempImgs[] = $tmp;
        $cleanup[] = $tmp;
    }

    // Gera PDF com limite 9MB
    $tmpPdf = sys_get_temp_dir() . '/pdf_lote_' . uniqid('', true) . '.pdf';
    $r = imagens_para_pdf_multi($tempImgs, $tmpPdf, 9 * 1024 * 1024);
    foreach ($cleanup as $c) @unlink($c);
    if (empty($r['success'])) {
        @unlink($tmpPdf);
        echo json_encode(array('error' => 'Falha ao gerar PDF: ' . ($r['error'] ?? 'desconhecido')));
        exit;
    }

    // Cria subpasta "01 - PARA DISTRIBUIR" (igual o salvar_drive faz)
    $sub = drive_get_or_create_subfolder($case['drive_folder_url'], '01 - PARA DISTRIBUIR');
    if (empty($sub['success'])) {
        @unlink($tmpPdf);
        echo json_encode(array('error' => 'Falha ao criar subpasta no Drive: ' . ($sub['error'] ?? 'desconhecido')));
        exit;
    }
    // Amanda 16/06/2026: URL da SUBPASTA pra mostrar botao 'Abrir pasta'
    $subpastaUrl = 'https://drive.google.com/drive/folders/' . $sub['folderId'];

    // Nome do arquivo
    $prefBase = $nomePersonalizado !== '' ? $nomePersonalizado : 'docs';
    $prefBase = preg_replace('/[^A-Za-z0-9_\.\-]/u', '_', $prefBase);
    $prefBase = preg_replace('/_{2,}/', '_', trim($prefBase, '_'));
    if ($prefBase === '') $prefBase = 'docs';
    $nomeFinal = $prefBase . '_' . date('Ymd_His') . '_' . count($msgs) . 'imgs.pdf';

    // Upload base64
    $bytes = file_get_contents($tmpPdf);
    $up = upload_file_to_drive_base64($sub['folderId'], $nomeFinal, base64_encode($bytes), 'application/pdf');
    @unlink($tmpPdf);

    if (empty($up['success'])) {
        echo json_encode(array('error' => 'Falha no upload pro Drive: ' . ($up['error'] ?? 'desconhecido')));
        exit;
    }

    // Marca as msgs com salvo_drive_em (igual o salvar_drive faz)
    try {
        $upd = $pdo->prepare("UPDATE zapi_mensagens SET salvo_drive_em = NOW(), salvo_drive_url = ? WHERE id IN ($placeholders)");
        $upd->execute(array_merge(array($up['fileUrl']), $msgIds));
    } catch (Throwable $e) {}

    audit_log('wa_salvar_lote_pdf_drive', 'cases', $caseId, count($msgs) . ' imgs · ' . $r['bytes'] . ' bytes · q=' . $r['qualidade']);

    echo json_encode(array(
        'ok'           => true,
        'drive_url'    => $up['fileUrl'],
        'subpasta_url' => $subpastaUrl,
        'nome_arquivo' => $nomeFinal,
        'case_title'   => $case['title'],
        'paginas'      => $r['paginas'],
        'bytes'        => $r['bytes'],
        'mb'           => round($r['bytes']/1024/1024, 2),
        'qualidade'    => $r['qualidade'],
    ));
    exit;
}

if ($action === 'salvar_drive') {
    require_once APP_ROOT . '/core/google_drive.php';
    $msgId = (int)($_POST['mensagem_id'] ?? 0);
    $caseId = (int)($_POST['case_id'] ?? 0);
    if (!$msgId || !$caseId) { echo json_encode(array('error' => 'Parametros obrigatorios')); exit; }

    $msg = $pdo->prepare("SELECT m.*, co.client_id FROM zapi_mensagens m
                          JOIN zapi_conversas co ON co.id = m.conversa_id
                          WHERE m.id = ?");
    $msg->execute(array($msgId));
    $msg = $msg->fetch();
    if (!$msg) { echo json_encode(array('error' => 'Mensagem nao encontrada')); exit; }
    if (!$msg['arquivo_url']) { echo json_encode(array('error' => 'Mensagem sem arquivo')); exit; }
    // Amanda 07/06/2026: removida a trava de "ja salvo" — mesmo arquivo pode ser
    // salvo varias vezes (case A + case B, ou tipo errado/certo, etc).

    $case = $pdo->prepare("SELECT drive_folder_url FROM cases WHERE id = ? AND client_id = ?");
    $case->execute(array($caseId, $msg['client_id']));
    $caseRow = $case->fetch();
    if (!$caseRow || !$caseRow['drive_folder_url']) {
        echo json_encode(array('error' => 'Caso sem pasta no Drive. Crie a pasta primeiro pelo Kanban Operacional.'));
        exit;
    }

    $tipoDoc = trim((string)($_POST['tipo_doc'] ?? ''));
    $nomePersonalizado = trim((string)($_POST['nome_personalizado'] ?? ''));
    // Amanda 03/07: primeiro nome do titular pra documentos pessoais
    // (identidade, cpf, cnh, ctps, certidões). Anexado ao prefixo no fluxo abaixo.
    $titularNome = trim((string)($_POST['titular_nome'] ?? ''));

    // ─── FLUXO NOVO: tipo_doc preenchido ──────────────────────────────
    if ($tipoDoc !== '') {
        require_once APP_ROOT . '/core/functions_doc_converter.php';

        // Mapa de tipos -> { prefixo, forcar_numeracao }
        // forcar_numeracao=true: sempre adiciona _N (mesmo no primeiro). Pra tipos
        // que naturalmente tem multiplas instancias (renda, contracheque, laudos).
        // forcar_numeracao=false: primeiro fica sem _N, segundos em diante ganham _2, _3.
        $tiposMap = array(
            'comprovante_residencia' => array('prefixo' => 'comprovante_residencia', 'forcar' => false),
            'comprovante_renda'      => array('prefixo' => 'comprovante_renda',      'forcar' => true),
            'contracheque'           => array('prefixo' => 'contracheque',           'forcar' => true),
            'identidade_CPF'         => array('prefixo' => 'identidade_CPF',         'forcar' => false),
            'cnh'                    => array('prefixo' => 'cnh',                    'forcar' => false),
            'ctps'                   => array('prefixo' => 'ctps',                   'forcar' => true),
            'cert_nascimento'        => array('prefixo' => 'cert_nascimento',        'forcar' => false),
            'cert_casamento'         => array('prefixo' => 'cert_casamento',         'forcar' => false),
            'cert_obito'             => array('prefixo' => 'cert_obito',             'forcar' => false),
            'procuracao'             => array('prefixo' => 'procuracao',             'forcar' => false),
            'processo_anterior'      => array('prefixo' => 'processo_anterior',      'forcar' => true),
            'laudo_medico'           => array('prefixo' => 'laudo_medico',           'forcar' => true),
            'atestado_medico'        => array('prefixo' => 'atestado_medico',        'forcar' => true),
            'BO'                     => array('prefixo' => 'BO',                     'forcar' => true),
            'contrato'               => array('prefixo' => 'contrato',               'forcar' => true),
            'print'                  => array('prefixo' => 'print',                  'forcar' => true),
            'docs_probatorios'       => array('prefixo' => 'docs_probatorios',       'forcar' => true),
            'outro'                  => array('prefixo' => null,                     'forcar' => false), // usa nome_personalizado
        );

        if (!isset($tiposMap[$tipoDoc])) {
            echo json_encode(array('error' => 'Tipo de documento invalido: ' . $tipoDoc));
            exit;
        }

        $config = $tiposMap[$tipoDoc];
        $prefixo = $config['prefixo'];

        // Pra tipo "outro", o prefixo vem do nome_personalizado (sanitizado)
        if ($tipoDoc === 'outro') {
            if ($nomePersonalizado === '') {
                echo json_encode(array('error' => 'Para tipo "Outro", informe um nome personalizado.'));
                exit;
            }
            // Sanitiza: so letras, numeros, _ - .
            $prefixo = preg_replace('/[^A-Za-z0-9_\.\-]/u', '_', $nomePersonalizado);
            $prefixo = trim($prefixo, '_');
            $prefixo = preg_replace('/_{2,}/', '_', $prefixo);
            // Remove extensao se usuario digitou
            $prefixo = preg_replace('/\.(pdf|jpg|jpeg|png|gif|webp|mp3|mp4|ogg|opus)$/i', '', $prefixo);
            if ($prefixo === '') {
                echo json_encode(array('error' => 'Nome personalizado invalido apos sanitizacao.'));
                exit;
            }
            $prefixo = mb_substr($prefixo, 0, 80);
        }

        // Amanda 03/07: anexa "_titular" no prefixo pra documentos pessoais.
        // Tipos que aceitam titular: identidade_CPF, cnh, ctps, cert_nascimento,
        // cert_casamento, cert_obito. Ficam tipo: cert_nascimento_joao.pdf
        $tiposComTitular = array('identidade_CPF','cnh','ctps','cert_nascimento','cert_casamento','cert_obito');
        if (in_array($tipoDoc, $tiposComTitular, true) && $titularNome !== '') {
            // Sanitiza: só primeiro nome, lowercase, ASCII puro
            $primeiroNome = trim(preg_split('/\s+/', $titularNome)[0]);
            $primeiroNome = mb_strtolower($primeiroNome, 'UTF-8');
            // Remove acentos manualmente (evita depender de intl/iconv que podem falhar)
            $primeiroNome = strtr($primeiroNome,
                array(
                    'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
                    'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
                    'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                    'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
                    'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
                    'ç'=>'c','ñ'=>'n',
                )
            );
            $primeiroNome = preg_replace('/[^a-z0-9]/', '', $primeiroNome);
            $primeiroNome = mb_substr($primeiroNome, 0, 20);
            if ($primeiroNome !== '') {
                $prefixo = $prefixo . '_' . $primeiroNome;
            }
        }

        // Decide extensao + se precisa converter
        $vaiConverter = false;
        $extFinal = 'pdf';
        $mimeOriginal = strtolower((string)$msg['arquivo_mime']);
        $tipoMsg = $msg['tipo'];

        if ($tipoMsg === 'imagem' || strpos($mimeOriginal, 'image/') === 0) {
            $vaiConverter = true;
            $extFinal = 'pdf';
        } elseif (strpos($mimeOriginal, 'application/pdf') !== false || pathinfo((string)$msg['arquivo_nome'], PATHINFO_EXTENSION) === 'pdf') {
            $extFinal = 'pdf';
        } elseif ($tipoMsg === 'video' || strpos($mimeOriginal, 'video/') === 0) {
            $extFinal = 'mp4';
        } elseif ($tipoMsg === 'audio' || strpos($mimeOriginal, 'audio/') === 0) {
            // Audio do WA vem em ogg/opus - salva como ogg (Amanda escolheu nao converter)
            $extFinal = 'ogg';
        } else {
            // Tipo desconhecido: salva extensao original do arquivo_nome
            $extOriginal = pathinfo((string)$msg['arquivo_nome'], PATHINFO_EXTENSION);
            $extFinal = $extOriginal ?: 'bin';
        }

        // Cria/pega subpasta "01 - PARA DISTRIBUIR"
        $subResult = drive_get_or_create_subfolder($caseRow['drive_folder_url'], '01 - PARA DISTRIBUIR');
        if (empty($subResult['success'])) {
            echo json_encode(array('error' => 'Falha ao criar/localizar subpasta: ' . ($subResult['error'] ?? '?')));
            exit;
        }
        $subfolderId = $subResult['folderId'];

        // Calcula nome final com auto-numeracao
        $nomeFinal = drive_calcular_nome_disponivel($subfolderId, $prefixo, $extFinal, $config['forcar']);

        // Upload: 2 caminhos diferentes dependendo se vamos converter ou nao
        if ($vaiConverter) {
            // Baixa imagem + converte pra PDF localmente + upload via base64
            $conv = baixar_e_converter_imagem_para_pdf($msg['arquivo_url']);
            if (empty($conv['success'])) {
                echo json_encode(array('error' => 'Falha na conversao para PDF: ' . ($conv['error'] ?? '?')));
                exit;
            }
            $pdfBytes = file_get_contents($conv['caminho_pdf']);
            @unlink($conv['caminho_pdf']);
            if (!$pdfBytes) {
                echo json_encode(array('error' => 'Falha ao ler PDF gerado'));
                exit;
            }
            $r = upload_file_to_drive_base64($subfolderId, $nomeFinal, base64_encode($pdfBytes), 'application/pdf');
        } else {
            // Upload direto via URL (Apps Script baixa a URL publica do WA)
            // Precisamos passar folderId via URL forjada que a regex do upload_file_to_drive
            // consiga parsear. Truque: monta uma URL valida do Drive com o subfolderId.
            $fakeFolderUrl = 'https://drive.google.com/drive/folders/' . $subfolderId;
            $r = upload_file_to_drive($fakeFolderUrl, $nomeFinal, $msg['arquivo_url'], $mimeOriginal);
        }

        if (empty($r['success'])) {
            echo json_encode(array('error' => 'Falha no upload: ' . ($r['error'] ?? '?')));
            exit;
        }

        $pdo->prepare("UPDATE zapi_mensagens SET arquivo_salvo_drive = 1, drive_file_id = ? WHERE id = ?")
            ->execute(array($r['fileId'] ?? '', $msgId));
        audit_log('zapi_salvar_drive', 'zapi_mensagens', $msgId, "case=$caseId tipo=$tipoDoc nome=$nomeFinal subpasta=$subfolderId file={$r['fileId']}");
        echo json_encode(array(
            'ok'         => true,
            'fileUrl'    => $r['fileUrl'] ?? null,
            'nome_final' => $nomeFinal,
            'subpasta'   => '01 - PARA DISTRIBUIR',
            'convertido' => $vaiConverter,
        ));
        exit;
    }

    // ─── FLUXO LEGADO: nome_personalizado (mantido pra compatibilidade) ──
    if ($nomePersonalizado !== '') {
        $nomePersonalizado = preg_replace('/[\/\\\\\\x00-\\x1F<>:"|?*]/', '', $nomePersonalizado);
        $nomePersonalizado = str_replace(array('..', '~'), '', $nomePersonalizado);
        $nomePersonalizado = mb_substr($nomePersonalizado, 0, 200);
        $nomeFinal = $nomePersonalizado;
    } else {
        $nomeFinal = $msg['arquivo_nome'] ?: ('whatsapp_' . date('Ymd_His') . '_' . $msgId);
    }
    if (!pathinfo($nomeFinal, PATHINFO_EXTENSION)) {
        $ext = 'bin';
        if ($msg['arquivo_mime']) {
            $ext = preg_replace('/.*\//', '', $msg['arquivo_mime']);
            if ($msg['tipo'] === 'imagem') $ext = 'jpg';
            elseif ($msg['tipo'] === 'video') $ext = 'mp4';
            elseif ($msg['tipo'] === 'audio') $ext = 'ogg';
        }
        $nomeFinal .= '.' . $ext;
    }

    $r = upload_file_to_drive($caseRow['drive_folder_url'], $nomeFinal, $msg['arquivo_url'], $msg['arquivo_mime'] ?? '');
    if (empty($r['success'])) {
        echo json_encode(array('error' => 'Falha no upload: ' . ($r['error'] ?? '?')));
        exit;
    }
    $pdo->prepare("UPDATE zapi_mensagens SET arquivo_salvo_drive = 1, drive_file_id = ? WHERE id = ?")
        ->execute(array($r['fileId'] ?? '', $msgId));
    audit_log('zapi_salvar_drive', 'zapi_mensagens', $msgId, "case=$caseId file={$r['fileId']} nome=$nomeFinal (legado)");
    echo json_encode(array('ok' => true, 'fileUrl' => $r['fileUrl'] ?? null, 'nome_final' => $nomeFinal));
    exit;
}

// ── EXPORTAR CONVERSA EM .TXT → DRIVE DO PROCESSO ────────
if ($action === 'exportar_conversa') {
    require_once APP_ROOT . '/core/google_drive.php';
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $destinoFolder = trim($_POST['destino_folder'] ?? ''); // processo escolhido OU link colado
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    $c = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $c->execute(array($convId));
    $conv = $c->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $nomeCli = $conv['nome_contato'] ?: ($conv['telefone'] ?: ('Conversa #' . $convId));
    $ms = $pdo->prepare("SELECT m.id, m.direcao, m.tipo, m.conteudo, m.enviado_por_id, m.enviado_por_bot,
                                m.created_at, m.arquivo_nome, m.transcricao, u.name AS atendente
                         FROM zapi_mensagens m LEFT JOIN users u ON u.id = m.enviado_por_id
                         WHERE m.conversa_id = ? ORDER BY m.id ASC");
    $ms->execute(array($convId));
    $rows = $ms->fetchAll();

    // Transcrição de áudios: usa a salva; se faltar e o Groq estiver ligado,
    // transcreve na hora (cacheia no banco). Limite pra não estourar timeout.
    @set_time_limit(300);
    $_groqOn = false;
    try {
        require_once APP_ROOT . '/core/functions_groq.php';
        $_groqOn = function_exists('groq_transcribe_enabled') && groq_transcribe_enabled();
    } catch (Exception $e) { $_groqOn = false; }
    $_transcRestantes = 25; // teto de transcrições novas por exportação

    $L = array();
    $L[] = 'Conversa WhatsApp — Ferreira & Sá Advocacia';
    $L[] = 'Contato: ' . $nomeCli;
    $L[] = 'Telefone: ' . ($conv['telefone'] ?: '-');
    $L[] = 'Canal: ' . ($conv['canal'] ?: '-');
    $L[] = 'Total de mensagens: ' . count($rows);
    $L[] = 'Exportado em: ' . date('d/m/Y H:i') . ' por ' . (function_exists('user_display_name') ? (user_display_name() ?: ('user#' . $userId)) : ('user#' . $userId));
    $L[] = str_repeat('-', 60);
    $L[] = '';
    $mark = array('imagem' => '[imagem]', 'audio' => '[áudio]', 'documento' => '[documento]',
                  'video' => '[vídeo]', 'sticker' => '[figurinha]', 'contato' => '[contato]',
                  'localizacao' => '[localização]', 'ptt' => '[áudio]');
    foreach ($rows as $r) {
        $ts = $r['created_at'] ? date('d/m/Y H:i', strtotime($r['created_at'])) : '';
        if ($r['direcao'] === 'recebida') $quem = $nomeCli;
        elseif (!empty($r['enviado_por_bot'])) $quem = 'Assistente';
        elseif (!empty($r['atendente'])) $quem = $r['atendente'];
        else $quem = 'Equipe';
        $tipo = strtolower((string)($r['tipo'] ?? 'texto'));
        $txt = trim((string)$r['conteudo']);
        if ($tipo !== 'texto' && isset($mark[$tipo])) {
            $corpo = $mark[$tipo];
            if (!empty($r['arquivo_nome'])) $corpo .= ' ' . $r['arquivo_nome'];
            if ($txt !== '' && $txt !== '[' . $tipo . ']') $corpo .= ' — ' . $txt;
        } else {
            $corpo = $txt !== '' ? $txt : '[mensagem vazia]';
        }
        $L[] = "[{$ts}] {$quem}: {$corpo}";
        // Áudio → anexa transcrição (existente ou transcreve agora)
        if ($tipo === 'audio' || $tipo === 'ptt') {
            $tr = trim((string)($r['transcricao'] ?? ''));
            if ($tr === '' && $_groqOn && $_transcRestantes > 0 && function_exists('groq_transcribe_mensagem')) {
                $_transcRestantes--;
                try {
                    $gr = groq_transcribe_mensagem((int)$r['id']);
                    if (!empty($gr['ok']) && !empty($gr['text'])) $tr = trim($gr['text']);
                } catch (Exception $e) { /* segue sem transcrição */ }
            }
            if ($tr !== '') {
                $L[] = '      ↳ transcrição do áudio: "' . $tr . '"';
            } else {
                $L[] = '      ↳ (áudio sem transcrição disponível)';
            }
        }
    }
    $conteudoTxt = implode("\r\n", $L) . "\r\n";

    $dir = APP_ROOT . '/files/wa_export';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    foreach (glob($dir . '/*.txt') as $old) { if (@filemtime($old) < time() - 172800) @unlink($old); }
    $token = 'conv' . $convId . '_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 8);
    $nomeArq = 'Conversa WhatsApp - ' . trim(preg_replace('/[^\p{L}\p{N} _-]/u', '', $nomeCli)) . ' - ' . date('Y-m-d') . '.txt';
    $localPath = $dir . '/' . $token . '.txt';
    if (@file_put_contents($localPath, $conteudoTxt) === false) {
        echo json_encode(array('error' => 'Falha ao gerar o arquivo')); exit;
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'ferreiraesa.com.br';
    $publicUrl = 'https://' . $host . '/conecta/files/wa_export/' . rawurlencode($token) . '.txt';

    if ($destinoFolder !== '') {
        $up = upload_file_to_drive($destinoFolder, $nomeArq, $publicUrl, 'text/plain');
        @file_put_contents(APP_ROOT . '/files/wa_export_debug.log',
            date('Y-m-d H:i:s') . " conv={$convId} url={$publicUrl} folder=" . substr($destinoFolder, 0, 80)
            . " => " . json_encode($up, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
        if (empty($up['success'])) {
            echo json_encode(array('error' => 'Falha ao salvar no Drive: ' . ($up['error'] ?? '?'), 'download_url' => $publicUrl));
            exit;
        }
        audit_log('zapi_exportar_conversa', 'zapi_conversas', $convId, 'drive=' . ($up['fileId'] ?? ''));
        echo json_encode(array('ok' => true, 'salvou_drive' => true, 'drive_url' => $up['fileUrl'] ?? null, 'download_url' => $publicUrl));
        exit;
    }

    $casos = array();
    if (!empty($conv['client_id'])) {
        $cs = $pdo->prepare("SELECT id, title, drive_folder_url FROM cases
                             WHERE client_id = ? AND drive_folder_url IS NOT NULL AND drive_folder_url <> ''
                             ORDER BY status = 'arquivado' ASC, created_at DESC");
        $cs->execute(array($conv['client_id']));
        foreach ($cs->fetchAll() as $row) {
            $casos[] = array('id' => (int)$row['id'], 'title' => $row['title'] ?: ('Processo #' . $row['id']), 'folder' => $row['drive_folder_url']);
        }
    }
    audit_log('zapi_exportar_conversa', 'zapi_conversas', $convId, 'gerado token=' . $token . ' casos=' . count($casos));
    echo json_encode(array('ok' => true, 'download_url' => $publicUrl, 'file_name' => $nomeArq, 'casos' => $casos));
    exit;
}

// ── VERIFICAR STATUS DA INSTÂNCIA ────────────────────────
if ($action === 'verificar_status') {
    $ddd = $_GET['ddd'] ?? '21';
    if (!in_array($ddd, array('21','24'), true)) { echo json_encode(array('error'=>'DDD inválido')); exit; }
    $conectado = zapi_verificar_status($ddd);
    echo json_encode(array('ok' => true, 'conectado' => $conectado));
    exit;
}

// ── ENVIAR ARQUIVO (imagem ou documento) ─────────────────
if ($action === 'enviar_arquivo') {
    $convId  = (int)($_POST['conversa_id'] ?? 0);
    $caption = trim($_POST['caption'] ?? '');
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    // Trava de atendimento (8h úteis seg-sex destrava se cliente é última; 36h se equipe é última)
    $lock = zapi_pode_enviar_conversa($convId, $userId);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois da trava liberar (8h úteis sem resposta, ou 36h de follow-up), ou se assumir a conversa."));
        exit;
    }

    if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(array('error' => 'Falha no upload'));
        exit;
    }

    $conv = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $tmp = $_FILES['arquivo']['tmp_name'];
    $nome = $_FILES['arquivo']['name'];
    $mime = $_FILES['arquivo']['type'] ?: mime_content_type($tmp);
    $tam  = (int)$_FILES['arquivo']['size'];

    // Limite 16 MB (WhatsApp aceita até ~100MB em docs, mas começamos conservador)
    if ($tam > 16 * 1024 * 1024) { echo json_encode(array('error' => 'Arquivo maior que 16 MB')); exit; }

    // Guardar o arquivo localmente em /files/whatsapp/ (para servir ao Z-API via URL)
    $destDir = APP_ROOT . '/files/whatsapp';
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $nomeSanitizado = preg_replace('/[^A-Za-z0-9._-]/', '_', $nome);
    $storedName = uniqid('wa_', true) . '_' . $nomeSanitizado;
    $dest = $destDir . '/' . $storedName;
    if (!move_uploaded_file($tmp, $dest)) {
        echo json_encode(array('error' => 'Falha ao salvar arquivo no servidor'));
        exit;
    }
    @chmod($dest, 0644);
    $publicUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ferreiraesa.com.br') . '/conecta/files/whatsapp/' . rawurlencode($storedName);

    // Detectar tipo
    $isImage = (strpos($mime, 'image/') === 0);
    $tipo    = $isImage ? 'imagem' : 'documento';

    // Enviar via Z-API
    // Amanda 16/06/2026: imagens via base64 INLINE em vez de URL publica.
    // Bug confirmado: ao enviar imagem via URL publica, a Z-API aceitava
    // (gerava ID sintetico 3EB0... 20 chars) mas a entrega real falhava —
    // o WhatsApp nunca recebia. Base64 elimina o passo 'Z-API baixa de
    // servidor externo' e a mensagem chega de verdade (vira ID A5... 32 chars).
    if ($isImage) {
        $bytes = @file_get_contents($dest);
        $b64   = $bytes !== false ? ('data:' . $mime . ';base64,' . base64_encode($bytes)) : $publicUrl;
        $resp  = zapi_send_image($conv['canal'], $conv['telefone'], $b64, $caption);
    } else {
        // Documentos seguem por URL (PDFs grandes em base64 estouram payload)
        $resp = zapi_send_document($conv['canal'], $conv['telefone'], $publicUrl, $nome, $caption);
    }
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($resp['http_code'] ?? '?') . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    $pdo->prepare(
        "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo,
            arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho, enviado_por_id, status)
         VALUES (?, ?, 'enviada', ?, ?, ?, ?, ?, ?, ?, 'enviada')"
    )->execute(array($convId, $zapiId, $tipo, $caption ?: '[' . $tipo . ']', $publicUrl, $nome, $mime, $tam, $userId));

    $preview = $caption ?: ('[' . $tipo . '] ' . $nome);
    $setAtend = zapi_sql_set_atendente_pos_envio($conv['canal'], $conv['atendente_id'], $userId);
    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                   status = CASE WHEN status IN ('aguardando','resolvido') THEN 'em_atendimento' ELSE status END,
                   {$setAtend}
                   WHERE id = ?")
        ->execute(array(mb_substr($preview, 0, 500), $convId));

    if (!empty($conv['client_id'])) ia_disparar_recalc_esfriando($pdo, (int)$conv['client_id']);

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId, 'url' => $publicUrl));
    exit;
}

// ── ENVIAR ÁUDIO (nota de voz gravada pelo navegador) ───
if ($action === 'enviar_audio') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    // Trava de atendimento (8h úteis seg-sex destrava se cliente é última; 36h se equipe é última)
    $lock = zapi_pode_enviar_conversa($convId, $userId);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois da trava liberar (8h úteis sem resposta, ou 36h de follow-up), ou se assumir a conversa."));
        exit;
    }

    if (empty($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(array('error' => 'Falha no upload do áudio'));
        exit;
    }

    $conv = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $tmp = $_FILES['audio']['tmp_name'];
    $mime = $_FILES['audio']['type'] ?: (mime_content_type($tmp) ?: 'audio/webm');
    $tam  = (int)$_FILES['audio']['size'];
    if ($tam > 16 * 1024 * 1024) { echo json_encode(array('error' => 'Áudio maior que 16 MB')); exit; }

    // Determinar extensão pelo mime
    $ext = 'webm';
    if (strpos($mime, 'ogg') !== false) $ext = 'ogg';
    elseif (strpos($mime, 'mpeg') !== false || strpos($mime, 'mp3') !== false) $ext = 'mp3';
    elseif (strpos($mime, 'wav') !== false) $ext = 'wav';
    elseif (strpos($mime, 'm4a') !== false || strpos($mime, 'mp4') !== false) $ext = 'm4a';

    $destDir = APP_ROOT . '/files/whatsapp';
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $storedName = 'wa_audio_' . uniqid('', true) . '.' . $ext;
    $dest = $destDir . '/' . $storedName;
    if (!move_uploaded_file($tmp, $dest)) {
        echo json_encode(array('error' => 'Falha ao salvar áudio no servidor'));
        exit;
    }
    @chmod($dest, 0644);
    $publicUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ferreiraesa.com.br') . '/conecta/files/whatsapp/' . rawurlencode($storedName);

    // Passa o CAMINHO LOCAL pra zapi_send_audio. Se o arquivo for legível,
    // ela converte pra base64 e força Z-API a re-hospedar no CDN dela —
    // resolve o bug "Este áudio não está mais disponível" pra WebM do Chrome.
    // Fallback: se o arquivo não estiver legível, manda a URL pública mesmo.
    $audioParam = is_readable($dest) ? $dest : $publicUrl;
    $resp = zapi_send_audio($conv['canal'], $conv['telefone'], $audioParam, true);
    if (empty($resp['ok'])) {
        // Amanda 17/06/2026: msg de erro mais util quando HTTP 0 (sem resposta da Z-API)
        $http = $resp['http_code'] ?? '?';
        if ($http === 0 || $http === '0') {
            $erroDetalhe = $resp['erro'] ?: 'sem resposta da Z-API (provável timeout)';
            $msg = '⚠️ Não consegui falar com a Z-API pra enviar o áudio. Motivo: ' . $erroDetalhe
                 . '. Áudios longos podem demorar mais — tente gravar um mais curto ou tente de novo.';
        } else {
            $msg = 'Z-API recusou: HTTP ' . $http . ' — ' . json_encode($resp['data'] ?? '');
        }
        echo json_encode(array('error' => $msg));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    $pdo->prepare(
        "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo,
            arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho, enviado_por_id, status)
         VALUES (?, ?, 'enviada', 'audio', '[áudio]', ?, ?, ?, ?, ?, 'enviada')"
    )->execute(array($convId, $zapiId, $publicUrl, $storedName, $mime, $tam, $userId));
    $newMsgId = (int)$pdo->lastInsertId();

    // Transcrever o áudio que acabamos de enviar (pro histórico)
    require_once APP_ROOT . '/core/functions_groq.php';
    if (groq_transcribe_enabled()) {
        try { groq_transcribe_mensagem($newMsgId); } catch (Exception $e) {}
    }

    $setAtend = zapi_sql_set_atendente_pos_envio($conv['canal'], $conv['atendente_id'], $userId);
    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = '[áudio]', ultima_msg_em = NOW(),
                   status = CASE WHEN status IN ('aguardando','resolvido') THEN 'em_atendimento' ELSE status END,
                   {$setAtend}
                   WHERE id = ?")
        ->execute(array($convId));

    if (!empty($conv['client_id'])) ia_disparar_recalc_esfriando($pdo, (int)$conv['client_id']);

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId, 'url' => $publicUrl));
    exit;
}

// ── ENVIAR STICKER (figurinha) ───────────────────────────
// Aceita upload de arquivo .webp (ou image convertida). WhatsApp espera
// stickers em formato webp 512x512. Outros formatos são enviados como está
// e a Z-API faz conversão quando possível.
if ($action === 'enviar_sticker') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    // Trava de atendimento (8h úteis seg-sex destrava se cliente é última; 36h se equipe é última)
    $lock = zapi_pode_enviar_conversa($convId, $userId);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois da trava liberar (8h úteis sem resposta, ou 36h de follow-up), ou se assumir a conversa."));
        exit;
    }

    if (empty($_FILES['sticker']) || $_FILES['sticker']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(array('error' => 'Falha no upload do sticker'));
        exit;
    }

    $conv = $pdo->prepare("SELECT * FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $tmp = $_FILES['sticker']['tmp_name'];
    $mime = $_FILES['sticker']['type'] ?: (mime_content_type($tmp) ?: 'image/webp');
    $tam  = (int)$_FILES['sticker']['size'];
    if ($tam > 2 * 1024 * 1024) { echo json_encode(array('error' => 'Sticker maior que 2 MB')); exit; }

    $destDir = APP_ROOT . '/files/whatsapp';
    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
    $ext = 'webp';
    if (strpos($mime, 'png') !== false) $ext = 'png';
    elseif (strpos($mime, 'jpeg') !== false || strpos($mime, 'jpg') !== false) $ext = 'jpg';
    elseif (strpos($mime, 'gif') !== false) $ext = 'gif';
    $storedName = 'wa_sticker_' . uniqid('', true) . '.' . $ext;
    $dest = $destDir . '/' . $storedName;
    if (!move_uploaded_file($tmp, $dest)) {
        echo json_encode(array('error' => 'Falha ao salvar sticker no servidor'));
        exit;
    }
    @chmod($dest, 0644);
    $publicUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'ferreiraesa.com.br') . '/conecta/files/whatsapp/' . rawurlencode($storedName);

    $resp = zapi_send_sticker($conv['canal'], $conv['telefone'], $publicUrl);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($resp['http_code'] ?? '?') . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    $pdo->prepare(
        "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo,
            arquivo_url, arquivo_nome, arquivo_mime, arquivo_tamanho, enviado_por_id, status)
         VALUES (?, ?, 'enviada', 'sticker', '[figurinha]', ?, ?, ?, ?, ?, 'enviada')"
    )->execute(array($convId, $zapiId, $publicUrl, $storedName, $mime, $tam, $userId));

    $setAtend = zapi_sql_set_atendente_pos_envio($conv['canal'], $conv['atendente_id'], $userId);
    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = '[figurinha]', ultima_msg_em = NOW(),
                   status = CASE WHEN status IN ('aguardando','resolvido') THEN 'em_atendimento' ELSE status END,
                   {$setAtend}
                   WHERE id = ?")
        ->execute(array($convId));

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId, 'url' => $publicUrl));
    exit;
}

// ── REAGIR A UMA MENSAGEM (emoji) ────────────────────────
// Envia uma reação (emoji) a uma mensagem específica. emoji='' remove.
if ($action === 'enviar_reacao') {
    $msgId  = (int)($_POST['mensagem_id'] ?? 0);
    $emoji  = trim($_POST['emoji'] ?? '');
    if (!$msgId) { echo json_encode(array('error' => 'mensagem_id obrigatório')); exit; }

    $m = $pdo->prepare("SELECT m.id, m.zapi_message_id, m.conversa_id, c.telefone, c.canal
                         FROM zapi_mensagens m JOIN zapi_conversas c ON c.id = m.conversa_id
                         WHERE m.id = ?");
    $m->execute(array($msgId));
    $row = $m->fetch();
    if (!$row) { echo json_encode(array('error' => 'Mensagem não encontrada')); exit; }
    if (empty($row['zapi_message_id'])) {
        echo json_encode(array('error' => 'Mensagem sem ID Z-API (não é possível reagir)'));
        exit;
    }

    $resp = zapi_send_reaction($row['canal'], $row['telefone'], $row['zapi_message_id'], $emoji);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($resp['http_code'] ?? '?') . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    // Salva a reação na própria mensagem (coluna JSON simples).
    try { $pdo->exec("ALTER TABLE zapi_mensagens ADD COLUMN minha_reacao VARCHAR(20) DEFAULT NULL"); } catch (Exception $e) {}
    $pdo->prepare("UPDATE zapi_mensagens SET minha_reacao = ? WHERE id = ?")
        ->execute(array($emoji !== '' ? $emoji : null, $msgId));

    echo json_encode(array('ok' => true));
    exit;
}

// ── ENVIO RÁPIDO (de qualquer tela do Hub) ───────────────
// Usado por botões fora do WhatsApp: cobrança, ficha cliente, proposta,
// portal (link Central VIP), etc. Respeita a trava de atendimento: se
// já existe conversa travada com outro atendente (e há atividade nas
// últimas 30 min), bloqueia. Amanda/Luiz têm bypass.
if ($action === 'enviar_rapido') {
    $telefone = trim($_POST['telefone'] ?? '');
    $mensagem = trim($_POST['mensagem'] ?? '');
    $canal    = in_array($_POST['canal'] ?? '', array('21','24'), true) ? $_POST['canal'] : '24';
    $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
    $leadId   = (int)($_POST['lead_id'] ?? 0) ?: null;
    $nomeHint = trim($_POST['nome'] ?? '');

    if (!$telefone || !$mensagem) {
        echo json_encode(array('error' => 'Telefone e mensagem obrigatórios'));
        exit;
    }

    // Trava de atendimento só vale no canal 21 (Comercial). CX/Operacional (24)
    // é colaborativo — qualquer pessoa envia, sem restrição.
    if ($canal !== '24') {
        try {
            $inst = zapi_get_instancia($canal);
            if ($inst) {
                $telNorm = zapi_normaliza_telefone($telefone);
                $qConv = $pdo->prepare("SELECT id FROM zapi_conversas WHERE telefone = ? AND instancia_id = ? LIMIT 1");
                $qConv->execute(array($telNorm, $inst['id']));
                $cid = (int)$qConv->fetchColumn();
                if ($cid) {
                    $lock = zapi_pode_enviar_conversa($cid, $userId);
                    if (empty($lock['pode'])) {
                        echo json_encode(array(
                            'error' => "Esta conversa está em atendimento com {$lock['atendente_name']}. Você só pode enviar depois da trava liberar (8h úteis sem resposta, ou 36h de follow-up), ou se assumir a conversa no módulo WhatsApp."
                        ));
                        exit;
                    }
                }
            }
        } catch (Exception $e) { /* se falhar checagem, permite (best-effort) */ }
    }

    // Aplica assinatura (se ligada) também no envio rápido (waSenderOpen)
    $assinar2 = zapi_auto_cfg('zapi_signature_on', '0') === '1';
    $mensagemFinal = $mensagem;
    if ($assinar2) {
        $formato2 = zapi_auto_cfg('zapi_signature_format', '*_{{atendente}}_*:');
        $nomeUser2 = user_display_name();
        $assinatura2 = str_replace('{{atendente}}', $nomeUser2, $formato2);
        $mensagemFinal = $assinatura2 . "\n" . ltrim($mensagem);
    }
    // 🔗 Shortlinks: encurta URLs pro Hub rastrear clique do cliente.
    require_once APP_ROOT . '/core/functions_shortlinks.php';
    $mensagemFinal = sl_encurtar_urls_no_texto($mensagemFinal, array(
        'client_id'  => $clientId,
        'lead_id'    => $leadId,
        'canal'      => $canal,
        'criado_por' => $userId,
    ));
    // Envia via Z-API
    $resp = zapi_send_text($canal, $telefone, $mensagemFinal);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($resp['http_code'] ?? '?') . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }
    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    // Busca/cria conversa pra espelhar no histórico
    $conv = zapi_buscar_ou_criar_conversa($telefone, $canal, $nomeHint ?: null);
    if ($conv) {
        // Se passou client_id e a conversa ainda não tem, vincula
        if ($clientId && !$conv['client_id']) {
            $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")->execute(array($clientId, $conv['id']));
            // Auto-merge: se já existem outras conversas deste cliente no canal, mescla
            try { zapi_auto_merge_por_client_id($pdo, $conv['id'], (int)$clientId, $canal); } catch (Exception $e) {}
        }
        if ($leadId && !$conv['lead_id']) {
            $pdo->prepare("UPDATE zapi_conversas SET lead_id = ? WHERE id = ?")->execute(array($leadId, $conv['id']));
        }

        $pdo->prepare(
            "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo, enviado_por_id, status)
             VALUES (?, ?, 'enviada', 'texto', ?, ?, 'enviada')"
        )->execute(array($conv['id'], $zapiId, $mensagem, $userId));

        $setAtend = zapi_sql_set_atendente_pos_envio($canal, isset($conv['atendente_id']) ? $conv['atendente_id'] : 0, $userId);
        $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                       status = CASE WHEN status IN ('aguardando','resolvido') THEN 'em_atendimento' ELSE status END,
                       {$setAtend}
                       WHERE id = ?")
            ->execute(array(mb_substr($mensagem, 0, 500), $conv['id']));
    }

    audit_log('wa_enviar_rapido', 'zapi_conversas', $conv['id'] ?? 0, "canal={$canal} tel={$telefone} client_id={$clientId}");
    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId, 'conversa_id' => $conv['id'] ?? null));
    exit;
}

// ── SETAR GÊNERO DO CLIENTE (1-click do banner do WhatsApp) ───────
// Quando o cliente da conversa nao tem gender cadastrado, o WhatsApp
// mostra banner perguntando. Click salva direto em clients.gender e
// faz a personalizacao de templates ({{masc|fem}}) funcionar corretamente.
if ($action === 'set_client_gender') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $gender = trim((string)($_POST['gender'] ?? '')); // 'Masculino' | 'Feminino' | 'Pular'
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatorio')); exit; }

    $cv = $pdo->prepare("SELECT client_id FROM zapi_conversas WHERE id = ?");
    $cv->execute(array($convId));
    $cv = $cv->fetch();
    if (!$cv || !$cv['client_id']) { echo json_encode(array('error' => 'Conversa sem cliente vinculado')); exit; }

    if ($gender === 'Pular') {
        // Marca como "pular" usando uma coluna auxiliar — assim o banner nao incomoda mais
        // pra esse cliente. Self-heal idempotente.
        try { $pdo->exec("ALTER TABLE clients ADD COLUMN gender_pulado TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
        $pdo->prepare("UPDATE clients SET gender_pulado = 1 WHERE id = ?")->execute(array($cv['client_id']));
        audit_log('wa_gender_pulado', 'clients', (int)$cv['client_id'], '');
        echo json_encode(array('ok' => true, 'pulado' => true));
        exit;
    }

    if (!in_array($gender, array('Masculino','Feminino','Outro'), true)) {
        echo json_encode(array('error' => 'Genero invalido')); exit;
    }

    $pdo->prepare("UPDATE clients SET gender = ?, updated_at = NOW() WHERE id = ?")
        ->execute(array($gender, $cv['client_id']));
    audit_log('wa_gender_set', 'clients', (int)$cv['client_id'], $gender);
    echo json_encode(array('ok' => true, 'gender' => $gender));
    exit;
}

// ── NOTA FIXA NA CONVERSA ─────────────────────────────────────────
// Banner amarelo permanente no topo do chat com observacao da equipe sobre
// o cliente/conversa (ex: "estamos tentando acordo, nao mover pro contencioso").
// Visivel pra TODOS os atendentes que abrem essa conversa. Nao vai pro cliente.
if ($action === 'set_nota_fixa') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $nota = trim((string)($_POST['nota'] ?? ''));
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatorio')); exit; }

    // Self-heal (caso ainda nao tenha rodado pelo abrir_conversa)
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN nota_fixa TEXT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN nota_fixa_em DATETIME NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE zapi_conversas ADD COLUMN nota_fixa_por INT NULL"); } catch (Exception $e) {}

    $check = $pdo->prepare("SELECT id FROM zapi_conversas WHERE id = ?");
    $check->execute(array($convId));
    if (!$check->fetchColumn()) { echo json_encode(array('error' => 'Conversa nao encontrada')); exit; }

    // Limite generoso pra observacao (textarea pode crescer). 4000 chars deve sobrar.
    if (mb_strlen($nota) > 4000) $nota = mb_substr($nota, 0, 4000);

    if ($nota === '') {
        // Apagar nota
        $pdo->prepare("UPDATE zapi_conversas SET nota_fixa = NULL, nota_fixa_em = NULL, nota_fixa_por = NULL WHERE id = ?")
            ->execute(array($convId));
        audit_log('wa_nota_fixa_remover', 'zapi_conversas', $convId, '');
        echo json_encode(array('ok' => true, 'removida' => true));
        exit;
    }

    $pdo->prepare("UPDATE zapi_conversas SET nota_fixa = ?, nota_fixa_em = NOW(), nota_fixa_por = ? WHERE id = ?")
        ->execute(array($nota, current_user_id(), $convId));
    audit_log('wa_nota_fixa_set', 'zapi_conversas', $convId, mb_substr($nota, 0, 200));

    // Devolve quem editou pra UI atualizar
    $stU = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stU->execute(array(current_user_id()));
    $nomeAutor = (string)$stU->fetchColumn();

    echo json_encode(array(
        'ok' => true,
        'nota' => $nota,
        'nota_fixa_em' => date('d/m/Y H:i'),
        'nota_fixa_por_name' => $nomeAutor,
    ));
    exit;
}

// ── VINCULAR CLIENTE À CONVERSA ──────────────────────────────────
// Permite que o atendente vincule manualmente um cliente cadastrado a uma
// conversa WA (ex: cliente trocou de numero, ou comecou a falar antes do
// cadastro). Resolve a categoria "conversa WA sem client_id".
if ($action === 'vincular_cliente_conversa') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $clientId = (int)($_POST['client_id'] ?? 0);
    if (!$convId || !$clientId) { echo json_encode(array('error' => 'Dados incompletos')); exit; }

    $conv = $pdo->prepare("SELECT id, canal, client_id, telefone, nome_contato FROM zapi_conversas WHERE id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

    $cli = $pdo->prepare("SELECT id, name, phone, phone2 FROM clients WHERE id = ?");
    $cli->execute(array($clientId));
    $cli = $cli->fetch();
    if (!$cli) { echo json_encode(array('error' => 'Cliente não encontrado')); exit; }

    // Vincula a conversa
    $pdo->prepare("UPDATE zapi_conversas SET client_id = ? WHERE id = ?")
        ->execute(array($clientId, $convId));

    // Auto-merge: outras conversas do mesmo cliente no mesmo canal sao mescladas
    try {
        if (function_exists('zapi_auto_merge_por_client_id')) {
            zapi_auto_merge_por_client_id($pdo, $convId, $clientId, $conv['canal']);
        }
    } catch (Exception $e) {}

    // Atualiza @lid do cliente pro telefone desta conversa (futuras msgs ja batem)
    try {
        require_once APP_ROOT . '/core/functions_zapi.php';
        zapi_atualizar_lid_cliente($clientId, true);
    } catch (Exception $e) {}

    audit_log('wa_vincular_cliente_manual', 'zapi_conversas', $convId,
        "conv tel={$conv['telefone']} -> client#{$clientId} ({$cli['name']})");

    // Bug fix 26/05/2026 (Amanda): se o telefone da conversa nova for diferente
    // do clients.phone atual, sinaliza pro frontend perguntar se quer atualizar
    // o cadastro. Sem isso, msgs automaticas (audiencia, central VIP, parabens)
    // continuam indo pro numero antigo.
    $telConvDig = preg_replace('/\D/', '', (string)$conv['telefone']);
    $telCliDig  = preg_replace('/\D/', '', (string)$cli['phone']);
    // Normaliza: remove 55 inicial pra comparar so DDD+numero
    $_norm = function($t){ $d = preg_replace('/\D/', '', (string)$t); if (strlen($d) > 11 && substr($d,0,2) === '55') $d = substr($d, 2); return $d; };
    $diferente = ($_norm($telConvDig) !== $_norm($telCliDig)) && strlen($telConvDig) >= 10;

    echo json_encode(array(
        'ok' => true,
        'client_id' => $clientId,
        'client_name' => $cli['name'],
        'conversa_id' => $convId,
        'telefone_diferente' => $diferente,
        'telefone_cadastro' => $cli['phone'],
        'telefone_conversa' => $conv['telefone'],
    ));
    exit;
}

// Atualiza o telefone principal do cliente, movendo o antigo pra phone2.
// Chamada pelo modal de confirmacao quando vincular_cliente_conversa detecta
// telefone diferente.
if ($action === 'atualizar_telefone_principal') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $novoTel  = trim($_POST['novo_telefone'] ?? '');
    if (!$clientId || !$novoTel) { echo json_encode(array('error' => 'Dados incompletos')); exit; }

    // Self-heal: phone2 ja existe no schema atual, mas garante
    try { $pdo->exec("ALTER TABLE clients ADD COLUMN phone2 VARCHAR(40) NULL"); } catch (Throwable $e) {}

    $st = $pdo->prepare("SELECT id, name, phone, phone2 FROM clients WHERE id = ?");
    $st->execute(array($clientId));
    $cli = $st->fetch();
    if (!$cli) { echo json_encode(array('error' => 'Cliente nao encontrado')); exit; }

    $telAntigo = $cli['phone'];
    // Move antigo pra phone2 se nao for igual ao novo nem ao phone2 ja existente
    $_dig = function($t){ return preg_replace('/\D/', '', (string)$t); };
    $movePhone2 = $telAntigo && $_dig($telAntigo) !== $_dig($novoTel) && $_dig($telAntigo) !== $_dig($cli['phone2']);

    if ($movePhone2) {
        $pdo->prepare("UPDATE clients SET phone = ?, phone2 = ? WHERE id = ?")
            ->execute(array($novoTel, $telAntigo, $clientId));
    } else {
        $pdo->prepare("UPDATE clients SET phone = ? WHERE id = ?")
            ->execute(array($novoTel, $clientId));
    }

    audit_log('wa_atualizar_telefone_principal', 'clients', $clientId,
        "novo='{$novoTel}' antigo='{$telAntigo}'" . ($movePhone2 ? " (antigo movido pra phone2)" : ""));

    echo json_encode(array(
        'ok' => true,
        'phone' => $novoTel,
        'phone2' => $movePhone2 ? $telAntigo : $cli['phone2'],
        'antigo_preservado' => $movePhone2,
    ));
    exit;
}

// ── GERAR/RENOVAR LINK DA CENTRAL VIP e retornar mensagem pronta pro WhatsApp ──
if ($action === 'gerar_link_salavip') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    $conv = $pdo->prepare("SELECT co.*, cl.id AS cli_id, cl.name AS cli_name, cl.cpf AS cli_cpf, cl.email AS cli_email
                           FROM zapi_conversas co LEFT JOIN clients cl ON cl.id = co.client_id
                           WHERE co.id = ?");
    $conv->execute(array($convId));
    $conv = $conv->fetch();
    if (!$conv || !$conv['cli_id']) {
        echo json_encode(array('error' => 'Conversa sem cliente vinculado. Vincule um cliente primeiro.'));
        exit;
    }

    $clientId = (int)$conv['cli_id'];
    $cpf = preg_replace('/\D/', '', $conv['cli_cpf'] ?? '');
    if (!$cpf) { echo json_encode(array('error' => 'Cliente sem CPF cadastrado. Edite o cadastro primeiro.')); exit; }

    // Buscar ou criar entrada em salavip_usuarios
    $svStmt = $pdo->prepare("SELECT id, ativo, senha_hash FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
    $svStmt->execute(array($clientId));
    $sv = $svStmt->fetch();

    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+72 hours'));

    if ($sv) {
        // FIX Amanda 10/06/2026: nao zerar ativo se cliente ja tem senha (ja ativou)
        if (!empty($sv['senha_hash'])) {
            $pdo->prepare("UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ? WHERE id = ?")
                ->execute(array($token, $expira, $sv['id']));
            audit_log('sv_renovar_via_wa', 'client', $clientId, 'Token renovado (cliente ja ativo)');
        } else {
            $pdo->prepare("UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ?, ativo = 0 WHERE id = ?")
                ->execute(array($token, $expira, $sv['id']));
            audit_log('sv_renovar_via_wa', 'client', $clientId, 'Token renovado no chat WA');
        }
    } else {
        // Cria novo
        $pdo->prepare("INSERT INTO salavip_usuarios (cliente_id, cpf, token_ativacao, token_expira, ativo) VALUES (?, ?, ?, ?, 0)")
            ->execute(array($clientId, $cpf, $token, $expira));
        audit_log('sv_criar_via_wa', 'client', $clientId, 'Acesso criado no chat WA');
    }

    $linkAtivacao = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=' . $token;
    $primeiroNome = explode(' ', $conv['cli_name'])[0];
    $cpfFmt = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    $msg = "Olá {$primeiroNome}! 🔑\n\n"
         . "Aqui está seu acesso à *Central VIP Ferreira & Sá* — o portal exclusivo onde você acompanha seu processo, envia documentos e conversa com a equipe:\n\n"
         . "🔗 *Link de ativação (válido por 72h):*\n{$linkAtivacao}\n\n"
         . "📋 *Como acessar:*\n"
         . "1. Clique no link acima e crie sua senha\n"
         . "2. Depois, entre em https://www.ferreiraesa.com.br/salavip/ usando:\n"
         . "   • CPF: *{$cpfFmt}*\n"
         . "   • Senha: a que você acabou de criar\n\n"
         . "Qualquer dúvida, é só responder aqui. 😊\n\n_Ferreira & Sá Advocacia_";

    echo json_encode(array(
        'ok' => true,
        'mensagem' => $msg,
        'link' => $linkAtivacao,
        'telefone' => $conv['telefone'],
        'canal' => $conv['canal'],
        'client_id' => $clientId,
        'client_name' => $conv['cli_name'],
    ));
    exit;
}

// Variante do gerar_link_salavip que aceita client_id direto (sem precisar
// de conversa). Usado pelo chip "Link Central VIP" do waSenderOpen (assets/js/
// wa_sender.js), chamado a partir de telas onde NAO ha conversa WhatsApp aberta
// — cliente_ver, crm, cobranca_honorarios, etc.
if ($action === 'gerar_link_salavip_por_cliente') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    if (!$clientId) { echo json_encode(array('error' => 'Cliente nao vinculado a esta tela. Use o botao da Central VIP no cadastro do cliente.')); exit; }

    $stmt = $pdo->prepare("SELECT id, name, cpf FROM clients WHERE id = ?");
    $stmt->execute(array($clientId));
    $cli = $stmt->fetch();
    if (!$cli) { echo json_encode(array('error' => 'Cliente nao encontrado.')); exit; }

    $cpf = preg_replace('/\D/', '', $cli['cpf'] ?? '');
    if (!$cpf) { echo json_encode(array('error' => 'Cliente sem CPF cadastrado. Edite o cadastro primeiro.')); exit; }

    $svStmt = $pdo->prepare("SELECT id, senha_hash FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
    $svStmt->execute(array($clientId));
    $sv = $svStmt->fetch();

    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+72 hours'));

    if ($sv) {
        // FIX Amanda 10/06/2026: nao zerar ativo se cliente ja tem senha
        if (!empty($sv['senha_hash'])) {
            $pdo->prepare("UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ? WHERE id = ?")
                ->execute(array($token, $expira, $sv['id']));
            audit_log('sv_renovar_via_wa_sender', 'client', $clientId, 'Token renovado via chip (cliente ja ativo)');
        } else {
            $pdo->prepare("UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ?, ativo = 0 WHERE id = ?")
                ->execute(array($token, $expira, $sv['id']));
            audit_log('sv_renovar_via_wa_sender', 'client', $clientId, 'Token renovado via waSenderOpen chip');
        }
    } else {
        $pdo->prepare("INSERT INTO salavip_usuarios (cliente_id, cpf, token_ativacao, token_expira, ativo) VALUES (?, ?, ?, ?, 0)")
            ->execute(array($clientId, $cpf, $token, $expira));
        audit_log('sv_criar_via_wa_sender', 'client', $clientId, 'Acesso criado via waSenderOpen chip');
    }

    $linkAtivacao = 'https://www.ferreiraesa.com.br/salavip/ativar_conta.php?token=' . $token;
    $primeiroNome = explode(' ', $cli['name'])[0];
    $cpfFmt = preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    $msg = "Olá {$primeiroNome}! 🔑\n\n"
         . "Aqui está seu acesso à *Central VIP Ferreira & Sá* — o portal exclusivo onde você acompanha seu processo, envia documentos e conversa com a equipe:\n\n"
         . "🔗 *Link de ativação (válido por 72h):*\n{$linkAtivacao}\n\n"
         . "📋 *Como acessar:*\n"
         . "1. Clique no link acima e crie sua senha\n"
         . "2. Depois, entre em https://www.ferreiraesa.com.br/salavip/ usando:\n"
         . "   • CPF: *{$cpfFmt}*\n"
         . "   • Senha: a que você acabou de criar\n\n"
         . "Qualquer dúvida, é só responder aqui. 😊\n\n_Equipe Ferreira & Sá Advocacia_";

    echo json_encode(array('ok' => true, 'mensagem' => $msg, 'link' => $linkAtivacao));
    exit;
}

// ── FILA DE ENVIOS: marcar como enviada ──
if ($action === 'fila_marcar_enviada') {
    $fid = (int)($_POST['fila_id'] ?? 0);
    if (!$fid) { echo json_encode(array('error' => 'fila_id obrigatório')); exit; }
    // Self-heal: garante coluna origem_id (fila antiga pode não ter)
    try { $pdo->exec("ALTER TABLE zapi_fila_envio ADD COLUMN origem_id INT UNSIGNED NULL"); } catch (Exception $e) {}

    // Lê a linha pra saber se é envio de andamento (precisa refletir no case_andamentos)
    $stmtF = $pdo->prepare("SELECT origem, origem_id, case_id FROM zapi_fila_envio WHERE id = ?");
    $stmtF->execute(array($fid));
    $filaRow = $stmtF->fetch();

    $pdo->prepare("UPDATE zapi_fila_envio SET status='enviada', enviada_por=?, enviada_em=NOW() WHERE id=? AND status='pendente'")
        ->execute(array($userId, $fid));

    // Se é um andamento visível — marca o andamento como "comunicado ao cliente"
    // pra aparecer o ✓ na timeline do caso sem depender do botão "Enviar" direto
    if ($filaRow && $filaRow['origem'] === 'andamento_visivel' && !empty($filaRow['origem_id']) && !empty($filaRow['case_id'])) {
        try {
            $pdo->prepare(
                "UPDATE case_andamentos
                 SET whatsapp_enviado_em = NOW(), whatsapp_enviado_por = ?
                 WHERE id = ? AND case_id = ? AND whatsapp_enviado_em IS NULL"
            )->execute(array($userId, (int)$filaRow['origem_id'], (int)$filaRow['case_id']));
        } catch (Exception $e) {}
    }

    echo json_encode(array('ok' => true));
    exit;
}

// ── FILA DE ENVIOS: editar texto da mensagem ──
if ($action === 'fila_editar') {
    $fid = (int)($_POST['fila_id'] ?? 0);
    $novoTexto = trim($_POST['mensagem'] ?? '');
    if (!$fid || !$novoTexto) { echo json_encode(array('error' => 'Parâmetros obrigatórios')); exit; }
    $pdo->prepare("UPDATE zapi_fila_envio SET mensagem = ? WHERE id = ? AND status = 'pendente'")
        ->execute(array($novoTexto, $fid));
    audit_log('fila_editar_msg', 'zapi_fila_envio', $fid);
    echo json_encode(array('ok' => true));
    exit;
}

// ── FILA DE ENVIOS: descartar ──
if ($action === 'fila_descartar') {
    $fid = (int)($_POST['fila_id'] ?? 0);
    if (!$fid) { echo json_encode(array('error' => 'fila_id obrigatório')); exit; }
    $pdo->prepare("UPDATE zapi_fila_envio SET status='descartada', descartada_por=?, descartada_em=NOW() WHERE id=? AND status='pendente'")
        ->execute(array($userId, $fid));
    echo json_encode(array('ok' => true));
    exit;
}

// ── FILA DE ENVIOS: descartar em lote (Amanda 08/06/2026) ──
if ($action === 'fila_bulk_descartar') {
    $idsIn = $_POST['fila_ids'] ?? array();
    if (!is_array($idsIn)) $idsIn = array($idsIn);
    $ids = array_values(array_unique(array_filter(array_map('intval', $idsIn), function($v){ return $v > 0; })));
    if (empty($ids)) { echo json_encode(array('error' => 'Nenhum ID válido informado.')); exit; }
    if (count($ids) > 500) { echo json_encode(array('error' => 'Máximo 500 itens por lote.')); exit; }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE zapi_fila_envio SET status='descartada', descartada_por=?, descartada_em=NOW()
            WHERE status='pendente' AND id IN ($ph)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge(array($userId), $ids));
    $afetadas = $stmt->rowCount();
    audit_log('fila_bulk_descartar', 'zapi_fila_envio', 0, "user=$userId pedidos=" . count($ids) . " descartadas=$afetadas");
    echo json_encode(array('ok' => true, 'descartadas' => $afetadas, 'ids_processados' => $ids));
    exit;
}

// ── TRANSCREVER MENSAGEM DE ÁUDIO SOB DEMANDA ──
if ($action === 'transcrever_audio') {
    $msgId = (int)($_POST['mensagem_id'] ?? $_GET['mensagem_id'] ?? 0);
    if (!$msgId) { echo json_encode(array('error' => 'mensagem_id obrigatório')); exit; }
    require_once APP_ROOT . '/core/functions_groq.php';
    $r = groq_transcribe_mensagem($msgId);
    if (empty($r['ok'])) { echo json_encode(array('error' => $r['erro'] ?? 'Falha na transcrição')); exit; }
    echo json_encode(array('ok' => true, 'text' => $r['text']));
    exit;
}

// NOTA: action 'sincronizar_conversa' foi REMOVIDA — Z-API não permite baixar
// histórico em Multi-Device (doc oficial:
// https://developer.z-api.io/en/chats/get-message-chats). Mensagens novas são
// capturadas via webhook; passadas só com export manual do WhatsApp.

// ── IMPORTAR TODOS OS CHATS DA INSTÂNCIA (admin/gestão) ──
if ($action === 'importar_todos') {
    if (!has_min_role('gestao')) { echo json_encode(array('error' => 'Acesso restrito')); exit; }
    set_time_limit(300); // 5 min para import grande
    $ddd = $_POST['ddd'] ?? '21';
    $max = (int)($_POST['max_chats'] ?? 200);
    if ($max > 500) $max = 500;

    $pageSize  = 50;     // Z-API pagina em lotes de 50
    $totalPages = (int)ceil($max / $pageSize);
    $totalConv = 0;
    $pulados   = 0;
    $pages     = array();

    for ($page = 1; $page <= $totalPages; $page++) {
        $chats = zapi_fetch_chats($ddd, $page, $pageSize);
        $pages[] = array('page' => $page, 'count' => is_array($chats) ? count($chats) : 0);
        if (!is_array($chats) || empty($chats)) break; // sem mais páginas

        foreach ($chats as $chat) {
            $tel  = $chat['phone'] ?? '';
            $nome = $chat['name'] ?? null;
            if (!$tel) { $pulados++; continue; }
            // Pular grupos
            if (strpos($tel, '-') !== false || strpos($tel, '@g.us') !== false) { $pulados++; continue; }
            $conv = zapi_buscar_ou_criar_conversa($tel, $ddd, $nome);
            if (!$conv) continue;
            $totalConv++;
            if ($totalConv >= $max) break 2; // atingiu o teto
        }
    }
    audit_log('zapi_import_all', 'zapi_instancias', 0, "Conv={$totalConv} Pulados={$pulados}");
    echo json_encode(array(
        'ok' => true,
        'conversas' => $totalConv,
        'pulados' => $pulados,
        'paginas' => $pages,
    ));
    exit;
}

// ── DEBUG: última mensagem recebida (pra ver estrutura do payload) ─
if ($action === 'debug_ultima_midia' && has_min_role('gestao')) {
    $row = $pdo->query("SELECT * FROM zapi_mensagens WHERE direcao='recebida' AND tipo IN ('imagem','video','documento','audio') ORDER BY id DESC LIMIT 1")->fetch();
    echo json_encode(array('ok' => true, 'msg' => $row));
    exit;
}

// Retorna texto formatado COMPLETO da conversa pra copiar pro clipboard
// (Amanda 02/06/2026). Sem limite de 500 - puxa tudo da conversa.
if ($action === 'copiar_conversa_texto') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatorio')); exit; }
    $stConv = $pdo->prepare("SELECT co.id, co.telefone, co.nome_contato, co.canal, co.created_at, cl.name as client_name
                             FROM zapi_conversas co LEFT JOIN clients cl ON cl.id = co.client_id WHERE co.id = ?");
    $stConv->execute(array($convId));
    $conv = $stConv->fetch(PDO::FETCH_ASSOC);
    if (!$conv) { echo json_encode(array('error' => 'Conversa nao encontrada')); exit; }

    // Defesa Simone (igual abrir_conversa) - nao pode copiar conv de Gisele
    if ($userId === 5 && !empty($conv['nome_contato']) && stripos($conv['nome_contato'], 'gisele') !== false) {
        echo json_encode(array('error' => 'Conversa nao encontrada')); exit;
    }

    $stMsgs = $pdo->prepare("SELECT m.direcao, m.tipo, m.conteudo, m.created_at, m.status, m.transcricao, m.arquivo_nome, u.name as enviado_por_name
                             FROM zapi_mensagens m LEFT JOIN users u ON u.id = m.enviado_por_id
                             WHERE m.conversa_id = ?
                             ORDER BY COALESCE(m.momment_ms, UNIX_TIMESTAMP(m.created_at)*1000) ASC, m.id ASC");
    $stMsgs->execute(array($convId));
    $msgs = $stMsgs->fetchAll(PDO::FETCH_ASSOC);

    $nome = $conv['client_name'] ?: ($conv['nome_contato'] ?: 'Contato sem nome');
    $tel = $conv['telefone'] ?: '?';
    $canal = $conv['canal'] === '21' ? 'WhatsApp 21 (Comercial)' : 'WhatsApp 24 (CX/Operacional)';

    $linhas = array();
    $linhas[] = 'Conversa com ' . $nome . ' (' . $tel . ')';
    $linhas[] = 'Canal: ' . $canal;
    $linhas[] = 'Iniciada em: ' . ($conv['created_at'] ? date('d/m/Y H:i', strtotime($conv['created_at'])) : '?');
    $linhas[] = 'Exportada em: ' . date('d/m/Y H:i');
    $linhas[] = 'Total de mensagens: ' . count($msgs);
    $linhas[] = str_repeat('=', 60);
    $linhas[] = '';

    foreach ($msgs as $m) {
        $hora = date('d/m H:i', strtotime($m['created_at']));
        if ($m['direcao'] === 'recebida') {
            $autor = $nome;
        } else {
            $autor = $m['enviado_por_name'] ?: 'Equipe';
        }
        $corpo = '';
        $tipo = (string)$m['tipo'];
        if ($m['status'] === 'deletada') {
            $corpo = '[mensagem apagada]';
        } elseif ($tipo === 'texto') {
            $corpo = (string)$m['conteudo'];
        } elseif ($tipo === 'audio') {
            $corpo = '[áudio]';
            if (!empty($m['transcricao'])) $corpo .= ' (transcricao: ' . $m['transcricao'] . ')';
        } elseif ($tipo === 'imagem') {
            $corpo = '[imagem]' . (!empty($m['conteudo']) && $m['conteudo'] !== '[imagem]' ? ' ' . $m['conteudo'] : '');
        } elseif ($tipo === 'video') {
            $corpo = '[vídeo]' . (!empty($m['conteudo']) && $m['conteudo'] !== '[video]' ? ' ' . $m['conteudo'] : '');
        } elseif ($tipo === 'documento') {
            $corpo = '[documento' . (!empty($m['arquivo_nome']) ? ': ' . $m['arquivo_nome'] : '') . ']';
        } elseif ($tipo === 'sticker') {
            $corpo = '[figurinha]';
        } elseif ($tipo === 'localizacao') {
            $corpo = '[localização]';
        } else {
            $corpo = (string)($m['conteudo'] ?: '[' . $tipo . ']');
        }
        $linhas[] = '[' . $hora . '] ' . $autor . ': ' . $corpo;
    }

    echo json_encode(array('ok' => true, 'texto' => implode("\n", $linhas), 'total' => count($msgs)));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
