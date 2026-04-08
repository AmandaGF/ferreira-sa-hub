<?php
/**
 * Ferreira & Sá Hub — Ficha Cadastral do Cliente (versão para impressão/PDF)
 * Abre em nova aba e dispara window.print() automaticamente
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$clientId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM clients WHERE id = ?');
$stmt->execute(array($clientId));
$client = $stmt->fetch();

if (!$client) { die('Cliente não encontrado.'); }

// Processos
$cases = $pdo->prepare(
    'SELECT cs.*, u.name as responsible_name FROM cases cs
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.client_id = ? ORDER BY cs.created_at DESC'
);
$cases->execute(array($clientId));
$cases = $cases->fetchAll();

$statusLabels = array(
    'aguardando_docs' => 'Aguardando docs', 'em_elaboracao' => 'Em elaboração',
    'aguardando_prazo' => 'Aguardando prazo', 'distribuido' => 'Distribuído',
    'em_andamento' => 'Em andamento', 'concluido' => 'Concluído',
    'arquivado' => 'Arquivado', 'suspenso' => 'Suspenso', 'ativo' => 'Ativo',
);

// Documentos pendentes
$docsPendentes = array();
try {
    $stmtDocs = $pdo->prepare("SELECT descricao, status, solicitado_em FROM documentos_pendentes WHERE client_id = ? ORDER BY solicitado_em DESC");
    $stmtDocs->execute(array($clientId));
    $docsPendentes = $stmtDocs->fetchAll();
} catch (Exception $e) {}

$hoje = date('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ficha Cadastral — <?= e($client['name']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size:11px; color:#222; padding:20px 30px; }

        .header { display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid #052228; padding-bottom:12px; margin-bottom:16px; }
        .header-left h1 { font-size:16px; color:#052228; margin-bottom:2px; }
        .header-left p { font-size:9px; color:#666; }
        .header-right { text-align:right; font-size:9px; color:#666; }
        .header-right .logo-text { font-size:14px; font-weight:800; color:#052228; }

        .section { margin-bottom:14px; }
        .section-title { font-size:12px; font-weight:700; color:#052228; background:#f0f4f7; padding:5px 10px; border-left:4px solid #052228; margin-bottom:8px; }
        .section-title.orange { border-left-color:#B87333; }

        .grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:6px 12px; padding:0 4px; }
        .grid-full { grid-column: 1 / -1; }
        .field label { font-size:8px; text-transform:uppercase; letter-spacing:.5px; color:#888; font-weight:700; display:block; margin-bottom:1px; }
        .field span { font-size:11px; color:#222; font-weight:500; }

        table { width:100%; border-collapse:collapse; margin-top:4px; font-size:10px; }
        table th { background:#052228; color:#fff; padding:4px 8px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.5px; }
        table td { padding:4px 8px; border-bottom:1px solid #e0e0e0; }
        table tr:nth-child(even) { background:#fafbfc; }

        .badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:8px; font-weight:700; color:#fff; }
        .badge-info { background:#0ea5e9; }
        .badge-success { background:#059669; }
        .badge-warning { background:#d97706; }
        .badge-danger { background:#dc2626; }

        .footer { margin-top:20px; padding-top:10px; border-top:2px solid #052228; display:flex; justify-content:space-between; font-size:8px; color:#888; }

        .no-print { margin-bottom:16px; text-align:center; }
        .no-print button { padding:8px 24px; background:#052228; color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; margin:0 4px; }
        .no-print button:hover { background:#0d3640; }
        .no-print button.outline { background:#fff; color:#052228; border:2px solid #052228; }

        @media print {
            .no-print { display:none !important; }
            body { padding:10px 20px; }
            @page { margin:15mm 10mm; size:A4; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
    <button class="outline" onclick="window.close()">Fechar</button>
</div>

<div class="header">
    <div class="header-left">
        <h1>FICHA CADASTRAL</h1>
        <p>Ferreira & Sá Advocacia — Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom — Barra Mansa/RJ</p>
    </div>
    <div class="header-right">
        <div class="logo-text">Ferreira & Sá</div>
        <div>Gerado em <?= $hoje ?></div>
        <div>Conecta Hub</div>
    </div>
</div>

<!-- Dados Pessoais -->
<div class="section">
    <div class="section-title">Dados Pessoais</div>
    <div class="grid">
        <div class="field grid-full"><label>Nome completo</label><span style="font-size:13px;font-weight:700;"><?= e($client['name']) ?></span></div>
        <div class="field"><label>CPF / CNPJ</label><span><?= e($client['cpf'] ?: '—') ?></span></div>
        <div class="field"><label>RG</label><span><?= e(isset($client['rg']) ? $client['rg'] : '') ?: '—' ?></span></div>
        <div class="field"><label>Data de Nascimento</label><span><?= $client['birth_date'] ? date('d/m/Y', strtotime($client['birth_date'])) : '—' ?></span></div>
        <div class="field"><label>Nacionalidade</label><span><?= e(isset($client['nacionalidade']) ? $client['nacionalidade'] : '') ?: '—' ?></span></div>
        <div class="field"><label>Estado Civil</label><span><?= e(isset($client['marital_status']) ? $client['marital_status'] : '') ?: '—' ?></span></div>
        <div class="field"><label>Profissão</label><span><?= e(isset($client['profession']) ? $client['profession'] : '') ?: '—' ?></span></div>
        <div class="field"><label>Sexo</label><span><?= e(isset($client['gender']) ? $client['gender'] : '') ?: '—' ?></span></div>
        <div class="field"><label>Origem</label><span><?= e($client['source'] ?: '—') ?></span></div>
    </div>
</div>

<!-- Contato -->
<div class="section">
    <div class="section-title">Contato</div>
    <div class="grid">
        <div class="field"><label>Telefone</label><span><?= e($client['phone'] ?: '—') ?></span></div>
        <div class="field"><label>Telefone 2</label><span><?= e(isset($client['phone2']) ? $client['phone2'] : '') ?: '—' ?></span></div>
        <div class="field"><label>E-mail</label><span><?= e($client['email'] ?: '—') ?></span></div>
        <div class="field"><label>Chave PIX</label><span><?= e(isset($client['pix_key']) ? $client['pix_key'] : '') ?: '—' ?></span></div>
    </div>
</div>

<!-- Endereço -->
<div class="section">
    <div class="section-title">Endereço</div>
    <div class="grid">
        <div class="field" style="grid-column:span 2;"><label>Logradouro</label><span><?= e(isset($client['address_street']) ? $client['address_street'] : '') ?: '—' ?></span></div>
        <div class="field"><label>Cidade</label><span><?= e(isset($client['address_city']) ? $client['address_city'] : '') ?: '—' ?></span></div>
        <div class="field"><label>UF</label><span><?= e(isset($client['address_state']) ? $client['address_state'] : '') ?: '—' ?></span></div>
        <div class="field"><label>CEP</label><span><?= e(isset($client['address_zip']) ? $client['address_zip'] : '') ?: '—' ?></span></div>
    </div>
</div>

<!-- Filhos -->
<?php if ((isset($client['has_children']) && $client['has_children']) || (isset($client['children_names']) && $client['children_names'])): ?>
<div class="section">
    <div class="section-title">Filhos</div>
    <div class="grid">
        <div class="field"><label>Possui filhos</label><span><?= (isset($client['has_children']) && $client['has_children']) ? 'Sim' : 'Não' ?></span></div>
        <?php if (isset($client['children_names']) && $client['children_names']): ?>
        <div class="field" style="grid-column:span 3;"><label>Nome(s)</label><span><?= e($client['children_names']) ?></span></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Observações -->
<?php if (isset($client['notes']) && $client['notes']): ?>
<div class="section">
    <div class="section-title">Observações</div>
    <div style="padding:4px 8px;font-size:10px;white-space:pre-wrap;"><?= e($client['notes']) ?></div>
</div>
<?php endif; ?>

<!-- Processos -->
<?php if (!empty($cases)): ?>
<div class="section">
    <div class="section-title orange">Processos Vinculados (<?= count($cases) ?>)</div>
    <table>
        <thead><tr>
            <th>Título</th>
            <th>Tipo</th>
            <th>Nº Processo</th>
            <th>Vara</th>
            <th>Status</th>
            <th>Responsável</th>
        </tr></thead>
        <tbody>
        <?php foreach ($cases as $cs): ?>
        <tr>
            <td style="font-weight:600;"><?= e($cs['title'] ?: 'Caso #' . $cs['id']) ?></td>
            <td><?= e($cs['case_type'] ?: '—') ?></td>
            <td style="font-family:monospace;"><?= e($cs['case_number'] ?: '—') ?></td>
            <td><?= e($cs['court'] ?: '—') ?></td>
            <td><span class="badge badge-<?= ($cs['status'] === 'em_andamento' || $cs['status'] === 'distribuido') ? 'info' : (in_array($cs['status'], array('concluido','arquivado')) ? 'success' : 'warning') ?>"><?= isset($statusLabels[$cs['status']]) ? $statusLabels[$cs['status']] : $cs['status'] ?></span></td>
            <td><?= e($cs['responsible_name'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Documentos Pendentes -->
<?php if (!empty($docsPendentes)): ?>
<div class="section">
    <div class="section-title orange">Documentos Pendentes</div>
    <table>
        <thead><tr><th>Documento</th><th>Status</th><th>Solicitado em</th></tr></thead>
        <tbody>
        <?php foreach ($docsPendentes as $doc): ?>
        <tr>
            <td style="font-weight:600;"><?= e($doc['descricao']) ?></td>
            <td><span class="badge badge-<?= $doc['status'] === 'recebido' ? 'success' : 'danger' ?>"><?= $doc['status'] === 'recebido' ? 'Recebido' : 'Pendente' ?></span></td>
            <td><?= $doc['solicitado_em'] ? date('d/m/Y', strtotime($doc['solicitado_em'])) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="footer">
    <div>Ferreira & Sá Advocacia — OAB/RJ 65.532 · OAB/RJ 249.105</div>
    <div>Ficha gerada pelo Conecta Hub em <?= $hoje ?></div>
</div>

<script>
// Auto-print ao carregar (se veio com ?print=1)
if (window.location.search.indexOf('print=1') !== -1) {
    window.onload = function() { setTimeout(function() { window.print(); }, 300); };
}
</script>

</body>
</html>
