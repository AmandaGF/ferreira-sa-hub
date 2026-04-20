<?php
/**
 * Ferreira & Sá Hub — API interna WhatsApp CRM
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_once APP_ROOT . '/core/functions_zapi.php';

header('Content-Type: application/json; charset=utf-8');
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
// Self-heal: wa_display_name em users (nome curto exibido nas mensagens WhatsApp)
try { $pdo->exec("ALTER TABLE users ADD COLUMN wa_display_name VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
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
$mutantes = array('enviar_mensagem', 'enviar_arquivo', 'enviar_audio', 'enviar_rapido', 'assumir_atendimento', 'atribuir', 'resolver',
                  'ativar_bot', 'desativar_bot', 'marcar_lida', 'arquivar',
                  'sincronizar_conversa', 'importar_todos',
                  'editar_conversa', 'adicionar_etiqueta', 'remover_etiqueta',
                  'deletar_mensagem', 'editar_mensagem',
                  'salvar_drive',
                  'fila_marcar_enviada', 'fila_descartar', 'fila_editar',
                  'gerar_link_salavip',
                  'delegar_conversa', 'remover_delegacao',
                  'enviar_sticker', 'enviar_reacao',
                  'sticker_biblioteca_add', 'sticker_biblioteca_enviar',
                  'sticker_biblioteca_remover', 'sticker_biblioteca_favoritar',
                  'sticker_biblioteca_add_from_msg',
                  'salvar_display_name');
if (in_array($action, $mutantes, true)) {
    if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
}

// ── LISTAR CONVERSAS ─────────────────────────────────────
if ($action === 'listar_conversas') {
    // Expira delegações sem interação há mais de 6h (lazy cleanup)
    zapi_expirar_delegacoes_estale(6);

    $canal   = $_GET['canal']   ?? '21';
    $status  = $_GET['status']  ?? '';
    $busca   = trim($_GET['q']  ?? '');
    $where   = array('co.canal = ?');
    $params  = array($canal);

    if ($status && $status !== 'todos') {
        if ($status === 'bot')  $where[] = 'co.bot_ativo = 1';
        elseif ($status === 'nao_lidas') $where[] = 'co.nao_lidas > 0';
        else { $where[] = 'co.status = ?'; $params[] = $status; }
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

    // ultima_mensagem e ultima_msg_em vêm de subquery (sempre reflete o estado real,
    // ignorando mensagens deletadas — evita depender do campo salvo ficar em sync)
    $sql = "SELECT co.id, co.telefone, co.nome_contato, co.status, co.nao_lidas,
                   co.bot_ativo, co.canal,
                   co.client_id, co.lead_id, co.atendente_id,
                   COALESCE(co.delegada, 0) AS delegada, co.delegada_por,
                   co.foto_perfil_url, COALESCE(co.eh_grupo, 0) AS eh_grupo,
                   cl.foto_path AS client_foto_path,
                   cl.name AS client_name,
                   u.wa_display_name AS atendente_display_name,
                   pl.name AS lead_name,
                   u.name AS atendente_name,
                   (SELECT m.conteudo FROM zapi_mensagens m
                    WHERE m.conversa_id = co.id AND m.status != 'deletada' AND m.conteudo IS NOT NULL AND m.conteudo != ''
                    ORDER BY m.id DESC LIMIT 1) AS ultima_mensagem,
                   (SELECT m.created_at FROM zapi_mensagens m
                    WHERE m.conversa_id = co.id AND m.status != 'deletada' AND m.conteudo IS NOT NULL AND m.conteudo != ''
                    ORDER BY m.id DESC LIMIT 1) AS ultima_msg_em,
                   (SELECT GROUP_CONCAT(CONCAT_WS('|', e.id, e.nome, e.cor) SEPARATOR '§')
                    FROM zapi_conversa_etiquetas ce JOIN zapi_etiquetas e ON e.id = ce.etiqueta_id
                    WHERE ce.conversa_id = co.id) AS etiquetas_raw
            FROM zapi_conversas co
            LEFT JOIN clients cl ON cl.id = co.client_id
            LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
            LEFT JOIN users u ON u.id = co.atendente_id
            {$joinEtq}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY COALESCE((SELECT m.created_at FROM zapi_mensagens m WHERE m.conversa_id = co.id AND m.status != 'deletada' ORDER BY m.id DESC LIMIT 1), co.created_at) DESC
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
    $stmt = $pdo->prepare("SELECT co.*, cl.name AS client_name, pl.name AS lead_name,
                                  u.name AS atendente_name, u.wa_display_name AS atendente_display_name
                           FROM zapi_conversas co
                           LEFT JOIN clients cl ON cl.id = co.client_id
                           LEFT JOIN pipeline_leads pl ON pl.id = co.lead_id
                           LEFT JOIN users u ON u.id = co.atendente_id
                           WHERE co.id = ?");
    $stmt->execute(array($id));
    $conv = $stmt->fetch();
    if (!$conv) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }

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
    $lock = zapi_pode_enviar_conversa($id, $userId, 6);
    $conv['lock_pode_enviar'] = !empty($lock['pode']) ? 1 : 0;
    $conv['lock_atendente_name'] = $lock['atendente_name'] ?? null;

    // Mensagens (últimas 200)
    $msgs = $pdo->prepare("SELECT m.*, u.name AS enviado_por_name, u.wa_display_name AS enviado_por_display_name
                           FROM zapi_mensagens m
                           LEFT JOIN users u ON u.id = m.enviado_por_id
                           WHERE m.conversa_id = ?
                           ORDER BY m.id ASC
                           LIMIT 200");
    $msgs->execute(array($id));
    $mensagens = $msgs->fetchAll();
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

    echo json_encode(array('ok' => true, 'conversa' => $conv, 'mensagens' => $mensagens));
    exit;
}

// ── ENVIAR MENSAGEM ──────────────────────────────────────
if ($action === 'enviar_mensagem') {
    $convId  = (int)($_POST['conversa_id'] ?? 0);
    $texto   = trim($_POST['mensagem'] ?? '');
    if (!$convId || !$texto) { echo json_encode(array('error' => 'Parâmetros inválidos')); exit; }

    // Trava de atendimento: se outro usuário já assumiu e conversa tem atividade
    // nas últimas 6h, bloqueia o envio. Amanda/Luiz sempre podem (bypass).
    $lock = zapi_pode_enviar_conversa($convId, $userId, 6);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois de 6h sem interação, ou se assumir a conversa."));
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
        $formato = zapi_auto_cfg('zapi_signature_format', '— {{atendente}}');
        $nomeUser = user_display_name();
        $assinatura = str_replace('{{atendente}}', $nomeUser, $formato);
        $textoEnviar = rtrim($texto) . "\n\n" . $assinatura;
    }

    $resp = zapi_send_text($conv['canal'], $conv['telefone'], $textoEnviar);
    if (empty($resp['ok'])) {
        echo json_encode(array('error' => 'Falha ao enviar: ' . ($resp['erro'] ?? 'HTTP ' . ($resp['http_code'] ?? '?')) . ' — ' . json_encode($resp['data'] ?? '')));
        exit;
    }

    $zapiId = '';
    if (is_array($resp['data'])) $zapiId = $resp['data']['id'] ?? ($resp['data']['zaapId'] ?? ($resp['data']['messageId'] ?? ''));

    $pdo->prepare("INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo, enviado_por_id, status)
                   VALUES (?, ?, 'enviada', 'texto', ?, ?, 'enviada')")
        ->execute(array($convId, $zapiId, $textoEnviar, $userId));

    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                   status = IF(status = 'aguardando', 'em_atendimento', status),
                   atendente_id = COALESCE(atendente_id, ?)
                   WHERE id = ?")
        ->execute(array(mb_substr($textoEnviar, 0, 500), $userId, $convId));

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId));
    exit;
}

// ── ASSUMIR ATENDIMENTO (e desativar bot) ────────────────
if ($action === 'assumir_atendimento') {
    // Expira delegações paradas há mais de 6h antes de checar bloqueio
    zapi_expirar_delegacoes_estale(6);

    $convId = (int)($_POST['conversa_id'] ?? 0);

    // Bloqueia se outro usuário já assumiu/foi delegada E a conversa teve atividade
    // nas últimas 6h. Pra realocar, Amanda ou Luiz Eduardo precisam usar "Delegar".
    // Amanda/Luiz têm bypass (podem assumir quando quiserem).
    $lock = zapi_pode_enviar_conversa($convId, $userId, 6);
    if (empty($lock['pode'])) {
        echo json_encode(array(
            'error' => "Esta conversa está em atendimento com {$lock['atendente_name']}. Apenas Amanda ou Luiz Eduardo podem delegar para outra pessoa, ou aguarde 6h sem interação."
        ));
        exit;
    }
    $pdo->prepare("UPDATE zapi_conversas SET atendente_id = ?, bot_ativo = 0, status = 'em_atendimento' WHERE id = ?")
        ->execute(array($userId, $convId));
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

// ── TEMPLATES ────────────────────────────────────────────
if ($action === 'listar_templates') {
    $canal = $_GET['canal'] ?? '21';
    $stmt = $pdo->prepare("SELECT id, nome, conteudo, categoria FROM zapi_templates
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
    $convId = (int)($_POST['conversa_id'] ?? 0);
    $nome   = trim($_POST['nome_contato'] ?? '');
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    if (mb_strlen($nome) > 150) $nome = mb_substr($nome, 0, 150);
    $pdo->prepare("UPDATE zapi_conversas SET nome_contato = ? WHERE id = ?")->execute(array($nome ?: null, $convId));
    audit_log('zapi_editar_conv', 'zapi_conversas', $convId, "nome={$nome}");
    echo json_encode(array('ok' => true));
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

    // Nome do arquivo: nome original ou deriva da extensão + timestamp
    $nomeFinal = $msg['arquivo_nome'] ?: ('whatsapp_' . date('Ymd_His') . '_' . $msgId);
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
    audit_log('zapi_salvar_drive', 'zapi_mensagens', $msgId, "case=$caseId file={$r['fileId']}");
    echo json_encode(array('ok' => true, 'fileUrl' => $r['fileUrl'] ?? null));
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

    // Trava de atendimento (6h sem atividade destrava)
    $lock = zapi_pode_enviar_conversa($convId, $userId, 6);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois de 6h sem interação, ou se assumir a conversa."));
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
    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                   status = IF(status = 'aguardando', 'em_atendimento', status),
                   atendente_id = COALESCE(atendente_id, ?)
                   WHERE id = ?")
        ->execute(array(mb_substr($preview, 0, 500), $userId, $convId));

    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId, 'url' => $publicUrl));
    exit;
}

// ── ENVIAR ÁUDIO (nota de voz gravada pelo navegador) ───
if ($action === 'enviar_audio') {
    $convId = (int)($_POST['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    // Trava de atendimento (6h sem atividade destrava)
    $lock = zapi_pode_enviar_conversa($convId, $userId, 6);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois de 6h sem interação, ou se assumir a conversa."));
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

    $resp = zapi_send_audio($conv['canal'], $conv['telefone'], $publicUrl, true);
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

    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = '[áudio]', ultima_msg_em = NOW(),
                   status = IF(status = 'aguardando', 'em_atendimento', status),
                   atendente_id = COALESCE(atendente_id, ?)
                   WHERE id = ?")
        ->execute(array($userId, $convId));

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

    // Trava de atendimento (6h sem atividade destrava)
    $lock = zapi_pode_enviar_conversa($convId, $userId, 6);
    if (empty($lock['pode'])) {
        echo json_encode(array('error' => "Esta conversa está com {$lock['atendente_name']}. Você só pode enviar depois de 6h sem interação, ou se assumir a conversa."));
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

    $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = '[figurinha]', ultima_msg_em = NOW(),
                   status = IF(status = 'aguardando', 'em_atendimento', status),
                   atendente_id = COALESCE(atendente_id, ?)
                   WHERE id = ?")
        ->execute(array($userId, $convId));

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
// Usado por botões fora do WhatsApp: cobrança, ficha cliente, proposta, etc
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

    // Envia via Z-API
    $resp = zapi_send_text($canal, $telefone, $mensagem);
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
        }
        if ($leadId && !$conv['lead_id']) {
            $pdo->prepare("UPDATE zapi_conversas SET lead_id = ? WHERE id = ?")->execute(array($leadId, $conv['id']));
        }

        $pdo->prepare(
            "INSERT INTO zapi_mensagens (conversa_id, zapi_message_id, direcao, tipo, conteudo, enviado_por_id, status)
             VALUES (?, ?, 'enviada', 'texto', ?, ?, 'enviada')"
        )->execute(array($conv['id'], $zapiId, $mensagem, $userId));

        $pdo->prepare("UPDATE zapi_conversas SET ultima_mensagem = ?, ultima_msg_em = NOW(),
                       status = IF(status = 'aguardando', 'em_atendimento', status),
                       atendente_id = COALESCE(atendente_id, ?)
                       WHERE id = ?")
            ->execute(array(mb_substr($mensagem, 0, 500), $userId, $conv['id']));
    }

    audit_log('wa_enviar_rapido', 'zapi_conversas', $conv['id'] ?? 0, "canal={$canal} tel={$telefone} client_id={$clientId}");
    echo json_encode(array('ok' => true, 'zapi_id' => $zapiId, 'conversa_id' => $conv['id'] ?? null));
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

// ── FILA DE ENVIOS: marcar como enviada ──
if ($action === 'fila_marcar_enviada') {
    $fid = (int)($_POST['fila_id'] ?? 0);
    if (!$fid) { echo json_encode(array('error' => 'fila_id obrigatório')); exit; }
    $pdo->prepare("UPDATE zapi_fila_envio SET status='enviada', enviada_por=?, enviada_em=NOW() WHERE id=? AND status='pendente'")
        ->execute(array($userId, $fid));
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

// ── SINCRONIZAR HISTÓRICO DE UMA CONVERSA ────────────────
if ($action === 'sincronizar_conversa') {
    $convId = (int)($_POST['conversa_id'] ?? $_GET['conversa_id'] ?? 0);
    $limit  = (int)($_POST['limite'] ?? $_GET['limite'] ?? 50);
    if ($limit > 200) $limit = 200;
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }

    $res = zapi_sincronizar_historico_conversa($convId, $limit);
    if (isset($res['erro'])) { echo json_encode(array('error' => $res['erro'])); exit; }
    audit_log('zapi_sync_conv', 'zapi_conversas', $convId, "Importadas: {$res['importadas']}/{$res['total_recebido']}");
    echo json_encode(array('ok' => true, 'importadas' => $res['importadas'], 'total' => $res['total_recebido']));
    exit;
}

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

// ── DEBUG: resposta crua do Z-API chat-messages (gestão+) ────────
if ($action === 'debug_zapi_fetch' && has_min_role('gestao')) {
    $convId = (int)($_GET['conversa_id'] ?? 0);
    if (!$convId) { echo json_encode(array('error' => 'conversa_id obrigatório')); exit; }
    $stmt = $pdo->prepare("SELECT co.*, i.ddd FROM zapi_conversas co JOIN zapi_instancias i ON i.id = co.instancia_id WHERE co.id = ?");
    $stmt->execute(array($convId));
    $c = $stmt->fetch();
    if (!$c) { echo json_encode(array('error' => 'Conversa não encontrada')); exit; }
    $debug = null;
    $raw = zapi_fetch_chat_messages($c['ddd'], $c['telefone'], 5, $debug);
    echo json_encode(array('ok' => true, 'ddd' => $c['ddd'], 'telefone' => $c['telefone'], 'debug' => $debug, 'raw' => $raw), JSON_PRETTY_PRINT);
    exit;
}

// ── DEBUG: última mensagem recebida (pra ver estrutura do payload) ─
if ($action === 'debug_ultima_midia' && has_min_role('gestao')) {
    $row = $pdo->query("SELECT * FROM zapi_mensagens WHERE direcao='recebida' AND tipo IN ('imagem','video','documento','audio') ORDER BY id DESC LIMIT 1")->fetch();
    echo json_encode(array('ok' => true, 'msg' => $row));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
