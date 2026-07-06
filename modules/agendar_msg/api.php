<?php
/**
 * API do módulo Agendar Mensagem WhatsApp.
 * Endpoints: criar, cancelar, buscar_cliente.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('agendar_msg');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(array('error' => 'POST obrigatório')); exit;
}
if (!validate_csrf()) {
    echo json_encode(array('error' => 'CSRF inválido — recarregue a página')); exit;
}

$pdo = db();
$userId = current_user_id();
$action = $_POST['action'] ?? '';

// ── BUSCAR CLIENTE (autocomplete) ─────────────────────────
if ($action === 'buscar_cliente') {
    $q = trim($_POST['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(array('ok' => true, 'itens' => array())); exit; }

    // Busca por nome OU telefone. Junta com case ativo pra dar contexto na sugestão.
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.phone,
               (SELECT cs.title FROM cases cs
                WHERE cs.client_id = c.id AND cs.stage NOT IN ('arquivado','concluido')
                ORDER BY cs.updated_at DESC LIMIT 1) AS case_title
        FROM clients c
        WHERE (c.name LIKE ? OR c.phone LIKE ?)
        ORDER BY c.name ASC
        LIMIT 12
    ");
    $stmt->execute(array($like, $like));
    $itens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(array('ok' => true, 'itens' => $itens));
    exit;
}

// ── CRIAR AGENDAMENTO ─────────────────────────────────────
if ($action === 'criar') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $telefone = trim($_POST['telefone'] ?? '');
    $canal = $_POST['canal'] ?? '24';
    $data = trim($_POST['data'] ?? '');
    $hora = trim($_POST['hora'] ?? '');
    $mensagem = trim($_POST['mensagem'] ?? '');

    if (!in_array($canal, array('21','24'), true)) { echo json_encode(array('error' => 'Canal inválido')); exit; }
    if ($telefone === '') { echo json_encode(array('error' => 'Informe o telefone')); exit; }
    if ($mensagem === '') { echo json_encode(array('error' => 'Escreva a mensagem')); exit; }
    if (mb_strlen($mensagem) > 3000) { echo json_encode(array('error' => 'Mensagem muito longa (máx 3000 caracteres)')); exit; }
    if ($data === '' || $hora === '') { echo json_encode(array('error' => 'Informe data e hora')); exit; }

    // Valida data/hora — precisa ser futuro (com tolerância de 1 min pra evitar
    // corrida do relógio entre servidor e navegador do usuário).
    $ts = strtotime($data . ' ' . $hora);
    if ($ts === false) { echo json_encode(array('error' => 'Data ou hora inválida')); exit; }
    if ($ts < time() - 60) { echo json_encode(array('error' => 'Não dá pra agendar no passado')); exit; }
    $agendadoPara = date('Y-m-d H:i:s', $ts);

    // Sanidade mínima do telefone — só pra bloquear input claramente errado.
    // Aceita @lid numérico (Z-API pode usar) e formato E.164, então NÃO limita
    // por comprimento — deixa a validação forte pra hora do envio (zapi_send_text
    // já bloqueia @lid puro e telefones com menos de 10 dígitos).
    $telDigits = preg_replace('/\D/', '', $telefone);
    if (strlen($telDigits) < 10) { echo json_encode(array('error' => 'Telefone parece incompleto (menos de 10 dígitos)')); exit; }

    // Se veio client_id, pega nome do cliente pra log (nome_contato)
    $nomeContato = null;
    $caseId = null;
    if ($clientId > 0) {
        $st = $pdo->prepare("SELECT name FROM clients WHERE id = ?");
        $st->execute(array($clientId));
        $nomeContato = $st->fetchColumn() ?: null;

        // Case ativo mais recente (pra futuro contexto — não obrigatório)
        $stCase = $pdo->prepare("SELECT id FROM cases WHERE client_id = ? AND stage NOT IN ('arquivado','concluido') ORDER BY updated_at DESC LIMIT 1");
        $stCase->execute(array($clientId));
        $cid = (int)$stCase->fetchColumn();
        if ($cid > 0) $caseId = $cid;
    }

    $pdo->prepare("INSERT INTO wa_agendamentos
        (canal, client_id, case_id, telefone, nome_contato, mensagem, agendado_para, status, criado_por)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?)")
        ->execute(array($canal, $clientId ?: null, $caseId, $telefone, $nomeContato, $mensagem, $agendadoPara, $userId));
    $novoId = (int)$pdo->lastInsertId();
    audit_log('wa_agenda_criar', 'wa_agenda', $novoId, "Cliente {$clientId} para {$agendadoPara}");

    echo json_encode(array('ok' => true, 'id' => $novoId));
    exit;
}

// ── CANCELAR AGENDAMENTO ──────────────────────────────────
if ($action === 'cancelar') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(array('error' => 'ID obrigatório')); exit; }

    $st = $pdo->prepare("SELECT status FROM wa_agendamentos WHERE id = ?");
    $st->execute(array($id));
    $status = $st->fetchColumn();
    if (!$status) { echo json_encode(array('error' => 'Agendamento não encontrado')); exit; }
    if ($status !== 'pendente') { echo json_encode(array('error' => 'Só dá pra cancelar agendamentos pendentes (este está: ' . $status . ')')); exit; }

    $pdo->prepare("UPDATE wa_agendamentos SET status = 'cancelado', cancelado_por = ?, cancelado_em = NOW() WHERE id = ? AND status = 'pendente'")
        ->execute(array($userId, $id));
    audit_log('wa_agenda_cancelar', 'wa_agenda', $id);

    echo json_encode(array('ok' => true));
    exit;
}

echo json_encode(array('error' => 'Ação desconhecida: ' . $action));
