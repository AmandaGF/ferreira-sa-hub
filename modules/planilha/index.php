<?php
/**
 * Ferreira & Sá Hub — Planilha (Visão tipo Excel)
 * Tabela editável com filtros, ordenação e exportação CSV
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
if (!has_min_role('gestao') && !has_role('comercial') && !has_role('cx')) {
    flash_set('error', 'Sem permissão.');
    redirect(url('modules/dashboard/'));
}

$pageTitle = 'Planilha';
$pdo = db();

// Aba ativa
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'clientes';
$validTabs = array('clientes', 'pipeline', 'operacional');
if (!in_array($tab, $validTabs)) $tab = 'clientes';

// Busca
$search = isset($_GET['q']) ? trim($_GET['q']) : '';

// Dados conforme aba
$rows = array();
$columns = array();

if ($tab === 'clientes') {
    $columns = array(
        'id' => 'ID',
        'name' => 'Nome',
        'phone' => 'Telefone',
        'email' => 'E-mail',
        'cpf' => 'CPF',
        'address_city' => 'Cidade',
        'address_state' => 'UF',
        'client_status' => 'Status',
        'created_at' => 'Cadastro',
    );
    $where = '1=1';
    $params = array();
    if ($search) {
        $where .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR c.cpf LIKE ?)';
        $s = "%$search%";
        $params = array($s, $s, $s, $s);
    }
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.phone, c.email, c.cpf, c.address_city, c.address_state, c.client_status, DATE_FORMAT(c.created_at, '%d/%m/%Y') as created_at FROM clients c WHERE $where ORDER BY c.name LIMIT 500");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

} elseif ($tab === 'pipeline') {
    $columns = array(
        'id' => 'ID',
        'name' => 'Nome',
        'phone' => 'Telefone',
        'stage' => 'Etapa',
        'case_type' => 'Tipo Ação',
        'assigned_user' => 'Responsável',
        'estimated_value' => 'Valor',
        'created_at' => 'Cadastro',
    );
    $where = '1=1';
    $params = array();
    if ($search) {
        $where .= ' AND (pl.name LIKE ? OR pl.phone LIKE ? OR pl.case_type LIKE ?)';
        $s = "%$search%";
        $params = array($s, $s, $s);
    }
    $stmt = $pdo->prepare("SELECT pl.id, pl.name, pl.phone, pl.stage, pl.case_type, u.name as assigned_user, CASE WHEN pl.estimated_value_cents > 100 THEN CONCAT('R\$ ', FORMAT(pl.estimated_value_cents/100, 2, 'pt_BR')) ELSE '' END as estimated_value, DATE_FORMAT(pl.created_at, '%d/%m/%Y') as created_at FROM pipeline_leads pl LEFT JOIN users u ON u.id = pl.assigned_to WHERE $where ORDER BY pl.created_at DESC LIMIT 500");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

} elseif ($tab === 'operacional') {
    $columns = array(
        'id' => 'ID',
        'title' => 'Caso',
        'client_name' => 'Cliente',
        'case_type' => 'Tipo',
        'status' => 'Status',
        'case_number' => 'Nº Processo',
        'court' => 'Vara',
        'responsible' => 'Responsável',
        'created_at' => 'Cadastro',
    );
    $where = '1=1';
    $params = array();
    if ($search) {
        $where .= ' AND (cs.title LIKE ? OR cl.name LIKE ? OR cs.case_number LIKE ?)';
        $s = "%$search%";
        $params = array($s, $s, $s);
    }
    $stmt = $pdo->prepare("SELECT cs.id, cs.title, cl.name as client_name, cs.case_type, cs.status, cs.case_number, cs.court, u.name as responsible, DATE_FORMAT(cs.created_at, '%d/%m/%Y') as created_at FROM cases cs LEFT JOIN clients cl ON cl.id = cs.client_id LEFT JOIN users u ON u.id = cs.responsible_user_id WHERE $where ORDER BY cs.created_at DESC LIMIT 500");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
}

// Labels para stages/status
$stageLabels = array(
    'cadastro_preenchido'=>'Cadastro','elaboracao_docs'=>'Elaboração Docs','link_enviados'=>'Link Enviado',
    'contrato_assinado'=>'Contrato Assinado','agendado_docs'=>'Agendado Docs','reuniao_cobranca'=>'Reunião Cobrança',
    'doc_faltante'=>'Doc Faltante','pasta_apta'=>'Pasta Apta','finalizado'=>'Finalizado',
    'perdido'=>'Perdido','cancelado'=>'Cancelado','suspenso'=>'Suspenso',
);
$statusLabels = array(
    'aguardando_docs'=>'Aguardando Docs','em_elaboracao'=>'Em Elaboração','em_andamento'=>'Em Andamento',
    'doc_faltante'=>'Doc Faltante','aguardando_prazo'=>'Aguardando Prazo','distribuido'=>'Distribuído',
    'parceria_previdenciario'=>'Parceria Previd.','cancelado'=>'Cancelado','suspenso'=>'Suspenso',
    'concluido'=>'Concluído','arquivado'=>'Arquivado',
);

require_once __DIR__ . '/../../templates/layout_start.php';
?>

<style>
.planilha-toolbar {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.planilha-tabs {
    display: flex;
    gap: 4px;
}
.planilha-tabs a {
    padding: 6px 16px;
    border-radius: 6px 6px 0 0;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
    border: 1px solid #ddd;
    border-bottom: none;
    color: var(--text-secondary);
    background: var(--bg-secondary);
}
.planilha-tabs a.active {
    background: #fff;
    color: var(--petrol-900);
    border-color: #bbb;
}
.planilha-search {
    display: flex;
    gap: 6px;
    margin-left: auto;
}
.planilha-search input {
    padding: 5px 10px;
    border: 1px solid #ccc;
    border-radius: 6px;
    font-size: 13px;
    width: 220px;
}
.planilha-info {
    font-size: 12px;
    color: var(--text-secondary);
    padding: 4px 0;
}

/* Excel-like table */
.excel-wrap {
    overflow-x: auto;
    border: 1px solid #bbb;
    border-radius: 4px;
    max-height: 75vh;
    overflow-y: auto;
}
.excel-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    font-family: 'Segoe UI', Arial, sans-serif;
}
.excel-table thead {
    position: sticky;
    top: 0;
    z-index: 2;
}
.excel-table th {
    background: linear-gradient(180deg, #f0f0f0, #e0e0e0);
    border: 1px solid #bbb;
    padding: 6px 10px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
    color: #333;
    white-space: nowrap;
    cursor: pointer;
    user-select: none;
    position: relative;
}
.excel-table th:hover {
    background: linear-gradient(180deg, #e8e8e8, #d8d8d8);
}
.excel-table th .sort-arrow {
    font-size: 10px;
    margin-left: 4px;
    opacity: 0.4;
}
.excel-table th.sorted-asc .sort-arrow,
.excel-table th.sorted-desc .sort-arrow {
    opacity: 1;
}
.excel-table td {
    border: 1px solid #d0d0d0;
    padding: 4px 8px;
    background: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 280px;
}
.excel-table tr:nth-child(even) td {
    background: #f8f9fa;
}
.excel-table tr:hover td {
    background: #e8f4f8 !important;
}
.excel-table tr.selected td {
    background: #cce5ff !important;
}
/* Row number column */
.excel-table .row-num {
    background: linear-gradient(180deg, #f0f0f0, #e4e4e4) !important;
    color: #666;
    text-align: center;
    font-size: 11px;
    width: 40px;
    min-width: 40px;
    border-right: 2px solid #bbb;
    cursor: default;
}
/* Stage/status badges inline */
.cell-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 600;
}
.cell-badge-green { background: #d1fae5; color: #065f46; }
.cell-badge-yellow { background: #fef3c7; color: #92400e; }
.cell-badge-red { background: #fee2e2; color: #991b1b; }
.cell-badge-blue { background: #dbeafe; color: #1e40af; }
.cell-badge-gray { background: #f3f4f6; color: #4b5563; }
.cell-badge-orange { background: #ffedd5; color: #9a3412; }

.btn-export {
    padding: 5px 14px;
    background: #059669;
    color: #fff;
    border: none;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.btn-export:hover { background: #047857; }
</style>

<div class="page-header" style="margin-bottom: 8px;">
    <h1>Planilha</h1>
</div>

<div class="planilha-toolbar">
    <div class="planilha-tabs">
        <a href="?tab=clientes" class="<?= $tab === 'clientes' ? 'active' : '' ?>">Clientes</a>
        <a href="?tab=pipeline" class="<?= $tab === 'pipeline' ? 'active' : '' ?>">Pipeline</a>
        <a href="?tab=operacional" class="<?= $tab === 'operacional' ? 'active' : '' ?>">Operacional</a>
    </div>
    <div class="planilha-search">
        <form method="get" style="display:flex; gap:6px;">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Buscar...">
            <button type="submit" class="btn btn-sm btn-primary">Buscar</button>
            <?php if ($search): ?>
                <a href="?tab=<?= e($tab) ?>" class="btn btn-sm btn-secondary">Limpar</a>
            <?php endif; ?>
        </form>
        <button onclick="exportCSV()" class="btn-export">Exportar CSV</button>
    </div>
</div>

<div class="planilha-info">
    <?= count($rows) ?> registro(s) <?= $search ? 'encontrado(s) para "' . e($search) . '"' : '' ?>
    <?= count($rows) >= 500 ? ' (limitado a 500 — refine a busca)' : '' ?>
</div>

<div class="excel-wrap">
    <table class="excel-table" id="planilhaTable">
        <thead>
            <tr>
                <th class="row-num">#</th>
                <?php $colIdx = 0; foreach ($columns as $key => $label): ?>
                    <th onclick="sortTable(<?= $colIdx + 1 ?>)" data-col="<?= $colIdx + 1 ?>">
                        <?= e($label) ?> <span class="sort-arrow">&#9650;&#9660;</span>
                    </th>
                <?php $colIdx++; endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
                <tr><td class="row-num">-</td><td colspan="<?= count($columns) ?>" style="text-align:center; color:#999; padding:30px;">Nenhum registro encontrado.</td></tr>
            <?php else: ?>
                <?php $n = 1; foreach ($rows as $row): ?>
                <tr onclick="selectRow(this)">
                    <td class="row-num"><?= $n++ ?></td>
                    <?php foreach ($columns as $key => $label):
                        $val = isset($row[$key]) ? $row[$key] : '';
                        $display = $val;

                        // Formatar stage/status com badges
                        if ($key === 'stage' && isset($stageLabels[$val])) {
                            $badgeClass = 'cell-badge-gray';
                            if (in_array($val, array('contrato_assinado','pasta_apta'))) $badgeClass = 'cell-badge-green';
                            elseif (in_array($val, array('cancelado','perdido'))) $badgeClass = 'cell-badge-red';
                            elseif (in_array($val, array('elaboracao_docs','link_enviados','reuniao_cobranca'))) $badgeClass = 'cell-badge-yellow';
                            elseif ($val === 'suspenso') $badgeClass = 'cell-badge-orange';
                            elseif ($val === 'finalizado') $badgeClass = 'cell-badge-blue';
                            $display = '<span class="cell-badge ' . $badgeClass . '">' . e($stageLabels[$val]) . '</span>';
                        } elseif ($key === 'status' && isset($statusLabels[$val])) {
                            $badgeClass = 'cell-badge-gray';
                            if (in_array($val, array('em_andamento','distribuido'))) $badgeClass = 'cell-badge-green';
                            elseif ($val === 'cancelado') $badgeClass = 'cell-badge-red';
                            elseif (in_array($val, array('aguardando_docs','doc_faltante'))) $badgeClass = 'cell-badge-yellow';
                            elseif ($val === 'suspenso') $badgeClass = 'cell-badge-orange';
                            elseif (in_array($val, array('concluido','arquivado'))) $badgeClass = 'cell-badge-blue';
                            $display = '<span class="cell-badge ' . $badgeClass . '">' . e($statusLabels[$val]) . '</span>';
                        } elseif ($key === 'client_status') {
                            $badgeClass = $val === 'ativo' ? 'cell-badge-green' : 'cell-badge-red';
                            $display = '<span class="cell-badge ' . $badgeClass . '">' . e($val) . '</span>';
                        } else {
                            $display = e($val);
                        }
                    ?>
                        <td title="<?= e($val) ?>"><?= $display ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Selecionar linha
function selectRow(tr) {
    document.querySelectorAll('.excel-table tr.selected').forEach(function(r) { r.classList.remove('selected'); });
    tr.classList.add('selected');
}

// Ordenar por coluna
var sortDir = {};
function sortTable(colIdx) {
    var table = document.getElementById('planilhaTable');
    var tbody = table.querySelector('tbody');
    var rows = Array.from(tbody.querySelectorAll('tr'));
    var dir = sortDir[colIdx] === 'asc' ? 'desc' : 'asc';
    sortDir[colIdx] = dir;

    // Limpar classes de sort
    table.querySelectorAll('th').forEach(function(th) { th.classList.remove('sorted-asc', 'sorted-desc'); });
    var th = table.querySelectorAll('th')[colIdx];
    th.classList.add(dir === 'asc' ? 'sorted-asc' : 'sorted-desc');

    rows.sort(function(a, b) {
        var aVal = (a.cells[colIdx] && a.cells[colIdx].textContent) || '';
        var bVal = (b.cells[colIdx] && b.cells[colIdx].textContent) || '';
        // Tentar comparar como número
        var aNum = parseFloat(aVal.replace(/[^\d,.-]/g, '').replace(',', '.'));
        var bNum = parseFloat(bVal.replace(/[^\d,.-]/g, '').replace(',', '.'));
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return dir === 'asc' ? aNum - bNum : bNum - aNum;
        }
        return dir === 'asc' ? aVal.localeCompare(bVal, 'pt-BR') : bVal.localeCompare(aVal, 'pt-BR');
    });

    rows.forEach(function(row, i) {
        row.cells[0].textContent = i + 1;
        tbody.appendChild(row);
    });
}

// Exportar CSV
function exportCSV() {
    var table = document.getElementById('planilhaTable');
    var rows = table.querySelectorAll('tr');
    var csv = [];
    rows.forEach(function(row) {
        var cols = [];
        row.querySelectorAll('th, td').forEach(function(cell, idx) {
            if (idx === 0) return; // pular #
            var text = cell.textContent.replace(/"/g, '""').trim();
            cols.push('"' + text + '"');
        });
        csv.push(cols.join(';'));
    });
    var blob = new Blob(['\uFEFF' + csv.join('\n')], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'planilha_<?= $tab ?>_' + new Date().toISOString().slice(0,10) + '.csv';
    link.click();
}

// Atalho: Ctrl+F foca na busca
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
        e.preventDefault();
        document.querySelector('.planilha-search input[name="q"]').focus();
    }
});
</script>

<?php require_once __DIR__ . '/../../templates/layout_end.php'; ?>
