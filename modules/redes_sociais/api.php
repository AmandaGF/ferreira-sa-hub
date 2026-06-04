<?php
/**
 * Redes Sociais — API base + self-heal do schema (Amanda 04/06/2026)
 *
 * Fase A (atual): schema + endpoints administrativos basicos. Fase C
 * (apos App Review da Meta) adiciona: enviar_mensagem, responder_comentario,
 * listar_conversas, abrir_conversa, etc - todos seguindo padrao do
 * modules/whatsapp/api.php.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();

// ═══════════════════════════════════════════════════════════
// SELF-HEAL DE SCHEMA (idempotente - roda 1x na primeira chamada)
// ═══════════════════════════════════════════════════════════

// Paginas Facebook conectadas. Uma linha por pagina (Meta retorna lista quando
// admin tem multiplas paginas). Instagram Business eh identificado pelo
// ig_business_id que vem vinculado a uma pagina.
try { $pdo->exec("CREATE TABLE IF NOT EXISTS meta_pages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fb_page_id VARCHAR(40) NOT NULL,
    fb_page_name VARCHAR(150) NULL,
    fb_page_username VARCHAR(150) NULL,
    page_access_token_encrypted TEXT NULL,
    ig_business_id VARCHAR(40) NULL,
    ig_username VARCHAR(150) NULL,
    ig_profile_pic_url VARCHAR(500) NULL,
    foto_perfil_url VARCHAR(500) NULL,
    ativa TINYINT(1) NOT NULL DEFAULT 1,
    conectada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    desconectada_em DATETIME NULL,
    created_by INT NULL,
    UNIQUE KEY uk_fb_page (fb_page_id),
    INDEX idx_ig (ig_business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// Conversas (mensageria) - mistura Instagram DM + Messenger numa tabela so.
// tipo='instagram' usa psid do Instagram (igsid); tipo='messenger' usa psid do FB.
try { $pdo->exec("CREATE TABLE IF NOT EXISTS meta_inbox_conversas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    tipo ENUM('instagram','messenger') NOT NULL,
    psid VARCHAR(40) NOT NULL,
    contato_nome VARCHAR(150) NULL,
    contato_username VARCHAR(150) NULL,
    foto_perfil_url VARCHAR(500) NULL,
    foto_perfil_local VARCHAR(300) NULL,
    ultima_msg TEXT NULL,
    ultima_msg_em DATETIME NULL,
    nao_lidas INT UNSIGNED NOT NULL DEFAULT 0,
    atendente_id INT NULL,
    status ENUM('aguardando','em_atendimento','resolvido','arquivado') NOT NULL DEFAULT 'aguardando',
    fixada TINYINT(1) NOT NULL DEFAULT 0,
    delegada TINYINT(1) NOT NULL DEFAULT 0,
    delegada_por INT NULL,
    delegada_em DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_psid_page (page_id, tipo, psid),
    INDEX idx_ultima (ultima_msg_em),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// Mensagens (DM Instagram + Messenger FB)
try { $pdo->exec("CREATE TABLE IF NOT EXISTS meta_inbox_mensagens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversa_id INT UNSIGNED NOT NULL,
    mid VARCHAR(150) NULL,
    direcao ENUM('recebida','enviada') NOT NULL,
    tipo ENUM('texto','imagem','video','audio','sticker','arquivo','reaction','story_reply','outro') NOT NULL DEFAULT 'texto',
    conteudo TEXT NULL,
    arquivo_url VARCHAR(500) NULL,
    arquivo_nome VARCHAR(200) NULL,
    reply_to_mid VARCHAR(150) NULL,
    enviado_por_id INT NULL,
    enviado_por_bot TINYINT(1) NOT NULL DEFAULT 0,
    lida TINYINT(1) NOT NULL DEFAULT 0,
    entregue TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NULL,
    raw_payload TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_mid (mid),
    INDEX idx_conv (conversa_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// Comentarios em posts do Facebook
try { $pdo->exec("CREATE TABLE IF NOT EXISTS meta_comentarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    post_id VARCHAR(60) NOT NULL,
    post_message TEXT NULL,
    comment_id VARCHAR(60) NOT NULL,
    parent_comment_id VARCHAR(60) NULL,
    autor_psid VARCHAR(40) NULL,
    autor_nome VARCHAR(150) NULL,
    autor_foto_url VARCHAR(500) NULL,
    texto TEXT NULL,
    respondido TINYINT(1) NOT NULL DEFAULT 0,
    respondido_por_id INT NULL,
    respondido_em DATETIME NULL,
    resposta_texto TEXT NULL,
    resposta_comment_id VARCHAR(60) NULL,
    arquivado TINYINT(1) NOT NULL DEFAULT 0,
    created_em_meta DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_comment (comment_id),
    INDEX idx_post (page_id, post_id),
    INDEX idx_respondido (page_id, respondido, arquivado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// Config geral: armazena verify_token do webhook, app_id, app_secret. Cada
// linha tipo chave-valor. Sao gravadas via setup.php (admin only) e lidas
// pelo api/meta_webhook.php pra validar handshake.
try { $pdo->exec("CREATE TABLE IF NOT EXISTS meta_config (
    chave VARCHAR(60) PRIMARY KEY,
    valor TEXT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (Exception $e) {}

// ═══════════════════════════════════════════════════════════
// ENDPOINTS
// ═══════════════════════════════════════════════════════════
header('Content-Type: application/json; charset=utf-8');
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Salvar config (admin only) ────────────────────────────
if ($action === 'salvar_config') {
    if (!can_access('redes_sociais_config')) { echo json_encode(array('error' => 'Sem permissão')); exit; }
    if (!validate_csrf()) { echo json_encode(array('error' => 'CSRF inválido')); exit; }
    $aceitos = array('meta_app_id','meta_app_secret','meta_verify_token','meta_webhook_active');
    $salvos = 0;
    foreach ($aceitos as $k) {
        if (!array_key_exists($k, $_POST)) continue;
        $v = trim($_POST[$k]);
        try {
            $pdo->prepare("INSERT INTO meta_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute(array($k, $v));
            $salvos++;
        } catch (Exception $e) {}
    }
    audit_log('meta_config_salva', 'meta_config', 0, "campos=$salvos");
    echo json_encode(array('ok' => true, 'salvos' => $salvos));
    exit;
}

// ── Listar paginas conectadas ─────────────────────────────
if ($action === 'listar_paginas') {
    if (!can_access('redes_sociais')) { echo json_encode(array('error' => 'Sem permissão')); exit; }
    $rs = $pdo->query("SELECT id, fb_page_id, fb_page_name, ig_username, ativa, conectada_em FROM meta_pages ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array('ok' => true, 'paginas' => $rs));
    exit;
}

// ── Status geral do modulo (pra mostrar 'configurado/aguardando' na UI) ──
if ($action === 'status') {
    if (!can_access('redes_sociais')) { echo json_encode(array('error' => 'Sem permissão')); exit; }
    $cfg = array();
    foreach ($pdo->query("SELECT chave, valor FROM meta_config")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cfg[$r['chave']] = $r['valor'];
    }
    $temAppId = !empty($cfg['meta_app_id']);
    $temAppSecret = !empty($cfg['meta_app_secret']);
    $temVerifyToken = !empty($cfg['meta_verify_token']);
    $totalPaginas = (int)$pdo->query("SELECT COUNT(*) FROM meta_pages WHERE ativa = 1")->fetchColumn();
    $totalConvs = (int)$pdo->query("SELECT COUNT(*) FROM meta_inbox_conversas")->fetchColumn();
    $totalCmts = (int)$pdo->query("SELECT COUNT(*) FROM meta_comentarios")->fetchColumn();
    echo json_encode(array(
        'ok' => true,
        'config_ok' => ($temAppId && $temAppSecret && $temVerifyToken),
        'app_id' => $temAppId ? '✓' : '—',
        'app_secret' => $temAppSecret ? '✓' : '—',
        'verify_token' => $temVerifyToken ? '✓' : '—',
        'paginas_conectadas' => $totalPaginas,
        'conversas_total' => $totalConvs,
        'comentarios_total' => $totalCmts,
    ));
    exit;
}

echo json_encode(array('error' => 'Ação inválida'));
