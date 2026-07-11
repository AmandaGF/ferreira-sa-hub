<?php
/**
 * Presença — API AJAX.
 * Endpoints: sugerir_manual, mudar_status, aprovar_lote, desbloquear,
 *            registrar_enviado, cancelar, buscar_cliente.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('presenca');
require_once APP_ROOT . '/core/functions_presenca.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = db();

// AJAX GET — buscar cliente por nome
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['acao'] ?? '') === 'buscar_cliente') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $st = $pdo->prepare("SELECT id, name, phone FROM clients WHERE name LIKE ? ORDER BY name LIMIT 12");
    $st->execute(array('%' . $q . '%'));
    echo json_encode($st->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(array('ok'=>false,'erro'=>'metodo')); exit; }
if (!validate_csrf()) { echo json_encode(array('ok'=>false,'erro'=>'CSRF','csrf_expired'=>true)); exit; }

$acao = $_POST['acao'] ?? '';

if ($acao === 'sugerir_manual') {
    $cliId = (int)($_POST['cliente_id'] ?? 0);
    $faseSlug = trim($_POST['fase_slug'] ?? '');
    $dataAlvo = trim($_POST['data_alvo'] ?? '');
    $procId = (int)($_POST['processo_id'] ?? 0) ?: null;
    $envId = presenca_sugerir_envio($pdo, $cliId, $faseSlug, $dataAlvo ?: null, 'manual', $procId, $reason);
    if ($envId) { echo json_encode(array('ok'=>true,'envio_id'=>$envId,'csrf'=>generate_csrf_token())); exit; }
    $msgs = array(
        'cliente_ausente' => 'Selecione o cliente.',
        'sem_perfil' => 'Cliente não tem processo com honorários — sem perfil pra encaixar.',
        'fase_invalida' => 'Fase inválida.',
        'restricao_nao_enviar' => 'Cliente tem restrição "não enviar" ativa.',
        'sem_regra' => 'Sem regra ativa pra essa combinação de perfil × fase. Preencha na Matriz.',
        'ja_existe' => 'Já existe um envio dessa fase pra esse cliente.',
    );
    echo json_encode(array('ok'=>false,'erro'=>$msgs[$reason] ?? ($reason ?: 'erro'),'csrf'=>generate_csrf_token()));
    exit;
}

if ($acao === 'mudar_status') {
    $envioId = (int)($_POST['envio_id'] ?? 0);
    $novo = trim($_POST['novo_status'] ?? '');
    $forca = !empty($_POST['forca_desbloqueio']);
    $r = presenca_mudar_status($pdo, $envioId, $novo, array('forca_desbloqueio' => $forca));
    $r['csrf'] = generate_csrf_token();
    echo json_encode($r); exit;
}

if ($acao === 'aprovar_lote') {
    $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array();
    $ok = 0; $erros = array();
    foreach ($ids as $id) {
        $r = presenca_mudar_status($pdo, $id, 'aprovado');
        if (!empty($r['ok'])) $ok++;
        else $erros[] = "#$id: " . ($r['erro'] ?? '?');
    }
    echo json_encode(array('ok'=>true,'aprovados'=>$ok,'erros'=>$erros,'csrf'=>generate_csrf_token()));
    exit;
}

if ($acao === 'desbloquear') {
    $envioId = (int)($_POST['envio_id'] ?? 0);
    presenca_desbloquear($pdo, $envioId, 'Endereço confirmado — ' . (function_exists('current_user') ? (current_user()['name'] ?? '') : ''));
    echo json_encode(array('ok'=>true,'csrf'=>generate_csrf_token())); exit;
}

if ($acao === 'registrar_enviado') {
    $envioId = (int)($_POST['envio_id'] ?? 0);
    $dados = array(
        'data_envio' => trim($_POST['data_envio'] ?? '') ?: date('Y-m-d'),
        'custo_real' => (float)str_replace(',', '.', str_replace('.', '', $_POST['custo_real'] ?? '0')),
        'fornecedor_id' => (int)($_POST['fornecedor_id'] ?? 0) ?: null,
        'rastreio' => trim($_POST['rastreio'] ?? ''),
    );
    $r = presenca_mudar_status($pdo, $envioId, 'enviado', $dados);
    $r['csrf'] = generate_csrf_token();
    echo json_encode($r); exit;
}

if ($acao === 'cancelar') {
    $envioId = (int)($_POST['envio_id'] ?? 0);
    $r = presenca_mudar_status($pdo, $envioId, 'cancelado');
    $r['csrf'] = generate_csrf_token();
    echo json_encode($r); exit;
}

if ($acao === 'excluir') {
    // Só admin/gestao pra realmente apagar
    $envioId = (int)($_POST['envio_id'] ?? 0);
    if (function_exists('has_min_role') && has_min_role('gestao')) {
        $pdo->prepare("DELETE FROM presenca_envio WHERE id = ?")->execute(array($envioId));
        audit_log('presenca_envio_del', 'presenca_envio', $envioId, '');
        echo json_encode(array('ok'=>true,'csrf'=>generate_csrf_token())); exit;
    }
    echo json_encode(array('ok'=>false,'erro'=>'Sem permissão')); exit;
}

echo json_encode(array('ok'=>false,'erro'=>'ação desconhecida'));
