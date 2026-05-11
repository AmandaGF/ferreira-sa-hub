<?php
/**
 * API do módulo Códigos 2FA — gerar código, CRUD de sistemas, testar chave.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_once __DIR__ . '/../../core/functions_totp.php';

header('Content-Type: application/json; charset=utf-8');

if (!can_access_codigos_2fa()) {
    echo json_encode(array('ok' => false, 'erro' => 'Sem permissão'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('ok' => false, 'erro' => 'POST only'));
    exit;
}
if (!validate_csrf()) {
    echo json_encode(array('ok' => false, 'erro' => 'CSRF inválido', 'csrf' => generate_csrf_token()));
    exit;
}

$pdo = db();
totp_ensure_schema($pdo);
$newCsrf = generate_csrf_token();
$action = $_POST['action'] ?? '';

// ─── Gerar código TOTP (leitura — Naiara/Carina também podem) ──────────
if ($action === 'gerar_codigo') {
    $sistemaId = (int)($_POST['sistema_id'] ?? 0);
    if (!$sistemaId) { echo json_encode(array('ok' => false, 'erro' => 'sistema_id obrigatório', 'csrf' => $newCsrf)); exit; }

    $stmt = $pdo->prepare("SELECT id, nome, chave_encrypted FROM sistemas_2fa WHERE id = ?");
    $stmt->execute(array($sistemaId));
    $sis = $stmt->fetch();
    if (!$sis) { echo json_encode(array('ok' => false, 'erro' => 'Sistema não encontrado', 'csrf' => $newCsrf)); exit; }

    $chave = totp_decrypt($sis['chave_encrypted']);
    if (!$chave) { echo json_encode(array('ok' => false, 'erro' => 'Falha ao decifrar chave (config inválido?)', 'csrf' => $newCsrf)); exit; }

    $codigo = totp_gerar($chave);
    if (!$codigo) { echo json_encode(array('ok' => false, 'erro' => 'Falha ao gerar código (chave inválida)', 'csrf' => $newCsrf)); exit; }

    $segRest = totp_segundos_restantes();

    // Audit log — registrar quem visualizou o código (pra rastreabilidade)
    try {
        audit_log('codigo_2fa_visto', 'sistemas_2fa', $sistemaId, 'sistema=' . $sis['nome']);
    } catch (Exception $e) {}

    echo json_encode(array(
        'ok' => true,
        'codigo' => $codigo,
        'segundos_restantes' => $segRest,
        'csrf' => $newCsrf,
    ));
    exit;
}

// ─── Testar chave (admin) — gera código sem salvar nada ───────────────
if ($action === 'testar_chave') {
    if (!can_admin_codigos_2fa()) { echo json_encode(array('ok' => false, 'erro' => 'Apenas admin', 'csrf' => $newCsrf)); exit; }
    $chave = trim($_POST['chave'] ?? '');
    if (!$chave) { echo json_encode(array('ok' => false, 'erro' => 'Chave vazia', 'csrf' => $newCsrf)); exit; }
    $codigo = totp_gerar($chave);
    if (!$codigo) { echo json_encode(array('ok' => false, 'erro' => 'Chave Base32 inválida — verifique se copiou todos os caracteres', 'csrf' => $newCsrf)); exit; }
    echo json_encode(array('ok' => true, 'codigo' => $codigo, 'csrf' => $newCsrf));
    exit;
}

// ─── A partir daqui: somente admin ────────────────────────────────────
if (!can_admin_codigos_2fa()) {
    echo json_encode(array('ok' => false, 'erro' => 'Apenas Amanda e Luiz Eduardo podem gerenciar sistemas.', 'csrf' => $newCsrf));
    exit;
}

if ($action === 'buscar_sistema') {
    $sistemaId = (int)($_POST['sistema_id'] ?? 0);
    if (!$sistemaId) { echo json_encode(array('ok' => false, 'erro' => 'sistema_id obrigatório', 'csrf' => $newCsrf)); exit; }
    $stmt = $pdo->prepare("SELECT * FROM sistemas_2fa WHERE id = ?");
    $stmt->execute(array($sistemaId));
    $sis = $stmt->fetch();
    if (!$sis) { echo json_encode(array('ok' => false, 'erro' => 'Sistema não encontrado', 'csrf' => $newCsrf)); exit; }
    // Devolve a chave DECIFRADA pro form de edição (admin já tá vendo o painel)
    $chave = totp_decrypt($sis['chave_encrypted']);
    echo json_encode(array(
        'ok' => true,
        'sistema' => array(
            'id' => (int)$sis['id'],
            'nome' => $sis['nome'],
            'icone' => $sis['icone'],
            'url_login' => $sis['url_login'],
            'notas' => $sis['notas'],
            'chave' => $chave,
        ),
        'csrf' => $newCsrf,
    ));
    exit;
}

if ($action === 'salvar_sistema') {
    $id = (int)($_POST['id'] ?? 0);
    $nome = trim($_POST['nome'] ?? '');
    $icone = trim($_POST['icone'] ?? '');
    $url = trim($_POST['url_login'] ?? '');
    $chave = trim($_POST['chave'] ?? '');
    $notas = trim($_POST['notas'] ?? '');

    if (!$nome) { echo json_encode(array('ok' => false, 'erro' => 'Nome obrigatório', 'csrf' => $newCsrf)); exit; }
    if (!$chave) { echo json_encode(array('ok' => false, 'erro' => 'Chave secreta obrigatória', 'csrf' => $newCsrf)); exit; }

    // Valida que a chave gera um código (Base32 válido)
    $testCodigo = totp_gerar($chave);
    if (!$testCodigo) {
        echo json_encode(array('ok' => false, 'erro' => 'Chave Base32 inválida — verifique se copiou todos os caracteres', 'csrf' => $newCsrf));
        exit;
    }

    $chaveEnc = totp_encrypt($chave);
    $uid = (int)current_user_id();

    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE sistemas_2fa SET nome=?, icone=?, url_login=?, chave_encrypted=?, notas=? WHERE id=?");
            $stmt->execute(array($nome, $icone ?: null, $url ?: null, $chaveEnc, $notas ?: null, $id));
            audit_log('sistema_2fa_editado', 'sistemas_2fa', $id, $nome);
        } else {
            $stmt = $pdo->prepare("INSERT INTO sistemas_2fa (nome, icone, url_login, chave_encrypted, notas, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->execute(array($nome, $icone ?: null, $url ?: null, $chaveEnc, $notas ?: null, $uid));
            $id = (int)$pdo->lastInsertId();
            audit_log('sistema_2fa_criado', 'sistemas_2fa', $id, $nome);
        }
        echo json_encode(array('ok' => true, 'id' => $id, 'csrf' => $newCsrf));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'erro' => 'Erro ao salvar: ' . $e->getMessage(), 'csrf' => $newCsrf));
    }
    exit;
}

if ($action === 'excluir_sistema') {
    $sistemaId = (int)($_POST['sistema_id'] ?? 0);
    if (!$sistemaId) { echo json_encode(array('ok' => false, 'erro' => 'sistema_id obrigatório', 'csrf' => $newCsrf)); exit; }
    try {
        $stN = $pdo->prepare("SELECT nome FROM sistemas_2fa WHERE id = ?");
        $stN->execute(array($sistemaId));
        $nome = (string)$stN->fetchColumn();
        $pdo->prepare("DELETE FROM sistemas_2fa WHERE id = ?")->execute(array($sistemaId));
        audit_log('sistema_2fa_excluido', 'sistemas_2fa', $sistemaId, $nome ?: ('id=' . $sistemaId));
        echo json_encode(array('ok' => true, 'csrf' => $newCsrf));
    } catch (Exception $e) {
        echo json_encode(array('ok' => false, 'erro' => 'Erro ao excluir: ' . $e->getMessage(), 'csrf' => $newCsrf));
    }
    exit;
}

echo json_encode(array('ok' => false, 'erro' => 'Action desconhecida', 'csrf' => $newCsrf));
exit;
