<?php
/**
 * Ferreira & Sá Hub — Ficha do Processo (versão para impressão/PDF)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$caseId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT cs.*, c.name as client_name, c.phone as client_phone, c.cpf as client_cpf,
            c.rg as client_rg, c.email as client_email, c.birth_date as client_birth,
            c.profession as client_profession, c.marital_status as client_marital,
            c.address_street as client_address, c.address_city as client_city,
            c.address_state as client_state, c.address_zip as client_zip,
            c.nacionalidade as client_nacionalidade,
            u.name as responsible_name
     FROM cases cs
     LEFT JOIN clients c ON c.id = cs.client_id
     LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.id = ?'
);
$stmt->execute(array($caseId));
$case = $stmt->fetch();

if (!$case) { die('Processo não encontrado.'); }

$userName = current_user()['name'] ?? 'Usuário';
$anoAtual = date('Y');
$hoje = date('d/m/Y H:i');

$statusLabels = array(
    'aguardando_docs' => 'Aguardando Docs', 'em_elaboracao' => 'Pasta Apta',
    'em_andamento' => 'Em Execução', 'doc_faltante' => 'Doc Faltante',
    'suspenso' => 'Suspenso', 'aguardando_prazo' => 'Aguard. Distribuição',
    'distribuido' => 'Distribuído', 'parceria_previdenciario' => 'Parceria',
    'arquivado' => 'Arquivado', 'cancelado' => 'Cancelado', 'concluido' => 'Concluído',
    'renunciamos' => 'Renunciamos',
);
$prioridadeLabels = array('urgente' => 'URGENTE', 'alta' => 'Alta', 'normal' => 'Normal', 'baixa' => 'Baixa');

// Partes do processo
$partes = array();
try {
    $stmtP = $pdo->prepare("SELECT * FROM case_partes WHERE case_id = ? ORDER BY FIELD(papel,'autor','reu','representante_legal','terceiro_interessado','litisconsorte_ativo','litisconsorte_passivo'), id ASC");
    $stmtP->execute(array($caseId));
    $partes = $stmtP->fetchAll();
} catch (Exception $e) {}

$isRecurso = (isset($case['tipo_vinculo']) && $case['tipo_vinculo'] === 'recurso');
$papelLabels = $isRecurso
    ? array('autor' => 'Recorrente', 'reu' => 'Recorrido', 'representante_legal' => 'Rep. Legal', 'terceiro_interessado' => '3º Interessado', 'litisconsorte_ativo' => 'Litis. Ativo', 'litisconsorte_passivo' => 'Litis. Passivo')
    : array('autor' => 'Autor', 'reu' => 'Réu', 'representante_legal' => 'Rep. Legal', 'terceiro_interessado' => '3º Interessado', 'litisconsorte_ativo' => 'Litis. Ativo', 'litisconsorte_passivo' => 'Litis. Passivo');

// Tarefas
$tarefas = array();
try {
    $stmtT = $pdo->prepare("SELECT ct.*, u.name as assigned_name FROM case_tasks ct LEFT JOIN users u ON u.id = ct.assigned_to WHERE ct.case_id = ? ORDER BY ct.status ASC, ct.sort_order ASC");
    $stmtT->execute(array($caseId));
    $tarefas = $stmtT->fetchAll();
} catch (Exception $e) {}

// Andamentos (últimos 20)
$andamentos = array();
try {
    $stmtA = $pdo->prepare("SELECT a.*, u.name as user_name FROM case_andamentos a LEFT JOIN users u ON u.id = a.created_by WHERE a.case_id = ? ORDER BY a.data_andamento DESC, a.created_at DESC LIMIT 20");
    $stmtA->execute(array($caseId));
    $andamentos = $stmtA->fetchAll();
} catch (Exception $e) {}

// Documentos pendentes
$docsPendentes = array();
$docsRecebidos = array();
try {
    $stmtD = $pdo->prepare("SELECT * FROM documentos_pendentes WHERE case_id = ? ORDER BY solicitado_em DESC");
    $stmtD->execute(array($caseId));
    foreach ($stmtD->fetchAll() as $d) {
        if ($d['status'] === 'pendente') $docsPendentes[] = $d;
        else $docsRecebidos[] = $d;
    }
} catch (Exception $e) {}

// Incidentais e recursos
$incidentais = array();
$recursos = array();
try {
    $stmtInc = $pdo->prepare("SELECT id, title, case_number, tipo_relacao, tipo_vinculo, status FROM cases WHERE processo_principal_id = ? ORDER BY created_at DESC");
    $stmtInc->execute(array($caseId));
    foreach ($stmtInc->fetchAll() as $v) {
        if (isset($v['tipo_vinculo']) && $v['tipo_vinculo'] === 'recurso') $recursos[] = $v;
        else $incidentais[] = $v;
    }
} catch (Exception $e) {}

// Parceria
$parceiroNome = '';
$parceiroTel = '';
$parceiroOab = '';
if (!empty($case['parceiro_id'])) {
    try {
        $pn = $pdo->prepare("SELECT nome, telefone, oab FROM parceiros WHERE id = ?");
        $pn->execute(array($case['parceiro_id']));
        $parcData = $pn->fetch();
        if ($parcData) {
            $parceiroNome = $parcData['nome'] ?: '';
            $parceiroTel = $parcData['telefone'] ?: '';
            $parceiroOab = $parcData['oab'] ?: '';
        }
    } catch (Exception $e) {}
}

// Prazos
$prazos = array();
try {
    $stmtPr = $pdo->prepare("SELECT * FROM prazos_processuais WHERE case_id = ? AND concluido = 0 ORDER BY prazo_fatal ASC");
    $stmtPr->execute(array($caseId));
    $prazos = $stmtPr->fetchAll();
} catch (Exception $e) {}

// Próxima audiência
$proxAudiencia = null;
try {
    $stmtAud = $pdo->prepare("SELECT titulo, data_inicio, local, modalidade FROM agenda_eventos WHERE case_id = ? AND tipo = 'audiencia' AND data_inicio >= NOW() AND status != 'cancelado' ORDER BY data_inicio ASC LIMIT 1");
    $stmtAud->execute(array($caseId));
    $proxAudiencia = $stmtAud->fetch();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Ficha do Processo — <?= e($case['title']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size:11px; color:#222; padding:20px 30px; }

        .header { display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid #052228; padding-bottom:12px; margin-bottom:16px; }
        .header-left { display:flex; align-items:center; gap:12px; }
        .header-left h1 { font-size:16px; color:#052228; margin-bottom:2px; }
        .header-left p { font-size:9px; color:#666; }
        .header-right { text-align:right; font-size:9px; color:#666; }
        .header-right .logo-text { font-size:14px; font-weight:800; color:#052228; }

        .processo-titulo { font-size:15px; font-weight:800; color:#052228; margin-bottom:4px; }
        .processo-numero { font-family:monospace; font-size:13px; color:#374151; margin-bottom:12px; }

        .section { margin-bottom:14px; }
        .section-title { font-size:12px; font-weight:700; color:#052228; background:#f0f4f7; padding:5px 10px; border-left:4px solid #052228; margin-bottom:8px; }
        .section-title.orange { border-left-color:#B87333; }
        .section-title.red { border-left-color:#dc2626; }
        .section-title.purple { border-left-color:#6366f1; }
        .section-title.green { border-left-color:#059669; }

        .grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:6px 12px; padding:0 4px; }
        .grid-2 { grid-template-columns: repeat(2, 1fr); }
        .grid-full { grid-column: 1 / -1; }
        .field label { font-size:8px; text-transform:uppercase; letter-spacing:.5px; color:#888; font-weight:700; display:block; margin-bottom:1px; }
        .field span { font-size:11px; color:#222; font-weight:500; }

        table { width:100%; border-collapse:collapse; margin-top:4px; font-size:10px; }
        table th { background:#052228; color:#fff; padding:4px 8px; text-align:left; font-size:8px; text-transform:uppercase; letter-spacing:.5px; }
        table td { padding:4px 8px; border-bottom:1px solid #e0e0e0; }
        table tr:nth-child(even) { background:#fafbfc; }

        .badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:8px; font-weight:700; color:#fff; }
        .status-box { display:inline-block; padding:3px 10px; border-radius:4px; font-size:10px; font-weight:700; color:#fff; }

        .footer { margin-top:20px; padding-top:10px; border-top:2px solid #052228; display:flex; justify-content:space-between; font-size:8px; color:#888; }

        .no-print { margin-bottom:16px; text-align:center; }
        .no-print button { padding:8px 24px; background:#052228; color:#fff; border:none; border-radius:8px; font-size:13px; font-weight:700; cursor:pointer; margin:0 4px; }
        .no-print button:hover { background:#0d3640; }
        .no-print button.outline { background:#fff; color:#052228; border:2px solid #052228; }

        .checklist-item { padding:2px 0; }
        .checklist-done { color:#059669; text-decoration:line-through; }
        .checklist-pending { color:#dc2626; font-weight:600; }

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
        <img src="<?= url('assets/img/logo-sidebar.png') ?>" alt="Logo" style="width:48px;height:48px;border-radius:10px;object-fit:cover;" onerror="this.style.display='none'">
        <div>
            <h1>FICHA DO PROCESSO</h1>
            <p>Ferreira & Sá Advocacia — Rua Dr. Aldrovando de Oliveira, 140 — Ano Bom — Barra Mansa/RJ</p>
        </div>
    </div>
    <div class="header-right">
        <div class="logo-text">Portal Ferreira & Sá HUB — <?= $anoAtual ?></div>
        <div>Gerado em <?= $hoje ?></div>
        <div>Por: <?= e($userName) ?></div>
    </div>
</div>

<!-- Título e nº processo -->
<div class="processo-titulo"><?= e($case['title']) ?></div>
<?php if ($case['case_number']): ?>
<div class="processo-numero">Nº <?= e($case['case_number']) ?></div>
<?php endif; ?>

<!-- Dados do Processo -->
<div class="section">
    <div class="section-title">Dados do Processo</div>
    <div class="grid">
        <div class="field"><label>Status</label><span style="font-weight:700;"><?= isset($statusLabels[$case['status']]) ? $statusLabels[$case['status']] : $case['status'] ?></span></div>
        <div class="field"><label>Prioridade</label><span><?= isset($prioridadeLabels[$case['priority']]) ? $prioridadeLabels[$case['priority']] : $case['priority'] ?></span></div>
        <div class="field"><label>Responsável</label><span><?= e($case['responsible_name'] ?: '—') ?></span></div>
        <div class="field"><label>Tipo de Ação</label><span><?= e($case['case_type'] ?: '—') ?></span></div>
        <div class="field"><label>Vara / Juízo</label><span><?= e($case['court'] ?: '—') ?></span></div>
        <div class="field"><label>Comarca</label><span><?= e((isset($case['comarca']) ? $case['comarca'] : '') ?: '—') ?><?= isset($case['comarca_uf']) && $case['comarca_uf'] ? '/' . e($case['comarca_uf']) : '' ?></span></div>
        <div class="field"><label>Sistema Tribunal</label><span><?= e((isset($case['sistema_tribunal']) ? $case['sistema_tribunal'] : '') ?: '—') ?></span></div>
        <div class="field"><label>Distribuição</label><span><?= (isset($case['distribution_date']) && $case['distribution_date']) ? date('d/m/Y', strtotime($case['distribution_date'])) : '—' ?></span></div>
        <div class="field"><label>Prazo</label><span><?= $case['deadline'] ? date('d/m/Y', strtotime($case['deadline'])) : '—' ?></span></div>
        <div class="field"><label>Segredo de Justiça</label><span><?= (isset($case['segredo_justica']) && $case['segredo_justica']) ? 'Sim' : 'Não' ?></span></div>
        <div class="field"><label>Cadastrado em</label><span><?= date('d/m/Y', strtotime($case['created_at'])) ?></span></div>
        <div class="field"><label>Pasta Drive</label><span><?= $case['drive_folder_url'] ? 'Vinculada' : '—' ?></span></div>
    </div>
    <?php if (!empty($case['is_parceria'])): ?>
    <div style="margin-top:8px;padding:8px 10px;background:#f0fdf4;border:1.5px solid #a7f3d0;border-radius:6px;">
        <div style="font-size:11px;font-weight:700;color:#059669;margin-bottom:4px;">🤝 Parceria</div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px;font-size:10px;">
            <div><span style="color:#888;font-size:8px;text-transform:uppercase;display:block;">Parceiro</span><strong><?= e($parceiroNome ?: '—') ?></strong><?= $parceiroOab ? ' <span style="color:#888;">(OAB ' . e($parceiroOab) . ')</span>' : '' ?></div>
            <div><span style="color:#888;font-size:8px;text-transform:uppercase;display:block;">Telefone do Parceiro</span><?= e($parceiroTel ?: '—') ?></div>
            <div><span style="color:#888;font-size:8px;text-transform:uppercase;display:block;">Quem executa</span><strong><?= (isset($case['parceria_executor']) && $case['parceria_executor'] === 'fes') ? 'Ferreira & Sá' : 'O Parceiro' ?></strong></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Cliente -->
<div class="section">
    <div class="section-title orange">Dados do Cliente</div>
    <div class="grid">
        <div class="field" style="grid-column:span 2;"><label>Nome</label><span style="font-weight:700;"><?= e($case['client_name'] ?: '—') ?></span></div>
        <div class="field"><label>CPF</label><span><?= e($case['client_cpf'] ?: '—') ?></span></div>
        <div class="field"><label>RG</label><span><?= e($case['client_rg'] ?: '—') ?></span></div>
        <div class="field"><label>Nascimento</label><span><?= $case['client_birth'] ? date('d/m/Y', strtotime($case['client_birth'])) : '—' ?></span></div>
        <div class="field"><label>Profissão</label><span><?= e($case['client_profession'] ?: '—') ?></span></div>
        <div class="field"><label>Estado Civil</label><span><?= e($case['client_marital'] ?: '—') ?></span></div>
        <div class="field"><label>Nacionalidade</label><span><?= e($case['client_nacionalidade'] ?: '—') ?></span></div>
        <div class="field"><label>Telefone</label><span><?= e($case['client_phone'] ?: '—') ?></span></div>
        <div class="field"><label>E-mail</label><span><?= e($case['client_email'] ?: '—') ?></span></div>
        <div class="field" style="grid-column:span 2;"><label>Endereço</label><span><?= e(($case['client_address'] ?: '') . ($case['client_city'] ? ', ' . $case['client_city'] : '') . ($case['client_state'] ? '/' . $case['client_state'] : '') . ($case['client_zip'] ? ' — CEP ' . $case['client_zip'] : '')) ?: '—' ?></span></div>
    </div>
</div>

<!-- Partes do Processo -->
<?php if (!empty($partes)): ?>
<div class="section">
    <div class="section-title purple">Partes do Processo (<?= count($partes) ?>)</div>
    <table>
        <thead><tr><th>Papel</th><th>Nome / Razão Social</th><th>CPF / CNPJ</th><th>Tipo</th></tr></thead>
        <tbody>
        <?php foreach ($partes as $p):
            $papelLabel = isset($papelLabels[$p['papel']]) ? $papelLabels[$p['papel']] : $p['papel'];
        ?>
        <tr>
            <td><span class="badge" style="background:<?= $p['papel'] === 'autor' ? '#059669' : ($p['papel'] === 'reu' ? '#dc2626' : '#6366f1') ?>;"><?= e($papelLabel) ?></span></td>
            <td style="font-weight:600;"><?= e($p['nome'] ?: ($p['razao_social'] ?: '—')) ?></td>
            <td style="font-family:monospace;"><?= e($p['cpf'] ?: ($p['cnpj'] ?: '—')) ?></td>
            <td><?= $p['tipo_pessoa'] === 'juridica' ? 'PJ' : 'PF' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Documentos Pendentes -->
<?php if (!empty($docsPendentes) || !empty($docsRecebidos)): ?>
<div class="section">
    <div class="section-title red">Documentos (<?= count($docsPendentes) ?> pendente(s), <?= count($docsRecebidos) ?> recebido(s))</div>
    <table>
        <thead><tr><th>Documento</th><th>Status</th><th>Solicitado em</th></tr></thead>
        <tbody>
        <?php foreach ($docsPendentes as $d): ?>
        <tr><td style="font-weight:600;"><?= e($d['descricao']) ?></td><td><span class="badge" style="background:#dc2626;">Pendente</span></td><td><?= $d['solicitado_em'] ? date('d/m/Y', strtotime($d['solicitado_em'])) : '—' ?></td></tr>
        <?php endforeach; ?>
        <?php foreach ($docsRecebidos as $d): ?>
        <tr><td><?= e($d['descricao']) ?></td><td><span class="badge" style="background:#059669;">Recebido</span></td><td><?= $d['solicitado_em'] ? date('d/m/Y', strtotime($d['solicitado_em'])) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Tarefas -->
<?php if (!empty($tarefas)): ?>
<div class="section">
    <div class="section-title green">Tarefas (<?= count($tarefas) ?>)</div>
    <?php foreach ($tarefas as $t):
        $done = in_array($t['status'], array('concluido', 'feito'));
    ?>
    <div class="checklist-item <?= $done ? 'checklist-done' : 'checklist-pending' ?>">
        <?= $done ? '☑' : '☐' ?> <?= e($t['title']) ?>
        <?php if ($t['assigned_name']): ?> <span style="color:#888;font-weight:400;">(<?= e(explode(' ', $t['assigned_name'])[0]) ?>)</span><?php endif; ?>
        <?php if ($t['due_date']): ?> <span style="color:#888;font-weight:400;">— <?= date('d/m', strtotime($t['due_date'])) ?></span><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Prazos ativos -->
<?php if (!empty($prazos)): ?>
<div class="section">
    <div class="section-title red">Prazos Processuais Ativos</div>
    <table>
        <thead><tr><th>Descrição</th><th>Prazo Fatal</th></tr></thead>
        <tbody>
        <?php foreach ($prazos as $pr): ?>
        <tr>
            <td style="font-weight:600;"><?= e($pr['descricao_acao']) ?></td>
            <td style="font-family:monospace;"><?= date('d/m/Y', strtotime($pr['prazo_fatal'])) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Próxima audiência -->
<?php if ($proxAudiencia): ?>
<div class="section">
    <div style="padding:6px 10px;background:#fef3c7;border:1px solid #fbbf24;border-radius:4px;font-size:10px;">
        <strong>📅 Próxima Audiência:</strong> <?= e($proxAudiencia['titulo']) ?> — <?= date('d/m/Y H:i', strtotime($proxAudiencia['data_inicio'])) ?>
        <?php if ($proxAudiencia['local']): ?> — <?= e($proxAudiencia['local']) ?><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Incidentais e Recursos -->
<?php if (!empty($incidentais) || !empty($recursos)): ?>
<div class="section">
    <div class="section-title purple">Processos Vinculados</div>
    <?php foreach ($incidentais as $inc): ?>
    <div style="padding:3px 8px;font-size:10px;">📎 <strong><?= e($inc['tipo_relacao'] ?: 'Incidental') ?></strong> — <?= e($inc['title']) ?> <?= $inc['case_number'] ? '(Nº ' . e($inc['case_number']) . ')' : '' ?> — <?= isset($statusLabels[$inc['status']]) ? $statusLabels[$inc['status']] : $inc['status'] ?></div>
    <?php endforeach; ?>
    <?php foreach ($recursos as $rec): ?>
    <div style="padding:3px 8px;font-size:10px;">📜 <strong><?= e($rec['tipo_relacao'] ?: 'Recurso') ?></strong> — <?= e($rec['title']) ?> <?= $rec['case_number'] ? '(Nº ' . e($rec['case_number']) . ')' : '' ?> — <?= isset($statusLabels[$rec['status']]) ? $statusLabels[$rec['status']] : $rec['status'] ?></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Andamentos (últimos 20) -->
<?php if (!empty($andamentos)): ?>
<div class="section">
    <div class="section-title">Andamentos (últimos <?= count($andamentos) ?>)</div>
    <table>
        <thead><tr><th style="width:80px;">Data</th><th style="width:80px;">Tipo</th><th>Descrição</th><th style="width:80px;">Por</th></tr></thead>
        <tbody>
        <?php foreach ($andamentos as $a): ?>
        <tr>
            <td><?= date('d/m/Y', strtotime($a['data_andamento'])) ?></td>
            <td><span class="badge" style="background:#052228;"><?= e($a['tipo'] ?: 'geral') ?></span></td>
            <td style="max-width:350px;overflow:hidden;text-overflow:ellipsis;"><?= e(mb_substr($a['descricao'], 0, 200, 'UTF-8')) ?></td>
            <td><?= e($a['user_name'] ? explode(' ', $a['user_name'])[0] : '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Observações -->
<?php if ($case['notes']): ?>
<div class="section">
    <div class="section-title">Observações</div>
    <div style="padding:4px 8px;font-size:10px;white-space:pre-wrap;"><?= e($case['notes']) ?></div>
</div>
<?php endif; ?>

<div class="footer">
    <div>Ferreira & Sá Advocacia — OAB/RJ 65.532 · OAB/RJ 249.105</div>
    <div>Portal Ferreira & Sá HUB — <?= $anoAtual ?> · Impresso por <?= e($userName) ?> em <?= $hoje ?></div>
</div>

<script>
if (window.location.search.indexOf('print=1') !== -1) {
    window.onload = function() { setTimeout(function() { window.print(); }, 300); };
}
</script>

</body>
</html>
