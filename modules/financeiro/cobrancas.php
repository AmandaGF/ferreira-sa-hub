<?php
/**
 * Ferreira & Sá Hub — Todas as Cobranças (filtros + busca + ordenação + paginação)
 * Reflete a realidade integral: não limita a 50, permite períodos customizados
 * e busca por nome/CPF/nº Asaas/processo.
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!can_access_financeiro()) { redirect(url('modules/dashboard/')); }

$pageTitle = 'Todas as Cobranças';
$pdo = db();

// ───────── Filtros ─────────
$busca       = trim($_GET['q'] ?? '');
$dtBase      = $_GET['dt_base']   ?? 'vencimento'; // vencimento | pagamento
if (!in_array($dtBase, array('vencimento','pagamento'), true)) $dtBase = 'vencimento';
$dtIni       = $_GET['dt_ini']    ?? '';
$dtFim       = $_GET['dt_fim']    ?? '';
$statusFilt  = $_GET['status']    ?? 'todos';
$formaFilt   = $_GET['forma']     ?? 'todas';
$caseFilt    = (int)($_GET['case_id'] ?? 0);
$ordem       = $_GET['ordem']     ?? 'venc_desc';
$page        = max(1, (int)($_GET['p'] ?? 1));
$perPage     = 50;

// Valida datas
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtIni)) $dtIni = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtFim)) $dtFim = '';

// Presets
$preset = $_GET['preset'] ?? '';
if ($preset === 'hoje')       { $dtIni = $dtFim = date('Y-m-d'); }
elseif ($preset === '7d')     { $dtIni = date('Y-m-d', strtotime('-6 days')); $dtFim = date('Y-m-d'); }
elseif ($preset === '30d')    { $dtIni = date('Y-m-d', strtotime('-29 days')); $dtFim = date('Y-m-d'); }
elseif ($preset === 'mes')    { $dtIni = date('Y-m-01'); $dtFim = date('Y-m-d'); }
elseif ($preset === 'mesant') { $dtIni = date('Y-m-01', strtotime('first day of last month')); $dtFim = date('Y-m-t', strtotime('last day of last month')); }
elseif ($preset === 'ano')    { $dtIni = date('Y-01-01'); $dtFim = date('Y-m-d'); }

// ───────── WHERE ─────────
$where = array();
$params = array();

if ($dtIni) { $where[] = "ac." . ($dtBase === 'pagamento' ? 'data_pagamento' : 'vencimento') . " >= ?"; $params[] = $dtIni; }
if ($dtFim) { $where[] = "ac." . ($dtBase === 'pagamento' ? 'data_pagamento' : 'vencimento') . " <= ?"; $params[] = $dtFim; }

if ($statusFilt !== 'todos') {
    if ($statusFilt === 'pagos') {
        $where[] = "ac.status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH')";
    } elseif ($statusFilt === 'pendentes') {
        $where[] = "ac.status = 'PENDING'";
    } elseif ($statusFilt === 'vencidos') {
        $where[] = "ac.status = 'OVERDUE'";
    } elseif ($statusFilt === 'cancelados') {
        $where[] = "ac.status IN ('CANCELED','DELETED','REFUNDED','REFUND_REQUESTED','CHARGEBACK_REQUESTED','CHARGEBACK_DISPUTE')";
    } else {
        $where[] = "ac.status = ?";
        $params[] = strtoupper($statusFilt);
    }
}

if ($formaFilt !== 'todas') {
    $where[] = "ac.forma_pagamento = ?";
    $params[] = strtoupper($formaFilt);
}

if ($caseFilt > 0) {
    $where[] = "ac.case_id = ?";
    $params[] = $caseFilt;
}

if ($busca !== '') {
    // Busca por nome OU CPF OU número Asaas OU nº processo vinculado
    $digits = preg_replace('/\D/', '', $busca);
    $sub = "(cl.name LIKE ? OR cl.cpf LIKE ? OR ac.asaas_payment_id LIKE ?";
    $likeBusca = '%' . $busca . '%';
    $likeDigits = '%' . ($digits !== '' ? $digits : $busca) . '%';
    $params[] = $likeBusca;
    $params[] = $likeDigits;
    $params[] = $likeBusca;
    $sub .= " OR cs.case_number LIKE ? OR cs.title LIKE ?";
    $params[] = $likeBusca;
    $params[] = $likeBusca;
    $sub .= ")";
    $where[] = $sub;
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ───────── ORDER BY ─────────
$ordens = array(
    'venc_desc'  => 'ac.vencimento DESC, ac.id DESC',
    'venc_asc'   => 'ac.vencimento ASC, ac.id ASC',
    'pag_desc'   => 'ac.data_pagamento DESC, ac.id DESC',
    'pag_asc'    => 'ac.data_pagamento ASC, ac.id ASC',
    'valor_desc' => 'ac.valor DESC',
    'valor_asc'  => 'ac.valor ASC',
    'nome_asc'   => 'cl.name ASC, ac.vencimento DESC',
    'status'     => "FIELD(ac.status,'OVERDUE','PENDING','CONFIRMED','RECEIVED','RECEIVED_IN_CASH','CANCELED') ASC, ac.vencimento DESC",
);
if (!isset($ordens[$ordem])) $ordem = 'venc_desc';
$orderBy = $ordens[$ordem];

// ───────── Totais (do filtro atual) ─────────
$sqlTotais = "SELECT
    COUNT(*) AS qtd,
    IFNULL(SUM(ac.valor), 0) AS total_valor,
    IFNULL(SUM(CASE WHEN ac.status IN ('RECEIVED','CONFIRMED','RECEIVED_IN_CASH') THEN ac.valor_pago ELSE 0 END), 0) AS total_pago,
    IFNULL(SUM(CASE WHEN ac.status = 'OVERDUE' THEN ac.valor ELSE 0 END), 0) AS total_vencido,
    IFNULL(SUM(CASE WHEN ac.status = 'PENDING' THEN ac.valor ELSE 0 END), 0) AS total_pendente
    FROM asaas_cobrancas ac
    LEFT JOIN clients cl ON cl.id = ac.client_id
    LEFT JOIN cases cs ON cs.id = ac.case_id
    {$whereStr}";
$sT = $pdo->prepare($sqlTotais);
$sT->execute($params);
$totais = $sT->fetch() ?: array('qtd' => 0, 'total_valor' => 0, 'total_pago' => 0, 'total_vencido' => 0, 'total_pendente' => 0);

// ───────── Export CSV ─────────
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cobrancas_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM utf-8
    $out = fopen('php://output', 'w');
    fputcsv($out, array('Vencimento','Pagamento','Cliente','CPF','Valor','Valor Pago','Status','Forma','Processo','Nº Asaas','Descrição'), ';');
    $sqlCsv = "SELECT ac.*, cl.name AS cli_name, cl.cpf AS cli_cpf,
                      cs.title AS case_title, cs.case_number AS case_number
               FROM asaas_cobrancas ac
               LEFT JOIN clients cl ON cl.id = ac.client_id
               LEFT JOIN cases cs ON cs.id = ac.case_id
               {$whereStr} ORDER BY {$orderBy}";
    $sCsv = $pdo->prepare($sqlCsv);
    $sCsv->execute($params);
    $statusPagosCsv = array('RECEIVED','CONFIRMED','RECEIVED_IN_CASH');
    while ($r = $sCsv->fetch()) {
        // Valor pago só aparece se o status for de pagamento efetivo — evita alimentar somas com cobranças canceladas
        $vPagoCsv = in_array($r['status'], $statusPagosCsv, true) ? (float)($r['valor_pago'] ?? 0) : 0;
        fputcsv($out, array(
            $r['vencimento'] ?: '',
            $r['data_pagamento'] ?: '',
            $r['cli_name'] ?: '',
            $r['cli_cpf'] ?: '',
            number_format((float)$r['valor'], 2, ',', '.'),
            number_format($vPagoCsv, 2, ',', '.'),
            $r['status'] ?: '',
            $r['forma_pagamento'] ?: '',
            ($r['case_title'] ? $r['case_title'] : '') . ($r['case_number'] ? ' — ' . $r['case_number'] : ''),
            $r['asaas_payment_id'] ?: '',
            $r['descricao'] ?: '',
        ), ';');
    }
    fclose($out); exit;
}

// ───────── Paginação ─────────
$qtdTotal = (int)$totais['qtd'];
$totalPages = max(1, (int)ceil($qtdTotal / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT ac.*, cl.name AS cli_name, cl.cpf AS cli_cpf, cl.phone AS cli_phone,
               cs.id AS cs_id, cs.title AS case_title, cs.case_number AS case_number
        FROM asaas_cobrancas ac
        LEFT JOIN clients cl ON cl.id = ac.client_id
        LEFT JOIN cases cs ON cs.id = ac.case_id
        {$whereStr}
        ORDER BY {$orderBy}
        LIMIT {$perPage} OFFSET {$offset}";
$s = $pdo->prepare($sql);
$s->execute($params);
$rows = $s->fetchAll();

// Status labels e cores
$stLabels = array(
    'PENDING'  => array('Pendente',    '#f59e0b'),
    'RECEIVED' => array('Pago',        '#059669'),
    'CONFIRMED'=> array('Confirmado',  '#059669'),
    'RECEIVED_IN_CASH' => array('Pago dinheiro', '#0891b2'),
    'OVERDUE'  => array('Vencido',     '#dc2626'),
    'CANCELED' => array('Cancelado',   '#6b7280'),
    'DELETED'  => array('Excluído',    '#6b7280'),
    'REFUNDED' => array('Estornado',   '#6b7280'),
    'REFUND_REQUESTED' => array('Estorno solic.', '#d97706'),
    'CHARGEBACK_REQUESTED' => array('Chargeback', '#d97706'),
    'CHARGEBACK_DISPUTE' => array('Disputa', '#d97706'),
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.cobr-toolbar { background:#fff;border:1px solid var(--border);border-radius:12px;padding:.8rem;margin-bottom:1rem;display:grid;grid-template-columns:repeat(auto-fit, minmax(160px, 1fr));gap:.5rem;align-items:end; }
.cobr-fld { display:flex;flex-direction:column;gap:3px; }
.cobr-fld label { font-size:.62rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px; }
.cobr-fld input, .cobr-fld select { padding:6px 8px;border:1.5px solid var(--border);border-radius:7px;font-size:.78rem;background:#fff;width:100%;box-sizing:border-box; }
.cobr-presets { display:flex;gap:4px;flex-wrap:wrap;margin-top:4px; }
.cobr-preset { padding:4px 8px;font-size:.65rem;background:#f3f4f6;border:1px solid var(--border);border-radius:999px;text-decoration:none;color:var(--text); }
.cobr-preset.active, .cobr-preset:hover { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
.cobr-kpis { display:grid;grid-template-columns:repeat(auto-fit, minmax(140px,1fr));gap:.5rem;margin-bottom:1rem; }
.cobr-kpi { background:#fff;border:1px solid var(--border);border-radius:10px;padding:.7rem .9rem; }
.cobr-kpi .v { font-size:1.15rem;font-weight:800;color:var(--petrol-900);line-height:1.1; }
.cobr-kpi .l { font-size:.65rem;color:var(--text-muted);margin-top:3px;text-transform:uppercase;letter-spacing:.3px; }
.cobr-tbl { width:100%;border-collapse:collapse;font-size:.78rem;background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden; }
.cobr-tbl thead { background:linear-gradient(180deg,var(--petrol-900),var(--petrol-700));color:#fff; }
.cobr-tbl th { text-align:left;padding:8px 10px;font-size:.65rem;text-transform:uppercase;letter-spacing:.3px;font-weight:700;white-space:nowrap; }
.cobr-tbl th a { color:#fff;text-decoration:none; }
.cobr-tbl th a:hover { opacity:.8; }
.cobr-tbl td { padding:7px 10px;border-bottom:1px solid #f3f4f6;vertical-align:top; }
.cobr-tbl tr:hover { background:#fafbfc; }
.cobr-tbl td.num { text-align:right;font-variant-numeric:tabular-nums; }
.cobr-status { display:inline-block;padding:2px 8px;border-radius:10px;font-size:.65rem;font-weight:700;color:#fff;white-space:nowrap; }
.cobr-pag { display:flex;gap:4px;justify-content:center;margin-top:1rem;flex-wrap:wrap; }
.cobr-pag a { padding:5px 11px;border:1.5px solid var(--border);border-radius:7px;font-size:.78rem;text-decoration:none;font-weight:600;color:var(--text);background:#fff; }
.cobr-pag a.active { background:var(--petrol-900);color:#fff;border-color:var(--petrol-900); }
.cobr-pag a.disabled { opacity:.4;pointer-events:none; }
</style>

<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap;">
    <h2 style="margin:0;font-size:1.1rem;font-weight:800;color:var(--petrol-900);">💰 Todas as Cobranças</h2>
    <span style="color:var(--text-muted);font-size:.78rem;">integradas com Asaas</span>
    <div style="margin-left:auto;display:flex;gap:.4rem;">
        <a href="<?= module_url('financeiro') ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">← Resumo</a>
        <a href="?<?= http_build_query(array_merge($_GET, array('csv' => 1))) ?>" class="btn btn-primary btn-sm" style="font-size:.72rem;background:#059669;">📥 Exportar CSV</a>
    </div>
</div>

<!-- Toolbar de filtros -->
<form method="GET" class="cobr-toolbar">
    <div class="cobr-fld" style="grid-column:span 2;">
        <label>🔍 Busca (nome, CPF, nº Asaas, nº processo)</label>
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Ex: Maria Silva, 123.456.789-00, 0123456-78.2023..." autofocus>
    </div>
    <div class="cobr-fld">
        <label>Data base</label>
        <select name="dt_base">
            <option value="vencimento" <?= $dtBase === 'vencimento' ? 'selected' : '' ?>>Vencimento</option>
            <option value="pagamento"  <?= $dtBase === 'pagamento'  ? 'selected' : '' ?>>Pagamento</option>
        </select>
    </div>
    <div class="cobr-fld">
        <label>De</label>
        <input type="date" name="dt_ini" value="<?= e($dtIni) ?>">
    </div>
    <div class="cobr-fld">
        <label>Até</label>
        <input type="date" name="dt_fim" value="<?= e($dtFim) ?>">
    </div>
    <div class="cobr-fld">
        <label>Status</label>
        <select name="status">
            <option value="todos"      <?= $statusFilt === 'todos'      ? 'selected' : '' ?>>Todos</option>
            <option value="pagos"      <?= $statusFilt === 'pagos'      ? 'selected' : '' ?>>✓ Pagos</option>
            <option value="pendentes"  <?= $statusFilt === 'pendentes'  ? 'selected' : '' ?>>⏳ Pendentes</option>
            <option value="vencidos"   <?= $statusFilt === 'vencidos'   ? 'selected' : '' ?>>⚠ Vencidos</option>
            <option value="cancelados" <?= $statusFilt === 'cancelados' ? 'selected' : '' ?>>✕ Cancelados/Estornados</option>
        </select>
    </div>
    <div class="cobr-fld">
        <label>Forma</label>
        <select name="forma">
            <option value="todas" <?= $formaFilt === 'todas' ? 'selected' : '' ?>>Todas</option>
            <option value="BOLETO" <?= $formaFilt === 'BOLETO' ? 'selected' : '' ?>>Boleto</option>
            <option value="PIX" <?= $formaFilt === 'PIX' ? 'selected' : '' ?>>PIX</option>
            <option value="CREDIT_CARD" <?= $formaFilt === 'CREDIT_CARD' ? 'selected' : '' ?>>Cartão</option>
            <option value="DEBIT_CARD" <?= $formaFilt === 'DEBIT_CARD' ? 'selected' : '' ?>>Débito</option>
            <option value="UNDEFINED" <?= $formaFilt === 'UNDEFINED' ? 'selected' : '' ?>>(Indefinida)</option>
        </select>
    </div>
    <div class="cobr-fld">
        <label>Ordenar por</label>
        <select name="ordem">
            <option value="venc_desc"  <?= $ordem === 'venc_desc' ? 'selected' : '' ?>>Vencto ↓ (novo→velho)</option>
            <option value="venc_asc"   <?= $ordem === 'venc_asc'  ? 'selected' : '' ?>>Vencto ↑</option>
            <option value="pag_desc"   <?= $ordem === 'pag_desc'  ? 'selected' : '' ?>>Pagamento ↓</option>
            <option value="pag_asc"    <?= $ordem === 'pag_asc'   ? 'selected' : '' ?>>Pagamento ↑</option>
            <option value="valor_desc" <?= $ordem === 'valor_desc'? 'selected' : '' ?>>Valor ↓</option>
            <option value="valor_asc"  <?= $ordem === 'valor_asc' ? 'selected' : '' ?>>Valor ↑</option>
            <option value="nome_asc"   <?= $ordem === 'nome_asc'  ? 'selected' : '' ?>>Nome (A-Z)</option>
            <option value="status"     <?= $ordem === 'status'    ? 'selected' : '' ?>>Status</option>
        </select>
    </div>
    <div class="cobr-fld" style="align-items:stretch;">
        <label>&nbsp;</label>
        <button type="submit" class="btn btn-primary btn-sm" style="background:#B87333;">Aplicar filtros</button>
    </div>
    <div style="grid-column:1 / -1;">
        <label style="font-size:.62rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;">Presets rápidos de data</label>
        <div class="cobr-presets">
        <?php
        $presetsList = array('hoje'=>'Hoje','7d'=>'7 dias','30d'=>'30 dias','mes'=>'Este mês','mesant'=>'Mês anterior','ano'=>'Este ano');
        $ps = $_GET; unset($ps['preset'], $ps['dt_ini'], $ps['dt_fim'], $ps['p']);
        foreach ($presetsList as $k => $lbl):
            $qs = http_build_query(array_merge($ps, array('preset' => $k)));
        ?>
            <a href="?<?= $qs ?>" class="cobr-preset <?= $preset === $k ? 'active' : '' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
            <a href="?" class="cobr-preset" style="background:#fee2e2;color:#991b1b;border-color:#fca5a5;">✕ Limpar tudo</a>
        </div>
    </div>
</form>

<!-- Totais do filtro -->
<div class="cobr-kpis">
    <div class="cobr-kpi"><div class="v" style="color:#3b82f6;"><?= number_format($totais['qtd']) ?></div><div class="l">📦 Cobranças (total)</div></div>
    <div class="cobr-kpi"><div class="v">R$ <?= number_format($totais['total_valor'], 2, ',', '.') ?></div><div class="l">💰 Valor total</div></div>
    <div class="cobr-kpi"><div class="v" style="color:#059669;">R$ <?= number_format($totais['total_pago'], 2, ',', '.') ?></div><div class="l">✓ Pago</div></div>
    <div class="cobr-kpi"><div class="v" style="color:#f59e0b;">R$ <?= number_format($totais['total_pendente'], 2, ',', '.') ?></div><div class="l">⏳ Pendente</div></div>
    <div class="cobr-kpi"><div class="v" style="color:#dc2626;">R$ <?= number_format($totais['total_vencido'], 2, ',', '.') ?></div><div class="l">⚠ Vencido</div></div>
</div>

<!-- Tabela -->
<?php if (empty($rows)): ?>
    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:2rem;text-align:center;color:var(--text-muted);">
        Nenhuma cobrança encontrada com esses filtros.
    </div>
<?php else: ?>
<!-- DEBUG v25: cobrancas.php — rendered at <?= date('Y-m-d H:i:s') ?> -->
<div style="background:#eef2ff;padding:.35rem .6rem;border-radius:6px;font-size:.66rem;color:#3730a3;margin-bottom:.5rem;display:inline-block;">🔧 versão v25 · <?= date('H:i:s') ?> · se os botões não funcionarem: abra F12 e me mande o erro</div>
<div style="overflow-x:auto;">
<table class="cobr-tbl">
    <thead>
        <tr>
            <th>Vencto</th>
            <th>Pagamento</th>
            <th>Cliente</th>
            <th>CPF</th>
            <th>Processo</th>
            <th class="num">Valor</th>
            <th class="num">Pago</th>
            <th>Status</th>
            <th>Forma</th>
            <th>Asaas ID</th>
            <th style="text-align:center;" title="Alterar vencto, dar baixa, cancelar">Ações</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r):
            $st = $stLabels[$r['status']] ?? array($r['status'], '#6b7280');
        ?>
        <tr>
            <td><?= $r['vencimento'] ? date('d/m/Y', strtotime($r['vencimento'])) : '—' ?></td>
            <td><?= $r['data_pagamento'] ? date('d/m/Y', strtotime($r['data_pagamento'])) : '—' ?></td>
            <td>
                <?php if ($r['client_id']): ?>
                    <a href="<?= module_url('financeiro', 'cliente.php?id=' . (int)$r['client_id']) ?>" style="color:var(--petrol-900);font-weight:600;text-decoration:none;"><?= e($r['cli_name'] ?: '(sem nome)') ?></a>
                <?php else: ?>
                    <span style="color:var(--text-muted);"><?= e($r['cli_name'] ?: '(sem cliente)') ?></span>
                <?php endif; ?>
            </td>
            <td style="font-family:monospace;font-size:.72rem;color:var(--text-muted);"><?= e($r['cli_cpf'] ?? '—') ?></td>
            <td style="font-size:.72rem;max-width:180px;">
                <?php if ($r['cs_id']): ?>
                    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . (int)$r['cs_id']) ?>" style="color:var(--petrol-900);text-decoration:none;">
                        <?= e($r['case_title']) ?>
                        <?php if ($r['case_number']): ?><br><span style="color:var(--text-muted);font-size:.68rem;"><?= e($r['case_number']) ?></span><?php endif; ?>
                    </a>
                <?php else: ?>
                    <span style="color:var(--text-muted);">—</span>
                <?php endif; ?>
            </td>
            <td class="num"><strong>R$ <?= number_format((float)$r['valor'], 2, ',', '.') ?></strong></td>
            <?php
                // Valor Pago só aparece em verde se o status é de pagamento efetivo.
                // Cobranças canceladas às vezes têm valor_pago > 0 no Asaas (tentativas, reembolsos etc) — não contam como receita.
                $_pagosOk = array('RECEIVED','CONFIRMED','RECEIVED_IN_CASH');
                $_vPago = (float)($r['valor_pago'] ?? 0);
                $_pagoOk = in_array($r['status'], $_pagosOk, true) && $_vPago > 0;
            ?>
            <td class="num">
                <?php if ($_pagoOk): ?>
                    <span style="color:#059669;">R$ <?= number_format($_vPago, 2, ',', '.') ?></span>
                <?php elseif ($_vPago > 0): ?>
                    <span style="color:#9ca3af;text-decoration:line-through;font-size:.72rem;" title="Valor registrado no Asaas mas cobrança está <?= e($r['status']) ?> — NÃO conta como receita">R$ <?= number_format($_vPago, 2, ',', '.') ?></span>
                <?php else: ?>
                    <span style="color:var(--text-muted);">—</span>
                <?php endif; ?>
            </td>
            <td><span class="cobr-status" style="background:<?= e($st[1]) ?>;"><?= e($st[0]) ?></span></td>
            <td style="font-size:.72rem;"><?= e($r['forma_pagamento'] ?: '—') ?></td>
            <td style="font-family:monospace;font-size:.68rem;color:var(--text-muted);"><?= e($r['asaas_payment_id'] ?? '—') ?></td>
            <td style="text-align:center;white-space:nowrap;">
                <?php
                    // Ações só fazem sentido se a cobrança está PENDING ou OVERDUE (case-insensitive por segurança)
                    $_statusUp = strtoupper((string)($r['status'] ?? ''));
                    $_podeEditar = in_array($_statusUp, array('PENDING','OVERDUE'), true);
                ?>
                <?php if ($_podeEditar): ?>
                    <button type="button" title="Alterar data de vencimento [status=<?= e($r['status']) ?>]"
                            onclick="cobAcaoSafe(<?= (int)$r['id'] ?>, 'vencto', '<?= e($r['vencimento']) ?>', <?= e(json_encode($r['cli_name'] ?: '')) ?>, <?= (float)$r['valor'] ?>)"
                            style="background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:6px;padding:3px 7px;font-size:.66rem;font-weight:700;cursor:pointer;margin:0 1px;">📅</button>
                    <button type="button" title="Dar baixa manual"
                            onclick="cobAcaoSafe(<?= (int)$r['id'] ?>, 'baixa', '<?= e($r['vencimento']) ?>', <?= e(json_encode($r['cli_name'] ?: '')) ?>, <?= (float)$r['valor'] ?>)"
                            style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:6px;padding:3px 7px;font-size:.66rem;font-weight:700;cursor:pointer;margin:0 1px;">✓</button>
                    <button type="button" title="Cancelar cobrança no Asaas"
                            onclick="cobAcaoSafe(<?= (int)$r['id'] ?>, 'cancelar', '<?= e($r['vencimento']) ?>', <?= e(json_encode($r['cli_name'] ?: '')) ?>, <?= (float)$r['valor'] ?>)"
                            style="background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:6px;padding:3px 7px;font-size:.66rem;font-weight:700;cursor:pointer;margin:0 1px;">✕</button>
                <?php else: ?>
                    <span style="color:#cbd5e1;font-size:.7rem;" title="status=<?= e($r['status']) ?> — sem ações">— <span style="font-size:.55rem;">[<?= e($r['status']) ?>]</span></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Paginação -->
<?php if ($totalPages > 1):
    $_pParams = $_GET; unset($_pParams['p']);
?>
<div class="cobr-pag">
    <a href="?<?= http_build_query(array_merge($_pParams, array('p' => max(1, $page - 1)))) ?>" class="<?= $page === 1 ? 'disabled' : '' ?>">‹ Anterior</a>
    <?php
    // Páginas com "..." se muitas
    $pagShow = array(1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $totalPages);
    $pagShow = array_filter(array_unique($pagShow), function($p) use ($totalPages){ return $p >= 1 && $p <= $totalPages; });
    sort($pagShow);
    $prev = 0;
    foreach ($pagShow as $p):
        if ($prev && $p > $prev + 1) echo '<span style="padding:5px 8px;color:var(--text-muted);">…</span>';
    ?>
        <a href="?<?= http_build_query(array_merge($_pParams, array('p' => $p))) ?>" class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php $prev = $p; endforeach; ?>
    <a href="?<?= http_build_query(array_merge($_pParams, array('p' => min($totalPages, $page + 1)))) ?>" class="<?= $page === $totalPages ? 'disabled' : '' ?>">Próxima ›</a>
</div>
<p style="text-align:center;font-size:.72rem;color:var(--text-muted);margin-top:.4rem;">Página <?= $page ?> de <?= $totalPages ?> · <?= number_format($qtdTotal) ?> cobrança(s) no total</p>
<?php endif; ?>
<?php endif; ?>

<script>
window._COB_CSRF = <?= json_encode(generate_csrf_token()) ?>;
window._COB_API_URL = <?= json_encode(module_url('financeiro', 'api.php')) ?>;
</script>
<script>
<?php readfile(APP_ROOT . '/assets/js/cobranca_acoes.js'); ?>

// Wrapper defensivo: se cobAcao não existir por qualquer motivo, alerta visível
window.cobAcaoSafe = function(id, tipo, venc, nome, valor) {
    console.info('[cobAcaoSafe] chamado', {id:id, tipo:tipo, venc:venc});
    if (typeof window.cobAcao !== 'function') {
        alert('⚠️ Erro: script de ações não carregou.\n\nPor favor:\n1. Feche o app\n2. Abra de novo\n3. Se ainda não funcionar, recarregue a página no navegador comum\n\n(Detalhe técnico: cobAcao is not defined)');
        return;
    }
    try { window.cobAcao(id, tipo, venc, nome, valor); }
    catch (e) { alert('Erro ao executar: ' + e.message); console.error(e); }
};
console.info('[cobrancas.php] JS pronto — cobAcao:', typeof window.cobAcao, '| CSRF definido:', !!window._COB_CSRF);
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
