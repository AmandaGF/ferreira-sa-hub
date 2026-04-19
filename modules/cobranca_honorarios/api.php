<?php
/**
 * Ferreira & Sá Hub — Cobrança de Honorários API
 * Ações: criar, avançar etapa, pagamento, judicial, config, detalhe, exportar
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access('cobranca_honorarios')) { redirect(url('modules/dashboard/')); }

$pdo = db();
$userId = current_user_id();
$userRole = current_user_role();
$isAdmin = ($userRole === 'admin');

// ─── GET: Detalhe (AJAX) ───
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'detalhe') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare(
        "SELECT hc.*, cl.name as client_name, cl.phone as client_phone, cl.cpf as client_cpf,
                cs.title as case_title, u.name as responsavel_nome,
                DATEDIFF(CURDATE(), hc.vencimento) as dias_atraso
         FROM honorarios_cobranca hc
         LEFT JOIN clients cl ON cl.id = hc.client_id
         LEFT JOIN cases cs ON cs.id = hc.case_id
         LEFT JOIN users u ON u.id = hc.responsavel_cobranca
         WHERE hc.id = ?"
    );
    $stmt->execute(array($id));
    $cob = $stmt->fetch();
    if (!$cob) { echo '<p style="color:#dc2626;">Cobrança não encontrada.</p>'; exit; }

    // Histórico desta cobrança
    $hist = $pdo->prepare(
        "SELECT hh.*, u.name as user_name FROM honorarios_cobranca_historico hh
         LEFT JOIN users u ON u.id = hh.enviado_por
         WHERE hh.cobranca_id = ? ORDER BY hh.created_at DESC"
    );
    $hist->execute(array($id));
    $timeline = $hist->fetchAll();

    $saldo = $cob['valor_total'] - $cob['valor_pago'];
    $statusLabels = array('em_dia'=>'Em dia','atrasado'=>'Atrasado','notificado_1'=>'Notif. 1','notificado_2'=>'Notif. 2','notificado_extrajudicial'=>'Extrajudicial','judicial'=>'Judicial','pago'=>'Pago','cancelado'=>'Cancelado');
    $etapaLabels = array('notificacao_1'=>'Notificação 1','notificacao_2'=>'Notificação 2','notificacao_extrajudicial'=>'Notificação Extrajudicial','judicial'=>'Cobrança Judicial','pagamento_parcial'=>'Pagamento Parcial','pagamento_total'=>'Pagamento Total','cancelamento'=>'Cancelamento','observacao'=>'Observação');
    ?>
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;">
        <div>
            <h3 style="font-size:1rem;margin:0 0 .2rem;color:var(--petrol-900);"><?= e($cob['client_name']) ?></h3>
            <span style="font-size:.75rem;color:var(--text-muted);"><?= e($cob['client_cpf'] ?: '') ?> · <?= e($cob['client_phone'] ?: '') ?></span>
        </div>
        <button onclick="document.getElementById('modalDetalhe').style.display='none';" style="background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--text-muted);">✕</button>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1rem;font-size:.8rem;">
        <div><strong>Tipo:</strong> <?= e($cob['tipo_debito']) ?></div>
        <div><strong>Status:</strong> <?= $statusLabels[$cob['status']] ?? ucfirst($cob['status']) ?></div>
        <div><strong>Valor total:</strong> R$ <?= number_format($cob['valor_total'], 2, ',', '.') ?></div>
        <div><strong>Valor pago:</strong> <span style="color:#059669;">R$ <?= number_format($cob['valor_pago'], 2, ',', '.') ?></span></div>
        <div><strong>Saldo:</strong> <span style="color:#dc2626;font-weight:700;">R$ <?= number_format($saldo, 2, ',', '.') ?></span></div>
        <div><strong>Atraso:</strong> <span style="color:#d97706;font-weight:700;"><?= max(0, (int)$cob['dias_atraso']) ?> dias</span></div>
        <div><strong>Vencimento:</strong> <?= date('d/m/Y', strtotime($cob['vencimento'])) ?></div>
        <div><strong>Processo:</strong> <?= e($cob['case_title'] ?: '—') ?></div>
        <div><strong>Responsável:</strong> <?= e($cob['responsavel_nome'] ?: '—') ?></div>
        <div><strong>Entrada:</strong> <?= $cob['entrada_automatica'] ? 'Automática' : 'Manual' ?></div>
    </div>

    <?php if ($cob['observacoes']): ?>
    <div style="background:rgba(249,115,22,.06);border:1px solid rgba(249,115,22,.2);border-radius:8px;padding:.6rem;font-size:.8rem;margin-bottom:1rem;">
        <strong>Obs:</strong> <?= nl2br(e($cob['observacoes'])) ?>
    </div>
    <?php endif; ?>

    <?php
    // Resumo Asaas do mesmo cliente
    $asaasTotais = null;
    try {
        $stmtA = $pdo->prepare(
            "SELECT
                COUNT(*) AS total_cobrancas,
                SUM(CASE WHEN status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') THEN valor_pago ELSE 0 END) AS recebido,
                SUM(CASE WHEN status = 'PENDING' THEN valor ELSE 0 END) AS pendente,
                SUM(CASE WHEN status = 'OVERDUE' THEN valor ELSE 0 END) AS vencido,
                COUNT(CASE WHEN status = 'OVERDUE' THEN 1 END) AS qtd_vencidas
             FROM asaas_cobrancas WHERE client_id = ?"
        );
        $stmtA->execute(array($cob['client_id']));
        $asaasTotais = $stmtA->fetch();
    } catch (Exception $e) {}
    if ($asaasTotais && (int)$asaasTotais['total_cobrancas'] > 0):
    ?>
    <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #93c5fd;border-radius:8px;padding:.7rem .9rem;margin-bottom:1rem;font-size:.8rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;">
            <strong style="color:#1e3a8a;">📊 Situação no Asaas deste cliente</strong>
            <?php if (function_exists('can_access_financeiro') && can_access_financeiro()): ?>
            <a href="<?= module_url('financeiro', 'cliente.php?id=' . $cob['client_id']) ?>" target="_blank" style="font-size:.7rem;color:#1e3a8a;">Extrato completo →</a>
            <?php endif; ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.4rem;font-size:.72rem;">
            <div><div style="color:#6b7280;">Recebido</div><strong style="color:#059669;">R$ <?= number_format((float)$asaasTotais['recebido'], 2, ',', '.') ?></strong></div>
            <div><div style="color:#6b7280;">Pendente</div><strong style="color:#b45309;">R$ <?= number_format((float)$asaasTotais['pendente'], 2, ',', '.') ?></strong></div>
            <div><div style="color:#6b7280;">Vencido</div><strong style="color:#dc2626;">R$ <?= number_format((float)$asaasTotais['vencido'], 2, ',', '.') ?> <?= $asaasTotais['qtd_vencidas'] > 0 ? '(' . $asaasTotais['qtd_vencidas'] . 'p)' : '' ?></strong></div>
            <div><div style="color:#6b7280;">Total histórico</div><strong><?= (int)$asaasTotais['total_cobrancas'] ?> cobranças</strong></div>
        </div>
    </div>
    <?php endif; ?>

    <!-- WhatsApp -->
    <?php if ($cob['client_phone']): ?>
    <div style="margin-bottom:1rem;">
        <button type="button" onclick="waSenderOpen({telefone:'<?= preg_replace('/\D/', '', $cob['client_phone']) ?>',nome:<?= json_encode($cob['client_name']) ?>,clientId:<?= (int)$cob['client_id'] ?>,canal:'24',mensagem:''})"
           class="btn btn-primary btn-sm" style="background:#25d366;font-size:.72rem;border:none;">
            📱 WhatsApp
        </button>
    </div>
    <?php endif; ?>

    <!-- Timeline -->
    <h4 style="font-size:.85rem;font-weight:700;margin-bottom:.5rem;">📜 Timeline</h4>
    <?php if (empty($timeline)): ?>
        <p style="font-size:.78rem;color:var(--text-muted);">Nenhuma ação registrada ainda.</p>
    <?php else: ?>
    <div style="max-height:250px;overflow-y:auto;">
        <?php foreach ($timeline as $tl): ?>
        <div style="border-left:2px solid #B87333;padding-left:.8rem;margin-bottom:.6rem;">
            <div style="font-size:.72rem;font-weight:700;color:#B87333;"><?= $etapaLabels[$tl['etapa']] ?? ucfirst($tl['etapa']) ?></div>
            <div style="font-size:.75rem;"><?= nl2br(e($tl['descricao'] ?: '')) ?></div>
            <?php if ($tl['valor_pago']): ?>
            <div style="font-size:.75rem;color:#059669;font-weight:700;">R$ <?= number_format($tl['valor_pago'], 2, ',', '.') ?></div>
            <?php endif; ?>
            <div style="font-size:.62rem;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($tl['created_at'])) ?> · <?= e($tl['user_name'] ?: 'Sistema') ?> <?= $tl['enviado_via'] ? '· via ' . $tl['enviado_via'] : '' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Ações rápidas -->
    <div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.75rem;padding-top:.75rem;border-top:1px solid var(--border);">
        <?php if (!in_array($cob['status'], array('pago','cancelado'))): ?>
        <button onclick="registrarPagamento(<?= $cob['id'] ?>,<?= $saldo ?>);document.getElementById('modalDetalhe').style.display='none';" class="btn btn-primary btn-sm" style="background:#059669;font-size:.72rem;">💰 Registrar Pagamento</button>
        <?php endif; ?>
        <?php if ($cob['status'] !== 'cancelado' && $cob['status'] !== 'pago'): ?>
        <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>" style="display:inline;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="cancelar">
            <input type="hidden" name="cobranca_id" value="<?= $cob['id'] ?>">
            <button type="submit" onclick="return confirm('Cancelar esta cobrança?');" class="btn btn-outline btn-sm" style="font-size:.72rem;color:#dc2626;border-color:#dc2626;">Cancelar</button>
        </form>
        <?php endif; ?>
    </div>
    <?php
    exit;
}

// ─── GET: Exportar Excel ───
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'exportar_excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cobranca_honorarios_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8
    fputcsv($out, array('ID','Cliente','CPF','Tipo','Valor Total','Valor Pago','Saldo','Vencimento','Dias Atraso','Status','Responsável','Entrada','Criado em'), ';');

    $rows = $pdo->query(
        "SELECT hc.*, cl.name as client_name, cl.cpf as client_cpf, u.name as responsavel_nome,
                DATEDIFF(CURDATE(), hc.vencimento) as dias_atraso
         FROM honorarios_cobranca hc
         LEFT JOIN clients cl ON cl.id = hc.client_id
         LEFT JOIN users u ON u.id = hc.responsavel_cobranca
         ORDER BY hc.created_at DESC"
    )->fetchAll();

    foreach ($rows as $r) {
        fputcsv($out, array(
            $r['id'], $r['client_name'], $r['client_cpf'], $r['tipo_debito'],
            number_format($r['valor_total'], 2, ',', '.'),
            number_format($r['valor_pago'], 2, ',', '.'),
            number_format($r['valor_total'] - $r['valor_pago'], 2, ',', '.'),
            date('d/m/Y', strtotime($r['vencimento'])),
            max(0, (int)$r['dias_atraso']),
            $r['status'], $r['responsavel_nome'] ?: '', $r['entrada_automatica'] ? 'Automática' : 'Manual',
            date('d/m/Y H:i', strtotime($r['created_at']))
        ), ';');
    }
    fclose($out);
    exit;
}

// ─── POST handlers ───
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(module_url('cobranca_honorarios')); }
if (!validate_csrf()) { flash_set('error', 'Token inválido.'); redirect(module_url('cobranca_honorarios')); }

$action = $_POST['action'] ?? '';

// ── Importar inadimplentes do Asaas (cobranças OVERDUE + PENDING > 5 dias) ──
if ($action === 'importar_asaas') {
    $maxAtrasoDias = (int)($_POST['max_atraso'] ?? 0); // 0 = sem limite; se >0, filtra
    $apenasOverdue = isset($_POST['apenas_overdue']) && $_POST['apenas_overdue'] === '1';

    $filtro = $apenasOverdue ? "status = 'OVERDUE'" : "status IN ('OVERDUE','PENDING') AND vencimento < CURDATE()";
    if ($maxAtrasoDias > 0) {
        $filtro .= " AND DATEDIFF(CURDATE(), vencimento) <= " . (int)$maxAtrasoDias;
    }

    $rows = $pdo->query("SELECT ac.* FROM asaas_cobrancas ac
                         WHERE {$filtro} AND ac.client_id IS NOT NULL
                         ORDER BY ac.vencimento ASC")->fetchAll();

    $inseridas = 0; $jaExistiam = 0;
    $upsert = $pdo->prepare(
        "INSERT INTO honorarios_cobranca (client_id, tipo_debito, valor_total, valor_pago, vencimento,
         status, entrada_automatica, asaas_payment_id, observacoes, created_by)
         VALUES (?, ?, ?, 0, ?, 'atrasado', 1, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           valor_total = VALUES(valor_total),
           vencimento = VALUES(vencimento),
           updated_at = NOW()"
    );

    foreach ($rows as $c) {
        $tipo = $c['descricao'] ? mb_substr($c['descricao'], 0, 100) : 'Cobrança Asaas';
        $obs = 'Importado do Asaas em ' . date('d/m/Y H:i') . '. Payment ID: ' . $c['asaas_payment_id']
             . ($c['invoice_url'] ? "\nFatura: " . $c['invoice_url'] : '');
        $before = $pdo->query("SELECT COUNT(*) FROM honorarios_cobranca WHERE asaas_payment_id = '" . addslashes($c['asaas_payment_id']) . "'")->fetchColumn();
        $upsert->execute(array(
            $c['client_id'], $tipo, $c['valor'], $c['vencimento'],
            $c['asaas_payment_id'], $obs, $userId,
        ));
        if ($before == 0) $inseridas++;
        else $jaExistiam++;
    }

    audit_log('hc_importar_asaas', 'honorarios_cobranca', 0, "inseridas={$inseridas} atualizadas={$jaExistiam}");
    flash_set('success', "Importação Asaas concluída: {$inseridas} novas, {$jaExistiam} atualizadas (de " . count($rows) . " verificadas).");
    redirect(module_url('cobranca_honorarios'));
}

// ── Criar cobrança (manual) ──
if ($action === 'criar_cobranca') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $tipoDebito = trim($_POST['tipo_debito'] ?? '');
    $valorTotal = (float)str_replace(array('.', ','), array('', '.'), $_POST['valor_total'] ?? '0');
    $vencimento = $_POST['vencimento'] ?? '';
    $caseId = (int)($_POST['case_id'] ?? 0);
    $obs = trim($_POST['observacoes'] ?? '');

    if (!$clientId || !$tipoDebito || $valorTotal <= 0 || !$vencimento) {
        flash_set('error', 'Preencha todos os campos obrigatórios.');
        redirect(module_url('cobranca_honorarios'));
    }

    $stmt = $pdo->prepare(
        "INSERT INTO honorarios_cobranca (client_id, case_id, tipo_debito, valor_total, vencimento, status, entrada_automatica, observacoes, created_by)
         VALUES (?, ?, ?, ?, ?, 'atrasado', 0, ?, ?)"
    );
    $stmt->execute(array($clientId, $caseId ?: null, $tipoDebito, $valorTotal, $vencimento, $obs, $userId));
    $cobId = (int)$pdo->lastInsertId();

    // Histórico
    $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_por) VALUES (?, 'observacao', ?, ?)")
        ->execute(array($cobId, 'Cobrança registrada manualmente. ' . $obs, $userId));

    audit_log('cobranca_criar', 'honorarios_cobranca', $cobId, "Inadimplência registrada: R$ " . number_format($valorTotal, 2, ',', '.'));

    // Notificar admins
    $clientName = $pdo->query("SELECT name FROM clients WHERE id = $clientId")->fetchColumn();
    notify_admins('⚠️ Inadimplência registrada', $clientName . ' — R$ ' . number_format($valorTotal, 2, ',', '.'), module_url('cobranca_honorarios', '?aba=fila'), 'warning', '⚠️');

    flash_set('success', 'Inadimplência registrada com sucesso.');
    redirect(module_url('cobranca_honorarios', '?aba=fila'));
}

// ── Avançar etapa EM MASSA (todas as parcelas de 1 cliente de uma vez) ──
if ($action === 'avancar_etapa_massa') {
    $ids = array_map('intval', (array)($_POST['cobranca_ids'] ?? array()));
    $proxEtapa = $_POST['proxima_etapa'] ?? '';
    $mapStatus = array(
        'notificar_1' => 'notificado_1',
        'notificar_2' => 'notificado_2',
        'notificar_extrajudicial' => 'notificado_extrajudicial',
    );
    $etapaHistMap = array(
        'notificar_1' => 'notificacao_1',
        'notificar_2' => 'notificacao_2',
        'notificar_extrajudicial' => 'notificacao_extrajudicial',
    );
    if (!isset($mapStatus[$proxEtapa]) || empty($ids)) {
        flash_set('error', 'Parâmetros inválidos.');
        redirect(module_url('cobranca_honorarios'));
    }
    $novoStatus = $mapStatus[$proxEtapa];

    // Pegar dados do cliente + parcelas antes de atualizar status
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmtP = $pdo->prepare(
        "SELECT hc.*, cl.id as cli_id, cl.name as client_name, cl.phone as client_phone
         FROM honorarios_cobranca hc LEFT JOIN clients cl ON cl.id = hc.client_id
         WHERE hc.id IN ($ph)"
    );
    $stmtP->execute($ids);
    $parcelas = $stmtP->fetchAll();

    // Atualiza status em massa
    $stmtUpd = $pdo->prepare("UPDATE honorarios_cobranca SET status = ?, updated_at = NOW() WHERE id IN ($ph)");
    $stmtUpd->execute(array_merge(array($novoStatus), $ids));
    $n = count($parcelas);

    // Monta mensagem consolidada pro cliente (soma de parcelas + maior vencimento/atraso)
    $cliente = $parcelas[0] ?? null;
    $totalSaldo = 0; $venMaisAntigo = null;
    foreach ($parcelas as $p) {
        $totalSaldo += ((float)$p['valor_total'] - (float)$p['valor_pago']);
        if (!$venMaisAntigo || $p['vencimento'] < $venMaisAntigo) $venMaisAntigo = $p['vencimento'];
    }

    // Carrega template de mensagem conforme etapa
    $config = $pdo->query("SELECT * FROM honorarios_config ORDER BY id LIMIT 1")->fetch();
    $template = '';
    if ($proxEtapa === 'notificar_1' && !empty($config['msg_notificacao_1'])) $template = $config['msg_notificacao_1'];
    elseif ($proxEtapa === 'notificar_2' && !empty($config['msg_notificacao_2'])) $template = $config['msg_notificacao_2'];
    elseif ($proxEtapa === 'notificar_extrajudicial') $template = "Prezado(a) [Nome], notificamos EXTRAJUDICIALMENTE sobre débito pendente no valor de R$ [valor] (vencimento mais antigo: [data]). Solicitamos regularização em até 10 dias úteis para evitar medidas judiciais. _Ferreira & Sá Advocacia_";

    $msg = $template;
    if ($cliente) {
        $msg = str_replace('[Nome]', $cliente['client_name'] ?: 'Cliente', $msg);
        $msg = str_replace('[valor]', number_format($totalSaldo, 2, ',', '.'), $msg);
        $msg = str_replace('[data]', $venMaisAntigo ? date('d/m/Y', strtotime($venMaisAntigo)) : '', $msg);
        if ($n > 1) $msg .= "\n\n_(Referente a {$n} parcelas em aberto)_";
    }

    // Enfileira 1 sugestão na Caixa de Envios (pra Amanda/equipe revisar e enviar)
    $filaId = null;
    if ($cliente && $cliente['client_phone'] && $msg) {
        require_once APP_ROOT . '/core/functions_zapi.php';
        $filaId = zapi_fila_enfileirar(
            'cobranca_' . $proxEtapa,
            (int)$cliente['cli_id'],
            $cliente['client_phone'],
            $msg,
            array('nome' => $cliente['client_name'], 'canal' => '24', 'criada_por' => $userId)
        );
    }

    // Log no histórico de cada parcela (como SUGESTÃO, não envio)
    $etapaHist = $etapaHistMap[$proxEtapa];
    $hist = $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_via, enviado_por) VALUES (?, ?, ?, ?, ?)");
    foreach ($ids as $cid) {
        $hist->execute(array($cid, $etapaHist,
            $filaId ? "Sugestão de mensagem enfileirada na Caixa de Envios WhatsApp (id fila #{$filaId}). Revisar antes de enviar." : "Status avançado sem envio (sem telefone/template).",
            'manual', $userId));
    }
    audit_log('hc_avancar_massa', 'honorarios_cobranca', 0, "ids=[" . implode(',', $ids) . "] novo={$novoStatus} fila_id={$filaId}");

    $fm = $filaId
        ? "{$n} parcela(s) movidas. 💬 Sugestão de mensagem na Caixa de Envios — revise e envie."
        : "{$n} parcela(s) movidas (sem telefone do cliente, nada foi sugerido).";
    flash_set('success', $fm);
    redirect(module_url('cobranca_honorarios'));
}

// ── Avançar etapa ──
if ($action === 'avancar_etapa') {
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $proxEtapa = $_POST['proxima_etapa'] ?? '';

    $cob = $pdo->prepare("SELECT hc.*, cl.name as client_name, cl.phone as client_phone FROM honorarios_cobranca hc LEFT JOIN clients cl ON cl.id = hc.client_id WHERE hc.id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { flash_set('error', 'Cobrança não encontrada.'); redirect(module_url('cobranca_honorarios', '?aba=fila')); }

    // Carregar config
    $config = $pdo->query("SELECT * FROM honorarios_config ORDER BY id LIMIT 1")->fetch();
    $saldo = $cob['valor_total'] - $cob['valor_pago'];

    // Mapear etapas
    $statusMap = array(
        'notificar_1' => 'notificado_1',
        'notificar_2' => 'notificado_2',
        'notificar_extrajudicial' => 'notificado_extrajudicial',
    );
    $etapaHistMap = array(
        'notificar_1' => 'notificacao_1',
        'notificar_2' => 'notificacao_2',
        'notificar_extrajudicial' => 'notificacao_extrajudicial',
    );

    if (!isset($statusMap[$proxEtapa])) {
        flash_set('error', 'Etapa inválida.');
        redirect(module_url('cobranca_honorarios', '?aba=fila'));
    }

    $novoStatus = $statusMap[$proxEtapa];
    $etapaHist = $etapaHistMap[$proxEtapa];

    // Atualizar status
    $pdo->prepare("UPDATE honorarios_cobranca SET status = ?, updated_at = NOW() WHERE id = ?")
        ->execute(array($novoStatus, $cobId));

    // Preparar mensagem WhatsApp
    $msg = '';
    if ($proxEtapa === 'notificar_1' && $config && $config['msg_notificacao_1']) {
        $msg = $config['msg_notificacao_1'];
    } elseif ($proxEtapa === 'notificar_2' && $config && $config['msg_notificacao_2']) {
        $msg = $config['msg_notificacao_2'];
    } elseif ($proxEtapa === 'notificar_extrajudicial') {
        $msg = "Prezado(a) [Nome], notificamos EXTRAJUDICIALMENTE sobre débito pendente no valor de R$ [valor] (vencimento: [data]). Solicitamos regularização em até 10 dias úteis para evitar medidas judiciais. _Ferreira & Sá Advocacia_";
    }

    // Substituir variáveis
    $msg = str_replace('[Nome]', $cob['client_name'] ?: '', $msg);
    $msg = str_replace('[valor]', number_format($saldo, 2, ',', '.'), $msg);
    $msg = str_replace('[data]', date('d/m/Y', strtotime($cob['vencimento'])), $msg);

    // Enfileira na Caixa de Envios WhatsApp pra revisão manual (não envia automático)
    $filaId = null;
    if ($cob['client_phone'] && $msg) {
        require_once APP_ROOT . '/core/functions_zapi.php';
        $filaId = zapi_fila_enfileirar(
            'cobranca_' . $proxEtapa,
            (int)$cob['client_id'],
            $cob['client_phone'],
            $msg,
            array('nome' => $cob['client_name'], 'canal' => '24', 'criada_por' => $userId)
        );
    }

    // Histórico
    $descHist = $filaId
        ? "Sugestão enfileirada na Caixa de Envios WhatsApp (id #{$filaId}) — revise e envie."
        : "Status avançado (sem telefone/template configurado).";
    $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_via, enviado_por) VALUES (?, ?, ?, 'manual', ?)")
        ->execute(array($cobId, $etapaHist, $descHist, $userId));

    audit_log('cobranca_avancar', 'honorarios_cobranca', $cobId, "Avançou para $novoStatus fila_id={$filaId}");
    if ($filaId) {
        flash_set('success', '✅ Status atualizado. 💬 Sugestão de mensagem na <a href="' . module_url('whatsapp', 'fila.php') . '" style="color:#B87333;font-weight:700;text-decoration:underline;">Caixa de Envios</a> — revise e envie.');
    } else {
        flash_set('success', 'Status atualizado (sem telefone do cliente ou template configurado).');
    }
    redirect(module_url('cobranca_honorarios', '?aba=fila'));
}

// ── Mover para Judicial (Admin) ──
if ($action === 'mover_judicial') {
    if (!$isAdmin) { flash_set('error', 'Apenas Admin pode mover para judicial.'); redirect(module_url('cobranca_honorarios', '?aba=fila')); }

    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $responsavelId = (int)($_POST['responsavel_id'] ?? 0);

    $cob = $pdo->prepare("SELECT hc.*, cl.name as client_name FROM honorarios_cobranca hc LEFT JOIN clients cl ON cl.id = hc.client_id WHERE hc.id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { flash_set('error', 'Cobrança não encontrada.'); redirect(module_url('cobranca_honorarios', '?aba=fila')); }

    // Atualizar cobrança
    $pdo->prepare("UPDATE honorarios_cobranca SET status = 'judicial', responsavel_cobranca = ?, updated_at = NOW() WHERE id = ?")
        ->execute(array($responsavelId, $cobId));

    // Criar caso no Operacional
    $saldo = $cob['valor_total'] - $cob['valor_pago'];
    $titulo = 'Cobrança de Honorários — ' . ($cob['client_name'] ?: 'Cliente');
    $stmt = $pdo->prepare(
        "INSERT INTO cases (client_id, title, case_type, status, responsible_user_id, priority, observacoes, created_at, updated_at)
         VALUES (?, ?, 'cobranca_honorarios', 'aguardando_docs', ?, 'alta', ?, NOW(), NOW())"
    );
    $obs = "Cobrança judicial de honorários. Valor: R$ " . number_format($saldo, 2, ',', '.') . ". Cobrança #$cobId.";
    $stmt->execute(array($cob['client_id'], $titulo, $responsavelId, $obs));
    $novoCaseId = (int)$pdo->lastInsertId();

    // Vincular case_id à cobrança
    $pdo->prepare("UPDATE honorarios_cobranca SET case_id = ? WHERE id = ? AND case_id IS NULL")
        ->execute(array($novoCaseId, $cobId));

    // Histórico
    $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_por) VALUES (?, 'judicial', ?, ?)")
        ->execute(array($cobId, "Movido para cobrança judicial. Caso #$novoCaseId criado. Responsável: user #$responsavelId", $userId));

    // Notificar responsável
    notify($responsavelId, '⚖️ Cobrança Judicial Atribuída', 'Cobrança de honorários — ' . ($cob['client_name'] ?: '') . ' (R$ ' . number_format($saldo, 2, ',', '.') . ')', module_url('operacional', 'caso_ver.php?id=' . $novoCaseId), 'warning', '⚖️');

    audit_log('cobranca_judicial', 'honorarios_cobranca', $cobId, "Movido para judicial, caso #$novoCaseId, responsável #$responsavelId");

    flash_set('success', 'Cobrança movida para judicial. Caso #' . $novoCaseId . ' criado no Operacional.');
    redirect(module_url('cobranca_honorarios', '?aba=fila'));
}

// ── Registrar pagamento ──
if ($action === 'registrar_pagamento') {
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $valorPago = (float)str_replace(array('.', ','), array('', '.'), $_POST['valor_pago'] ?? '0');
    $via = $_POST['enviado_via'] ?? 'manual';

    if ($cobId <= 0 || $valorPago <= 0) {
        flash_set('error', 'Dados inválidos.');
        redirect(module_url('cobranca_honorarios', '?aba=fila'));
    }

    $cob = $pdo->prepare("SELECT * FROM honorarios_cobranca WHERE id = ?");
    $cob->execute(array($cobId));
    $cob = $cob->fetch();
    if (!$cob) { flash_set('error', 'Cobrança não encontrada.'); redirect(module_url('cobranca_honorarios', '?aba=fila')); }

    $novoValorPago = $cob['valor_pago'] + $valorPago;
    $saldoRestante = $cob['valor_total'] - $novoValorPago;
    $etapa = $saldoRestante <= 0.01 ? 'pagamento_total' : 'pagamento_parcial';
    $novoStatus = $saldoRestante <= 0.01 ? 'pago' : $cob['status'];

    $pdo->prepare("UPDATE honorarios_cobranca SET valor_pago = ?, status = ?, updated_at = NOW() WHERE id = ?")
        ->execute(array($novoValorPago, $novoStatus, $cobId));

    $desc = ($etapa === 'pagamento_total')
        ? 'Pagamento total recebido. Cobrança quitada.'
        : 'Pagamento parcial de R$ ' . number_format($valorPago, 2, ',', '.') . '. Saldo restante: R$ ' . number_format(max(0, $saldoRestante), 2, ',', '.');

    $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, valor_pago, enviado_via, enviado_por) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute(array($cobId, $etapa, $desc, $valorPago, $via, $userId));

    audit_log('cobranca_pagamento', 'honorarios_cobranca', $cobId, "Pagamento R$ " . number_format($valorPago, 2, ',', '.'));

    flash_set('success', ($etapa === 'pagamento_total' ? 'Cobrança quitada!' : 'Pagamento parcial registrado.'));
    redirect(module_url('cobranca_honorarios', '?aba=fila'));
}

// ── Cancelar cobrança ──
if ($action === 'cancelar') {
    $cobId = (int)($_POST['cobranca_id'] ?? 0);
    $pdo->prepare("UPDATE honorarios_cobranca SET status = 'cancelado', updated_at = NOW() WHERE id = ?")->execute(array($cobId));
    $pdo->prepare("INSERT INTO honorarios_cobranca_historico (cobranca_id, etapa, descricao, enviado_por) VALUES (?, 'cancelamento', 'Cobrança cancelada.', ?)")
        ->execute(array($cobId, $userId));
    audit_log('cobranca_cancelar', 'honorarios_cobranca', $cobId, 'Cancelada');
    flash_set('success', 'Cobrança cancelada.');
    redirect(module_url('cobranca_honorarios', '?aba=historico'));
}

// ── Salvar config (Admin) ──
if ($action === 'salvar_config') {
    if (!$isAdmin) { flash_set('error', 'Sem permissão.'); redirect(module_url('cobranca_honorarios', '?aba=config')); }

    $dias = max(1, (int)($_POST['dias_para_cobranca'] ?? 90));
    $p1 = max(1, (int)($_POST['prazo_notificacao_1'] ?? 7));
    $p2 = max(1, (int)($_POST['prazo_notificacao_2'] ?? 15));
    $pe = max(1, (int)($_POST['prazo_extrajudicial'] ?? 10));
    $resp = (int)($_POST['responsavel_padrao_id'] ?? 0);

    $pdo->prepare("UPDATE honorarios_config SET dias_para_cobranca = ?, prazo_notificacao_1 = ?, prazo_notificacao_2 = ?, prazo_extrajudicial = ?, responsavel_padrao_id = ? ORDER BY id LIMIT 1")
        ->execute(array($dias, $p1, $p2, $pe, $resp ?: null));

    audit_log('cobranca_config', 'honorarios_config', 0, "Parâmetros atualizados: $dias/$p1/$p2/$pe dias");
    flash_set('success', 'Parâmetros salvos.');
    redirect(module_url('cobranca_honorarios', '?aba=config'));
}

// ── Salvar mensagens (Admin) ──
if ($action === 'salvar_mensagens') {
    if (!$isAdmin) { flash_set('error', 'Sem permissão.'); redirect(module_url('cobranca_honorarios', '?aba=config')); }

    $msg1 = trim($_POST['msg_notificacao_1'] ?? '');
    $msg2 = trim($_POST['msg_notificacao_2'] ?? '');

    $pdo->prepare("UPDATE honorarios_config SET msg_notificacao_1 = ?, msg_notificacao_2 = ? ORDER BY id LIMIT 1")
        ->execute(array($msg1, $msg2));

    audit_log('cobranca_config_msg', 'honorarios_config', 0, 'Templates de mensagem atualizados');
    flash_set('success', 'Templates de mensagem salvos.');
    redirect(module_url('cobranca_honorarios', '?aba=config'));
}

// Fallback
flash_set('error', 'Ação não reconhecida.');
redirect(module_url('cobranca_honorarios'));
