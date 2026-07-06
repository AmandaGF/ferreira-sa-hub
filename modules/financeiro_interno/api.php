<?php
/**
 * Ferreira & Sá Hub — API do Setor Financeiro Interno
 * CRUD de lançamentos (contas a pagar/receber) e despesas fixas recorrentes.
 * Acesso: Amanda (1) e Luiz Eduardo (6).
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro_interno()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => 'Acesso restrito ao setor financeiro.'));
    exit;
}
require_once __DIR__ . '/../../core/functions_financeiro_interno.php';

$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
if ($isAjax) { @ob_start(); }
function _fi_json($data, $status = null) {
    while (@ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        if ($status) http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(url('modules/financeiro_interno/')); }

if (!validate_csrf()) {
    _fi_json(array('error' => 'Token CSRF expirado — recarregue a página e tente de novo', 'csrf_expired' => true), 419);
}

$pdo = db();
fin_int_ensure_schema($pdo);
$uid = current_user_id();
$action = $_POST['action'] ?? '';

// Categorias permitidas ficam livres (texto), mas normalizamos tipo.
function _fi_tipo($v) { return ($v === 'entrada') ? 'entrada' : 'saida'; }

// ── Salvar/editar lançamento ──
if ($action === 'lanc_salvar') {
    $id        = (int)($_POST['id'] ?? 0);
    $tipo      = _fi_tipo($_POST['tipo'] ?? 'saida');
    $categoria = trim((string)($_POST['categoria'] ?? 'Outros'));
    if ($categoria === '') $categoria = 'Outros';
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $valorCents = fin_int_parse_valor_cents($_POST['valor'] ?? '');
    $venc      = trim((string)($_POST['vencimento'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc)) $venc = null;
    $pago      = !empty($_POST['pago']) ? 1 : 0;
    $obs       = trim((string)($_POST['observacao'] ?? ''));

    if ($descricao === '') _fi_json(array('error' => 'Informe a descrição.'), 422);
    if ($valorCents <= 0)  _fi_json(array('error' => 'Informe um valor válido.'), 422);

    $pagoEm = $pago ? ($venc ?: date('Y-m-d')) : null;
    // Se marcou pago e informou data explícita de pagamento, respeita
    if ($pago && !empty($_POST['pago_em']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['pago_em'])) {
        $pagoEm = $_POST['pago_em'];
    }

    if ($id > 0) {
        $st = $pdo->prepare("UPDATE fin_lancamentos SET tipo=?, categoria=?, descricao=?, valor_cents=?, vencimento=?, pago=?, pago_em=?, observacao=? WHERE id=?");
        $st->execute(array($tipo, $categoria, $descricao, $valorCents, $venc, $pago, $pagoEm, $obs, $id));
        audit_log('fin_int_lanc_editado', 'fin_lancamentos', $id, $descricao . ' ' . fin_int_fmt($valorCents));
    } else {
        $st = $pdo->prepare("INSERT INTO fin_lancamentos (tipo, categoria, descricao, valor_cents, vencimento, pago, pago_em, observacao, criado_por) VALUES (?,?,?,?,?,?,?,?,?)");
        $st->execute(array($tipo, $categoria, $descricao, $valorCents, $venc, $pago, $pagoEm, $obs, $uid));
        $id = (int)$pdo->lastInsertId();
        audit_log('fin_int_lanc_criado', 'fin_lancamentos', $id, $descricao . ' ' . fin_int_fmt($valorCents));
    }
    _fi_json(array('ok' => true, 'id' => $id));
}

// ── Marcar/desmarcar pago ──
if ($action === 'lanc_toggle_pago') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT pago, vencimento FROM fin_lancamentos WHERE id=?");
    $st->execute(array($id));
    $row = $st->fetch();
    if (!$row) _fi_json(array('error' => 'Lançamento não encontrado.'), 404);
    $novo = ((int)$row['pago'] === 1) ? 0 : 1;
    $pagoEm = $novo ? date('Y-m-d') : null;
    $pdo->prepare("UPDATE fin_lancamentos SET pago=?, pago_em=? WHERE id=?")->execute(array($novo, $pagoEm, $id));
    audit_log('fin_int_lanc_pago', 'fin_lancamentos', $id, $novo ? 'pago/recebido' : 'reaberto');
    _fi_json(array('ok' => true, 'pago' => $novo));
}

// ── Excluir lançamento ──
if ($action === 'lanc_excluir') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM fin_lancamentos WHERE id=?")->execute(array($id));
    audit_log('fin_int_lanc_excluido', 'fin_lancamentos', $id, '');
    _fi_json(array('ok' => true));
}

// ── Salvar/editar despesa/receita fixa (recorrente) ──
if ($action === 'rec_salvar') {
    $id        = (int)($_POST['id'] ?? 0);
    $tipo      = _fi_tipo($_POST['tipo'] ?? 'saida');
    $categoria = trim((string)($_POST['categoria'] ?? 'Outros'));
    if ($categoria === '') $categoria = 'Outros';
    $descricao = trim((string)($_POST['descricao'] ?? ''));
    $valorCents = fin_int_parse_valor_cents($_POST['valor'] ?? '');
    $dia       = (int)($_POST['dia_vencimento'] ?? 5);
    if ($dia < 1) $dia = 1;
    if ($dia > 31) $dia = 31;
    $ativo     = isset($_POST['ativo']) ? (!empty($_POST['ativo']) ? 1 : 0) : 1;

    if ($descricao === '') _fi_json(array('error' => 'Informe a descrição.'), 422);
    if ($valorCents <= 0)  _fi_json(array('error' => 'Informe um valor válido.'), 422);

    if ($id > 0) {
        $st = $pdo->prepare("UPDATE fin_recorrentes SET tipo=?, categoria=?, descricao=?, valor_cents=?, dia_vencimento=?, ativo=? WHERE id=?");
        $st->execute(array($tipo, $categoria, $descricao, $valorCents, $dia, $ativo, $id));
        audit_log('fin_int_rec_editado', 'fin_recorrentes', $id, $descricao . ' dia ' . $dia);
    } else {
        $st = $pdo->prepare("INSERT INTO fin_recorrentes (tipo, categoria, descricao, valor_cents, dia_vencimento, ativo, criado_por) VALUES (?,?,?,?,?,?,?)");
        $st->execute(array($tipo, $categoria, $descricao, $valorCents, $dia, $ativo, $uid));
        $id = (int)$pdo->lastInsertId();
        audit_log('fin_int_rec_criado', 'fin_recorrentes', $id, $descricao . ' dia ' . $dia);
    }
    // Gera imediatamente o lançamento do mês atual pra fixa nova/ativa
    fin_int_gerar_recorrentes($pdo, $uid);
    _fi_json(array('ok' => true, 'id' => $id));
}

// ── Ligar/desligar recorrente ──
if ($action === 'rec_toggle_ativo') {
    $id = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT ativo FROM fin_recorrentes WHERE id=?");
    $st->execute(array($id));
    $row = $st->fetch();
    if (!$row) _fi_json(array('error' => 'Não encontrado.'), 404);
    $novo = ((int)$row['ativo'] === 1) ? 0 : 1;
    $pdo->prepare("UPDATE fin_recorrentes SET ativo=? WHERE id=?")->execute(array($novo, $id));
    audit_log('fin_int_rec_toggle', 'fin_recorrentes', $id, $novo ? 'ativada' : 'desativada');
    _fi_json(array('ok' => true, 'ativo' => $novo));
}

// ── Excluir recorrente (mantém os lançamentos já gerados) ──
if ($action === 'rec_excluir') {
    $id = (int)($_POST['id'] ?? 0);
    $pdo->prepare("DELETE FROM fin_recorrentes WHERE id=?")->execute(array($id));
    audit_log('fin_int_rec_excluido', 'fin_recorrentes', $id, '');
    _fi_json(array('ok' => true));
}

_fi_json(array('error' => 'Ação desconhecida: ' . $action), 400);
