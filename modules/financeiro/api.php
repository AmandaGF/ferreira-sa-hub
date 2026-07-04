<?php
/**
 * Ferreira & Sá Hub — API Financeiro (Asaas)
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { http_response_code(403); echo json_encode(array('error'=>'Acesso negado')); exit; }

require_once __DIR__ . '/../../core/asaas_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('financeiro')); }

$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

// Para AJAX: liga buffer pra impedir que warnings/notices/BOM quebrem o JSON.
// Helper limpa o buffer antes do echo. Corrige bug do "Resposta nao-JSON" que
// a Amanda reportou em 26/05/2026 ao vincular cobranca a processo.
if ($isAjax) { @ob_start(); }
function _fin_json_echo($data, $status = null) {
    while (@ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
        if ($status) http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data);
}

// Combo: monta o payload de processos de uma cobrança (primário + extras + todos do cliente)
function _fin_cob_processos_payload($pdo, $cobId) {
    $st = $pdo->prepare("SELECT client_id, case_id FROM asaas_cobrancas WHERE id = ?");
    $st->execute(array((int)$cobId));
    $cob = $st->fetch();
    if (!$cob) return array('error' => 'Cobrança não encontrada');
    $extras = cobranca_processos_extras($cobId);
    $stAll = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE client_id = ? ORDER BY id DESC");
    $stAll->execute(array((int)$cob['client_id']));
    return array(
        'primario' => (int)($cob['case_id'] ?? 0),
        'extras'   => $extras,
        'todos'    => $stAll->fetchAll(),
    );
}

// Capturador de erro fatal — loga em uploads/financeiro_last_error.log
// pra rastrear o 500 que aparece pra Amanda em produção.
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
        $logDir = dirname(__DIR__, 2) . '/uploads';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        @file_put_contents(
            $logDir . '/financeiro_last_error.log',
            '[' . date('Y-m-d H:i:s') . "]\n"
            . 'TIPO: ' . $err['type'] . "\n"
            . 'MSG: ' . $err['message'] . "\n"
            . 'FILE: ' . $err['file'] . ':' . $err['line'] . "\n"
            . 'ACTION: ' . ($_POST['action'] ?? '?') . "\n"
            . 'POST: ' . json_encode($_POST) . "\n"
            . "\n----\n",
            FILE_APPEND
        );
    }
});

if (!validate_csrf()) {
    if ($isAjax) {
        header('Content-Type: application/json', true, 403);
        echo json_encode(array('error' => 'Token CSRF expirado — recarregue a página e tente de novo', 'csrf_expired' => true));
        exit;
    }
    flash_set('error', 'Token inválido.');
    redirect(module_url('financeiro'));
}

$action = $_POST['action'] ?? '';
$pdo = db();

// SYNC POR CLIENTE — busca TODAS as cobranças desse customer no Asaas (sem limite
// de data) e faz UPSERT no banco. Resolve o problema da Amanda 26/05/2026:
// cliente Thais tem 12 parcelas no Asaas mas só apareciam algumas no Hub porque
// o sync.php geral só busca os últimos 30 dias (cobranças criadas além disso
// ficavam invisíveis).
if ($action === 'sync_cliente') {
    try {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if (!$clientId) { _fin_json_echo(array('error' => 'client_id obrigatório')); exit; }

        $st = $pdo->prepare("SELECT id, name, cpf, asaas_customer_id FROM clients WHERE id = ?");
        $st->execute(array($clientId));
        $cli = $st->fetch();
        if (!$cli) { _fin_json_echo(array('error' => 'Cliente não encontrado')); exit; }
        if (empty($cli['asaas_customer_id']) && empty($cli['cpf'])) {
            _fin_json_echo(array('error' => 'Cliente sem asaas_customer_id e sem CPF — não há como localizar cobranças'));
            exit;
        }

        // Bug Thais Rodrigues (26/05/2026): no Asaas pode haver MAIS DE UM
        // customer com mesmo CPF (duplicatas). O sync antigo so olhava o
        // asaas_customer_id salvo no clients e perdia cobrancas dos outros
        // cadastros. Agora: monta a lista de TODOS os customers Asaas com
        // mesmo CPF + o asaas_customer_id ja vinculado.
        $custIds = array();
        if (!empty($cli['asaas_customer_id'])) $custIds[$cli['asaas_customer_id']] = true;
        if (!empty($cli['cpf'])) {
            require_once __DIR__ . '/../../core/asaas_helper.php';
            $cpfLimpo = preg_replace('/\D/', '', $cli['cpf']);
            if (strlen($cpfLimpo) >= 11) {
                $rc = asaas_request('GET', '/customers?cpfCnpj=' . urlencode($cpfLimpo) . '&limit=20');
                if ($rc && !empty($rc['data'])) {
                    foreach ($rc['data'] as $c) { if (!empty($c['id'])) $custIds[$c['id']] = true; }
                }
            }
        }
        if (empty($custIds)) {
            _fin_json_echo(array('error' => 'Nenhum customer Asaas localizado pelo CPF'));
            exit;
        }
        $custIds = array_keys($custIds);

        set_time_limit(120);

        $inserted = 0; $updated = 0; $totalApi = 0; $custsVistos = array();
        $upsert = $pdo->prepare(
            "INSERT INTO asaas_cobrancas
                (client_id, asaas_payment_id, asaas_customer_id, descricao, valor,
                 vencimento, status, forma_pagamento, data_pagamento, valor_pago,
                 link_boleto, invoice_url, ultima_sync, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id), asaas_customer_id = VALUES(asaas_customer_id),
                descricao = VALUES(descricao), valor = VALUES(valor), vencimento = VALUES(vencimento),
                status = VALUES(status), forma_pagamento = VALUES(forma_pagamento),
                data_pagamento = VALUES(data_pagamento), valor_pago = VALUES(valor_pago),
                link_boleto = VALUES(link_boleto), invoice_url = VALUES(invoice_url),
                ultima_sync = NOW()"
        );
        // Pra cada customer Asaas encontrado, pagina /payments?customer={cust}
        foreach ($custIds as $custId) {
            $custsVistos[] = $custId;
            $offset = 0; $limit = 100; $paginas = 0;
            while ($paginas < 20) { // ate 2000 cobrancas por customer
                $resp = asaas_request('GET', '/payments?customer=' . urlencode($custId) . '&limit=' . $limit . '&offset=' . $offset);
                if (!$resp || isset($resp['error'])) {
                    _fin_json_echo(array('error' => 'Asaas (' . $custId . '): ' . ($resp['error'] ?? 'sem resposta')));
                    exit;
                }
                $lista = $resp['data'] ?? array();
                if (empty($lista)) break;
                $totalApi += count($lista);

                foreach ($lista as $p) {
                    $payId = $p['id'] ?? ''; if (!$payId) continue;
                    $upsert->execute(array(
                        $clientId, $payId, $custId,
                        mb_substr((string)($p['description'] ?? ''), 0, 250),
                        $p['value'] ?? 0,
                        $p['dueDate'] ?? date('Y-m-d'),
                        $p['status'] ?? 'PENDING',
                        $p['billingType'] ?? null,
                        $p['paymentDate'] ?? null,
                        $p['netValue'] ?? null,
                        $p['bankSlipUrl'] ?? null,
                        $p['invoiceUrl'] ?? null,
                    ));
                    if ($upsert->rowCount() === 1) $inserted++;
                    else $updated++;
                }
                $offset += $limit;
                $paginas++;
                if (!($resp['hasMore'] ?? false)) break;
            }
        }

        $custsCsv = implode(',', $custsVistos);
        audit_log('asaas_sync_cliente', 'clients', $clientId, "custs=[{$custsCsv}] total={$totalApi} novas={$inserted} upd={$updated}");
        _fin_json_echo(array(
            'ok' => true,
            'total' => $totalApi,
            'novas' => $inserted,
            'atualizadas' => $updated,
            'cliente' => $cli['name'],
            'customers_consultados' => $custsVistos,
        ));
        exit;
    } catch (Exception $e) {
        @error_log('[sync_cliente] ' . $e->getMessage());
        _fin_json_echo(array('error' => 'Erro: ' . $e->getMessage()), 500);
        exit;
    }
}

// Vincular / desvincular cobrança a um processo (case_id)
if ($action === 'vincular_case') {
    try {
        $cobId = (int)($_POST['cobranca_id'] ?? 0);
        $caseId = (int)($_POST['case_id'] ?? 0);
        if (!$cobId) { _fin_json_echo(array('error' => 'cobranca_id obrigatório')); exit; }

        // Se caseId > 0, validar que o caso pertence ao mesmo cliente da cobrança
        if ($caseId > 0) {
            $chk = $pdo->prepare("SELECT cs.id FROM cases cs JOIN asaas_cobrancas ac ON ac.client_id = cs.client_id WHERE ac.id = ? AND cs.id = ?");
            $chk->execute(array($cobId, $caseId));
            if (!$chk->fetch()) { _fin_json_echo(array('error' => 'Processo não pertence a este cliente')); exit; }
        }
        $pdo->prepare("UPDATE asaas_cobrancas SET case_id = ? WHERE id = ?")
            ->execute(array($caseId ?: null, $cobId));
        // Sincroniza honorarios_cobranca se existir entrada
        $pdo->prepare("UPDATE honorarios_cobranca SET case_id = ? WHERE asaas_payment_id = (SELECT asaas_payment_id FROM asaas_cobrancas WHERE id = ?)")
            ->execute(array($caseId ?: null, $cobId));
        audit_log('asaas_vincular_case', 'asaas_cobrancas', $cobId, "case_id={$caseId}");
        _fin_json_echo(array('ok' => true));
        exit;
    } catch (Exception $e) {
        @error_log('[vincular_case] ' . $e->getMessage() . ' cob=' . $cobId . ' case=' . $caseId);
        _fin_json_echo(array('error' => 'Erro: ' . $e->getMessage()), 500);
        exit;
    }
}

// Combo: vincular UMA cobrança a MÚLTIPLOS processos (extras). O primário
// continua em asaas_cobrancas.case_id; os extras vão pra asaas_cobranca_cases.
if ($action === 'vincular_cobranca_processos') {
    try {
        $cobId = (int)($_POST['cobranca_id'] ?? 0);
        if (!$cobId) { _fin_json_echo(array('error' => 'cobranca_id obrigatório')); exit; }
        $ids = $_POST['case_ids'] ?? array();
        if (!is_array($ids)) $ids = ($ids === '' ? array() : explode(',', $ids));
        $res = cobranca_set_processos_extras($cobId, $ids);
        if (isset($res['error'])) { _fin_json_echo($res, 400); exit; }
        audit_log('cobranca_processos_vinculados', 'asaas_cobrancas', $cobId, 'extras=' . implode(',', $res['extras']));
        _fin_json_echo(array_merge(array('ok' => true), _fin_cob_processos_payload($pdo, $cobId)));
        exit;
    } catch (Exception $e) {
        _fin_json_echo(array('error' => 'Erro: ' . $e->getMessage()), 500);
        exit;
    }
}

// Combo: lista os processos vinculados (primário + extras) + todos do cliente (pro picker)
if ($action === 'listar_cobranca_processos') {
    try {
        $cobId = (int)($_POST['cobranca_id'] ?? 0);
        if (!$cobId) { _fin_json_echo(array('error' => 'cobranca_id obrigatório')); exit; }
        $pay = _fin_cob_processos_payload($pdo, $cobId);
        if (isset($pay['error'])) { _fin_json_echo($pay, 404); exit; }
        _fin_json_echo($pay);
        exit;
    } catch (Exception $e) {
        _fin_json_echo(array('error' => 'Erro: ' . $e->getMessage()), 500);
        exit;
    }
}

// Vincular TODAS as cobranças de um cliente a um processo específico (bulk)
// Opcionalmente filtra por status (só pendentes, só vencidas, etc)
if ($action === 'vincular_case_bulk') {
    try {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $caseId   = (int)($_POST['case_id'] ?? 0);
        $apenas   = $_POST['apenas'] ?? 'todas'; // todas | sem_vinculo | pendentes_vencidas
        if (!$clientId) { _fin_json_echo(array('error' => 'client_id obrigatório')); exit; }

        // Valida que o caso pertence ao cliente (quando caseId > 0)
        if ($caseId > 0) {
            $chk = $pdo->prepare("SELECT id FROM cases WHERE id = ? AND client_id = ?");
            $chk->execute(array($caseId, $clientId));
            if (!$chk->fetch()) { _fin_json_echo(array('error' => 'Processo não pertence a este cliente')); exit; }
        }

        $where = "client_id = ?";
        $params = array($clientId);
        if ($apenas === 'sem_vinculo') {
            $where .= " AND (case_id IS NULL OR case_id = 0)";
        } elseif ($apenas === 'pendentes_vencidas') {
            $where .= " AND status IN ('PENDING', 'OVERDUE')";
        }

        // Atualiza em asaas_cobrancas
        $sql = "UPDATE asaas_cobrancas SET case_id = ? WHERE $where";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge(array($caseId ?: null), $params));
        $atualizadas = $stmt->rowCount();

        // Sincroniza em honorarios_cobranca (pelos asaas_payment_id dos registros afetados)
        try {
            $pdo->prepare("UPDATE honorarios_cobranca hc
                           JOIN asaas_cobrancas ac ON BINARY ac.asaas_payment_id = BINARY hc.asaas_payment_id
                           SET hc.case_id = ?
                           WHERE ac.$where")
                ->execute(array_merge(array($caseId ?: null), $params));
        } catch (Exception $e) {}

        audit_log('asaas_vincular_case_bulk', 'clients', $clientId, "case_id={$caseId}, apenas={$apenas}, atualizadas={$atualizadas}");
        _fin_json_echo(array('ok' => true, 'atualizadas' => $atualizadas));
        exit;
    } catch (Exception $e) {
        @error_log('[vincular_case_bulk] ' . $e->getMessage() . ' client=' . $clientId . ' case=' . $caseId);
        _fin_json_echo(array('error' => 'Erro: ' . $e->getMessage()), 500);
        exit;
    }
}

// ═══ Ações sobre cobranças existentes (AJAX) ═══
// Padrão: retorna JSON; recebe cobranca_id (id da tabela asaas_cobrancas)

if ($action === 'cobranca_cancelar') {
    header('Content-Type: application/json');
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }
    $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { echo json_encode(array('error' => 'Cobrança não encontrada')); exit; }
    if (in_array($cob['status'], array('CANCELED','REFUNDED'), true)) {
        echo json_encode(array('error' => 'Cobrança já está ' . asaas_status_label($cob['status']))); exit;
    }
    if (in_array($cob['status'], array('RECEIVED','CONFIRMED','RECEIVED_IN_CASH'), true)) {
        echo json_encode(array('error' => 'Cobrança já foi paga — não pode ser cancelada. Use "Estornar" no painel do Asaas se necessário.')); exit;
    }
    $resp = cancelar_cobranca_asaas($cob['asaas_payment_id']);
    if (isset($resp['error'])) { echo json_encode(array('error' => $resp['error'])); exit; }
    audit_log('cobranca_cancelada', 'asaas_cobrancas', $cobId, 'Payment: ' . $cob['asaas_payment_id']);
    echo json_encode(array('ok' => true));
    exit;
}

if ($action === 'cobranca_alterar_vencimento') {
    header('Content-Type: application/json');
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $novaData = $_POST['nova_data'] ?? '';
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $novaData)) { echo json_encode(array('error' => 'Data inválida')); exit; }
    $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { echo json_encode(array('error' => 'Cobrança não encontrada')); exit; }
    if (!in_array($cob['status'], array('PENDING','OVERDUE'), true)) {
        echo json_encode(array('error' => 'Só é possível alterar vencimento de cobrança pendente ou vencida. Status atual: ' . asaas_status_label($cob['status']))); exit;
    }
    $resp = alterar_vencimento_asaas($cob['asaas_payment_id'], $novaData);
    if (isset($resp['error'])) { echo json_encode(array('error' => $resp['error'])); exit; }
    audit_log('cobranca_vencto_alterado', 'asaas_cobrancas', $cobId, 'de ' . $cob['vencimento'] . ' → ' . $novaData);
    echo json_encode(array('ok' => true, 'nova_data' => $novaData));
    exit;
}

// 29/06/2026 Amanda: criar cobrança DIRETO no Kanban de Cobrança de Honorários,
// sem depender do Asaas. Cenário: cliente que deve honorários mas a cobrança Asaas
// foi cancelada (Luiz cancelava pra não pagar taxa de notificação). Também serve
// pra clientes sem cobrança Asaas alguma (acordo, contrato antigo, etc.).
// Ao criar, abre tarefa pro Luiz Eduardo atualizar valor com correção/juros.
if ($action === 'criar_cobranca_honorarios') {
    header('Content-Type: application/json');
    $clientId = (int)($_POST['client_id'] ?? 0);
    $caseId   = (int)($_POST['case_id'] ?? 0) ?: null;
    $valor    = (float)str_replace(array('.', ','), array('', '.'), trim($_POST['valor'] ?? '0'));
    // Tenta também parser BR robusto se veio com vírgula decimal
    $valorRaw = trim($_POST['valor'] ?? '');
    if (function_exists('parse_valor_reais')) {
        $cents = parse_valor_reais($valorRaw);
        if ($cents !== null) $valor = $cents / 100;
    }
    $vencto  = $_POST['vencimento'] ?? date('Y-m-d');
    $estagio = trim($_POST['estagio'] ?? 'judicial');
    $obs     = trim($_POST['observacao'] ?? '');
    $tipoDeb = trim($_POST['tipo_debito'] ?? 'honorarios_contratuais');
    $estagiosValidos = array('atrasado','notificado_1','notificado_extrajudicial','judicial');

    if (!$clientId)                                  { echo json_encode(array('error' => 'Cliente obrigatório.')); exit; }
    if ($valor <= 0)                                 { echo json_encode(array('error' => 'Informe um valor maior que zero.')); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencto)) { echo json_encode(array('error' => 'Vencimento inválido.')); exit; }
    if (!in_array($estagio, $estagiosValidos, true)) { echo json_encode(array('error' => 'Estágio inválido.')); exit; }

    // Verifica cliente
    $stCli = $pdo->prepare("SELECT id, name FROM clients WHERE id = ?");
    $stCli->execute(array($clientId));
    $cli = $stCli->fetch(PDO::FETCH_ASSOC);
    if (!$cli) { echo json_encode(array('error' => 'Cliente não encontrado.')); exit; }

    // Verifica caso (se passado)
    $caseTitle = null;
    if ($caseId) {
        $stCs = $pdo->prepare("SELECT id, title FROM cases WHERE id = ?");
        $stCs->execute(array($caseId));
        $cs = $stCs->fetch(PDO::FETCH_ASSOC);
        if (!$cs) { echo json_encode(array('error' => 'Caso não encontrado.')); exit; }
        $caseTitle = $cs['title'];
    }

    try {
        // Evita duplicar: se já existe registro aberto pro mesmo (client+case), atualiza valor + estágio
        $stExist = $pdo->prepare(
            "SELECT id, status, valor_total FROM honorarios_cobranca
             WHERE client_id = ? AND " . ($caseId ? "case_id = ?" : "case_id IS NULL")
            . " AND status NOT IN ('pago','cancelado')
             ORDER BY id DESC LIMIT 1"
        );
        if ($caseId) $stExist->execute(array($clientId, $caseId));
        else         $stExist->execute(array($clientId));
        $existente = $stExist->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            $ordem = array('em_dia' => 0, 'atrasado' => 1, 'notificado_1' => 2, 'notificado_2' => 3,
                           'notificado_extrajudicial' => 4, 'judicial' => 5);
            $stAtual = (int)($ordem[$existente['status']] ?? 0);
            $stNovo  = (int)($ordem[$estagio] ?? 0);
            $novoSt  = ($stNovo > $stAtual) ? $estagio : $existente['status'];
            $pdo->prepare(
                "UPDATE honorarios_cobranca
                 SET valor_total = ?, vencimento = ?, status = ?, tipo_debito = ?,
                     observacoes = CONCAT(IFNULL(observacoes,''), ?, ?)
                 WHERE id = ?"
            )->execute(array(
                $valor, $vencto, $novoSt, $tipoDeb,
                "\n[" . date('d/m/Y H:i') . "] ", $obs ?: 'atualizado pela ficha do cliente',
                (int)$existente['id'],
            ));
            $hcId = (int)$existente['id'];
            $jaExistia = true;
        } else {
            $pdo->prepare(
                "INSERT INTO honorarios_cobranca
                 (client_id, case_id, tipo_debito, valor_total, vencimento, status, entrada_automatica, observacoes, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())"
            )->execute(array(
                $clientId, $caseId, $tipoDeb, $valor, $vencto, $estagio,
                $obs ?: 'Criado manualmente pela ficha do cliente',
                current_user_id(),
            ));
            $hcId = (int)$pdo->lastInsertId();
            $jaExistia = false;
        }

        // Histórico
        $etapaMap = array(
            'atrasado'                => 'observacao',
            'notificado_1'            => 'notificacao_1',
            'notificado_extrajudicial'=> 'notificacao_extrajudicial',
            'judicial'                => 'judicial',
        );
        try {
            $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_por, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute(array(
                    $hcId, $etapaMap[$estagio] ?? 'observacao',
                    ($jaExistia ? 'Atualizado para ' : 'Criado em ') . str_replace('_', ' ', $estagio)
                  . ' pela ficha do cliente. Valor: R$ ' . number_format($valor, 2, ',', '.')
                  . ($obs ? '. Obs: ' . $obs : ''),
                    current_user_id(),
                ));
        } catch (Exception $e) {}

        // Tarefa pro Luiz Eduardo atualizar valor com correção/juros
        $tarefaCriada = false;
        try {
            $luizId = (int)($pdo->query("SELECT id FROM users WHERE is_active=1 AND name LIKE 'Luiz Eduardo%' ORDER BY id LIMIT 1")->fetchColumn());
            if ($luizId) {
                $titTar = '💰 Atualizar valor devido — ' . $cli['name'];
                $descTar = 'Foi criada uma cobrança de honorários no estágio ' . str_replace('_', ' ', $estagio) . '.'
                         . ' Valor base: R$ ' . number_format($valor, 2, ',', '.')
                         . ' (vencto ' . date('d/m/Y', strtotime($vencto)) . ').'
                         . ' Aplicar correção monetária + juros até hoje e atualizar o registro #' . $hcId . ' no Kanban de Cobrança.'
                         . ($obs ? "\n\nObservação: " . $obs : '');
                if ($caseId) {
                    $pdo->prepare("INSERT INTO case_tasks (case_id, title, tipo, descricao, assigned_to, due_date, prioridade, status, sort_order, created_at)
                                   VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                        ->execute(array($caseId, $titTar, 'outros', $descTar, $luizId,
                                        date('Y-m-d', strtotime('+2 days')), 'alta', 'a_fazer', 0));
                    $tarefaCriada = true;
                }
                // Notify Luiz mesmo se não tem case
                if (function_exists('notify')) {
                    notify($luizId, '💰 Atualizar valor devido — ' . $cli['name'],
                        'Cobrança #' . $hcId . ' criada. Aplicar correção/juros sobre R$ ' . number_format($valor, 2, ',', '.') . '.',
                        'pendencia', url('modules/cobranca_honorarios/?id=' . $hcId), '💰');
                }
            }
        } catch (Exception $e) {}

        // Notifica gestão se vai direto pra judicial
        if ($estagio === 'judicial' && function_exists('notify_gestao')) {
            try {
                notify_gestao(
                    '⚖️ Cobrança criada pra EXECUÇÃO',
                    $cli['name'] . ' — R$ ' . number_format($valor, 2, ',', '.') . ($caseTitle ? ' · ' . $caseTitle : '') . ($obs ? ' · ' . $obs : ''),
                    'alerta', url('modules/cobranca_honorarios/?id=' . $hcId), '⚖️'
                );
            } catch (Exception $e) {}
        }

        audit_log('cobranca_honorarios_criada', 'honorarios_cobranca', $hcId,
            "Cliente #{$clientId} · R$ " . number_format($valor, 2, ',', '.') . " · estágio={$estagio}");

        echo json_encode(array(
            'ok'             => true,
            'cobranca_id'    => $hcId,
            'estagio'        => $estagio,
            'ja_existia'     => $jaExistia,
            'tarefa_luiz'    => $tarefaCriada,
            'kanban_url'     => url('modules/cobranca_honorarios/?id=' . $hcId),
        ));
        exit;
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Falha ao criar cobrança: ' . $e->getMessage())); exit;
    }
}

// 29/06/2026 Amanda: encaminhar cobrança Asaas pro Kanban de Cobrança de Honorários.
// Cria registro em honorarios_cobranca (ou atualiza se já existe) no estágio escolhido.
// Estágios aceitos: 'atrasado', 'notificado_1', 'notificado_extrajudicial', 'judicial'.
// O 'judicial' = execução (Amanda 29/06: "iniciar execução contra ela").
if ($action === 'cobranca_encaminhar_execucao') {
    header('Content-Type: application/json');
    $cobId   = (int)($_POST['cobranca_id'] ?? 0);
    $estagio = trim($_POST['estagio'] ?? 'judicial');
    $obs     = trim($_POST['observacao'] ?? '');
    $estagiosValidos = array('atrasado','notificado_1','notificado_extrajudicial','judicial');
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }
    if (!in_array($estagio, $estagiosValidos, true)) {
        echo json_encode(array('error' => 'Estágio inválido.')); exit;
    }

    $cob = $pdo->prepare(
        "SELECT ac.*, cl.name AS cli_name, cs.title AS case_title
         FROM asaas_cobrancas ac
         LEFT JOIN clients cl ON cl.id = ac.client_id
         LEFT JOIN cases cs ON cs.id = ac.case_id
         WHERE ac.id = ?"
    );
    $cob->execute(array($cobId));
    $cob = $cob->fetch(PDO::FETCH_ASSOC);
    if (!$cob)              { echo json_encode(array('error' => 'Cobrança não encontrada')); exit; }
    if (!$cob['client_id']) { echo json_encode(array('error' => 'Cobrança sem cliente vinculado — vincule primeiro.')); exit; }
    if ($cob['status'] !== 'OVERDUE' && $cob['status'] !== 'PENDING') {
        echo json_encode(array('error' => 'Só dá pra encaminhar cobrança pendente ou vencida. Status atual: ' . asaas_status_label($cob['status']))); exit;
    }

    try {
        // Existe registro aberto pra mesmo client/case? Atualiza em vez de duplicar.
        // Match: client_id + case_id + status NOT IN (pago, cancelado).
        $stExist = $pdo->prepare(
            "SELECT id, status FROM honorarios_cobranca
             WHERE client_id = ? AND " . ($cob['case_id'] ? "case_id = ?" : "case_id IS NULL")
            . " AND status NOT IN ('pago','cancelado')
             ORDER BY id DESC LIMIT 1"
        );
        if ($cob['case_id']) $stExist->execute(array((int)$cob['client_id'], (int)$cob['case_id']));
        else $stExist->execute(array((int)$cob['client_id']));
        $existente = $stExist->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            // Atualiza pro novo estágio (só se mais avançado)
            $ordem = array('em_dia' => 0, 'atrasado' => 1, 'notificado_1' => 2, 'notificado_2' => 3,
                           'notificado_extrajudicial' => 4, 'judicial' => 5);
            $stAtual = (int)($ordem[$existente['status']] ?? 0);
            $stNovo  = (int)($ordem[$estagio] ?? 0);
            if ($stNovo > $stAtual) {
                $pdo->prepare("UPDATE honorarios_cobranca SET status = ?, observacoes = CONCAT(IFNULL(observacoes,''), ?, ?) WHERE id = ?")
                    ->execute(array($estagio, "\n[" . date('d/m/Y H:i') . "] ", $obs ?: 'encaminhado de Todas as Cobranças', (int)$existente['id']));
            }
            $cobHonId = (int)$existente['id'];
            $jaExistia = true;
        } else {
            // Cria novo registro
            $pdo->prepare(
                "INSERT INTO honorarios_cobranca
                 (client_id, case_id, tipo_debito, valor_total, vencimento, status, entrada_automatica, observacoes, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())"
            )->execute(array(
                (int)$cob['client_id'],
                $cob['case_id'] ? (int)$cob['case_id'] : null,
                'honorarios_atraso',
                (float)$cob['valor'],
                $cob['vencimento'] ?: date('Y-m-d'),
                $estagio,
                $obs ?: 'Encaminhado de Todas as Cobranças (Asaas #' . $cob['asaas_payment_id'] . ')',
                current_user_id(),
            ));
            $cobHonId = (int)$pdo->lastInsertId();
            $jaExistia = false;
        }

        // Registra histórico
        $etapaMap = array(
            'atrasado'                => 'observacao',
            'notificado_1'            => 'notificacao_1',
            'notificado_extrajudicial'=> 'notificacao_extrajudicial',
            'judicial'                => 'judicial',
        );
        $etapaHist = $etapaMap[$estagio] ?? 'observacao';
        $descHist = ($jaExistia ? 'Atualizado para ' : 'Encaminhado em ')
                  . str_replace('_', ' ', $estagio)
                  . ' a partir de Todas as Cobranças.'
                  . ($obs ? ' Observação: ' . $obs : '');
        try {
            $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_por, created_at) VALUES (?, ?, ?, ?, NOW())")
                ->execute(array($cobHonId, $etapaHist, $descHist, current_user_id()));
        } catch (Exception $e) {}

        audit_log('cobranca_encaminhada_kanban', 'asaas_cobrancas', $cobId, "→ honorarios_cobranca#{$cobHonId} estágio={$estagio}");

        // Notifica gestão quando vai DIRETO pra execução (judicial) — sinal importante
        if ($estagio === 'judicial' && function_exists('notify_gestao')) {
            try {
                notify_gestao(
                    '⚖️ Cobrança encaminhada pra EXECUÇÃO',
                    ($cob['cli_name'] ?: '?') . ' — R$ ' . number_format((float)$cob['valor'], 2, ',', '.') . ($cob['case_title'] ? ' · ' . $cob['case_title'] : '') . ($obs ? ' · ' . $obs : ''),
                    'alerta', url('modules/cobranca_honorarios/'), '⚖️'
                );
            } catch (Exception $e) {}
        }

        echo json_encode(array(
            'ok'             => true,
            'cobranca_id'    => $cobHonId,
            'estagio'        => $estagio,
            'ja_existia'     => $jaExistia,
            'kanban_url'     => url('modules/cobranca_honorarios/?id=' . $cobHonId),
        ));
        exit;
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Falha ao encaminhar: ' . $e->getMessage())); exit;
    }
}

if ($action === 'cobranca_dar_baixa') {
    header('Content-Type: application/json');
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $dataPagto = $_POST['data_pagamento'] ?? '';
    $valorRaw = $_POST['valor'] ?? '';
    if (!$cobId) { echo json_encode(array('error' => 'cobranca_id obrigatório')); exit; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataPagto)) { echo json_encode(array('error' => 'Data de pagamento inválida')); exit; }
    // Parser robusto que detecta BR (1.234,56) vs US (1234.56) vs raw (1234)
    $cents2 = parse_valor_reais($valorRaw);
    $valor = $cents2 !== null ? ($cents2 / 100) : 0.0;
    // Se não informou, tenta usar o valor da cobrança
    $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { echo json_encode(array('error' => 'Cobrança não encontrada')); exit; }
    if ($valor <= 0) $valor = (float)$cob['valor'];
    if (!in_array($cob['status'], array('PENDING','OVERDUE'), true)) {
        echo json_encode(array('error' => 'Só é possível dar baixa em cobrança pendente ou vencida. Status atual: ' . asaas_status_label($cob['status']))); exit;
    }
    $resp = baixar_cobranca_asaas($cob['asaas_payment_id'], $dataPagto, $valor);
    if (isset($resp['error'])) { echo json_encode(array('error' => $resp['error'])); exit; }
    audit_log('cobranca_baixa_manual', 'asaas_cobrancas', $cobId, "R$ " . number_format($valor,2,',','.') . " em " . $dataPagto);
    echo json_encode(array('ok' => true, 'valor' => $valor, 'data' => $dataPagto));
    exit;
}

// Criar cobrança Asaas a partir de um lead da Planilha Comercial
// (botão 💰 Cobrar no pipeline/index.php)
if ($action === 'criar_cobranca_lead') {
    header('Content-Type: application/json');
    $leadId = (int)($_POST['lead_id'] ?? 0);
    if (!$leadId) { echo json_encode(array('error' => 'lead_id obrigatório')); exit; }

    $l = $pdo->prepare("SELECT pl.*, c.id AS client_id_real, c.name AS client_name, c.cpf, c.asaas_customer_id
                        FROM pipeline_leads pl
                        LEFT JOIN clients c ON c.id = pl.client_id
                        WHERE pl.id = ?");
    $l->execute(array($leadId));
    $lead = $l->fetch();
    if (!$lead) { echo json_encode(array('error' => 'Lead não encontrado')); exit; }
    if (!$lead['client_id_real']) { echo json_encode(array('error' => 'Lead não vinculado a cliente. Vincule primeiro pelo cadastro.')); exit; }
    if (!$lead['cpf']) { echo json_encode(array('error' => 'Cliente sem CPF cadastrado. Atualize no CRM antes de criar cobrança.')); exit; }

    // Valor: usa honorarios_cents ou estimated_value_cents
    $valorCents = (int)($lead['honorarios_cents'] ?: ($lead['estimated_value_cents'] ?? 0));
    if ($valorCents <= 0) { echo json_encode(array('error' => 'Valor dos honorários não informado — preencha a coluna "Honorários (R$)".')); exit; }
    $valor = $valorCents / 100;

    // ─── Defesa em profundidade contra double-submit (Amanda 08/06/2026) ───
    // Bloqueia criacao duplicada do mesmo cliente nos ultimos 30s. Cobre cenarios
    // onde o frontend foi burlado (refresh, JS off, POST direto, etc).
    // Como criar_cobranca_lead nao tem case_id explicito, checa por client_id apenas.
    $janelaSegs = 30;
    $clientIdLead = (int)$lead['client_id_real'];
    $userIdAtual  = current_user_id();
    $stDup = $pdo->prepare(
        "SELECT id, 'contrato' AS origem FROM contratos_financeiros
         WHERE client_id = ? AND created_by = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
         UNION ALL
         SELECT id, 'cobranca' AS origem FROM asaas_cobrancas
         WHERE client_id = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
         LIMIT 1"
    );
    $stDup->execute(array($clientIdLead, $userIdAtual, $janelaSegs, $clientIdLead, $janelaSegs));
    if ($dup = $stDup->fetch()) {
        audit_log('cobranca_duplicada_bloqueada', 'lead', $leadId,
            "client=$clientIdLead origem={$dup['origem']} user=$userIdAtual janela={$janelaSegs}s (via criar_cobranca_lead)");
        @error_log('[criar_cobranca_lead] BLOQUEADA duplicata client=' . $clientIdLead . ' lead=' . $leadId);
        echo json_encode(array('error' => 'Já existe uma cobrança recém-criada para este cliente (menos de ' . $janelaSegs . 's atrás). Aguarde alguns segundos e confira na lista antes de tentar de novo.'));
        exit;
    }

    $venc = $lead['vencimento_parcela'] ?? '';
    // Aceita YYYY-MM-DD ou DD/MM/YYYY
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $venc)) { $vencIso = $venc; }
    elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $venc, $m)) { $vencIso = $m[3] . '-' . $m[2] . '-' . $m[1]; }
    else { echo json_encode(array('error' => 'Data de 1º vencimento inválida — preencha a coluna "Vencto 1ª" (formato DD/MM/AAAA).')); exit; }

    $formaTxt = mb_strtoupper(trim($lead['forma_pagamento'] ?? ''));
    if (!$formaTxt) { echo json_encode(array('error' => 'Forma de pagamento não informada — escolha na coluna "Pgto".')); exit; }

    // Vincular cliente no Asaas se ainda não vinculado
    if (empty($lead['asaas_customer_id'])) {
        $vinc = vincular_cliente_asaas((int)$lead['client_id_real']);
        if (isset($vinc['error'])) { echo json_encode(array('error' => 'Falha ao vincular cliente no Asaas: ' . $vinc['error'])); exit; }
        $asaasCustomerId = $vinc['id'];
    } else {
        $asaasCustomerId = $lead['asaas_customer_id'];
    }

    // Descrição automática
    $descBase = 'Honorários advocatícios';
    if (!empty($lead['case_type'])) $descBase .= ' — ' . $lead['case_type'];
    $descBase .= ' (' . $lead['client_name'] . ')';

    // Mapeia forma de pagamento → cobrança única vs subscription + billingType
    // CARTÃO DE CRÉDITO → cobrança única CREDIT_CARD
    // CRÉDITO RECORRENTE → subscription CREDIT_CARD (parcelado mensal)
    // PIX RECORRENTE → subscription PIX
    // BOLETO → cobrança única BOLETO
    // À VISTA → cobrança única UNDEFINED (cliente escolhe qualquer forma)
    $numParcelas = (int)($lead['num_parcelas'] ?? 1);
    if ($numParcelas < 1) $numParcelas = 1;

    $recorrente = false;
    $billingType = 'UNDEFINED';
    if (strpos($formaTxt, 'CARTÃO DE CRÉDITO') !== false || $formaTxt === 'CARTAO DE CREDITO') {
        $billingType = 'CREDIT_CARD';
    } elseif (strpos($formaTxt, 'CRÉDITO RECORRENTE') !== false || $formaTxt === 'CREDITO RECORRENTE') {
        $billingType = 'CREDIT_CARD'; $recorrente = true;
    } elseif (strpos($formaTxt, 'PIX RECORRENTE') !== false) {
        $billingType = 'PIX'; $recorrente = true;
    } elseif ($formaTxt === 'BOLETO') {
        $billingType = 'BOLETO';
    } elseif (strpos($formaTxt, 'VISTA') !== false) {
        $billingType = 'UNDEFINED';
    }

    try {
        if ($recorrente) {
            if ($numParcelas < 2) $numParcelas = 12; // se não informou, assume 12 meses
            $diaVenc = (int)date('d', strtotime($vencIso));
            $resp = criar_assinatura_asaas($asaasCustomerId, $valor, $diaVenc, $numParcelas, $descBase, $billingType, $vencIso);
        } else {
            $resp = criar_cobranca_asaas($asaasCustomerId, $valor, $vencIso, $descBase, $billingType);
        }
        if (isset($resp['error'])) {
            echo json_encode(array('error' => 'Asaas recusou: ' . (is_array($resp['error']) ? json_encode($resp['error']) : $resp['error'])));
            exit;
        }
        $asaasId = $resp['id'] ?? null;
        $invoiceUrl = $resp['invoiceUrl'] ?? ($resp['invoiceUrl'] ?? null);
        // case_id do lead: vincula a cobrança à DEMANDA (não só ao cliente), pra coluna
        // Asaas do Kanban contar por demanda e não marcar SIM em 2ª demanda sem cobrança.
        $caseIdLead = !empty($lead['linked_case_id']) ? (int)$lead['linked_case_id'] : null;

        // Persiste em asaas_cobrancas se for cobrança única (subscriptions geram payments automaticamente no webhook)
        if (!$recorrente && $asaasId) {
            try {
                $pdo->prepare(
                    "INSERT IGNORE INTO asaas_cobrancas (client_id, case_id, asaas_payment_id, asaas_customer_id, descricao, valor, vencimento, status, forma_pagamento, invoice_url, ultima_sync)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDING', ?, ?, NOW())"
                )->execute(array($lead['client_id_real'], $caseIdLead, $asaasId, $asaasCustomerId, $descBase, $valor, $vencIso, $billingType, $invoiceUrl));
            } catch (Exception $e) {}
        } elseif ($recorrente && $caseIdLead) {
            // Assinatura: as parcelas nascem via webhook/sync. Sincroniza agora e vincula
            // ao caso do lead as que ainda não têm case_id (mesmo padrão do fluxo do financeiro).
            try {
                sync_cobrancas_cliente($lead['client_id_real'], $asaasCustomerId);
                $pdo->prepare("UPDATE asaas_cobrancas SET case_id = ? WHERE client_id = ? AND case_id IS NULL")
                    ->execute(array($caseIdLead, $lead['client_id_real']));
            } catch (Exception $e) {}
        }

        audit_log('asaas_cobranca_lead', 'lead', $leadId, 'Cobrança criada (' . ($recorrente ? 'subscription ' . $numParcelas . 'x' : 'avulsa') . ') — ' . $billingType . ' — R$ ' . number_format($valor, 2, ',', '.'));

        echo json_encode(array(
            'ok' => true,
            'asaas_id' => $asaasId,
            'invoice_url' => $invoiceUrl,
            'recorrente' => $recorrente,
            'msg' => $recorrente
                ? 'Assinatura criada com sucesso (' . $numParcelas . 'x R$ ' . number_format($valor, 2, ',', '.') . ').'
                : 'Cobrança criada com sucesso — R$ ' . number_format($valor, 2, ',', '.') . ' · venc ' . date('d/m/Y', strtotime($vencIso)),
        ));
        exit;
    } catch (Exception $e) {
        echo json_encode(array('error' => 'Erro interno: ' . $e->getMessage()));
        exit;
    }
}

switch ($action) {
    case 'criar_cobranca':
        $clientId = (int)($_POST['client_id'] ?? 0);
        $tipo = $_POST['tipo'] ?? 'unica';
        // Converter "1.500,00" → 1500.00 usando parser robusto (detecta BR vs US)
        $valorRaw = $_POST['valor'] ?? '0';
        $cents = parse_valor_reais($valorRaw); // centavos ou null
        $valor = $cents !== null ? ($cents / 100) : 0.0;
        @error_log('[criar_cobranca] valorRaw="' . $valorRaw . '" cents=' . var_export($cents, true) . ' valor=' . $valor . ' user=' . current_user_id());
        $vencimento = $_POST['vencimento'] ?? '';
        $descricao = clean_str($_POST['descricao'] ?? 'Honorários Advocatícios', 250);
        $formaPag = $_POST['forma_pagamento'] ?? 'PIX';
        $caseId = (int)($_POST['case_id'] ?? 0) ?: null;
        $numParcelas = (int)($_POST['num_parcelas'] ?? 12);
        $diaVenc = (int)($_POST['dia_vencimento'] ?? 10);

        if (!$clientId || $valor < 5 || !$vencimento) {
            flash_set('error', 'Preencha cliente, valor (mín R$5) e vencimento.');
            redirect(module_url('financeiro'));
        }

        // Processo é OBRIGATÓRIO — toda cobrança deve estar vinculada a um caso específico.
        if (!$caseId) {
            flash_set('error', 'Selecione o processo vinculado à cobrança.');
            redirect(module_url('financeiro', 'cliente.php?id=' . $clientId));
        }
        // Validar que o processo pertence ao cliente
        $chkCase = $pdo->prepare("SELECT id FROM cases WHERE id = ? AND client_id = ?");
        $chkCase->execute(array($caseId, $clientId));
        if (!$chkCase->fetchColumn()) {
            flash_set('error', 'Processo não pertence a este cliente. Selecione um válido.');
            redirect(module_url('financeiro', 'cliente.php?id=' . $clientId));
        }

        // ─── Defesa em profundidade contra double-submit ─────────────
        // Amanda 08/06/2026: alem da trava no frontend (onsubmit disable do
        // botao), tambem rejeitar no backend se houve criacao do mesmo
        // (cliente+caso) nos ultimos 30s. Cobre cenarios em que o frontend
        // foi burlado (refresh, JS off, POST direto via curl, refresh durante
        // processamento, etc).
        $janelaSegs = 30;
        $userIdAtual = current_user_id();
        $stDup = $pdo->prepare(
            "SELECT id, 'contrato' AS origem FROM contratos_financeiros
             WHERE client_id = ?
               AND COALESCE(case_id, 0) = COALESCE(?, 0)
               AND created_by = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
             UNION ALL
             SELECT id, 'cobranca' AS origem FROM asaas_cobrancas
             WHERE client_id = ?
               AND COALESCE(case_id, 0) = COALESCE(?, 0)
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
             LIMIT 1"
        );
        $stDup->execute(array(
            $clientId, $caseId, $userIdAtual, $janelaSegs,
            $clientId, $caseId, $janelaSegs
        ));
        if ($dup = $stDup->fetch()) {
            audit_log('cobranca_duplicada_bloqueada', 'cobranca', (int)$dup['id'],
                "client=$clientId case=$caseId origem={$dup['origem']} user=$userIdAtual janela={$janelaSegs}s");
            @error_log('[criar_cobranca] BLOQUEADA duplicata client=' . $clientId . ' case=' . $caseId . ' user=' . $userIdAtual);
            flash_set('error', '⚠️ Já existe uma cobrança recém-criada para este cliente/caso (menos de ' . $janelaSegs . 's atrás). Aguarde alguns segundos e confira a lista antes de tentar de novo.');
            redirect(module_url('financeiro', 'cliente.php?id=' . $clientId));
        }

        // Vincular cliente no Asaas (se ainda não vinculado)
        $vinculo = vincular_cliente_asaas($clientId);
        if (isset($vinculo['error'])) {
            flash_set('error', 'Erro ao vincular cliente no Asaas: ' . $vinculo['error']);
            redirect(module_url('financeiro'));
        }
        $asaasCustomerId = $vinculo['id'];

        if ($tipo === 'parcelado') {
            // modo_valor: 'total' (Asaas divide) ou 'parcela' (Asaas multiplica). Default 'parcela'.
            $modoValor = ($_POST['modo_valor'] ?? 'parcela') === 'total' ? 'total' : 'parcela';
            $valorTotal = $modoValor === 'total' ? $valor : ($valor * $numParcelas);
            $valorParcela = $modoValor === 'total' ? ($valor / $numParcelas) : $valor;

            $resp = criar_parcelamento_asaas($asaasCustomerId, $valor, $numParcelas, $vencimento, $descricao, $formaPag, $modoValor);
            if (isset($resp['error'])) {
                flash_set('error', 'Erro Asaas: ' . $resp['error']);
                redirect(module_url('financeiro'));
            }
            // Salvar contrato (tipo fixo com N parcelas) — sempre grava valor_total e valor_parcela corretos
            $pdo->prepare(
                "INSERT INTO contratos_financeiros (client_id, case_id, tipo_honorario, valor_total, num_parcelas, valor_parcela, forma_pagamento, data_fechamento, created_by)
                 VALUES (?, ?, 'entrada_parcelas', ?, ?, ?, ?, CURDATE(), ?)"
            )->execute(array($clientId, $caseId, $valorTotal, $numParcelas, $valorParcela, strtolower($formaPag), current_user_id()));
            // Sincroniza as N parcelas criadas
            sync_cobrancas_cliente($clientId, $asaasCustomerId);
            // Vincula ao processo as parcelas recém criadas (sem case_id ainda)
            $pdo->prepare("UPDATE asaas_cobrancas SET case_id = ? WHERE client_id = ? AND case_id IS NULL")
                ->execute(array($caseId, $clientId));
            flash_set('success', "Parcelamento criado! $numParcelas × R$ " . number_format($valorParcela, 2, ',', '.') . " = R$ " . number_format($valorTotal, 2, ',', '.') . " (" . strtoupper($formaPag) . ")");

        } elseif ($tipo === 'recorrente') {
            // Assinatura recorrente: mensal, SEM FIM (ou até maxPayments).
            // Passa $vencimento pra 1ª cobrança respeitar a data escolhida pelo usuário.
            $resp = criar_assinatura_asaas($asaasCustomerId, $valor, $diaVenc, $numParcelas, $descricao, $formaPag, $vencimento);
            if (isset($resp['error'])) {
                flash_set('error', 'Erro Asaas: ' . $resp['error']);
                redirect(module_url('financeiro'));
            }

            // Salvar contrato
            $pdo->prepare(
                "INSERT INTO contratos_financeiros (client_id, case_id, tipo_honorario, valor_total, num_parcelas, valor_parcela, dia_vencimento, forma_pagamento, data_fechamento, asaas_subscription_id, created_by)
                 VALUES (?, ?, 'entrada_parcelas', ?, ?, ?, ?, ?, CURDATE(), ?, ?)"
            )->execute(array($clientId, $caseId, $valor * $numParcelas, $numParcelas, $valor, $diaVenc, strtolower($formaPag), $resp['id'], current_user_id()));

            // Sincronizar parcelas criadas
            sync_cobrancas_cliente($clientId, $asaasCustomerId);
            // Vincular ao processo TODAS as parcelas dessa assinatura que acabaram de ser criadas sem case_id
            $pdo->prepare("UPDATE asaas_cobrancas SET case_id = ? WHERE client_id = ? AND case_id IS NULL")
                ->execute(array($caseId, $clientId));
            flash_set('success', "Assinatura criada! $numParcelas parcelas de R$ " . number_format($valor, 2, ',', '.'));

        } else {
            // Cobrança única
            $resp = criar_cobranca_asaas($asaasCustomerId, $valor, $vencimento, $descricao, $formaPag);
            if (isset($resp['error'])) {
                flash_set('error', 'Erro Asaas: ' . $resp['error']);
                redirect(module_url('financeiro'));
            }

            // Salvar no cache — já com case_id vinculado
            $pdo->prepare(
                "INSERT INTO asaas_cobrancas (client_id, case_id, contrato_id, asaas_payment_id, asaas_customer_id, descricao, valor, vencimento, status, forma_pagamento, link_boleto, invoice_url)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 'PENDING', ?, ?, ?)"
            )->execute(array(
                $clientId, $caseId, $resp['id'], $asaasCustomerId, $descricao, $valor, $vencimento,
                strtolower($formaPag),
                isset($resp['bankSlipUrl']) ? $resp['bankSlipUrl'] : null,
                isset($resp['invoiceUrl']) ? $resp['invoiceUrl'] : null,
            ));

            // Salvar contrato
            $pdo->prepare(
                "INSERT INTO contratos_financeiros (client_id, case_id, tipo_honorario, valor_total, num_parcelas, valor_parcela, forma_pagamento, data_fechamento, created_by)
                 VALUES (?, ?, 'fixo', ?, 1, ?, ?, CURDATE(), ?)"
            )->execute(array($clientId, $caseId, $valor, $valor, strtolower($formaPag), current_user_id()));

            $linkMsg = '';
            if (isset($resp['invoiceUrl'])) $linkMsg = "\n\nLink: " . $resp['invoiceUrl'];
            flash_set('success', "Cobrança criada! R$ " . number_format($valor, 2, ',', '.') . " vencimento " . date('d/m/Y', strtotime($vencimento)) . $linkMsg);
        }

        // Combo: processos EXTRAS selecionados no modal (1 contrato cobre 2+ processos).
        // Aplica a todas as cobranças recém-criadas deste caso primário.
        $extrasSel = $_POST['case_ids_extra'] ?? array();
        if (!is_array($extrasSel)) $extrasSel = ($extrasSel === '' ? array() : explode(',', $extrasSel));
        $extrasSel = array_values(array_filter(array_map('intval', $extrasSel)));
        if ($extrasSel && $caseId) {
            $nAfet = cobranca_extras_por_caso_primario($clientId, $caseId, $extrasSel);
            if ($nAfet) audit_log('cobranca_combo_extras', 'financeiro', $clientId, "caso={$caseId} extras=" . implode(',', $extrasSel) . " cobrancas={$nAfet}");
        }

        audit_log('cobranca_criada', 'financeiro', $clientId, "R$ " . number_format($valor, 2, ',', '.') . " - $descricao");
        redirect(module_url('financeiro', 'cliente.php?id=' . $clientId));
        break;

    case 'cancelar_cobranca':
        $cobId = (int)($_POST['cobranca_id'] ?? 0);
        $cob = $pdo->prepare("SELECT * FROM asaas_cobrancas WHERE id = ?");
        $cob->execute(array($cobId));
        $cob = $cob->fetch();
        if (!$cob) { flash_set('error', 'Cobrança não encontrada.'); redirect(module_url('financeiro')); }

        $resp = asaas_delete('/payments/' . $cob['asaas_payment_id']);
        if (isset($resp['error'])) {
            flash_set('error', 'Erro ao cancelar: ' . $resp['error']);
        } else {
            $pdo->prepare("UPDATE asaas_cobrancas SET status = 'CANCELED' WHERE id = ?")->execute(array($cobId));
            audit_log('cobranca_cancelada', 'financeiro', $cob['client_id'], "Payment: " . $cob['asaas_payment_id']);
            flash_set('success', 'Cobrança cancelada.');
        }
        redirect(module_url('financeiro', 'cliente.php?id=' . $cob['client_id']));
        break;

    default:
        flash_set('error', 'Ação inválida.');
        redirect(module_url('financeiro'));
}
