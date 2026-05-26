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

        $stM = db()->prepare(
            "SELECT direcao, tipo, conteudo, created_at, u.name AS quem
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

$mutantes = array('enviar_mensagem', 'enviar_arquivo', 'enviar_audio', 'enviar_rapido', 'assumir_atendimento', 'atribuir', 'resolver',
                  'ativar_bot', 'desativar_bot', 'marcar_lida', 'marcar_nao_lida', 'arquivar',
                  'sincronizar_conversa', 'importar_todos',
                  'editar_conversa', 'adicionar_etiqueta', 'remover_etiqueta',
                  'deletar_mensagem', 'editar_mensagem',
                  'salvar_drive',
                  'fila_marcar_enviada', 'fila_descartar', 'fila_editar',
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
            LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

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

    echo json_encode(array('ok' => true, 'conversas' => $rows, 'instancias' => $inst));
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
    $stmt = $pdo->prepare("SELECT co.*, cl.name AS client_name, cl.gender AS client_gender,
                                  COALESCE(cl.gender_pulado, 0) AS client_gender_pulado,
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
if ($action === 'salvar_drive') {
    require_once APP_ROOT . '/core/google_drive.php';
    $msgId = (int)($_POST['mensagem_id'] ?? 0);
    $caseId = (int)($_POST['case_id'] ?? 0);
    if (!$msgId || !$caseId) { echo json_encode(array('error' => 'Parâmetros obrigatórios')); exit; }

    // Buscar a mensagem
    $msg = $pdo->prepare("SELECT m.*, co.client_id FROM zapi_mensagens m
                          JOIN zapi_conversas co ON co.id = m.conversa_id
                          WHERE m.id = ?");
    $msg->execute(array($msgId));
    $msg = $msg->fetch();
    if (!$msg) { echo json_encode(array('error' => 'Mensagem não encontrada')); exit; }
    if (!$msg['arquivo_url']) { echo json_encode(array('error' => 'Mensagem sem arquivo')); exit; }
    if ($msg['arquivo_salvo_drive']) { echo json_encode(array('error' => 'Arquivo já salvo no Drive')); exit; }

    // Pegar a pasta do Drive do caso escolhido
    $case = $pdo->prepare("SELECT drive_folder_url FROM cases WHERE id = ? AND client_id = ?");
    $case->execute(array($caseId, $msg['client_id']));
    $caseRow = $case->fetch();
    if (!$caseRow || !$caseRow['drive_folder_url']) {
        echo json_encode(array('error' => 'Caso sem pasta no Drive. Crie a pasta primeiro pelo Kanban Operacional.'));
        exit;
    }

    // Nome do arquivo:
    // 1. Se Amanda renomeou no prompt (nome_personalizado), usa esse (sanitizado)
    // 2. Senao, nome original do anexo do WhatsApp (arquivo_nome)
    // 3. Senao, deriva de "whatsapp_<data>_<msgId>"
    // Em qualquer caso, garante extensao baseada no MIME.
    $nomePersonalizado = trim((string)($_POST['nome_personalizado'] ?? ''));
    if ($nomePersonalizado !== '') {
        // Sanitiza: remove caracteres perigosos pra Drive/filesystem
        $nomePersonalizado = preg_replace('/[\/\\\\\\x00-\\x1F<>:"|?*]/', '', $nomePersonalizado);
        // Bloqueia path traversal
        $nomePersonalizado = str_replace(array('..', '~'), '', $nomePersonalizado);
        // Limita tamanho
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
    audit_log('zapi_salvar_drive', 'zapi_mensagens', $msgId, "case=$caseId file={$r['fileId']} nome={$nomeFinal}");
    echo json_encode(array(
        'ok' => true,
        'fileUrl' => $r['fileUrl'] ?? null,
        'nome_final' => $nomeFinal,
    ));
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
    if ($isImage) {
        $resp = zapi_send_image($conv['canal'], $conv['telefone'], $publicUrl, $caption);
    } else {
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
        echo json_encode(array('error' => 'Z-API recusou: HTTP ' . ($resp['http_code'] ?? '?') . ' — ' . json_encode($resp['data'] ?? '')));
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

    $cli = $pdo->prepare("SELECT id, name, phone FROM clients WHERE id = ?");
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

    echo json_encode(array(
        'ok' => true,
        'client_id' => $clientId,
        'client_name' => $cli['name'],
        'conversa_id' => $convId,
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
    $svStmt = $pdo->prepare("SELECT id, ativo FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
    $svStmt->execute(array($clientId));
    $sv = $svStmt->fetch();

    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+72 hours'));

    if ($sv) {
        // Renova token
        $pdo->prepare("UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ?, ativo = 0 WHERE id = ?")
            ->execute(array($token, $expira, $sv['id']));
        audit_log('sv_renovar_via_wa', 'client', $clientId, 'Token renovado no chat WA');
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

    $svStmt = $pdo->prepare("SELECT id FROM salavip_usuarios WHERE cliente_id = ? LIMIT 1");
    $svStmt->execute(array($clientId));
    $sv = $svStmt->fetch();

    $token = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', strtotime('+72 hours'));

    if ($sv) {
        $pdo->prepare("UPDATE salavip_usuarios SET token_ativacao = ?, token_expira = ?, ativo = 0 WHERE id = ?")
            ->execute(array($token, $expira, $sv['id']));
        audit_log('sv_renovar_via_wa_sender', 'client', $clientId, 'Token renovado via waSenderOpen chip');
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

echo json_encode(array('error' => 'Ação inválida'));
