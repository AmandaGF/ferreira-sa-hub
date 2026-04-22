<?php
/**
 * Ferreira & Sá Hub — Detalhe do Caso (Operacional) v2
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$caseId = (int)($_GET['id'] ?? 0);
$userId = current_user_id();
$isColaborador = has_role('colaborador');

// Salvar origem para "Voltar ao processo" nas outras páginas
$_SESSION['origem_case_id'] = $caseId;

$stmt = $pdo->prepare(
    'SELECT cs.*, c.name as client_name, c.phone as client_phone, c.id as client_id, u.name as responsible_name
     FROM cases cs LEFT JOIN clients c ON c.id = cs.client_id LEFT JOIN users u ON u.id = cs.responsible_user_id
     WHERE cs.id = ?'
);
$stmt->execute([$caseId]);
$case = $stmt->fetch();

if (!$case) { flash_set('error', 'Caso não encontrado.'); redirect(module_url('operacional')); }

// Colaborador só vê seus próprios casos
if ($isColaborador && (int)$case['responsible_user_id'] !== $userId) {
    flash_set('error', 'Sem permissão.'); redirect(module_url('operacional'));
}

$pageTitle = $case['title'];

// Tarefas
$tasks = $pdo->prepare(
    'SELECT ct.*, u.name as assigned_name FROM case_tasks ct
     LEFT JOIN users u ON u.id = ct.assigned_to
     WHERE ct.case_id = ? ORDER BY ct.status ASC, ct.sort_order ASC, ct.created_at ASC'
);
$tasks->execute([$caseId]);
$tasks = $tasks->fetchAll();

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// Andamentos do caso
$andamentos = array();
try {
    $stmtAnd = $pdo->prepare(
        "SELECT a.*, u.name as user_name FROM case_andamentos a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.case_id = ? ORDER BY a.data_andamento DESC, a.created_at DESC"
    );
    $stmtAnd->execute(array($caseId));
    $andamentos = $stmtAnd->fetchAll();
} catch (Exception $e) { /* tabela pode não existir ainda */ }

// Ofícios enviados deste caso (com dados completos do novo_oficio.php)
$oficiosEnviados = array();
$oficiosHistorico = array(); // { oficio_id: [eventos...] }
try {
    $stmtOf = $pdo->prepare(
        "SELECT id, empregador, empresa_cnpj, rh_email, rh_contato, funcionario_nome,
                data_envio, plataforma, cod_rastreio, retorno_ar, observacoes, created_at,
                status_oficio, ultima_atividade_em
         FROM oficios_enviados
         WHERE case_id = ?
         ORDER BY data_envio DESC, id DESC"
    );
    $stmtOf->execute(array($caseId));
    $oficiosEnviados = $stmtOf->fetchAll();
    // Carrega histórico dos ofícios desta pasta (se houver)
    if (!empty($oficiosEnviados)) {
        $ids = array_map(function($o){ return (int)$o['id']; }, $oficiosEnviados);
        $inIds = implode(',', $ids);
        try {
            $stmtH = $pdo->query(
                "SELECT h.oficio_id, h.tipo, h.descricao, h.created_at, u.name AS user_name
                 FROM oficios_historico h
                 LEFT JOIN users u ON u.id = h.created_by
                 WHERE h.oficio_id IN ($inIds)
                 ORDER BY h.created_at DESC, h.id DESC"
            );
            foreach ($stmtH->fetchAll() as $h) {
                $oficiosHistorico[(int)$h['oficio_id']][] = $h;
            }
        } catch (Exception $e) { /* tabela pode não existir ainda */ }
    }
} catch (Exception $e) { /* tabela pode não ter case_id ainda */ }

// Mapas pra timeline inline
$_ofStatusMeta = array(
    'aguardando_contato_rh' => array('📮 Aguardando contato do RH', '#f59e0b'),
    'oficio_enviado'        => array('📬 Ofício formal enviado',    '#2563eb'),
    'rh_respondeu'          => array('💬 RH respondeu',             '#0ea5e9'),
    'em_cobranca'           => array('⏰ Em cobrança',               '#d97706'),
    'pensao_implantada'     => array('✅ Pensão implantada',         '#059669'),
    'sem_resposta'          => array('❌ Sem resposta',              '#6b7280'),
    'problema'              => array('⚠️ Problema',                  '#dc2626'),
    'arquivado'             => array('📁 Arquivado',                 '#94a3b8'),
);
$_ofTipoMeta = array(
    'email_inicial'     => array('📮', 'E-mail inicial enviado'),
    'cobranca'          => array('⏰', 'Cobrança enviada'),
    'rh_respondeu'      => array('💬', 'RH respondeu'),
    'oficio_formal'     => array('📬', 'Ofício formal enviado'),
    'confirmado'        => array('🤝', 'RH confirmou recebimento'),
    'pensao_implantada' => array('✅', 'Pensão implantada em folha'),
    'problema'          => array('⚠️', 'Problema / obstáculo'),
    'outro'             => array('📝', 'Outro evento'),
);

// Documentos pendentes deste caso
$docsPendentes = array();
$docsRecebidos = array();
try {
    $allDocs = $pdo->prepare(
        "SELECT dp.*, us.name as solicitante_name, ur.name as receptor_name
         FROM documentos_pendentes dp
         LEFT JOIN users us ON us.id = dp.solicitado_por
         LEFT JOIN users ur ON ur.id = dp.recebido_por
         WHERE dp.case_id = ?
         ORDER BY dp.solicitado_em DESC"
    );
    $allDocs->execute(array($caseId));
    foreach ($allDocs->fetchAll() as $doc) {
        if ($doc['status'] === 'pendente') $docsPendentes[] = $doc;
        else $docsRecebidos[] = $doc;
    }
} catch (Exception $e) {}

// Documentos GED (compartilhados com cliente via Central VIP)
$docsGed = array();
try {
    $stmtGed = $pdo->prepare(
        "SELECT g.*, u.name as user_name FROM salavip_ged g LEFT JOIN users u ON u.id = g.compartilhado_por WHERE g.processo_id = ? ORDER BY g.compartilhado_em DESC"
    );
    $stmtGed->execute(array($caseId));
    $docsGed = $stmtGed->fetchAll();
} catch (Exception $e) {}

$statusLabels = array(
    'aguardando_docs'  => 'Contrato — Aguardando Docs',
    'em_elaboracao'    => 'Pasta Apta',
    'em_andamento'     => 'Em Execução',
    'doc_faltante'     => 'Doc Faltante',
    'suspenso'         => 'Suspenso',
    'aguardando_prazo' => 'Aguard. Distribuição',
    'distribuido'      => 'Processo Distribuído',
    'parceria_previdenciario' => 'Parceria',
    'arquivado'        => 'Finalizado / Arquivado',
    'renunciamos'      => 'Renunciamos',
    'cancelado'        => 'Cancelado',
    'concluido'        => 'Concluído',
);

$statusCores = array(
    'aguardando_docs'  => '#f59e0b',
    'em_elaboracao'    => '#059669',
    'em_andamento'     => '#0ea5e9',
    'doc_faltante'     => '#dc2626',
    'suspenso'         => '#5B2D8E',
    'aguardando_prazo' => '#8b5cf6',
    'distribuido'      => '#15803d',
    'parceria_previdenciario' => '#06b6d4',
    'arquivado'        => '#6b7280',
    'renunciamos'      => '#6b7280',
    'cancelado'        => '#6b7280',
    'concluido'        => '#059669',
);

$clientPhone = $case['client_phone'] ? preg_replace('/\D/', '', $case['client_phone']) : '';
$clientWhatsapp = $clientPhone ? 'https://wa.me/55' . $clientPhone : '';

// Todos os clientes vinculados ao processo (principal + partes com client_id)
$clientesVinculados = array();
if ($case['client_id']) {
    $clientesVinculados[] = array('id' => $case['client_id'], 'name' => $case['client_name'], 'phone' => $case['client_phone'] ?: '');
}
try {
    $stmtCliVinc = $pdo->prepare("SELECT DISTINCT cp.client_id, c.name, c.phone FROM case_partes cp INNER JOIN clients c ON c.id = cp.client_id WHERE cp.case_id = ? AND cp.client_id IS NOT NULL AND cp.client_id != ?");
    $stmtCliVinc->execute(array($caseId, (int)($case['client_id'] ?: 0)));
    foreach ($stmtCliVinc->fetchAll() as $cv) {
        $clientesVinculados[] = array('id' => $cv['client_id'], 'name' => $cv['name'], 'phone' => $cv['phone'] ?: '');
    }
} catch (Exception $e) {}

// Detectar processos duplicados (mesmo case_number)
$duplicatas = array();
if (!empty($case['case_number'])) {
    try {
        $stmtDup = $pdo->prepare("SELECT id, title, case_number, status, client_id FROM cases WHERE case_number = ? AND id != ? AND status NOT IN ('arquivado')");
        $stmtDup->execute(array($case['case_number'], $caseId));
        $duplicatas = $stmtDup->fetchAll();
    } catch (Exception $e) {}
}

// Processos incidentais e recursos
$incidentais = array();
$recursos = array();
$processoPrincipal = null;
try {
    // Se este é o principal → buscar vinculados
    $stmtInc = $pdo->prepare("SELECT id, title, case_number, case_type, status, tipo_relacao, tipo_vinculo FROM cases WHERE processo_principal_id = ? ORDER BY created_at DESC");
    $stmtInc->execute(array($caseId));
    $vinculados = $stmtInc->fetchAll();
    foreach ($vinculados as $v) {
        if (isset($v['tipo_vinculo']) && $v['tipo_vinculo'] === 'recurso') {
            $recursos[] = $v;
        } else {
            $incidentais[] = $v;
        }
    }

    // Se este é vinculado → buscar o principal
    if (!empty($case['processo_principal_id'])) {
        $stmtPrinc = $pdo->prepare("SELECT id, title, case_number FROM cases WHERE id = ?");
        $stmtPrinc->execute(array($case['processo_principal_id']));
        $processoPrincipal = $stmtPrinc->fetch();
    }
} catch (Exception $e) {}

$tiposRelacao = array(
    'Execução de Alimentos', 'Revisional de Alimentos', 'Exoneração de Alimentos',
    'Tutela de Urgência', 'Medida Protetiva', 'Busca e Apreensão de Menor',
    'Modificação de Guarda', 'Alienação Parental', 'Arrolamento de Bens',
    'Cumprimento de Sentença', 'Embargos à Execução', 'Outros',
);

$tiposRecurso = array(
    'Apelação', 'Agravo de Instrumento', 'Agravo Interno',
    'Embargos de Declaração', 'Recurso Especial (REsp)', 'Recurso Extraordinário (RE)',
    'Recurso Ordinário', 'Recurso Inominado', 'Embargos Infringentes',
    'Reclamação', 'Outros',
);

require_once APP_ROOT . '/templates/layout_start.php';
?>

<style>
.caso-header { background:linear-gradient(135deg, var(--petrol-900), var(--petrol-500)); color:#fff; border-radius:var(--radius-lg); padding:1.5rem; margin-bottom:1.5rem; }
.caso-header h2 { font-size:1.2rem; margin-bottom:.25rem; }
.caso-header .meta { font-size:.82rem; color:var(--rose); }
.caso-header .actions { margin-top:1rem; display:flex; gap:.5rem; flex-wrap:wrap; }

.task-list { list-style:none; padding:0; }
.task-item { display:flex; align-items:center; gap:.75rem; padding:.75rem 0; border-bottom:1px solid var(--border); }
.task-item:last-child { border-bottom:none; }
.task-check { width:22px; height:22px; border-radius:6px; border:2px solid var(--border); display:flex; align-items:center; justify-content:center; cursor:pointer; flex-shrink:0; transition:all var(--transition); }
.task-check:hover { border-color:var(--success); }
.task-check.done { background:var(--success); border-color:var(--success); color:#fff; font-size:.7rem; }
.task-text { flex:1; font-size:.88rem; }
.task-text.done { text-decoration:line-through; color:var(--text-muted); }
.task-meta { font-size:.72rem; color:var(--text-muted); flex-shrink:0; }
@keyframes spin { to { transform:rotate(360deg); } }
/* Publicacoes processuais */
.pub-item { border-left-color: #dc2626 !important; background: #fff8f8 !important; }
.pub-item .pub-badge {
    font-size:.58rem; background:#fef2f2; color:#dc2626;
    padding:2px 7px; border-radius:4px; font-weight:700;
    border:1px solid #fca5a5; display:inline-flex; align-items:center; gap:3px;
}
.pub-item .prazo-badge {
    font-size:.58rem; padding:2px 7px; border-radius:4px; font-weight:700; display:inline-flex; align-items:center; gap:3px;
}
.pub-item .prazo-badge.pendente { background:#fef3c7; color:#d97706; border:1px solid #fcd34d; }
.pub-item .prazo-badge.confirmado { background:#ecfdf5; color:#059669; border:1px solid #6ee7b7; }
.pub-item .prazo-badge.descartado { background:#f1f5f9; color:#94a3b8; border:1px solid #cbd5e1; }
.pub-item .prazo-vence {
    font-size:.72rem; font-weight:700; color:#dc2626;
    background:#fef2f2; padding:2px 8px; border-radius:4px;
}
.pub-item .prazo-vence.ok { color:#059669; background:#ecfdf5; }
.pub-item .prazo-vence.alerta { color:#d97706; background:#fef3c7; }
.filtro-andamentos { display:flex; gap:.4rem; flex-wrap:wrap; margin-bottom:.8rem; }
.filtro-andamentos button {
    font-size:.72rem; padding:3px 10px; border-radius:20px;
    border:1px solid var(--border); background:#fff; cursor:pointer;
    transition:.15s; color:var(--text-muted); font-family:inherit;
}
.filtro-andamentos button.ativo { background:#052228; color:#fff; border-color:#052228; }
.filtro-andamentos button.ativo-pub { background:#dc2626; color:#fff; border-color:#dc2626; }

/* Dropdowns da toolbar do processo */
.cv-dropdown { position:relative; display:inline-block; }
.cv-dropdown .dropdown-trigger { display:inline-flex; align-items:center; gap:4px; }
.cv-dropdown-menu {
    display:none; position:absolute; top:calc(100% + 4px); left:0;
    background:var(--bg-card, #fff); border:1px solid var(--border, #e0e0e0);
    border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.14);
    min-width:220px; z-index:1000; padding:5px;
}
.cv-dropdown.open > .cv-dropdown-menu { display:block; }
.cv-dropdown-menu a, .cv-dropdown-menu .cv-item {
    display:flex; align-items:center; gap:8px; width:100%;
    padding:8px 12px; background:transparent; border:none;
    text-align:left; cursor:pointer; color:var(--text, #222);
    font-size:.82rem; border-radius:6px; text-decoration:none;
    font-family:inherit; line-height:1.3;
}
.cv-dropdown-menu a:hover, .cv-dropdown-menu .cv-item:hover { background:var(--bg-secondary, #f0f4f7); }
.cv-dropdown-menu .cv-item.danger { color:#dc2626; }
.cv-dropdown-menu .cv-item.danger:hover { background:#fef2f2; }
.cv-dropdown-menu form { margin:0; display:block; }
.cv-dropdown-menu .cv-divider { height:1px; background:var(--border, #e0e0e0); margin:4px 2px; }
</style>

<div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap;">
    <?php
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $fromProcessos = (strpos($referer, '/processos') !== false);
    $voltarUrl = $fromProcessos ? module_url('processos') : module_url('operacional');
    $voltarLabel = $fromProcessos ? 'Processos' : 'Operacional';
    ?>
    <!-- Inline: os mais usados -->
    <a href="<?= $voltarUrl ?>" class="btn btn-outline btn-sm">&larr; <?= $voltarLabel ?></a>
    <?php if (has_min_role('gestao')): ?>
    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="toggle_salavip">
        <input type="hidden" name="case_id" value="<?= $caseId ?>">
        <button type="submit" class="btn btn-primary btn-sm" style="background:<?= $case['salavip_ativo'] ? '#059669' : '#94a3b8' ?>;" title="<?= $case['salavip_ativo'] ? 'Visível na Central VIP — clique para ocultar' : 'Oculto da Central VIP — clique para tornar visível' ?>">
            <?= $case['salavip_ativo'] ? '🟢' : '⚪' ?> Central VIP
        </button>
    </form>
    <?php endif; ?>
    <a href="<?= module_url('documentos') . '?client_id=' . ($case['client_id'] ?: '') . '&case_id=' . $caseId ?>" class="btn btn-primary btn-sm" style="background:#052228;">📄 Documentos</a>
    <?php if ($case['client_id'] && can_access('financeiro')): ?>
        <a href="<?= module_url('financeiro', 'cliente.php?id=' . $case['client_id'] . '&from_case=' . $caseId) ?>" class="btn btn-outline btn-sm">💰 Financeiro</a>
    <?php endif; ?>
    <!-- Dropdown: Imprimir -->
    <div class="cv-dropdown">
        <button type="button" class="btn btn-outline btn-sm dropdown-trigger">🖨️ Imprimir <span style="font-size:.65rem;">▾</span></button>
        <div class="cv-dropdown-menu">
            <a href="<?= module_url('operacional', 'ficha_processo_pdf.php?id=' . $caseId) ?>" target="_blank" title="Versão interna completa — tarefas, pasta Drive, andamentos restritos, observações">🖨️ <div><strong>PDF Completo</strong><div style="font-size:.7rem;color:#888;">Versão interna (tudo)</div></div></a>
            <a href="<?= module_url('operacional', 'ficha_processo_pdf.php?id=' . $caseId . '&modo=cliente') ?>" target="_blank" style="color:#7c2d12;" title="Versão para envio ao cliente — só andamentos públicos e dados do cliente">📤 <div><strong>PDF Cliente</strong><div style="font-size:.7rem;color:#888;">Versão pra enviar ao cliente</div></div></a>
        </div>
    </div>

    <!-- Dropdown: Ações -->
    <div class="cv-dropdown">
        <button type="button" class="btn btn-outline btn-sm dropdown-trigger">⚙️ Ações <span style="font-size:.65rem;">▾</span></button>
        <div class="cv-dropdown-menu">
            <a href="<?= module_url('peticoes', 'index.php?case_id=' . $caseId) ?>" style="color:#B87333;">📝 <div><strong>Fábrica de Petições</strong><div style="font-size:.7rem;color:#888;">gerar petição com IA</div></div></a>
            <div class="cv-divider"></div>
            <?php if ($case['client_id']): ?>
                <a href="<?= module_url('operacional', 'caso_novo.php?client_id=' . $case['client_id']) ?>">➕ <div><strong>Novo Processo</strong><div style="font-size:.7rem;color:#888;">pro mesmo cliente</div></div></a>
            <?php endif; ?>
            <a href="<?= module_url('helpdesk', 'novo.php?caso_id=' . $caseId . '&from_case=' . $caseId) ?>" style="color:#dc2626;">🎫 <div><strong>Abrir Chamado</strong><div style="font-size:.7rem;color:#888;">helpdesk interno</div></div></a>
            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" onsubmit="return confirm('Duplicar esta pasta para uma nova ação do mesmo cliente?');">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="duplicate_case">
                <input type="hidden" name="case_id" value="<?= $caseId ?>">
                <button type="submit" class="cv-item" style="color:#6366f1;">📋 <div><strong>Duplicar Pasta</strong><div style="font-size:.7rem;color:#888;">nova ação com mesmo cliente</div></div></button>
            </form>
            <?php if (has_role('admin')): ?>
            <div class="cv-divider"></div>
            <button type="button" onclick="confirmarExclusao()" class="cv-item danger">🗑️ <div><strong>Excluir Processo</strong><div style="font-size:.7rem;color:#b91c1c;">ação destrutiva</div></div></button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function(){
    document.addEventListener('click', function(e){
        var trigger = e.target.closest('.dropdown-trigger');
        document.querySelectorAll('.cv-dropdown').forEach(function(d){
            if (trigger && d.contains(trigger)) {
                d.classList.toggle('open');
            } else {
                d.classList.remove('open');
            }
        });
    });
})();
</script>

<!-- Header do caso -->
<?php $corStatus = isset($statusCores[$case['status']]) ? $statusCores[$case['status']] : '#052228'; ?>
<div class="caso-header" style="border-left:6px solid <?= $corStatus ?>;"><?php /* cor lateral pelo status */ ?>
    <h2 style="display:flex;align-items:center;gap:.5rem;">
        <span id="casoTitulo" onclick="editarTitulo()" style="cursor:pointer;" title="Clique para editar o nome da pasta"><?= e($case['title']) ?></span>
        <span onclick="editarTitulo()" style="cursor:pointer;font-size:.7rem;opacity:.6;" title="Editar nome">✏️</span>
    </h2>
    <form id="formTitulo" method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:none;margin-bottom:.5rem;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="update_title">
        <input type="hidden" name="case_id" value="<?= $caseId ?>">
        <div style="display:flex;gap:.35rem;align-items:center;">
            <input type="text" name="title" id="inputTitulo" value="<?= e($case['title']) ?>" minlength="5" required onkeydown="if(event.key==='Escape'){cancelarTitulo();event.preventDefault();}" style="flex:1;padding:.4rem .6rem;font-size:1rem;font-weight:700;border:2px solid rgba(255,255,255,.4);border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-family:inherit;">
            <button type="submit" style="background:#059669;color:#fff;border:none;padding:.4rem .8rem;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;">Salvar</button>
            <button type="button" onclick="cancelarTitulo()" style="background:rgba(255,255,255,.15);color:#fff;border:none;padding:.4rem .6rem;border-radius:8px;font-size:.78rem;cursor:pointer;">✕</button>
        </div>
    </form>
    <div class="meta">
        <?php
        // Buscar partes do processo para o header
        $headerPartes = buscar_partes_caso($caseId);
        $headerAutores = array();
        $headerReus = array();
        foreach ($headerPartes['autores'] as $pa) {
            $n = $pa['tipo_pessoa'] === 'juridica' ? ($pa['razao_social'] ?: $pa['nome_fantasia']) : $pa['nome'];
            if ($n) $headerAutores[] = $n;
        }
        foreach ($headerPartes['reus'] as $pr) {
            $n = $pr['tipo_pessoa'] === 'juridica' ? ($pr['razao_social'] ?: $pr['nome_fantasia']) : $pr['nome'];
            if ($n) $headerReus[] = $n;
        }
        // Representantes — descobrir quem cada um representa
        $repsAutores = array();
        $repsReus = array();
        foreach ($headerPartes['representantes'] as $prep) {
            if (!$prep['nome']) continue;
            // Verificar quem este representante representa
            $repQuem = 'desconhecido';
            foreach ($headerPartes['todas'] as $pt) {
                if (isset($pt['representa_parte_id']) && (int)$pt['representa_parte_id'] === (int)$prep['id']) {
                    if ($pt['papel'] === 'autor' || $pt['papel'] === 'litisconsorte_ativo') { $repQuem = 'autores'; break; }
                    if ($pt['papel'] === 'reu' || $pt['papel'] === 'litisconsorte_passivo') { $repQuem = 'reus'; break; }
                }
            }
            if ($repQuem === 'autores') $repsAutores[] = $prep['nome'];
            else $repsReus[] = $prep['nome'];
        }
        ?>
        <?php if (!empty($headerAutores)): ?>
            <?= e(implode(' e ', $headerAutores)) ?>
            <?php if (!empty($repsAutores)): ?>
                <span style="font-size:.72rem;opacity:.7;font-style:italic;">(rep. por <?= e(implode(', ', $repsAutores)) ?>)</span>
            <?php endif; ?>
        <?php else: ?>
            <?= e($case['client_name'] ?? 'Sem cliente') ?>
        <?php endif; ?>
        <?php if (!empty($headerReus)): ?>
            <span style="opacity:.6;margin:0 4px;">×</span>
            <?= e(implode(' e ', $headerReus)) ?>
            <?php if (!empty($repsReus)): ?>
                <span style="font-size:.72rem;opacity:.7;font-style:italic;">(rep. por <?= e(implode(', ', $repsReus)) ?>)</span>
            <?php endif; ?>
        <?php endif; ?>
        <br>
        <span style="font-size:.78rem;opacity:.8;">
        <?= e($case['case_type'] ?: '') ?>
        · <?= e($case['responsible_name'] ?: 'Sem responsável') ?>
        <?php if ($case['deadline']): ?> · Prazo: <?= data_br($case['deadline']) ?><?php endif; ?>
        </span>
    </div>
    <?php if ($case['case_number'] || (isset($case['court']) && $case['court']) || (isset($case['comarca']) && $case['comarca'])): ?>
    <div style="margin-top:.5rem;font-size:.82rem;color:rgba(255,255,255,.8);">
        <?php if ($case['case_number']): ?>
            <span onclick="copiarNumero(this)" style="font-family:monospace;font-size:.85rem;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:4px;cursor:pointer;transition:all .2s;" title="Clique para copiar o nº do processo"><?= e(format_cnj($case['case_number'])) ?></span>
        <?php endif; ?>
        <?php if (isset($case['court']) && $case['court']): ?>
            · <?= e($case['court']) ?>
        <?php endif; ?>
        <?php if (isset($case['comarca']) && $case['comarca']): ?>
            · <?= e($case['comarca']) ?><?php if (isset($case['comarca_uf']) && $case['comarca_uf']): ?>/<?= e($case['comarca_uf']) ?><?php endif; ?><?php if (isset($case['regional']) && $case['regional']): ?> — Regional de <?= e($case['regional']) ?><?php endif; ?>
        <?php endif; ?>
        <?php if ($case['distribution_date']): ?>
            · Distribuído em <?= data_br($case['distribution_date']) ?>
        <?php endif; ?>
        <?php if (isset($case['sistema_tribunal']) && $case['sistema_tribunal']): ?>
            · <span style="background:rgba(255,255,255,.2);padding:1px 6px;border-radius:3px;font-size:.75rem;font-weight:600;"><?= e($case['sistema_tribunal']) ?></span>
        <?php endif; ?>
        <?php if (isset($case['segredo_justica']) && $case['segredo_justica']): ?>
            · <span style="background:#dc2626;padding:1px 6px;border-radius:3px;font-size:.72rem;font-weight:700;color:#fff;">SEGREDO DE JUSTIÇA</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (isset($case['departamento']) && $case['departamento']): ?>
    <div style="margin-top:.3rem;font-size:.75rem;color:rgba(255,255,255,.6);">
        Dept: <?= ucfirst(e($case['departamento'])) ?>
    </div>
    <?php endif; ?>
    <div class="actions">
        <?php
        // Montar lista de clientes com WhatsApp
        $clientesComWa = array();
        foreach ($clientesVinculados as $cv) {
            $ph = $cv['phone'] ? preg_replace('/\D/', '', $cv['phone']) : '';
            if ($ph) {
                $primeiro = explode(' ', trim($cv['name']))[0];
                $msg = "Olá " . $primeiro . ", tudo bem? Aqui é do escritório Ferreira & Sá Advocacia. Entramos em contato sobre o seu processo" . ($case['title'] ? " (" . $case['title'] . ")" : "") . ".";
                $clientesComWa[] = array('name' => $cv['name'], 'primeiro' => $primeiro, 'wa' => 'https://wa.me/55' . $ph . '?text=' . rawurlencode($msg));
            }
        }
        ?>
        <?php if (count($clientesComWa) === 1): ?>
            <a href="<?= $clientesComWa[0]['wa'] ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php elseif (count($clientesComWa) > 1): ?>
            <div style="position:relative;display:inline-block;">
                <button type="button" onclick="var m=document.getElementById('menuWa');m.style.display=m.style.display==='block'?'none':'block';" class="btn btn-success btn-sm">💬 WhatsApp ▾</button>
                <div id="menuWa" style="display:none;position:absolute;top:100%;left:0;background:#fff;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.2);z-index:50;min-width:220px;margin-top:4px;overflow:hidden;">
                    <?php foreach ($clientesComWa as $cw): ?>
                    <a href="<?= $cw['wa'] ?>" target="_blank" style="display:block;padding:.6rem 1rem;color:#052228;text-decoration:none;font-size:.85rem;font-weight:500;border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#ecfdf5'" onmouseout="this.style.background=''">
                        💬 <?= e($cw['name']) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (!empty($clientesVinculados)): ?>
            <?php if (count($clientesVinculados) === 1): ?>
                <a href="<?= module_url('clientes', 'ver.php?id=' . $clientesVinculados[0]['id']) ?>" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);" title="Cliente principal: <?= e($clientesVinculados[0]['name']) ?>">⭐ Ver cliente</a>
            <?php else: ?>
                <div style="position:relative;display:inline-block;">
                    <button type="button" onclick="var m=document.getElementById('menuClientes');m.style.display=m.style.display==='block'?'none':'block';" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);">👤 Ver cliente ▾</button>
                    <div id="menuClientes" style="display:none;position:absolute;top:100%;left:0;background:#fff;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.2);z-index:50;min-width:260px;margin-top:4px;overflow:hidden;">
                        <?php foreach ($clientesVinculados as $idx => $cv):
                            $isPrincipal = ($idx === 0);
                        ?>
                        <a href="<?= module_url('clientes', 'ver.php?id=' . $cv['id']) ?>"
                           style="display:block;padding:.6rem 1rem;color:#052228;text-decoration:none;font-size:.85rem;font-weight:<?= $isPrincipal ? '700' : '500' ?>;border-bottom:1px solid #f1f5f9;<?= $isPrincipal ? 'background:#ecfdf5;border-left:3px solid #059669;' : '' ?>"
                           onmouseover="this.style.background='<?= $isPrincipal ? '#d1fae5' : '#f8f6f2' ?>'"
                           onmouseout="this.style.background='<?= $isPrincipal ? '#ecfdf5' : '' ?>'">
                            <?php if ($isPrincipal): ?>
                                <div style="display:flex;align-items:center;gap:.35rem;">
                                    <span style="font-size:1rem;">⭐</span>
                                    <span><?= e($cv['name']) ?></span>
                                </div>
                                <div style="font-size:.62rem;color:#059669;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-top:2px;margin-left:1.3rem;">Cliente principal</div>
                            <?php else: ?>
                                <div style="display:flex;align-items:center;gap:.35rem;">
                                    <span>👤</span>
                                    <span><?= e($cv['name']) ?></span>
                                </div>
                                <div style="font-size:.62rem;color:#64748b;margin-top:2px;margin-left:1.3rem;">Representado · rep. por <?= e(explode(' ', $clientesVinculados[0]['name'])[0]) ?></div>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($duplicatas)): ?>
<!-- Banner: Processo duplicado detectado -->
<div style="background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border-radius:var(--radius-lg);padding:.75rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
    <span style="font-size:1.1rem;">⚠️</span>
    <div style="flex:1;">
        <span style="font-size:.85rem;font-weight:700;">Processo com número duplicado!</span>
        <span style="font-size:.78rem;opacity:.9;margin-left:.5rem;">O nº <?= e($case['case_number']) ?> também existe em:</span>
        <?php foreach ($duplicatas as $dup): ?>
            <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $dup['id']) ?>" style="color:#fff;font-weight:700;text-decoration:underline;margin-left:.5rem;">
                <?= e($dup['title']) ?> (#<?= $dup['id'] ?>)
            </a>
        <?php endforeach; ?>
    </div>
    <?php if (has_min_role('gestao')): ?>
    <button onclick="abrirMergeDuplicata(<?= (int)$duplicatas[0]['id'] ?>, '<?= e(addslashes($duplicatas[0]['title'])) ?>')" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:none;font-size:.78rem;font-weight:700;">🔗 Mesclar pastas</button>
    <?php endif; ?>
</div>
<script>
function abrirMergeDuplicata(outroId, outroTitulo) {
    if (!confirm('Mesclar esta pasta com "' + outroTitulo + '"?\n\nUma das pastas será absorvida pela outra. Todos os dados (tarefas, andamentos, partes, docs) serão migrados.\n\nDeseja continuar?')) return;

    var quem = prompt('Qual pasta deve ser MANTIDA?\n\n1 = Esta pasta (<?= e(addslashes($case['title'])) ?>)\n2 = ' + outroTitulo + '\n\nDigite 1 ou 2:');
    if (quem !== '1' && quem !== '2') { alert('Operação cancelada.'); return; }

    var principalId = quem === '1' ? <?= $caseId ?> : outroId;
    var absorvidoId = quem === '1' ? outroId : <?= $caseId ?>;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= module_url("operacional", "api.php") ?>';
    function addH(n, v) { var i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); }
    addH('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    addH('action', 'merge_cases');
    addH('principal_id', principalId);
    addH('absorvido_id', absorvidoId);
    addH('novo_titulo', '');
    document.body.appendChild(form);
    form.submit();
}
</script>
<?php endif; ?>

<?php if ($processoPrincipal):
    $isRecurso = (isset($case['tipo_vinculo']) && $case['tipo_vinculo'] === 'recurso');
    $bannerGrad = $isRecurso ? '#b45309,#d97706' : '#6366f1,#4f46e5';
    $bannerIcon = $isRecurso ? '📜' : '🔗';
    $bannerLabel = $isRecurso ? 'Recurso vinculado a:' : 'Processo incidental de:';
?>
<!-- Banner: Este é um processo vinculado -->
<div style="background:linear-gradient(135deg,<?= $bannerGrad ?>);color:#fff;border-radius:var(--radius-lg);padding:.75rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
    <span style="font-size:1.1rem;"><?= $bannerIcon ?></span>
    <div style="flex:1;">
        <span style="font-size:.82rem;font-weight:700;"><?= $bannerLabel ?></span>
        <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $processoPrincipal['id']) ?>" style="color:#fff;font-weight:700;text-decoration:underline;margin-left:.3rem;">
            <?= e($processoPrincipal['title']) ?>
        </a>
        <?php if ($case['tipo_relacao']): ?>
            <span style="background:rgba(255,255,255,.2);padding:1px 8px;border-radius:4px;font-size:.72rem;font-weight:600;margin-left:.5rem;"><?= e($case['tipo_relacao']) ?></span>
        <?php endif; ?>
    </div>
    <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $processoPrincipal['id']) ?>" class="btn btn-sm" style="background:rgba(255,255,255,.2);color:#fff;border:none;font-size:.75rem;">Ver processo principal</a>
</div>
<?php endif; ?>

<!-- Próximos Compromissos do Processo -->
<?php
$compromissosCaso = array();
$compromissosRealizados = array();
try {
    $stmtComp = $pdo->prepare(
        "SELECT e.*, u.name as responsavel_name,
                (SELECT cp.id FROM case_publicacoes cp
                 WHERE cp.case_id = e.case_id
                   AND cp.status_prazo = 'pendente'
                   AND (cp.agenda_id = e.id OR cp.data_disponibilizacao = DATE(e.data_inicio))
                 ORDER BY cp.id DESC LIMIT 1) AS pub_pendente_id
         FROM agenda_eventos e
         LEFT JOIN users u ON u.id = e.responsavel_id
         WHERE e.case_id = ? AND e.status NOT IN ('cancelado','remarcado','realizado')
         ORDER BY e.data_inicio ASC"
    );
    $stmtComp->execute(array($caseId));
    $compromissosCaso = $stmtComp->fetchAll();

    $stmtCompR = $pdo->prepare(
        "SELECT e.*, u.name as responsavel_name
         FROM agenda_eventos e
         LEFT JOIN users u ON u.id = e.responsavel_id
         WHERE e.case_id = ? AND e.status IN ('realizado','nao_compareceu','remarcado')
         ORDER BY e.data_inicio DESC"
    );
    $stmtCompR->execute(array($caseId));
    $compromissosRealizados = $stmtCompR->fetchAll();
} catch (Exception $e) {}

$compFuturos = array();
$compPassados = array();
foreach ($compromissosCaso as $comp) {
    $dtComp = substr($comp['data_inicio'], 0, 10);
    if ($dtComp >= date('Y-m-d')) {
        $compFuturos[] = $comp;
    } else {
        $compPassados[] = $comp;
    }
}

$tipoCompCores = array(
    'audiencia'=>'#e67e22','reuniao_cliente'=>'#B87333','prazo'=>'#CC0000',
    'onboarding'=>'#2D7A4F','reuniao_interna'=>'#1a3a7a','mediacao_cejusc'=>'#6B4C9A',
    'balcao_virtual'=>'#0d9488','ligacao'=>'#888880','publicacao'=>'#dc2626',
);
$tipoCompLabels = array(
    'audiencia'=>'Audiência','reuniao_cliente'=>'Reunião','prazo'=>'Prazo',
    'onboarding'=>'Onboarding','reuniao_interna'=>'R. Interna','mediacao_cejusc'=>'Mediação',
    'balcao_virtual'=>'Balcão Virtual','ligacao'=>'Ligação','publicacao'=>'Publicação',
);

if (!empty($compFuturos)): ?>
<div style="margin-bottom:1rem;">
    <?php foreach ($compFuturos as $comp):
        $corComp = isset($tipoCompCores[$comp['tipo']]) ? $tipoCompCores[$comp['tipo']] : '#052228';
        $labelComp = isset($tipoCompLabels[$comp['tipo']]) ? $tipoCompLabels[$comp['tipo']] : ucfirst($comp['tipo']);
        $dtInicio = $comp['data_inicio'];
        $diasAte = (int)((strtotime(substr($dtInicio, 0, 10)) - strtotime(date('Y-m-d'))) / 86400);
        $isHoje = $diasAte === 0;
        $isAmanha = $diasAte === 1;
        $isUrgente = $diasAte <= 1;
    ?>
    <div style="display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;margin-bottom:.4rem;border-radius:10px;border-left:4px solid <?= $corComp ?>;background:<?= $isUrgente ? '#fef2f2' : '#f8fafc' ?>;border:1px solid <?= $isUrgente ? '#fca5a5' : 'var(--border)' ?>;border-left:4px solid <?= $corComp ?>;">
        <div style="text-align:center;min-width:48px;">
            <div style="font-size:1.2rem;font-weight:800;color:<?= $isUrgente ? '#dc2626' : $corComp ?>;"><?= date('d', strtotime($dtInicio)) ?></div>
            <div style="font-size:.65rem;color:var(--text-muted);text-transform:uppercase;font-weight:600;"><?= date('M', strtotime($dtInicio)) ?></div>
        </div>
        <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                <span style="font-size:.68rem;font-weight:700;color:#fff;background:<?= $corComp ?>;padding:1px 6px;border-radius:3px;"><?= $labelComp ?></span>
                <?php if ($isHoje): ?>
                    <span style="font-size:.65rem;font-weight:700;color:#dc2626;background:#fef2f2;padding:1px 6px;border-radius:3px;border:1px solid #fca5a5;">HOJE</span>
                <?php elseif ($isAmanha): ?>
                    <span style="font-size:.65rem;font-weight:700;color:#d97706;background:#fef3c7;padding:1px 6px;border-radius:3px;border:1px solid #fcd34d;">AMANHÃ</span>
                <?php elseif ($diasAte <= 7): ?>
                    <span style="font-size:.65rem;color:var(--text-muted);"><?= $diasAte ?>d</span>
                <?php endif; ?>
                <span style="font-size:.85rem;font-weight:600;color:var(--petrol-900);"><?= e($comp['titulo']) ?></span>
            </div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:2px;">
                <?php if ($comp['dia_todo'] != 1): ?>
                    <?= date('H:i', strtotime($dtInicio)) ?>
                    <?php if ($comp['data_fim']): ?> — <?= date('H:i', strtotime($comp['data_fim'])) ?><?php endif; ?>
                    &middot;
                <?php endif; ?>
                <?= date('d/m/Y', strtotime($dtInicio)) ?>
                <?php if ($comp['local']): ?> &middot; <?= e($comp['local']) ?><?php endif; ?>
                <?php if ($comp['responsavel_name']): ?> &middot; <?= e(explode(' ', $comp['responsavel_name'])[0]) ?><?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:.3rem;flex-shrink:0;">
            <?php
            // Montar links WhatsApp para cada cliente vinculado
            $waComps = array();
            $dataCompFmt = date('d/m/Y', strtotime($dtInicio));
            $horaCompFmt = ($comp['dia_todo'] != 1) ? date('H:i', strtotime($dtInicio)) : '';
            $tipoCompMsg = isset($tipoCompLabels[$comp['tipo']]) ? mb_strtolower($tipoCompLabels[$comp['tipo']]) : $comp['tipo'];
            foreach ($clientesVinculados as $cvw) {
                $ph = $cvw['phone'] ? preg_replace('/\D/', '', $cvw['phone']) : '';
                if (!$ph) continue;
                $primeiro = explode(' ', trim($cvw['name']))[0];
                $msg = "Olá " . $primeiro . ", tudo bem?\n\nGostaríamos de lembrar sobre a *" . $tipoCompMsg . "* agendada para o dia *" . $dataCompFmt . "*";
                if ($horaCompFmt) $msg .= " às *" . $horaCompFmt . "*";
                $msg .= ".";
                if ($comp['local']) $msg .= "\n\n📍 Local: " . $comp['local'];
                if ($comp['meet_link']) $msg .= "\n\n💻 Link de acesso: " . $comp['meet_link'];
                $msg .= "\n\nQualquer dúvida, estamos à disposição.\nFerreira & Sá Advocacia";
                $waComps[] = array('name' => $cvw['name'], 'url' => 'https://wa.me/55' . $ph . '?text=' . rawurlencode($msg));
            }
            ?>
            <?php if (count($waComps) === 1): ?>
                <a href="<?= e($waComps[0]['url']) ?>" target="_blank" style="font-size:.7rem;background:#25D366;color:#fff;padding:3px 8px;border-radius:5px;text-decoration:none;font-weight:600;">WhatsApp</a>
            <?php elseif (count($waComps) > 1): ?>
                <div style="position:relative;display:inline-block;">
                    <button type="button" onclick="var m=this.nextElementSibling;m.style.display=m.style.display==='block'?'none':'block';" style="font-size:.7rem;background:#25D366;color:#fff;padding:3px 8px;border-radius:5px;border:none;font-weight:600;cursor:pointer;">WhatsApp ▾</button>
                    <div style="display:none;position:absolute;top:100%;right:0;background:#fff;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.2);z-index:50;min-width:200px;margin-top:4px;overflow:hidden;">
                        <?php foreach ($waComps as $wc): ?>
                        <a href="<?= e($wc['url']) ?>" target="_blank" style="display:block;padding:.5rem .75rem;color:#052228;text-decoration:none;font-size:.78rem;font-weight:500;border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#ecfdf5'" onmouseout="this.style.background=''">💬 <?= e($wc['name']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($comp['meet_link']): ?>
                <a href="<?= e($comp['meet_link']) ?>" target="_blank" style="font-size:.7rem;background:#052228;color:#fff;padding:3px 8px;border-radius:5px;text-decoration:none;font-weight:600;">Meet</a>
            <?php endif; ?>
            <a href="<?= module_url('agenda') ?>?dia=<?= date('Y-m-d', strtotime($dtInicio)) ?>&voltar_caso=<?= $caseId ?>" style="font-size:.7rem;color:var(--petrol-900);padding:3px 8px;border:1px solid var(--border);border-radius:5px;text-decoration:none;">Ver agenda</a>
            <?php if (!empty($comp['pub_pendente_id']) && (has_min_role('operacional') || has_min_role('gestao'))): ?>
                <!-- Evento vinculado a uma intimação pendente: oferece fechar direto no card -->
                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;" onsubmit="return confirm('Confirmar prazo desta intimação? (você vai cumprir)');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="confirmar_prazo_publicacao">
                    <input type="hidden" name="pub_id" value="<?= (int)$comp['pub_pendente_id'] ?>">
                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                    <input type="hidden" name="novo_status" value="confirmado">
                    <input type="hidden" name="_back" value="<?= htmlspecialchars(module_url('operacional', 'caso_ver.php?id=' . $caseId), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" title="Confirmar prazo — vou cumprir" style="font-size:.7rem;background:#dcfce7;color:#15803d;padding:3px 8px;border:1px solid #86efac;border-radius:5px;cursor:pointer;font-weight:600;">✓ Cumprir</button>
                </form>
                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;" onsubmit="return confirm('Descartar esta intimação? (não precisa cumprir)');">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="confirmar_prazo_publicacao">
                    <input type="hidden" name="pub_id" value="<?= (int)$comp['pub_pendente_id'] ?>">
                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                    <input type="hidden" name="novo_status" value="descartado">
                    <input type="hidden" name="_back" value="<?= htmlspecialchars(module_url('operacional', 'caso_ver.php?id=' . $caseId), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" title="Não precisa cumprir — fechar" style="font-size:.7rem;background:#fef2f2;color:#b91c1c;padding:3px 8px;border:1px solid #fca5a5;border-radius:5px;cursor:pointer;font-weight:600;">⊘ Descartar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($compromissosRealizados)): ?>
<div style="margin-bottom:1rem;">
    <button type="button" onclick="var el=document.getElementById('compRealizados');el.style.display=el.style.display==='none'?'block':'none';this.querySelector('.chv').textContent=el.style.display==='none'?'▸':'▾';" style="background:none;border:none;font-size:.75rem;color:#059669;cursor:pointer;font-family:inherit;font-weight:600;display:flex;align-items:center;gap:.3rem;">
        <span class="chv">▸</span> Compromissos realizados (<?= count($compromissosRealizados) ?>)
    </button>
    <div id="compRealizados" style="display:none;margin-top:.3rem;">
        <?php foreach ($compromissosRealizados as $comp):
            $corComp = isset($tipoCompCores[$comp['tipo']]) ? $tipoCompCores[$comp['tipo']] : '#6b7280';
            $labelComp = isset($tipoCompLabels[$comp['tipo']]) ? $tipoCompLabels[$comp['tipo']] : ucfirst($comp['tipo']);
            $statusLabel = $comp['status'] === 'realizado' ? 'Realizado' : ($comp['status'] === 'nao_compareceu' ? 'Não compareceu' : 'Remarcado');
            $statusCor = $comp['status'] === 'realizado' ? '#059669' : ($comp['status'] === 'nao_compareceu' ? '#b45309' : '#7c3aed');
        ?>
        <div style="display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;border-bottom:1px solid rgba(0,0,0,.04);opacity:.7;">
            <div style="width:30px;text-align:center;font-size:.7rem;font-weight:700;color:<?= $corComp ?>;flex-shrink:0;">
                <?= date('d', strtotime($comp['data_inicio'])) ?><br><span style="font-size:.55rem;text-transform:uppercase;"><?= date('M', strtotime($comp['data_inicio'])) ?></span>
            </div>
            <div style="flex:1;min-width:0;">
                <div style="font-size:.78rem;text-decoration:line-through;color:var(--text-muted);">
                    <span style="background:<?= $corComp ?>;color:#fff;padding:1px 5px;border-radius:3px;font-size:.6rem;font-weight:700;margin-right:4px;"><?= $labelComp ?></span>
                    <?= e($comp['titulo']) ?>
                </div>
            </div>
            <span style="font-size:.65rem;font-weight:600;color:<?= $statusCor ?>;flex-shrink:0;"><?= $statusLabel ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Atalhos rápidos -->
<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <a href="<?= module_url('tarefas') ?>?case_id=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#6366f1;">+ Criar Tarefa</a>
    <a href="<?= module_url('tarefas_audio') ?>?case_id=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:linear-gradient(135deg,#B87333,#8b5a26);color:#fff;font-weight:700;box-shadow:0 2px 6px rgba(184,115,51,.4);" title="Gravar áudio ditando a tarefa — IA transcreve e cria automaticamente">🎙️ Tarefa por áudio</a>
    <a href="<?= module_url('agenda') ?>?novo=1&tipo=audiencia&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>&voltar_caso=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#052228;">Agendar Audiência</a>
    <a href="<?= module_url('agenda') ?>?novo=1&tipo=reuniao_cliente&modalidade=online&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>&voltar_caso=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#059669;">Reunião + Meet</a>
    <a href="<?= module_url('agenda') ?>?novo=1&tipo=balcao_virtual&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>&voltar_caso=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#0d9488;">Balcão Virtual</a>
    <a href="<?= module_url('agenda') ?>?novo=1&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>&voltar_caso=<?= $caseId ?>" class="btn btn-outline btn-sm" style="font-size:.78rem;">+ Compromisso</a>
    <a href="<?= module_url('operacional', 'prazos_calc.php?case_id=' . $caseId) ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#dc2626;">Calcular Prazo</a>
    <a href="<?= module_url('oficios', 'novo_oficio.php?case_id=' . $caseId) ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#7c3aed;" title="Montar ofício pro RH do empregador (pensão alimentícia) — modelos prontos de e-mail e WhatsApp">📬 Ofício p/ empregador</a>
</div>

<!-- Processos Incidentais -->
<?php if (!empty($incidentais) || !$case['is_incidental']): ?>
<div class="card mb-2">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>📎 Processos Incidentais (<?= count($incidentais) ?>)</h3>
        <?php if (has_min_role('gestao')): ?>
        <button onclick="document.getElementById('modalIncidental').style.display='flex'" class="btn btn-primary btn-sm" style="font-size:.72rem;">+ Vincular processo incidental</button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($incidentais)): ?>
            <p class="text-muted text-sm">Nenhum processo incidental vinculado.</p>
        <?php else: ?>
            <?php foreach ($incidentais as $inc):
                $incStatusLabel = isset($statusLabels[$inc['status']]) ? $statusLabels[$inc['status']] : ucfirst($inc['status']);
                $incStatusCor = isset($statusCores[$inc['status']]) ? $statusCores[$inc['status']] : '#6b7280';
            ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-bottom:1px solid var(--border);">
                <span style="font-size:1rem;">⚖️</span>
                <div style="flex:1;">
                    <div style="font-weight:700;font-size:.88rem;color:var(--petrol-900);">
                        <?= e($inc['tipo_relacao'] ?: $inc['case_type'] ?: 'Processo Incidental') ?>
                    </div>
                    <div style="font-size:.75rem;color:var(--text-muted);">
                        <?= $inc['case_number'] ? 'Nº ' . e($inc['case_number']) : 'Sem número' ?>
                        · <span style="color:<?= $incStatusCor ?>;font-weight:600;"><?= e($incStatusLabel) ?></span>
                    </div>
                </div>
                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $inc['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Abrir processo</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Vincular Processo Incidental -->
<div id="modalIncidental" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#052228;margin-bottom:1rem;">📎 Vincular Processo Incidental</h3>

        <div style="margin-bottom:1rem;">
            <label style="font-size:.75rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.3rem;">Tipo de relação *</label>
            <select id="incTipoRelacao" style="width:100%;padding:.5rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                <option value="">Selecione...</option>
                <?php foreach ($tiposRelacao as $tr): ?>
                <option value="<?= e($tr) ?>"><?= e($tr) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Tab: Vincular existente / Criar novo -->
        <div style="display:flex;border:2px solid var(--petrol-900);border-radius:10px;overflow:hidden;margin-bottom:1rem;">
            <button type="button" id="btnIncExistente" onclick="toggleIncTab('existente')" style="flex:1;padding:6px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:var(--petrol-900);color:#fff;">Vincular existente</button>
            <button type="button" id="btnIncNovo" onclick="toggleIncTab('novo')" style="flex:1;padding:6px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#fff;color:var(--petrol-900);">Criar novo</button>
        </div>

        <!-- Vincular existente -->
        <div id="incTabExistente">
            <label style="font-size:.75rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.3rem;">Buscar processo do mesmo cliente</label>
            <select id="incCasoSelect" style="width:100%;padding:.5rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                <option value="">Carregando...</option>
            </select>
        </div>

        <!-- Criar novo -->
        <div id="incTabNovo" style="display:none;">
            <p style="font-size:.78rem;color:#6b7280;margin-bottom:.5rem;">Será criado um novo processo vinculado a este como incidental.</p>
        </div>

        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="document.getElementById('modalIncidental').style.display='none'" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-size:.82rem;">Cancelar</button>
            <button id="btnIncConfirmar" onclick="confirmarIncidental()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#052228;color:#fff;cursor:pointer;font-size:.82rem;font-weight:700;">Vincular →</button>
        </div>
    </div>
</div>

<!-- Recursos Vinculados -->
<?php if (!empty($recursos) || !$case['is_incidental']): ?>
<div class="card mb-2">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>📜 Recursos (<?= count($recursos) ?>)</h3>
        <?php if (has_min_role('gestao')): ?>
        <button onclick="document.getElementById('modalRecurso').style.display='flex'" class="btn btn-primary btn-sm" style="font-size:.72rem;background:#b45309;">+ Vincular recurso</button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if (empty($recursos)): ?>
            <p class="text-muted text-sm">Nenhum recurso vinculado.</p>
        <?php else: ?>
            <?php foreach ($recursos as $rec):
                $recStatusLabel = isset($statusLabels[$rec['status']]) ? $statusLabels[$rec['status']] : ucfirst($rec['status']);
                $recStatusCor = isset($statusCores[$rec['status']]) ? $statusCores[$rec['status']] : '#6b7280';
            ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem 0;border-bottom:1px solid var(--border);">
                <span style="font-size:1rem;">📜</span>
                <div style="flex:1;">
                    <div style="font-weight:700;font-size:.88rem;color:var(--petrol-900);">
                        <?= e($rec['tipo_relacao'] ?: 'Recurso') ?>
                    </div>
                    <div style="font-size:.75rem;color:var(--text-muted);">
                        <?= e($rec['title']) ?>
                        · <?= $rec['case_number'] ? 'Nº ' . e($rec['case_number']) : 'Sem número' ?>
                        · <span style="color:<?= $recStatusCor ?>;font-weight:600;"><?= e($recStatusLabel) ?></span>
                    </div>
                </div>
                <a href="<?= module_url('operacional', 'caso_ver.php?id=' . $rec['id']) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Abrir recurso</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Modal: Vincular Recurso -->
<div id="modalRecurso" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;padding:1.75rem;max-width:520px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);">
        <h3 style="font-size:1rem;font-weight:700;color:#b45309;margin-bottom:1rem;">📜 Vincular Recurso</h3>

        <div style="margin-bottom:1rem;">
            <label style="font-size:.75rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.3rem;">Tipo de recurso *</label>
            <select id="recTipoRelacao" style="width:100%;padding:.5rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                <option value="">Selecione...</option>
                <?php foreach ($tiposRecurso as $tr): ?>
                <option value="<?= e($tr) ?>"><?= e($tr) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Tab: Vincular existente / Criar novo -->
        <div style="display:flex;border:2px solid #b45309;border-radius:10px;overflow:hidden;margin-bottom:1rem;">
            <button type="button" id="btnRecExistente" onclick="toggleRecTab('existente')" style="flex:1;padding:6px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#b45309;color:#fff;">Vincular existente</button>
            <button type="button" id="btnRecNovo" onclick="toggleRecTab('novo')" style="flex:1;padding:6px;font-size:.82rem;font-weight:700;border:none;cursor:pointer;background:#fff;color:#b45309;">Criar novo</button>
        </div>

        <!-- Vincular existente -->
        <div id="recTabExistente">
            <label style="font-size:.75rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.3rem;">Buscar processo do mesmo cliente</label>
            <select id="recCasoSelect" style="width:100%;padding:.5rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">
                <option value="">Carregando...</option>
            </select>
        </div>

        <!-- Criar novo -->
        <div id="recTabNovo" style="display:none;">
            <p style="font-size:.78rem;color:#6b7280;margin-bottom:.5rem;">Será criado um novo processo vinculado a este como recurso.</p>
        </div>

        <div style="display:flex;gap:.5rem;margin-top:1.25rem;justify-content:flex-end;">
            <button onclick="document.getElementById('modalRecurso').style.display='none'" style="padding:.5rem 1rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-size:.82rem;">Cancelar</button>
            <button id="btnRecConfirmar" onclick="confirmarRecurso()" style="padding:.5rem 1.25rem;border:none;border-radius:8px;background:#b45309;color:#fff;cursor:pointer;font-size:.82rem;font-weight:700;">Vincular →</button>
        </div>
    </div>
</div>

<!-- Partes do Processo -->
<div class="card mb-2">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>Partes do Processo</h3>
        <button onclick="abrirModalParte()" class="btn btn-primary btn-sm" style="font-size:.75rem;">+ Adicionar Parte</button>
    </div>
    <div class="card-body" id="partesLista" style="padding:.5rem .85rem;">
        <div style="text-align:center;color:var(--text-muted);padding:.5rem;">Carregando...</div>
    </div>
</div>

<!-- Modal Parte -->
<div id="parteOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:14px;max-width:600px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1rem 1.2rem;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;">
        <h3 style="margin:0;font-size:1rem;" id="parteTitModal">Adicionar Parte</h3>
        <button onclick="fecharModalParte()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer">X</button>
    </div>
    <div style="padding:1.2rem;">
        <input type="hidden" id="parteId" value="0">
        <input type="hidden" id="parteClientId" value="0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.8rem;">
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:.2rem;">Papel</label>
                <?php $isRecursoVer = (isset($case['tipo_vinculo']) && $case['tipo_vinculo'] === 'recurso'); ?>
                <select id="partePapel" class="form-select" style="font-size:.85rem;" onchange="mudouPapel()">
                    <option value="autor"><?= $isRecursoVer ? 'Recorrente' : 'Autor' ?></option>
                    <option value="reu"><?= $isRecursoVer ? 'Recorrido' : 'Réu' ?></option>
                    <option value="representante_legal">Representante Legal</option>
                    <option value="terceiro_interessado">Terceiro Interessado</option>
                    <option value="litisconsorte_ativo">Litisconsorte Ativo</option>
                    <option value="litisconsorte_passivo">Litisconsorte Passivo</option>
                </select>
            </div>
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:.2rem;">Tipo</label>
                <select id="parteTipo" class="form-select" style="font-size:.85rem;" onchange="mudouTipoPessoa()">
                    <option value="fisica">Pessoa Física</option>
                    <option value="juridica">Pessoa Jurídica</option>
                </select>
            </div>
        </div>
        <div id="parteRepBox" style="display:none;margin-bottom:.8rem;">
            <label style="font-size:.72rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:.3rem;">Representa quais partes?</label>
            <div id="parteRepChecks" style="max-height:150px;overflow-y:auto;border:1.5px solid var(--border);border-radius:8px;padding:.4rem .6rem;"></div>
            <input type="hidden" id="parteRepId" value="">
        </div>
        <!-- Pessoa Física -->
        <div id="partePF">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;">
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">CPF</label><input id="parteCpf" class="form-input" style="font-size:.85rem;" placeholder="000.000.000-00" oninput="mascaraCpfParte(this); autoBuscarCpfParte()" onblur="buscarCpfParte()"><span id="parteCpfStatus" style="font-size:.65rem;"></span></div>
                <div style="position:relative;"><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Nome Completo</label><input id="parteNome" class="form-input" style="font-size:.85rem;" autocomplete="off" oninput="buscarNomeParte(this.value)"><div id="parteNomeSugestoes" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:0 0 8px 8px;max-height:200px;overflow-y:auto;z-index:50;box-shadow:0 4px 16px rgba(0,0,0,.12);"></div></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem;margin-top:.5rem;">
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">RG</label><input id="parteRg" class="form-input" style="font-size:.85rem;"></div>
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Nascimento</label><input type="date" id="parteNasc" class="form-input" style="font-size:.85rem;"></div>
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Estado Civil</label><input id="parteEC" class="form-input" style="font-size:.85rem;" placeholder="Solteiro(a)"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.5rem;">
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Profissão</label><input id="parteProf" class="form-input" style="font-size:.85rem;"></div>
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">E-mail</label><input id="parteEmail" class="form-input" style="font-size:.85rem;"></div>
            </div>
        </div>
        <!-- Pessoa Jurídica -->
        <div id="partePJ" style="display:none;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;">
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">CNPJ</label><input id="parteCnpj" class="form-input" style="font-size:.85rem;" placeholder="00.000.000/0000-00" onblur="buscarCnpjParte()"><span id="parteCnpjStatus" style="font-size:.65rem;"></span></div>
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Razão Social</label><input id="parteRazao" class="form-input" style="font-size:.85rem;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.5rem;">
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Nome Fantasia</label><input id="parteFantasia" class="form-input" style="font-size:.85rem;"></div>
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">E-mail</label><input id="parteEmailPJ" class="form-input" style="font-size:.85rem;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.5rem;">
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Representante Legal</label><input id="parteRepNome" class="form-input" style="font-size:.85rem;"></div>
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">CPF do Representante</label><input id="parteRepCpf" class="form-input" style="font-size:.85rem;"></div>
            </div>
        </div>
        <!-- Contato -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-top:.5rem;">
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Telefone</label><input id="parteTel" class="form-input" style="font-size:.85rem;"></div>
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">CEP</label><input id="parteCep" class="form-input" style="font-size:.85rem;" placeholder="00000-000" onblur="buscarCepParte()"></div>
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:.6rem;margin-top:.5rem;">
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Endereço</label><input id="parteEnd" class="form-input" style="font-size:.85rem;"></div>
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Cidade</label><input id="parteCid" class="form-input" style="font-size:.85rem;"></div>
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">UF</label><input id="parteUf" class="form-input" style="font-size:.85rem;" maxlength="2"></div>
        </div>
        <!-- Vincular como cliente -->
        <div style="margin-top:.75rem;padding:.6rem .8rem;border:1.5px dashed rgba(184,115,51,.3);border-radius:8px;background:rgba(184,115,51,.03);">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.4rem;">
                <input type="checkbox" id="parteEhCliente" onchange="toggleVincularCliente(this.checked)" style="width:16px;height:16px;">
                <label for="parteEhCliente" style="font-size:.78rem;font-weight:600;color:#B87333;cursor:pointer;">Esta parte é nosso cliente</label>
                <span id="parteClienteNome" style="font-size:.72rem;color:#059669;font-weight:600;"></span>
            </div>
            <div id="parteClienteBusca" style="display:none;">
                <div style="position:relative;">
                    <input type="text" id="parteClienteBuscaInput" class="form-input" style="font-size:.82rem;" placeholder="Buscar cliente por nome..." autocomplete="off" oninput="buscarClienteParaVincular(this.value)">
                    <div id="parteClienteResultados" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border);border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;z-index:60;box-shadow:0 4px 16px rgba(0,0,0,.15);"></div>
                </div>
            </div>
        </div>

        <div style="margin-top:.5rem;"><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Observações</label><textarea id="parteObs" class="form-input" style="font-size:.85rem;" rows="2"></textarea></div>
    </div>
    <div style="padding:.8rem 1.2rem;border-top:1px solid var(--border);display:flex;justify-content:space-between;">
        <button id="parteBtnDel" onclick="excluirParte()" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#dc2626;display:none;">Excluir</button>
        <div style="display:flex;gap:.5rem;margin-left:auto;">
            <button onclick="fecharModalParte()" class="btn btn-outline btn-sm">Cancelar</button>
            <button onclick="salvarParte()" class="btn btn-primary btn-sm">Salvar</button>
        </div>
    </div>
</div>
</div>

<!-- Documentos Pendentes / Recebidos -->
<?php if (!empty($docsPendentes) || !empty($docsRecebidos)): ?>
<div class="card mb-2">
    <div class="card-header">
        <h3>📄 Documentos Solicitados (<?= count($docsPendentes) ?> pendente<?= count($docsPendentes) !== 1 ? 's' : '' ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (!empty($docsPendentes)): ?>
            <?php foreach ($docsPendentes as $dp): ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.65rem .85rem;margin-bottom:.4rem;background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;">
                <span style="font-size:1.1rem;">⚠️</span>
                <div style="flex:1;">
                    <div style="font-size:.88rem;font-weight:700;color:#dc2626;"><?= e($dp['descricao']) ?></div>
                    <div style="font-size:.68rem;color:#6b7280;">Solicitado por <?= e($dp['solicitante_name'] ?: '—') ?> em <?= date('d/m/Y H:i', strtotime($dp['solicitado_em'])) ?></div>
                </div>
                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="resolve_doc">
                    <input type="hidden" name="doc_id" value="<?= $dp['id'] ?>">
                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                    <button type="submit" class="btn btn-success btn-sm" style="font-size:.72rem;" data-confirm="Confirmar que este documento foi recebido?">✓ Recebido</button>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (!empty($docsRecebidos)): ?>
            <div style="margin-top:<?= !empty($docsPendentes) ? '.75rem' : '0' ?>;<?= !empty($docsPendentes) ? 'padding-top:.75rem;border-top:1px solid var(--border);' : '' ?>">
                <p style="font-size:.72rem;font-weight:700;color:var(--text-muted);margin-bottom:.35rem;">Recebidos:</p>
                <?php foreach ($docsRecebidos as $dr): ?>
                <div style="display:flex;align-items:center;gap:.5rem;padding:.35rem 0;font-size:.78rem;color:var(--text-muted);">
                    <span style="color:#059669;">✓</span>
                    <span style="text-decoration:line-through;"><?= e($dr['descricao']) ?></span>
                    <span style="font-size:.65rem;">— recebido em <?= date('d/m H:i', strtotime($dr['recebido_em'])) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- GED — Documentos para o Cliente (Central VIP) -->
<div class="card mb-2">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>📁 Documentos para o Cliente (<?= count($docsGed) ?>)</h3>
        <button type="button" onclick="document.getElementById('gedUploadForm').style.display=document.getElementById('gedUploadForm').style.display==='none'?'block':'none'" class="btn btn-primary btn-sm" style="font-size:.72rem;">+ Enviar Documento</button>
    </div>
    <div class="card-body">
        <!-- Upload Form (oculto por padrão) -->
        <div id="gedUploadForm" style="display:none;margin-bottom:1rem;padding:1rem;background:var(--bg-card);border:1.5px solid var(--rose);border-radius:var(--radius);;">
            <form method="POST" action="<?= module_url('salavip', 'ged.php') ?>" enctype="multipart/form-data">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="upload">
                <input type="hidden" name="client_id" value="<?= (int)($case['client_id'] ?: 0) ?>">
                <input type="hidden" name="case_id" value="<?= $caseId ?>">
                <input type="hidden" name="from_case" value="<?= $caseId ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem;">
                    <div>
                        <label class="form-label" style="font-size:.72rem;">Título *</label>
                        <input type="text" name="titulo" class="form-control" required placeholder="Ex: Procuração, Decisão..." style="font-size:.82rem;">
                    </div>
                    <div>
                        <label class="form-label" style="font-size:.72rem;">Categoria</label>
                        <select name="categoria" class="form-control" style="font-size:.82rem;">
                            <?php foreach (array('Procuração','Contrato','Petição','Decisão','Sentença','Certidão','Comprovante','Acordo','Parecer','Outro') as $cat): ?>
                            <option value="<?= $cat ?>"><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom:.5rem;">
                    <label class="form-label" style="font-size:.72rem;">Arquivo * (PDF, JPG, PNG, DOCX — máx 10MB)</label>
                    <input type="file" name="arquivo" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.docx" style="font-size:.82rem;">
                </div>
                <div style="display:flex;gap:.75rem;align-items:center;">
                    <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;cursor:pointer;">
                        <input type="checkbox" name="visivel_cliente" value="1" checked> Visível na Central VIP
                    </label>
                    <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;">Enviar</button>
                </div>
            </form>
        </div>

        <?php if (empty($docsGed)): ?>
            <p class="text-muted text-sm">Nenhum documento compartilhado com o cliente neste processo.</p>
        <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:.4rem;">
                <?php foreach ($docsGed as $ged): ?>
                <?php
                $totalViews = isset($ged['total_visualizacoes']) ? (int)$ged['total_visualizacoes'] : 0;
                $ultimaView = isset($ged['ultima_visualizacao']) ? $ged['ultima_visualizacao'] : null;
                ?>
                <div style="display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;border-bottom:1px solid var(--border);font-size:.82rem;">
                    <span style="font-size:1rem;">📄</span>
                    <div style="flex:1;min-width:0;">
                        <div style="font-weight:600;color:var(--petrol-900);"><?= e($ged['titulo']) ?></div>
                        <div style="font-size:.7rem;color:var(--text-muted);">
                            <?= e($ged['categoria'] ?? '') ?> · <?= e($ged['arquivo_nome'] ?? '') ?> · <?= date('d/m/Y', strtotime($ged['compartilhado_em'])) ?>
                            <?php if ($ged['user_name']): ?> · <?= e(explode(' ', $ged['user_name'])[0]) ?><?php endif; ?>
                        </div>
                    </div>
                    <?php if ($totalViews > 0): ?>
                        <span style="font-size:.62rem;padding:2px 6px;border-radius:4px;font-weight:700;background:#ecfdf5;color:#059669;" title="<?= $ultimaView ? 'Último acesso: ' . date('d/m/Y H:i', strtotime($ultimaView)) : '' ?>">
                            ✓ Visualizado <?= $totalViews ?>x
                        </span>
                    <?php elseif ($ged['visivel_cliente']): ?>
                        <span style="font-size:.62rem;padding:2px 6px;border-radius:4px;font-weight:700;background:#fef3c7;color:#92400e;" title="Cliente ainda não visualizou">
                            ⏳ Não visto
                        </span>
                    <?php endif; ?>
                    <span style="font-size:.65rem;padding:2px 6px;border-radius:4px;font-weight:600;<?= $ged['visivel_cliente'] ? 'background:#ecfdf5;color:#059669;' : 'background:#fef2f2;color:#dc2626;' ?>">
                        <?= $ged['visivel_cliente'] ? '👁 Visível' : '🔒 Oculto' ?>
                    </span>
                    <a href="<?= module_url('salavip', 'download.php?id=' . $ged['id']) ?>" target="_blank" style="background:none;border:none;cursor:pointer;font-size:.9rem;color:#0d9488;text-decoration:none;" title="Ver arquivo">📥</a>
                    <form method="POST" action="<?= module_url('salavip', 'ged.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="toggle_visivel">
                        <input type="hidden" name="id" value="<?= $ged['id'] ?>">
                        <input type="hidden" name="from_case" value="<?= $caseId ?>">
                        <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.9rem;color:var(--text-muted);" title="Alternar visibilidade">&#128260;</button>
                    </form>
                    <form method="POST" action="<?= module_url('salavip', 'ged.php') ?>" style="display:inline;" onsubmit="return confirm('Excluir este documento? Esta ação não pode ser desfeita.');">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="excluir_ged">
                        <input type="hidden" name="id" value="<?= $ged['id'] ?>">
                        <input type="hidden" name="from_case" value="<?= $caseId ?>">
                        <button type="submit" style="background:none;border:none;cursor:pointer;font-size:.9rem;color:#dc2626;" title="Excluir">🗑️</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Status e Informações -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">
    <!-- Alterar status -->
    <div class="card">
        <div class="card-header"><h3>Status</h3></div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="case_id" value="<?= $case['id'] ?>">
                <div class="form-group" style="margin:0;">
                    <select name="status" class="form-select" id="selectStatus" onchange="this.form.submit()" style="border-left:4px solid <?= $corStatus ?>;font-weight:700;">
                        <?php foreach ($statusLabels as $k => $v):
                            $cor = isset($statusCores[$k]) ? $statusCores[$k] : '#888';
                        ?>
                            <option value="<?= $k ?>" <?= $case['status'] === $k ? 'selected' : '' ?> style="color:<?= $cor ?>;font-weight:700;"><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <!-- Alterar prioridade e responsável -->
    <div class="card">
        <div class="card-header"><h3>Prioridade / Responsável</h3></div>
        <div class="card-body">
            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:flex;gap:.5rem;">
                <?= csrf_input() ?>
                <input type="hidden" name="action" value="update_case_info">
                <input type="hidden" name="case_id" value="<?= $case['id'] ?>">
                <select name="priority" class="form-select" style="flex:1;">
                    <option value="baixa" <?= $case['priority'] === 'baixa' ? 'selected' : '' ?>>Baixa</option>
                    <option value="normal" <?= $case['priority'] === 'normal' ? 'selected' : '' ?>>Normal</option>
                    <option value="alta" <?= $case['priority'] === 'alta' ? 'selected' : '' ?>>Alta</option>
                    <option value="urgente" <?= $case['priority'] === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                </select>
                <select name="responsible_user_id" class="form-select" style="flex:1;">
                    <option value="">Sem resp.</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= (int)$case['responsible_user_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">Salvar</button>
            </form>
        </div>
    </div>
</div>

<!-- Parceria -->
<?php
$parceirosAtivos = array();
try { $parceirosAtivos = $pdo->query("SELECT id, nome FROM parceiros WHERE ativo = 1 ORDER BY nome")->fetchAll(); } catch (Exception $e) {}
$parceiroAtual = null;
if (!empty($case['parceiro_id'])) {
    try { $stmtP = $pdo->prepare("SELECT id, nome FROM parceiros WHERE id = ?"); $stmtP->execute(array($case['parceiro_id'])); $parceiroAtual = $stmtP->fetch(); } catch (Exception $e) {}
}
$isParceria = !empty($case['is_parceria']);
$parceriaExecutor = isset($case['parceria_executor']) ? $case['parceria_executor'] : '';
?>
<div class="card mb-2">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>🤝 Parceria</h3>
        <?php if ($isParceria && $parceiroAtual): ?>
            <span style="font-size:.72rem;font-weight:700;color:#17A589;">Parceria ativa — <?= e($parceiroAtual['nome']) ?></span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="update_parceria">
            <input type="hidden" name="case_id" value="<?= $caseId ?>">

            <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.75rem;">
                <label style="font-size:.82rem;font-weight:700;color:var(--petrol-900);white-space:nowrap;">É parceria?</label>
                <select name="is_parceria" id="isParceriaSelect" class="form-select" style="width:80px;" onchange="toggleParceriaFields()">
                    <option value="0" <?= !$isParceria ? 'selected' : '' ?>>Não</option>
                    <option value="1" <?= $isParceria ? 'selected' : '' ?>>Sim</option>
                </select>
            </div>

            <div id="parceriaFields" style="<?= $isParceria ? '' : 'display:none;' ?>">
                <div style="display:flex;gap:.75rem;margin-bottom:.75rem;flex-wrap:wrap;">
                    <div style="flex:1;min-width:200px;">
                        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;">Parceiro</label>
                        <select name="parceiro_id" id="parceiroSelect" class="form-select" style="font-size:.85rem;">
                            <option value="">— Selecionar —</option>
                            <?php foreach ($parceirosAtivos as $pa): ?>
                                <option value="<?= $pa['id'] ?>" <?= (int)($case['parceiro_id'] ?? 0) === (int)$pa['id'] ? 'selected' : '' ?>><?= e($pa['nome']) ?></option>
                            <?php endforeach; ?>
                            <option value="_novo">+ Cadastrar novo parceiro</option>
                        </select>
                    </div>
                    <div style="flex:1;min-width:200px;">
                        <label style="font-size:.72rem;font-weight:700;color:var(--text-muted);display:block;margin-bottom:.2rem;">Quem está executando?</label>
                        <select name="parceria_executor" class="form-select" style="font-size:.85rem;">
                            <option value="">— Selecionar —</option>
                            <option value="fes" <?= $parceriaExecutor === 'fes' ? 'selected' : '' ?>>Ferreira & Sá</option>
                            <option value="parceiro" <?= $parceriaExecutor === 'parceiro' ? 'selected' : '' ?>>O Parceiro</option>
                        </select>
                    </div>
                </div>

                <!-- Cadastro rápido de parceiro -->
                <div id="novoParcBox" style="display:none;background:#f0fdf4;border:1.5px solid #a7f3d0;border-radius:8px;padding:.75rem;margin-bottom:.75rem;">
                    <label style="font-size:.72rem;font-weight:700;color:#059669;display:block;margin-bottom:.3rem;">Nome do novo parceiro *</label>
                    <input type="text" name="novo_parceiro_nome" id="novoParcNome" class="form-input" style="font-size:.85rem;" placeholder="Nome completo do advogado parceiro">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-sm">Salvar parceria</button>
        </form>
    </div>
</div>

<script>
function toggleParceriaFields() {
    var v = document.getElementById('isParceriaSelect').value;
    document.getElementById('parceriaFields').style.display = v === '1' ? '' : 'none';
}
document.getElementById('parceiroSelect').addEventListener('change', function() {
    document.getElementById('novoParcBox').style.display = this.value === '_novo' ? '' : 'none';
});
</script>

<!-- Dados do Processo (editável inline) -->
<div class="card mb-2">
    <div class="card-header">
        <h3>Dados do Processo</h3>
        <span style="font-size:.68rem;color:var(--text-muted);">Clique em qualquer campo para editar</span>
    </div>
    <div class="card-body" style="padding:.75rem 1rem;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
            <?php
            $camposProcesso = array(
                array('label' => 'Número do Processo', 'field' => 'case_number', 'value' => $case['case_number'] ?? '', 'type' => 'text', 'placeholder' => '0000000-00.0000.0.00.0000'),
                array('label' => 'Tipo de Ação', 'field' => 'case_type', 'value' => $case['case_type'] ?? '', 'type' => 'text', 'placeholder' => 'Ex: Alimentos, Divórcio...'),
                array('label' => 'Vara / Juízo', 'field' => 'court', 'value' => $case['court'] ?? '', 'type' => 'text', 'placeholder' => 'Ex: 1ª Vara de Família'),
                array('label' => 'Comarca', 'field' => 'comarca', 'value' => $case['comarca'] ?? '', 'type' => 'text', 'placeholder' => 'Ex: Barra Mansa'),
                array('label' => 'UF', 'field' => 'comarca_uf', 'value' => $case['comarca_uf'] ?? '', 'type' => 'text', 'placeholder' => 'RJ'),
                array('label' => 'Regional', 'field' => 'regional', 'value' => $case['regional'] ?? '', 'type' => 'text', 'placeholder' => 'Ex: Barra Mansa'),
                array('label' => 'Sistema Tribunal', 'field' => 'sistema_tribunal', 'value' => $case['sistema_tribunal'] ?? '', 'type' => 'text', 'placeholder' => 'Ex: PJe TJRJ'),
                array('label' => 'Data Distribuição', 'field' => 'distribution_date', 'value' => $case['distribution_date'] ?? '', 'type' => 'date', 'placeholder' => ''),
                array('label' => 'Link Google Drive', 'field' => 'drive_folder_url', 'value' => $case['drive_folder_url'] ?? '', 'type' => 'url', 'placeholder' => 'https://drive.google.com/...'),
            );
            foreach ($camposProcesso as $cp):
            ?>
            <div style="display:flex;align-items:center;padding:.45rem .6rem;border-bottom:1px solid var(--border);" class="campo-proc-row">
                <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);min-width:140px;flex-shrink:0;"><?= $cp['label'] ?></label>
                <input type="<?= $cp['type'] ?>" value="<?= e($cp['value']) ?>"
                       data-id="<?= $caseId ?>" data-field="<?= $cp['field'] ?>"
                       onchange="salvarCampoProcesso(this)"
                       placeholder="<?= e($cp['placeholder']) ?>"
                       style="flex:1;border:none;background:transparent;font-size:.82rem;color:var(--text);padding:.2rem .4rem;font-family:inherit;outline:none;min-width:0;"
                       onfocus="this.style.background='#eff6ff';this.style.borderRadius='4px'"
                       onblur="this.style.background='transparent'">
                <?php if ($cp['field'] === 'drive_folder_url' && !empty($cp['value'])): ?>
                    <a href="<?= e($cp['value']) ?>" target="_blank" rel="noopener" title="Abrir pasta no Google Drive"
                       style="display:inline-flex;align-items:center;gap:4px;background:#4285f4;color:#fff;padding:4px 10px;border-radius:6px;font-size:.72rem;font-weight:700;text-decoration:none;flex-shrink:0;margin-left:.4rem;">
                        📁 Abrir
                    </a>
                    <button type="button" onclick="copiarLinkDrive(this, '<?= e($cp['value']) ?>')" title="Copiar link pra colar em mensagem"
                            style="background:#6b7280;color:#fff;padding:4px 10px;border-radius:6px;font-size:.72rem;font-weight:700;border:none;cursor:pointer;flex-shrink:0;margin-left:.3rem;">
                        📋 Copiar
                    </button>
                    <a href="https://app.zapsign.com.br" target="_blank" rel="noopener" title="Abrir ZapSign em nova aba para enviar documento para assinatura"
                       style="display:inline-flex;align-items:center;gap:4px;background:#7c3aed;color:#fff;padding:4px 10px;border-radius:6px;font-size:.72rem;font-weight:700;text-decoration:none;flex-shrink:0;margin-left:.3rem;">
                        ✍️ ZapSign
                    </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- Segredo de justiça -->
        <div style="display:flex;align-items:center;padding:.45rem .6rem;gap:.5rem;">
            <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);min-width:140px;">🔒 Segredo de Justiça</label>
            <select onchange="salvarCampoProcesso({dataset:{id:'<?= $caseId ?>',field:'segredo_justica'},value:this.value});this.style.background=this.value==='1'?'#fee2e2':'#f9fafb';this.style.color=this.value==='1'?'#991b1b':'var(--text-muted)';"
                    style="padding:3px 10px;border:1px solid var(--border);border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;background:<?= !empty($case['segredo_justica']) ? '#fee2e2' : '#f9fafb' ?>;color:<?= !empty($case['segredo_justica']) ? '#991b1b' : 'var(--text-muted)' ?>;">
                <option value="0" <?= empty($case['segredo_justica']) ? 'selected' : '' ?>>Não (processo público)</option>
                <option value="1" <?= !empty($case['segredo_justica']) ? 'selected' : '' ?>>🔒 Sim (sob segredo)</option>
            </select>
        </div>
        <!-- Pro Bono -->
        <div style="display:flex;align-items:center;padding:.45rem .6rem;gap:.5rem;border-top:1px solid var(--border);">
            <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);min-width:140px;">🤝 Pro Bono</label>
            <select onchange="salvarCampoProcesso({dataset:{id:'<?= $caseId ?>',field:'pro_bono'},value:this.value});this.style.background=this.value==='1'?'#dcfce7':'#f9fafb';this.style.color=this.value==='1'?'#166534':'var(--text-muted)';"
                    style="padding:3px 10px;border:1px solid var(--border);border-radius:6px;font-size:.78rem;font-weight:700;cursor:pointer;background:<?= !empty($case['pro_bono']) ? '#dcfce7' : '#f9fafb' ?>;color:<?= !empty($case['pro_bono']) ? '#166534' : 'var(--text-muted)' ?>;">
                <option value="0" <?= empty($case['pro_bono']) ? 'selected' : '' ?>>Não (cobrado)</option>
                <option value="1" <?= !empty($case['pro_bono']) ? 'selected' : '' ?>>✓ Sim (gratuito)</option>
            </select>
        </div>

        <!-- Desfecho do Processo (afeta cobrança de honorários) -->
        <div style="display:flex;align-items:center;padding:.45rem .6rem;gap:.5rem;border-top:1px solid var(--border);">
            <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);min-width:140px;" title="Usado pelo Kanban de Cobrança de Honorários para alertar quando a cobrança é questionável">🏁 Desfecho</label>
            <?php
            $desfechos = case_desfechos();
            $desfAtual = $case['desfecho_processo'] ?? 'em_andamento';
            $desfInfo = $desfechos[$desfAtual] ?? $desfechos['em_andamento'];
            ?>
            <select data-id="<?= $caseId ?>" data-field="desfecho_processo"
                    onchange="salvarCampoProcesso(this); document.getElementById('avisoDesf').style.display = ['extinto_sem_julgamento','desistencia'].indexOf(this.value) !== -1 ? 'block' : 'none';"
                    style="border:1px solid var(--border);background:<?= $desfInfo['cor'] ?>15;color:<?= $desfInfo['cor'] ?>;font-size:.78rem;padding:3px 10px;border-radius:6px;font-weight:700;cursor:pointer;">
                <?php foreach ($desfechos as $dk => $di): ?>
                <option value="<?= $dk ?>" <?= $desfAtual === $dk ? 'selected' : '' ?>><?= e($di['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$desfInfo['cobravel']): ?>
                <span style="font-size:.68rem;color:#dc2626;font-weight:700;">⚠️ Avaliar cobrança</span>
            <?php endif; ?>
        </div>
        <?php $mostrarAviso = in_array($desfAtual, array('extinto_sem_julgamento','desistencia'), true); ?>
        <div id="avisoDesf" style="<?= $mostrarAviso ? '' : 'display:none;' ?>margin:.2rem .6rem .5rem;padding:.6rem .8rem;background:#fef2f2;border-left:4px solid #dc2626;border-radius:6px;font-size:.78rem;color:#991b1b;">
            <strong>Atenção:</strong> Este desfecho <strong>pode impedir ou limitar a cobrança de honorários contratuais</strong>. Antes de movimentar cobrança (notif. 1/2/extrajudicial/judicial) consulte o contrato e a jurisprudência aplicável.
        </div>
        <!-- Observações -->
        <div style="padding:.45rem .6rem;border-top:1px solid var(--border);">
            <label style="font-size:.75rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:.3rem;">Observações</label>
            <textarea data-id="<?= $caseId ?>" data-field="notes"
                      onchange="salvarCampoProcesso(this)"
                      placeholder="Observações internas..."
                      style="width:100%;border:1px solid var(--border);background:transparent;font-size:.82rem;color:var(--text);padding:.4rem .6rem;font-family:inherit;border-radius:6px;resize:vertical;min-height:40px;"
                      onfocus="this.style.borderColor='#3b82f6'"
                      onblur="this.style.borderColor='var(--border)'"><?= e($case['notes'] ?? '') ?></textarea>
        </div>
    </div>
</div>

<!-- Ofícios Enviados -->
<?php if (!empty($oficiosEnviados) || true): // sempre aparece pra permitir criar direto ?>
<div class="card mb-2">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <h3>📬 Ofícios enviados (<?= count($oficiosEnviados) ?>)</h3>
        <a href="<?= module_url('oficios', 'novo_oficio.php?case_id=' . $caseId) ?>" class="btn btn-primary btn-sm" style="font-size:.72rem;background:#7c3aed;">+ Novo ofício</a>
    </div>
    <div class="card-body">
        <?php if (empty($oficiosEnviados)): ?>
            <div style="text-align:center;color:var(--text-muted);padding:1rem;font-size:.82rem;">Nenhum ofício enviado pra este processo ainda.</div>
        <?php else: ?>
            <?php foreach ($oficiosEnviados as $of):
                $diasDesde = $of['data_envio'] ? (int)((strtotime('today') - strtotime($of['data_envio'])) / 86400) : 0;
                $semAR = !$of['retorno_ar'] && $diasDesde > 15;
                $_st = $of['status_oficio'] ?? 'aguardando_contato_rh';
                $_stM = $_ofStatusMeta[$_st] ?? array($_st, '#6b7280');
                $_hist = $oficiosHistorico[(int)$of['id']] ?? array();
            ?>
            <div style="border:1px solid var(--border);border-radius:8px;margin-bottom:.6rem;<?= $semAR ? 'background:#fef2f2;' : 'background:#fff;' ?>">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;padding:.55rem .85rem;flex-wrap:wrap;">
                    <div style="flex:1;min-width:240px;">
                        <div style="font-weight:700;color:var(--petrol-900);font-size:.88rem;"><?= e($of['empregador'] ?: '—') ?></div>
                        <div style="font-size:.7rem;color:var(--text-muted);margin-top:.15rem;">
                            <?= $of['funcionario_nome'] ? e($of['funcionario_nome']) . ' · ' : '' ?>
                            <?= $of['data_envio'] ? '📅 ' . date('d/m/Y', strtotime($of['data_envio'])) : '' ?>
                            <?= $of['plataforma'] ? ' · ' . e(strtoupper($of['plataforma'])) : '' ?>
                        </div>
                    </div>
                    <span style="background:<?= e($_stM[1]) ?>;color:#fff;padding:3px 10px;border-radius:12px;font-size:.68rem;font-weight:700;white-space:nowrap;"><?= e($_stM[0]) ?></span>
                    <?php if ($of['retorno_ar']): ?>
                        <span style="background:#059669;color:#fff;padding:3px 8px;border-radius:4px;font-size:.66rem;font-weight:700;">✓ AR <?= e($of['retorno_ar']) ?></span>
                    <?php elseif ($semAR): ?>
                        <span style="background:#dc2626;color:#fff;padding:3px 8px;border-radius:4px;font-size:.66rem;font-weight:700;">⚠️ Sem AR +<?= $diasDesde ?>d</span>
                    <?php endif; ?>
                    <button type="button" onclick="var b=document.getElementById('ofHist<?= (int)$of['id'] ?>');b.style.display=b.style.display==='none'?'block':'none';this.textContent=b.style.display==='none'?'▼ Linha do tempo ('+<?= count($_hist) ?>+')':'▲ Ocultar';" class="btn btn-outline btn-sm" style="font-size:.66rem;padding:2px 8px;">▼ Linha do tempo (<?= count($_hist) ?>)</button>
                    <a href="<?= module_url('oficios', 'novo_oficio.php?id=' . (int)$of['id']) ?>" class="btn btn-primary btn-sm" style="font-size:.66rem;padding:2px 8px;background:#3730a3;">✏️ Abrir</a>
                </div>

                <!-- Timeline expansível -->
                <div id="ofHist<?= (int)$of['id'] ?>" style="display:none;border-top:1px solid #f0f0f0;padding:.75rem 1rem;background:#fafbfc;">
                    <?php if (empty($_hist)): ?>
                        <div style="font-size:.78rem;color:var(--text-muted);text-align:center;padding:.4rem;">Nenhum evento registrado ainda. <a href="<?= module_url('oficios', 'novo_oficio.php?id=' . (int)$of['id']) ?>" style="color:#B87333;">Abrir ofício pra adicionar eventos →</a></div>
                    <?php else: ?>
                        <div style="position:relative;padding-left:1.4rem;">
                            <div style="position:absolute;left:.45rem;top:.3rem;bottom:.3rem;width:2px;background:#e5e7eb;"></div>
                            <?php foreach ($_hist as $h):
                                $_tm = $_ofTipoMeta[$h['tipo']] ?? array('📝', $h['tipo']);
                            ?>
                            <div style="position:relative;margin-bottom:.55rem;">
                                <div style="position:absolute;left:-1.15rem;top:0;background:#fff;border:2px solid #B87333;border-radius:50%;width:14px;height:14px;display:flex;align-items:center;justify-content:center;font-size:.62rem;"><?= $_tm[0] ?></div>
                                <div style="padding:.1rem 0;">
                                    <div style="font-size:.78rem;"><b><?= e($_tm[1]) ?></b> <span style="color:var(--text-muted);font-size:.68rem;"> · <?= date('d/m/Y H:i', strtotime($h['created_at'])) ?><?= $h['user_name'] ? ' · ' . e(explode(' ', $h['user_name'])[0]) : '' ?></span></div>
                                    <?php if ($h['descricao']): ?>
                                        <div style="font-size:.76rem;color:#374151;margin-top:.1rem;white-space:pre-wrap;"><?= e($h['descricao']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="text-align:right;margin-top:.35rem;">
                            <a href="<?= module_url('oficios', 'novo_oficio.php?id=' . (int)$of['id']) ?>" style="font-size:.7rem;color:#B87333;">+ Adicionar evento →</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Prazos Processuais -->
<?php
$prazosCase = array();
try {
    $stmtPrazos = $pdo->prepare(
        "SELECT pp.*, u.name as user_name FROM prazos_processuais pp
         LEFT JOIN users u ON u.id = pp.usuario_id
         WHERE pp.case_id = ? ORDER BY pp.prazo_fatal ASC"
    );
    $stmtPrazos->execute(array($caseId));
    $prazosCase = $stmtPrazos->fetchAll();
} catch (Exception $e) {}

$prazosAtivos = array_filter($prazosCase, function($p) { return empty($p['concluido']); });
$prazosConcluidos = array_filter($prazosCase, function($p) { return !empty($p['concluido']); });
?>
<div class="card mb-2">
    <div class="card-header">
        <h3>Prazos (<?= count($prazosAtivos) ?> ativo<?= count($prazosAtivos) !== 1 ? 's' : '' ?>)</h3>
        <div style="display:flex;gap:.5rem;">
            <a href="<?= module_url('operacional', 'prazos_calc.php?case_id=' . $caseId) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Calculadora de Prazos</a>
        </div>
    </div>
    <div class="card-body">
        <!-- Formulário novo prazo rápido -->
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border);">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_prazo">
            <input type="hidden" name="case_id" value="<?= $caseId ?>">
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
                <div style="display:flex;flex-direction:column;gap:2px;">
                    <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Tipo</label>
                    <input type="text" name="tipo" class="form-input" style="width:180px;" placeholder="Ex: Contestação, Recurso..." required>
                </div>
                <div style="display:flex;flex-direction:column;gap:2px;">
                    <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Data fatal</label>
                    <input type="date" name="prazo_fatal" class="form-input" style="width:150px;" required>
                </div>
                <div style="display:flex;flex-direction:column;gap:2px;">
                    <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Descrição (opcional)</label>
                    <input type="text" name="descricao" class="form-input" style="width:220px;" placeholder="Detalhes do prazo...">
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="background:#dc2626;">+ Prazo</button>
            </div>
        </form>

        <?php if (empty($prazosCase)): ?>
            <p class="text-muted text-sm" style="text-align:center;padding:.5rem;">Nenhum prazo cadastrado. Use o botão acima ou a Calculadora de Prazos.</p>
        <?php else: ?>
            <?php foreach ($prazosAtivos as $pz):
                $diasFalta = (int)((strtotime($pz['prazo_fatal']) - time()) / 86400);
                $corPrazo = '#059669'; $bgPrazo = '#ecfdf5';
                if ($diasFalta < 0) { $corPrazo = '#dc2626'; $bgPrazo = '#fef2f2'; }
                elseif ($diasFalta <= 3) { $corPrazo = '#d97706'; $bgPrazo = '#fef3c7'; }
            ?>
            <div style="display:flex;align-items:center;gap:.75rem;padding:.6rem .8rem;border-bottom:1px solid var(--border);">
                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="concluir_prazo">
                    <input type="hidden" name="prazo_id" value="<?= $pz['id'] ?>">
                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                    <button type="submit" title="Marcar como concluído" style="width:20px;height:20px;border-radius:50%;border:2px solid <?= $corPrazo ?>;background:transparent;cursor:pointer;flex-shrink:0;"></button>
                </form>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:.85rem;font-weight:600;color:var(--text);"><?= e($pz['tipo'] ?: $pz['descricao_acao'] ?: 'Prazo') ?></div>
                    <?php if ($pz['descricao'] ?: ($pz['descricao_acao'] ?? '')): ?>
                        <div style="font-size:.72rem;color:var(--text-muted);"><?= e($pz['descricao'] ?: $pz['descricao_acao']) ?></div>
                    <?php endif; ?>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                    <div style="font-size:.82rem;font-weight:700;color:<?= $corPrazo ?>;background:<?= $bgPrazo ?>;padding:2px 8px;border-radius:4px;">
                        <?= date('d/m/Y', strtotime($pz['prazo_fatal'])) ?>
                    </div>
                    <div style="font-size:.65rem;font-weight:600;color:<?= $corPrazo ?>;margin-top:2px;">
                        <?php if ($diasFalta < 0): ?>
                            Vencido há <?= abs($diasFalta) ?>d
                        <?php elseif ($diasFalta === 0): ?>
                            HOJE
                        <?php else: ?>
                            <?= $diasFalta ?>d restante<?= $diasFalta > 1 ? 's' : '' ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($prazosConcluidos)): ?>
            <div style="margin-top:.75rem;padding-top:.5rem;border-top:1px dashed var(--border);">
                <button type="button" onclick="var el=document.getElementById('prazosConcluidos');el.style.display=el.style.display==='none'?'block':'none';this.querySelector('.chv').textContent=el.style.display==='none'?'▸':'▾';" style="background:none;border:none;font-size:.75rem;color:#059669;cursor:pointer;font-family:inherit;font-weight:600;display:flex;align-items:center;gap:.3rem;">
                    <span class="chv">▸</span> Prazos cumpridos (<?= count($prazosConcluidos) ?>)
                </button>
                <div id="prazosConcluidos" style="display:none;margin-top:.3rem;">
                    <?php foreach ($prazosConcluidos as $pz): ?>
                    <div style="display:flex;align-items:center;gap:.75rem;padding:.5rem .8rem;border-bottom:1px solid rgba(0,0,0,.04);">
                        <span style="width:20px;height:20px;border-radius:50%;background:#059669;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.6rem;flex-shrink:0;">&#10003;</span>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:.8rem;text-decoration:line-through;color:var(--text-muted);"><?= e($pz['tipo'] ?: $pz['descricao_acao'] ?: 'Prazo') ?></div>
                            <?php if (!empty($pz['concluido_em'])): ?>
                            <div style="font-size:.65rem;color:#059669;">Cumprido em <?= date('d/m/Y H:i', strtotime($pz['concluido_em'])) ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="text-align:right;flex-shrink:0;">
                            <div style="font-size:.72rem;color:var(--text-muted);">Prazo: <?= date('d/m/Y', strtotime($pz['prazo_fatal'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Separar tarefas reais de checklist de documentos
$tarefasReais = array();
$checklistDocs = array();
foreach ($tasks as $t) {
    if (!empty($t['tipo']) && $t['tipo'] !== '') {
        $tarefasReais[] = $t;
    } else {
        $checklistDocs[] = $t;
    }
}
$checkDone = count(array_filter($checklistDocs, function($t){ return $t['status'] === 'concluido' || $t['status'] === 'feito'; }));
?>

<!-- Tarefas Operacionais -->
<?php
// Só destaca se tiver pelo menos UMA tarefa pendente (não concluída)
$temTarefasPendentes = false;
foreach ($tarefasReais as $_t) {
    $_s = $_t['status'] ?? '';
    if ($_s !== 'concluido' && $_s !== 'feito') { $temTarefasPendentes = true; break; }
}
?>
<div class="card mb-2" style="<?= $temTarefasPendentes ? 'border:2px solid #d97706;box-shadow:0 0 12px rgba(217,119,6,.15);' : '' ?>">
    <div class="card-header" style="<?= $temTarefasPendentes ? 'background:linear-gradient(135deg,rgba(217,119,6,.08),rgba(217,119,6,.02));' : '' ?>">
        <h3><?= $temTarefasPendentes ? '⚡ ' : '' ?>Tarefas (<?= count($tarefasReais) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($tarefasReais)): ?>
            <p class="text-muted text-sm">Nenhuma tarefa operacional.</p>
        <?php else: ?>
            <ul class="task-list">
                <?php foreach ($tarefasReais as $task): ?>
                <li class="task-item">
                    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="toggle_task">
                        <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                        <input type="hidden" name="case_id" value="<?= $caseId ?>">
                        <?php $isDone = ($task['status'] === 'concluido' || $task['status'] === 'feito'); ?>
                        <button type="submit" class="task-check <?= $isDone ? 'done' : '' ?>" title="<?= $isDone ? 'Desfazer' : 'Concluir' ?>">
                            <?= $isDone ? '✓' : '' ?>
                        </button>
                    </form>
                    <span class="task-text <?= $isDone ? 'done' : '' ?>"><?= e($task['title']) ?></span>
                    <span class="task-meta">
                        <?php if ($task['tipo']): ?><span style="font-size:.65rem;background:#eff6ff;color:#3b82f6;padding:1px 5px;border-radius:3px;font-weight:600;"><?= e($task['tipo']) ?></span><?php endif; ?>
                        <?php if ($task['assigned_name']): ?><?= e(explode(' ', $task['assigned_name'])[0]) ?><?php endif; ?>
                        <?php if ($task['due_date']): ?> · <?= data_br($task['due_date']) ?><?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- Adicionar tarefa -->
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:flex;gap:.5rem;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);flex-wrap:wrap;">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_task">
            <input type="hidden" name="case_id" value="<?= $caseId ?>">
            <input type="text" name="title" class="form-input" placeholder="Nova tarefa..." required style="flex:1;min-width:180px;">
            <select name="tipo" class="form-select" style="width:150px;" title="Tipo da tarefa">
                <option value="outros">Outros</option>
                <option value="peticionar">Peticionar</option>
                <option value="juntar_documento">Juntar Documento</option>
                <option value="prazo">Prazo Processual</option>
                <option value="oficio">Ofício</option>
                <option value="acordo">Acordo/Conciliação</option>
            </select>
            <select name="assigned_to" class="form-select" style="width:130px;">
                <option value="">Quem?</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="due_date" class="form-input" style="width:140px;">
            <button type="submit" class="btn btn-primary btn-sm">+</button>
        </form>
    </div>
</div>

<?php if (!empty($checklistDocs)): ?>
<!-- Checklist de Documentos (colapsável) -->
<div class="card mb-2">
    <div class="card-header" style="cursor:pointer;" onclick="document.getElementById('checkDocsBody').style.display=document.getElementById('checkDocsBody').style.display==='none'?'block':'none';this.querySelector('.chevron').textContent=document.getElementById('checkDocsBody').style.display==='none'?'&#9660;':'&#9650;'">
        <h3 style="display:flex;align-items:center;gap:.5rem;">
            Checklist de Documentos
            <span style="font-size:.75rem;font-weight:400;color:var(--text-muted);">(<?= $checkDone ?>/<?= count($checklistDocs) ?>)</span>
            <span class="chevron" style="font-size:.7rem;color:var(--text-muted);">&#9660;</span>
        </h3>
    </div>
    <div class="card-body" id="checkDocsBody" style="display:none;">
        <ul class="task-list">
            <?php foreach ($checklistDocs as $task): ?>
            <li class="task-item" id="taskItem<?= $task['id'] ?>">
                <?php $isDone = ($task['status'] === 'concluido' || $task['status'] === 'feito'); ?>
                <button type="button" class="task-check <?= $isDone ? 'done' : '' ?>" title="<?= $isDone ? 'Desfazer' : 'Concluir' ?>"
                    onclick="toggleCheckDoc(<?= $task['id'] ?>, this)">
                    <?= $isDone ? '✓' : '' ?>
                </button>
                <span class="task-text <?= $isDone ? 'done' : '' ?>"><?= e($task['title']) ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
        <script>
        function toggleCheckDoc(taskId, btn) {
            btn.disabled = true;
            var fd = new FormData();
            fd.append('action', 'toggle_task');
            fd.append('task_id', taskId);
            fd.append('case_id', '<?= $caseId ?>');
            fd.append('csrf_token', '<?= generate_csrf_token() ?>');
            fd.append('ajax', '1');
            fetch('<?= module_url('operacional', 'api.php') ?>', { method: 'POST', body: fd })
            .then(function(r) { return r.text(); })
            .then(function() {
                var isDone = btn.classList.contains('done');
                btn.classList.toggle('done');
                btn.textContent = isDone ? '' : '✓';
                var span = btn.nextElementSibling;
                if (span) span.classList.toggle('done');
                // Atualizar contador no header
                var items = document.querySelectorAll('#checkDocsBody .task-check.done');
                var total = document.querySelectorAll('#checkDocsBody .task-check');
                var header = document.querySelector('#checkDocsBody').parentElement.querySelector('.card-header span');
                if (header) header.textContent = '(' + items.length + '/' + total.length + ')';
                btn.disabled = false;
            })
            .catch(function() { btn.disabled = false; location.reload(); });
        }
        </script>
    </div>
</div>
<?php endif; ?>

<!-- Andamentos Processuais -->
<div class="card mb-2">
    <div class="card-header">
        <h3>Andamentos (<?= count($andamentos) ?>)</h3>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <?php if ($case['case_number']): ?>
            <button onclick="syncDataJud(this)" class="btn btn-outline btn-sm" style="font-size:.72rem;border-color:#052228;color:#052228;" id="btnSyncDJ">Sincronizar DataJud</button>
            <span id="djSyncStatus" style="font-size:.68rem;color:var(--text-muted);">
                <?php if ($case['datajud_ultima_sync']): ?>
                    Ultima sync: <?= date('d/m/Y H:i', strtotime($case['datajud_ultima_sync'])) ?>
                <?php else: ?>
                    Nunca sincronizado
                <?php endif; ?>
            </span>
            <?php else: ?>
            <button class="btn btn-outline btn-sm" style="font-size:.72rem;opacity:.5;" disabled title="Cadastre o numero do processo primeiro">Sincronizar DataJud</button>
            <span style="font-size:.68rem;color:#d97706;">Cadastre o numero do processo</span>
            <?php endif; ?>
            <a href="<?= module_url('operacional', 'importar_andamentos.php?case_id=' . $caseId) ?>" class="btn btn-outline btn-sm" style="font-size:.72rem;">Importar LegalOne</a>
        </div>
    </div>
    <div class="card-body">
        <!-- Formulario de publicacao -->
        <?php if (has_min_role('operacional') || has_min_role('gestao')): ?>
        <div style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:2px dashed #fca5a5;">
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;cursor:pointer;" onclick="togglePubForm()">
                <span style="font-size:.8rem;font-weight:700;color:#dc2626;">Lançar Publicação / Intimação</span>
                <span id="pubFormArrow" style="font-size:.7rem;color:#dc2626;">&#9660;</span>
            </div>
            <div id="pubFormWrap" style="display:none;">
                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add_publicacao">
                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                    <div style="display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap;">
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Data Disponibilização *</label>
                            <input type="date" name="data_disponibilizacao" class="form-input" value="<?= date('Y-m-d') ?>" required style="width:160px;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Data Publicação</label>
                            <input type="date" name="data_publicacao" class="form-input" style="width:160px;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Tipo</label>
                            <select name="tipo_publicacao" class="form-select" style="width:150px;" id="tipoPubSelect" onchange="sugerirPrazo(this.value)">
                                <option value="intimacao">Intimação</option>
                                <option value="citacao">Citação</option>
                                <option value="despacho">Despacho</option>
                                <option value="decisao">Decisão</option>
                                <option value="sentenca">Sentença</option>
                                <option value="acordao">Acórdão</option>
                                <option value="edital">Edital</option>
                                <option value="outro">Outro</option>
                            </select>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Prazo (dias úteis)</label>
                            <div style="display:flex;align-items:center;gap:4px;">
                                <input type="number" name="prazo_dias" id="prazoDiasInput" class="form-input" min="0" max="365" style="width:80px;" placeholder="0">
                                <span id="prazoSugestao" style="font-size:.65rem;color:#d97706;font-weight:600;"></span>
                            </div>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Tribunal</label>
                            <input type="text" name="tribunal" class="form-input" placeholder="Ex: TJRJ" style="width:120px;" value="<?= e($case['sistema_tribunal'] ?? '') ?>">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Caderno</label>
                            <input type="text" name="caderno" class="form-input" placeholder="Ex: Civel" style="width:120px;">
                        </div>
                    </div>
                    <textarea name="conteudo" class="form-input" rows="3"
                        placeholder="Cole aqui o texto completo da publicação/intimação..."
                        required style="width:100%;font-size:.83rem;margin-bottom:.5rem;border-color:#fca5a5;"></textarea>
                    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <label style="font-size:.75rem;display:flex;align-items:center;gap:4px;cursor:pointer;">
                            <input type="checkbox" name="visivel_cliente" value="1"> Visível ao cliente
                        </label>
                        <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;">
                            Registrar Publicação
                        </button>
                        <span style="font-size:.68rem;color:var(--text-muted);">
                            A data de disponibilização é o marco legal do prazo (art. 224 CPC)
                        </span>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Barra de ações: importação em lote + botão de novo andamento existente -->
        <div style="display:flex;justify-content:flex-end;margin-bottom:.5rem;">
            <button type="button" onclick="abrirImportAndamentos()" class="btn btn-outline btn-sm" style="background:#eff6ff;border-color:#bfdbfe;color:#1e40af;font-size:.78rem;" title="Colar bloco pipe-delimited com vários andamentos de uma vez (gerado por IA a partir dos autos)">
                📋 Importar em lote
            </button>
        </div>

        <!-- Formulario de novo andamento -->
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border);">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_andamento">
            <input type="hidden" name="case_id" value="<?= $caseId ?>">
            <div style="display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap;align-items:end;">
                <input type="date" name="data_andamento" class="form-input" value="<?= date('Y-m-d') ?>" required style="width:150px;">
                <input type="time" name="hora_andamento" class="form-input" value="<?= date('H:i') ?>" style="width:100px;" title="Horário do andamento">
                <select name="tipo" class="form-select" style="width:180px;">
                    <option value="movimentacao">Movimentação</option>
                    <option value="despacho">Despacho</option>
                    <option value="decisao">Decisão</option>
                    <option value="sentenca">Sentença</option>
                    <option value="audiencia">Audiência</option>
                    <option value="peticao_juntada">Petição juntada</option>
                    <option value="intimacao">Intimação</option>
                    <option value="citacao">Citação</option>
                    <option value="acordo">Acordo</option>
                    <option value="recurso">Recurso</option>
                    <option value="cumprimento">Cumprimento</option>
                    <option value="diligencia">Diligência</option>
                    <option value="observacao">Observação interna</option>
                </select>
                <button type="submit" class="btn btn-primary btn-sm">+ Adicionar</button>
                <label style="display:flex;align-items:center;gap:4px;font-size:.75rem;color:var(--text-muted);cursor:pointer;margin-left:.5rem;" title="Se marcado, o cliente NÃO verá este andamento na Central VIP">
                    <input type="checkbox" name="interno" value="1" style="width:15px;height:15px;">
                    <span>&#128274; Interno</span>
                </label>
            </div>
            <textarea name="descricao" class="form-input" rows="2" placeholder="Descreva o andamento..." required style="width:100%;font-size:.85rem;"></textarea>
        </form>

        <?php
        // Buscar publicacoes do caso
        $publicacoes = array();
        try {
            $stmtPubs = $pdo->prepare(
                "SELECT cp.*, u.name as criado_por_nome
                 FROM case_publicacoes cp
                 LEFT JOIN users u ON u.id = cp.criado_por
                 WHERE cp.case_id = ?
                 ORDER BY cp.data_disponibilizacao DESC, cp.created_at DESC"
            );
            $stmtPubs->execute(array($caseId));
            $publicacoes = $stmtPubs->fetchAll();
        } catch (Exception $e) {}
        ?>

        <!-- Filtros da timeline -->
        <div class="filtro-andamentos" id="filtroAndamentos">
            <button class="ativo" onclick="filtrarTimeline('todos', this)">Todos (<?= count($andamentos) + count($publicacoes) ?>)</button>
            <button onclick="filtrarTimeline('andamentos', this)">Andamentos (<?= count($andamentos) ?>)</button>
            <button onclick="filtrarTimeline('publicacoes', this)" style="<?= count($publicacoes) > 0 ? 'border-color:#dc2626;color:#dc2626;' : '' ?>">
                Publicações (<?= count($publicacoes) ?>)
            </button>
        </div>

        <!-- Publicacoes na timeline -->
        <?php if (!empty($publicacoes)): ?>
        <div id="blocoPublicacoes" style="position:relative;padding-left:24px;">
            <div style="position:absolute;left:8px;top:0;bottom:0;width:2px;background:#fca5a5;"></div>
            <?php foreach ($publicacoes as $pub):
                $diasRestantes = null;
                $classeVence = 'ok';
                if ($pub['data_prazo_fim'] && $pub['status_prazo'] !== 'descartado') {
                    $diasRestantes = (int)((strtotime($pub['data_prazo_fim']) - strtotime(date('Y-m-d'))) / 86400);
                    if ($diasRestantes < 0) $classeVence = 'vencido';
                    elseif ($diasRestantes <= 3) $classeVence = 'alerta';
                    else $classeVence = 'ok';
                }
            ?>
            <div class="andamento-item pub-item" data-tipo="publicacao" style="position:relative;margin-bottom:16px;padding-left:20px;">
                <div style="position:absolute;left:-20px;top:6px;width:18px;height:18px;border-radius:50%;background:#dc2626;display:flex;align-items:center;justify-content:center;font-size:10px;z-index:1;color:#fff;">P</div>
                <div style="background:#fff8f8;border:1px solid #fca5a5;border-radius:10px;padding:12px 16px;border-left:3px solid #dc2626;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                            <span class="pub-badge"><?= e(ucfirst($pub['tipo_publicacao'])) ?></span>
                            <span class="prazo-badge <?= e($pub['status_prazo']) ?>"><?= e(ucfirst($pub['status_prazo'])) ?></span>
                            <?php if ($pub['data_prazo_fim'] && $pub['status_prazo'] !== 'descartado'): ?>
                                <span class="prazo-vence <?= $classeVence ?>">
                                    <?php if ($classeVence === 'vencido'): ?>
                                        Vencido ha <?= abs($diasRestantes) ?> dia(s)
                                    <?php elseif ($classeVence === 'alerta'): ?>
                                        Vence em <?= $diasRestantes ?> dia(s)
                                    <?php else: ?>
                                        Vence <?= date('d/m/Y', strtotime($pub['data_prazo_fim'])) ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <span style="font-size:.7rem;color:var(--text-muted);">
                                Disponibilizado: <?= date('d/m/Y', strtotime($pub['data_disponibilizacao'])) ?>
                                <?php if ($pub['tribunal']): ?> &middot; <?= e($pub['tribunal']) ?><?php endif; ?>
                                <?php if ($pub['caderno']): ?> &middot; <?= e($pub['caderno']) ?><?php endif; ?>
                            </span>
                        </div>
                        <div style="display:flex;align-items:center;gap:6px;">
                            <span style="font-size:.68rem;color:var(--text-muted);"><?= e($pub['criado_por_nome'] ?? '') ?></span>
                            <?php if ($pub['status_prazo'] === 'pendente' && (has_min_role('operacional') || has_min_role('gestao'))): ?>
                            <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="confirmar_prazo_publicacao">
                                <input type="hidden" name="pub_id" value="<?= $pub['id'] ?>">
                                <input type="hidden" name="case_id" value="<?= $caseId ?>">
                                <button type="submit" style="font-size:.65rem;background:#059669;color:#fff;border:none;border-radius:4px;padding:2px 7px;cursor:pointer;">Confirmar prazo</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <p style="font-size:.83rem;margin:0;white-space:pre-wrap;line-height:1.5;color:#374151;"><?= e($pub['conteudo']) ?></p>
                    <?php if ($pub['fonte'] !== 'manual'): ?>
                    <div style="margin-top:4px;font-size:.65rem;color:#94a3b8;">Fonte: <?= e(strtoupper($pub['fonte'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (empty($andamentos) && empty($publicacoes)): ?>
            <p class="text-muted text-sm" style="text-align:center;padding:1rem;">Nenhum andamento registrado.</p>
        <?php else: ?>
            <div style="position:relative;padding-left:24px;">
                <!-- Linha vertical da timeline -->
                <div style="position:absolute;left:8px;top:0;bottom:0;width:2px;background:var(--border);"></div>

                <?php
                $tipoIcons = array(
                    'movimentacao'=>'📋','despacho'=>'📤','decisao'=>'⚖️','sentenca'=>'🏛️',
                    'audiencia'=>'🎤','peticao_juntada'=>'📎','intimacao'=>'📬','citacao'=>'📨',
                    'acordo'=>'🤝','recurso'=>'📑','cumprimento'=>'✅','diligencia'=>'🔍','observacao'=>'💬',
                    // Novos tipos da importação em lote
                    'protocolo'=>'📝','distribuicao'=>'📤','ato_ordinatorio'=>'📋','certidao'=>'📜',
                    'publicacao_djen'=>'📰','manifestacao_mp'=>'⚖️','mandado_expedido'=>'📨','acordao'=>'🏛️',
                    'peticao_parte_autora'=>'📎','peticao_parte_re'=>'📎','chamado'=>'🎫',
                );
                $tipoCores = array(
                    'movimentacao'=>'#888','despacho'=>'#B87333','decisao'=>'#052228','sentenca'=>'#052228',
                    'audiencia'=>'#6B4C9A','peticao_juntada'=>'#059669','intimacao'=>'#dc2626','citacao'=>'#dc2626',
                    'acordo'=>'#2D7A4F','recurso'=>'#1a3a7a','cumprimento'=>'#059669','diligencia'=>'#B87333','observacao'=>'#888',
                    // Novos tipos da importação em lote
                    'protocolo'=>'#0ea5e9','distribuicao'=>'#0ea5e9','ato_ordinatorio'=>'#B87333','certidao'=>'#6B4C9A',
                    'publicacao_djen'=>'#d97706','manifestacao_mp'=>'#1a3a7a','mandado_expedido'=>'#dc2626','acordao'=>'#052228',
                    'peticao_parte_autora'=>'#059669','peticao_parte_re'=>'#B87333','chamado'=>'#dc2626',
                );
                $tipoLabels = array(
                    'movimentacao'=>'Movimentação','despacho'=>'Despacho','decisao'=>'Decisão','sentenca'=>'Sentença',
                    'audiencia'=>'Audiência','peticao_juntada'=>'Petição juntada','intimacao'=>'Intimação','citacao'=>'Citação',
                    'acordo'=>'Acordo','recurso'=>'Recurso','cumprimento'=>'Cumprimento','diligencia'=>'Diligência','observacao'=>'Observação',
                    // Novos tipos com acentuação e espaço corretos
                    'protocolo'=>'Protocolo','distribuicao'=>'Distribuição','ato_ordinatorio'=>'Ato Ordinatório','certidao'=>'Certidão',
                    'publicacao_djen'=>'Publicação DJEN','manifestacao_mp'=>'Manifestação do MP','mandado_expedido'=>'Mandado expedido','acordao'=>'Acórdão',
                    'peticao_parte_autora'=>'Petição da parte autora','peticao_parte_re'=>'Petição da parte ré','chamado'=>'Chamado interno',
                );
                foreach ($andamentos as $and):
                    $icon = isset($tipoIcons[$and['tipo']]) ? $tipoIcons[$and['tipo']] : '📋';
                    $cor = isset($tipoCores[$and['tipo']]) ? $tipoCores[$and['tipo']] : '#888';
                    $lbl = isset($tipoLabels[$and['tipo']]) ? $tipoLabels[$and['tipo']] : $and['tipo'];
                ?>
                <div class="andamento-item" style="position:relative;margin-bottom:16px;padding-left:20px;">
                    <!-- Bolinha da timeline -->
                    <div style="position:absolute;left:-20px;top:6px;width:18px;height:18px;border-radius:50%;background:<?= $cor ?>;display:flex;align-items:center;justify-content:center;font-size:10px;z-index:1;"><?= $icon ?></div>

                    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;border-left:3px solid <?= $cor ?>;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:.72rem;font-weight:700;color:<?= $cor ?>;text-transform:uppercase;letter-spacing:.5px;" data-and-tipo="<?= $and['id'] ?>" data-tipo-val="<?= e($and['tipo']) ?>"><?= $lbl ?></span>
                                <?php
                                $tipoOrigem = isset($and['tipo_origem']) ? $and['tipo_origem'] : 'manual';
                                if ($tipoOrigem === 'datajud'): ?>
                                    <span
                                        class="dj-origem-badge"
                                        title="Importado automaticamente do DataJud (CNJ)<?= !empty($and['created_at']) ? ' em ' . date('d/m/Y \à\s H:i', strtotime($and['created_at'])) : '' ?>"
                                        style="display:inline-flex;align-items:center;gap:3px;font-size:.62rem;background:#eff6ff;color:#3b82f6;padding:2px 6px;border-radius:4px;font-weight:700;cursor:default;border:1px solid #bfdbfe;"
                                    >
                                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                        CNJ
                                    </span>
                                <?php elseif ($and['tipo'] === 'chamado'): ?>
                                    <span style="font-size:.58rem;background:#fef3c7;color:#d97706;padding:1px 5px;border-radius:3px;font-weight:700;">Chamado</span>
                                    <?php
                                    // Extrair ID do chamado da descrição (ex: "Chamado #129: ...")
                                    if (preg_match('/Chamado #(\d+)/', $and['descricao'], $mChamado)):
                                        $chamadoId = (int)$mChamado[1];
                                    ?>
                                    <a href="<?= module_url('helpdesk', 'ver.php?id=' . $chamadoId) ?>" style="font-size:.6rem;background:#052228;color:#fff;padding:1px 6px;border-radius:3px;text-decoration:none;font-weight:600;">Abrir Chamado #<?= $chamadoId ?></a>
                                    <?php endif; ?>
                                <?php elseif ($tipoOrigem === 'importacao_lote'): ?>
                                    <span title="Importado em lote a partir dos autos em <?= !empty($and['created_at']) ? date('d/m/Y \à\s H:i', strtotime($and['created_at'])) : '' ?>" style="display:inline-flex;align-items:center;gap:3px;font-size:.62rem;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-weight:700;border:1px solid #fde68a;">📋 Lote</span>
                                <?php endif; ?>
                                <?php
                                // Prioridade: hora_andamento (campo próprio) > hora extraída de created_at (legado)
                                $horaEfetiva = '';
                                if (!empty($and['hora_andamento'])) {
                                    $horaEfetiva = substr($and['hora_andamento'], 0, 5); // HH:MM
                                } elseif (!empty($and['created_at'])) {
                                    $horaEfetiva = date('H:i', strtotime($and['created_at']));
                                }
                                ?>
                                <span style="font-size:.7rem;color:var(--text-muted);" data-data="<?= date('Y-m-d', strtotime($and['data_andamento'])) ?>" data-and-data="<?= $and['id'] ?>"><?= date('d/m/Y', strtotime($and['data_andamento'])) ?></span><?php if ($horaEfetiva): ?> <span style="color:#94a3b8;font-size:.7rem;" data-hora="<?= e($horaEfetiva) ?>" data-and-hora="<?= $and['id'] ?>"><?= e($horaEfetiva) ?></span><?php endif; ?>
                                <?php
                                $visivel = isset($and['visivel_cliente']) ? (int)$and['visivel_cliente'] : 1;
                                $sigilo = isset($and['segredo_justica']) ? (int)$and['segredo_justica'] : 0;
                                ?>
                                <button onclick="toggleVisibilidade(<?= $and['id'] ?>, this)" title="<?= $visivel ? 'Visível ao cliente — clique para ocultar' : 'Oculto do cliente — clique para tornar visível' ?>" style="background:none;border:none;cursor:pointer;font-size:.68rem;padding:1px 5px;border-radius:3px;<?= $visivel ? 'background:#ecfdf5;color:#059669;' : 'background:#fef2f2;color:#dc2626;' ?>" data-vis="<?= $visivel ?>"><?= $visivel ? '&#128065; Visível' : '&#128274; Interno' ?></button>
                                <?php if ($sigilo): ?><span style="font-size:.6rem;background:#fef2f2;color:#dc2626;padding:1px 4px;border-radius:3px;font-weight:600;">Segredo</span><?php endif; ?>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:.68rem;color:var(--text-muted);"><?= e($and['user_name'] ?: '') ?></span>
                                <?php
                                // Montar dados WhatsApp para todos os clientes vinculados (envio pelo Hub via waSenderOpen)
                                $waAnds = array();
                                $tipoAcaoRef = ($case['case_type'] && $case['case_type'] !== 'outro') ? ' — ' . $case['case_type'] : '';
                                $refProcesso = $case['case_number'] ? " (Proc. " . $case['case_number'] . $tipoAcaoRef . ")" : ($case['title'] ? " (" . $case['title'] . ")" : "");
                                foreach ($clientesVinculados as $cvAnd) {
                                    $phAnd = $cvAnd['phone'] ? preg_replace('/\D/', '', $cvAnd['phone']) : '';
                                    if (!$phAnd) continue;
                                    $nomeCvAnd = explode(' ', trim($cvAnd['name']))[0];
                                    $msgAnd = "Olá " . $nomeCvAnd . ", informamos sobre o andamento do seu processo" . $refProcesso . ":\n\n*" . $lbl . "* - " . date('d/m/Y', strtotime($and['data_andamento'])) . "\n" . $and['descricao'] . "\n\nQualquer dúvida, estamos à disposição.\nFerreira e Sá Advocacia";
                                    $waAnds[] = array(
                                        'name'     => $cvAnd['name'],
                                        'phone'    => $phAnd,
                                        'clientId' => (int)$cvAnd['id'],
                                        'msg'      => $msgAnd,
                                    );
                                }
                                $jaEnviou = !empty($and['whatsapp_enviado_em']);
                                ?>
                                <?php if (count($waAnds) === 1): $waC = $waAnds[0]; ?>
                                <span id="waBtnWrap<?= $and['id'] ?>" style="display:inline-flex;align-items:center;gap:4px;">
                                    <button type="button" onclick="waSenderOpen({telefone:<?= e(json_encode($waC['phone'])) ?>,nome:<?= e(json_encode($waC['name'])) ?>,clientId:<?= (int)$waC['clientId'] ?>,canal:'24',mensagem:<?= e(json_encode($waC['msg'])) ?>,onSuccess:function(){logWhatsApp(<?= (int)$and['id'] ?>);}});" style="background:#25D366;color:#fff;border:none;border-radius:4px;font-size:.7rem;padding:2px 8px;font-weight:600;cursor:pointer;" id="waBtn<?= $and['id'] ?>" title="Envia pelo Hub (Z-API) — abre modal para revisar antes de enviar">💬 Enviar</button>
                                    <?php if ($jaEnviou): ?><span style="font-size:.65rem;color:#059669;font-weight:600;" title="Enviado em <?= date('d/m/Y H:i', strtotime($and['whatsapp_enviado_em'])) ?>">✓ <?= date('d/m H:i', strtotime($and['whatsapp_enviado_em'])) ?></span><?php endif; ?>
                                </span>
                                <?php elseif (count($waAnds) > 1): ?>
                                <span id="waBtnWrap<?= $and['id'] ?>" style="display:inline-flex;align-items:center;gap:4px;position:relative;">
                                    <button type="button" onclick="var m=this.nextElementSibling;m.style.display=m.style.display==='block'?'none':'block';" style="background:#25D366;color:#fff;border-radius:4px;font-size:.7rem;padding:2px 8px;border:none;font-weight:600;cursor:pointer;" id="waBtn<?= $and['id'] ?>">💬 Enviar ▾</button>
                                    <div style="display:none;position:absolute;top:100%;right:0;background:#fff;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.2);z-index:50;min-width:220px;margin-top:2px;overflow:hidden;">
                                        <?php foreach ($waAnds as $wa): ?>
                                        <button type="button" onclick="this.closest('div').style.display='none';waSenderOpen({telefone:<?= e(json_encode($wa['phone'])) ?>,nome:<?= e(json_encode($wa['name'])) ?>,clientId:<?= (int)$wa['clientId'] ?>,canal:'24',mensagem:<?= e(json_encode($wa['msg'])) ?>,onSuccess:function(){logWhatsApp(<?= (int)$and['id'] ?>);}});" style="display:block;width:100%;text-align:left;padding:.45rem .75rem;background:none;color:#052228;border:none;cursor:pointer;font-size:.75rem;font-weight:500;border-bottom:1px solid #f1f5f9;" onmouseover="this.style.background='#ecfdf5'" onmouseout="this.style.background=''">💬 <?= e($wa['name']) ?></button>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if ($jaEnviou): ?><span style="font-size:.65rem;color:#059669;font-weight:600;" title="Enviado em <?= date('d/m/Y H:i', strtotime($and['whatsapp_enviado_em'])) ?>">✓ <?= date('d/m H:i', strtotime($and['whatsapp_enviado_em'])) ?></span><?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <?php if (has_min_role('gestao') || (int)($and['created_by'] ?? 0) === $userId): ?>
                                <button onclick="editarAndamento(<?= $and['id'] ?>)" style="background:none;border:none;color:#B87333;cursor:pointer;font-size:.68rem;padding:2px 4px;" title="Editar">&#9998;</button>
                                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;" data-confirm="Excluir este andamento?">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_andamento">
                                    <input type="hidden" name="andamento_id" value="<?= $and['id'] ?>">
                                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                                    <button type="submit" onclick="return confirm('Excluir este andamento? Esta ação não pode ser desfeita.');" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:.7rem;padding:2px 4px;" title="Excluir">✕</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p id="andDesc<?= $and['id'] ?>" style="font-size:.85rem;margin:0;white-space:pre-wrap;line-height:1.5;"><?= e($and['descricao']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Paginação -->
            <?php if (count($andamentos) > 10): ?>
            <div id="andPaginacao" style="display:flex;justify-content:center;gap:4px;margin-top:1rem;flex-wrap:wrap;"></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>


<script>

// ── DataJud Sync ──
function syncDataJud(btn) {
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;width:12px;height:12px;border:2px solid #052228;border-top-color:transparent;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;"></span> Sincronizando...';
    var statusEl = document.getElementById('djSyncStatus');

    var fd = new FormData();
    fd.append('case_id', '<?= $caseId ?>');
    fd.append('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= url("api/datajud_sync.php") ?>');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.status === 'sucesso') {
                if (r.novos > 0) {
                    statusEl.innerHTML = '<span style="color:#059669;font-weight:700;">' + r.novos + ' movimento(s) novo(s) importado(s)</span>';
                    btn.innerHTML = 'Sincronizar DataJud';
                    btn.style.background = '#059669';
                    btn.style.color = '#fff';
                    btn.style.borderColor = '#059669';
                    // Recarregar para ver os novos andamentos
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    statusEl.innerHTML = '<span style="color:#3b82f6;">Nenhuma novidade desde a ultima sincronizacao</span>';
                    btn.innerHTML = 'Sincronizar DataJud';
                    btn.disabled = false;
                }
            } else if (r.status === 'nao_encontrado') {
                statusEl.innerHTML = '<span style="color:#d97706;">Processo nao encontrado no DataJud — pode ser sigiloso ou tribunal nao coberto. Tentaremos novamente amanha.</span>';
                btn.innerHTML = 'Sincronizar DataJud';
                btn.disabled = false;
            } else {
                statusEl.innerHTML = '<span style="color:#dc2626;">Erro de comunicacao com DataJud — tente novamente</span>';
                btn.innerHTML = 'Sincronizar DataJud';
                btn.disabled = false;
            }
        } catch(e) {
            statusEl.innerHTML = '<span style="color:#dc2626;">Erro ao processar resposta</span>';
            btn.innerHTML = 'Sincronizar DataJud';
            btn.disabled = false;
        }
    };
    xhr.onerror = function() {
        statusEl.innerHTML = '<span style="color:#dc2626;">Erro de rede — tente novamente</span>';
        btn.innerHTML = 'Sincronizar DataJud';
        btn.disabled = false;
    };
    xhr.send(fd);
}

function logWhatsApp(andamentoId) {
    // Registrar envio via AJAX (o link href já abre o WhatsApp)
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        var btn = document.getElementById('waBtn' + andamentoId);
        if (btn) {
            btn.textContent = 'Enviado';
            btn.style.background = '#047857';
            setTimeout(function() { btn.textContent = 'Reenviar'; btn.style.background = '#25D366'; }, 3000);
        }
        var wrap = document.getElementById('waBtnWrap' + andamentoId);
        if (wrap && !wrap.querySelector('span[style*="059669"]')) {
            var badge = document.createElement('span');
            badge.style.cssText = 'font-size:.65rem;color:#059669;font-weight:600;';
            var agora = new Date();
            badge.textContent = 'ok ' + agora.toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
            wrap.appendChild(badge);
        }
    };
    xhr.send('action=log_whatsapp_andamento&andamento_id=' + andamentoId + '&case_id=<?= $caseId ?>&<?= CSRF_TOKEN_NAME ?>=<?= generate_csrf_token() ?>');
}

// ── Edição inline campos do processo ──
var _cvCsrf = '<?= generate_csrf_token() ?>';
function copiarLinkDrive(btn, url) {
    navigator.clipboard.writeText(url).then(function(){
        var origText = btn.textContent;
        btn.textContent = '✓ Copiado!';
        btn.style.background = '#059669';
        setTimeout(function(){ btn.textContent = origText; btn.style.background = '#6b7280'; }, 1500);
    }).catch(function(){
        // Fallback pra browsers sem clipboard API
        var tmp = document.createElement('textarea');
        tmp.value = url; document.body.appendChild(tmp); tmp.select();
        try { document.execCommand('copy'); btn.textContent = '✓ Copiado!'; btn.style.background = '#059669';
              setTimeout(function(){ btn.textContent = '📋 Copiar'; btn.style.background = '#6b7280'; }, 1500);
        } catch(e) { alert('Link:\n' + url); }
        tmp.remove();
    });
}

function salvarCampoProcesso(el) {
    var id = el.dataset ? el.dataset.id : el.id;
    var field = el.dataset ? el.dataset.field : '';
    var value = el.value !== undefined ? el.value : '';
    if (!id || !field) return;

    var fd = new FormData();
    fd.append('action', 'inline_edit_case');
    fd.append('case_id', id);
    fd.append('field', field);
    fd.append('value', value);
    fd.append('<?= CSRF_TOKEN_NAME ?>', _cvCsrf);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.csrf) _cvCsrf = r.csrf;
            if (r.ok) {
                if (el.style) { el.style.background = '#ecfdf5'; setTimeout(function(){ el.style.background = 'transparent'; }, 1000); }
            } else if (r.error) {
                alert('Erro: ' + r.error);
            }
        } catch(e) {}
    };
    xhr.send(fd);
}

// ── Publicacoes ──
function togglePubForm() {
    var wrap = document.getElementById('pubFormWrap');
    var arrow = document.getElementById('pubFormArrow');
    if (wrap.style.display === 'none') {
        wrap.style.display = 'block';
        arrow.innerHTML = '&#9650;';
    } else {
        wrap.style.display = 'none';
        arrow.innerHTML = '&#9660;';
    }
}

var prazosSugeridos = {
    'intimacao': 15, 'citacao': 15, 'despacho': 5,
    'decisao': 15, 'sentenca': 15, 'acordao': 15,
    'edital': 20, 'outro': 0
};

function sugerirPrazo(tipo) {
    var dias = prazosSugeridos[tipo] || 0;
    var input = document.getElementById('prazoDiasInput');
    var sugestao = document.getElementById('prazoSugestao');
    if (dias > 0 && input) {
        input.value = dias;
        sugestao.textContent = 'Sugerido: ' + dias + 'du (CPC)';
    } else if (sugestao) {
        sugestao.textContent = '';
    }
}

function filtrarTimeline(filtro, btn) {
    var btns = document.querySelectorAll('.filtro-andamentos button');
    btns.forEach(function(b) { b.classList.remove('ativo', 'ativo-pub'); });
    if (filtro === 'publicacoes') { btn.classList.add('ativo-pub'); }
    else { btn.classList.add('ativo'); }

    var andItems = document.querySelectorAll('.andamento-item:not(.pub-item)');
    var pubItems = document.querySelectorAll('.andamento-item.pub-item');

    andItems.forEach(function(el) { el.style.display = (filtro === 'publicacoes') ? 'none' : ''; });
    pubItems.forEach(function(el) { el.style.display = (filtro === 'andamentos') ? 'none' : ''; });
}

// Inicializar sugestao de prazo
var tipoPubEl = document.getElementById('tipoPubSelect');
if (tipoPubEl) sugerirPrazo(tipoPubEl.value);

function copiarNumero(el) {
    var texto = el.textContent.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(texto);
    } else {
        var ta = document.createElement('textarea');
        ta.value = texto;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
    var original = el.innerHTML;
    el.innerHTML = '✓ Copiado!';
    el.style.background = 'rgba(5,150,105,.5)';
    setTimeout(function() { el.innerHTML = original; el.style.background = 'rgba(255,255,255,.15)'; }, 1500);
}
// ── Paginação dos andamentos (10 por página) ──
(function() {
    var items = document.querySelectorAll('.andamento-item');
    if (items.length <= 10) return;
    var perPage = 10;
    var totalPages = Math.ceil(items.length / perPage);
    var currentPage = 1;
    var pagDiv = document.getElementById('andPaginacao');
    if (!pagDiv) return;

    function showPage(page) {
        currentPage = page;
        for (var i = 0; i < items.length; i++) {
            items[i].style.display = (i >= (page - 1) * perPage && i < page * perPage) ? '' : 'none';
        }
        renderPag();
    }

    function renderPag() {
        var html = '';
        var btnStyle = 'padding:4px 10px;border-radius:6px;font-size:.78rem;font-weight:600;cursor:pointer;border:1px solid var(--border);text-decoration:none;';
        if (currentPage > 1) {
            html += '<a href="#" onclick="andPage(' + (currentPage - 1) + ');return false;" style="' + btnStyle + 'color:var(--petrol-900);">← Ant</a>';
        }
        for (var p = 1; p <= totalPages; p++) {
            if (p === currentPage) {
                html += '<span style="' + btnStyle + 'background:var(--petrol-900);color:#fff;border-color:var(--petrol-900);">' + p + '</span>';
            } else {
                html += '<a href="#" onclick="andPage(' + p + ');return false;" style="' + btnStyle + 'color:var(--petrol-900);">' + p + '</a>';
            }
        }
        if (currentPage < totalPages) {
            html += '<a href="#" onclick="andPage(' + (currentPage + 1) + ');return false;" style="' + btnStyle + 'color:var(--petrol-900);">Próx →</a>';
        }
        html += '<span style="font-size:.72rem;color:var(--text-muted);margin-left:8px;">' + items.length + ' andamentos</span>';
        pagDiv.innerHTML = html;
    }

    window.andPage = function(p) { showPage(p); };
    showPage(1);
})();

function editarTitulo() {
    <?php if (!has_min_role('gestao')): ?>
    alert('Apenas gestão ou admin pode renomear.');
    return;
    <?php endif; ?>
    document.getElementById('casoTitulo').parentElement.style.display = 'none';
    document.getElementById('formTitulo').style.display = 'block';
    var input = document.getElementById('inputTitulo');
    input.focus();
    input.select();
}
function cancelarTitulo() {
    document.getElementById('casoTitulo').parentElement.style.display = 'flex';
    document.getElementById('formTitulo').style.display = 'none';
}

// Submit do form título — form normal (sem AJAX para evitar problemas de CSRF)
document.getElementById('formTitulo').addEventListener('submit', function(e) {
    var input = document.getElementById('inputTitulo');
    var novoNome = input.value.trim();
    if (novoNome.length < 5) { e.preventDefault(); alert('Nome deve ter no mínimo 5 caracteres.'); input.focus(); return; }
    if (!confirm('Renomear a pasta? Confirmar?')) { e.preventDefault(); return; }
    // Segue o submit normal do form
});

// ══════════════════════════════════════
// PARTES DO PROCESSO
// ══════════════════════════════════════
var PARTES_API = '<?= url("modules/shared/partes_api.php") ?>';
var PARTES_CSRF = '<?= generate_csrf_token() ?>';
var PARTES_CASE = <?= $caseId ?>;
var partesData = [];
var _isRecurso = <?= (isset($case['tipo_vinculo']) && $case['tipo_vinculo'] === 'recurso') ? 'true' : 'false' ?>;
var papelLabels = _isRecurso
    ? {autor:'Recorrente',reu:'Recorrido',representante_legal:'Rep. Legal',terceiro_interessado:'3º Interessado',litisconsorte_ativo:'Litis. Ativo',litisconsorte_passivo:'Litis. Passivo'}
    : {autor:'Autor',reu:'Réu',representante_legal:'Rep. Legal',terceiro_interessado:'3º Interessado',litisconsorte_ativo:'Litis. Ativo',litisconsorte_passivo:'Litis. Passivo'};
var papelCores = {autor:'#059669',reu:'#dc2626',representante_legal:'#6366f1',terceiro_interessado:'#d97706',litisconsorte_ativo:'#0d9488',litisconsorte_passivo:'#8b5cf6'};

carregarPartes();

function carregarPartes() {
    var x = new XMLHttpRequest();
    x.open('GET', PARTES_API + '?action=listar&case_id=' + PARTES_CASE);
    x.onload = function() {
        try { partesData = JSON.parse(x.responseText); } catch(e) { partesData = []; }
        renderPartes();
    };
    x.send();
}

function renderPartes() {
    var el = document.getElementById('partesLista');
    if (!partesData.length) {
        el.innerHTML = '<div style="text-align:center;color:var(--text-muted);padding:.8rem;font-size:.85rem;">Nenhuma parte cadastrada. Clique em "+ Adicionar Parte".</div>';
        return;
    }
    var html = '<table style="width:100%;font-size:.82rem;border-collapse:collapse;"><thead><tr style="background:var(--petrol-900);color:#fff;"><th style="padding:6px 8px;">Papel</th><th>Nome / Razão Social</th><th>CPF / CNPJ</th><th>Tipo</th><th style="width:100px;">Ações</th></tr></thead><tbody>';
    partesData.forEach(function(p) {
        var nome = p.tipo_pessoa === 'juridica' ? (p.razao_social || p.nome_fantasia || '—') : (p.nome || '—');
        var doc = p.tipo_pessoa === 'juridica' ? (p.cnpj || '—') : (p.cpf || '—');
        var tipo = p.tipo_pessoa === 'juridica' ? 'Jurídica' : 'Física';
        var cor = papelCores[p.papel] || '#888';
        var repInfo = '';
        if (p.representado_por) repInfo = ' <span style="font-size:.68rem;color:#6366f1;">(rep. por ' + esc(p.representado_por) + ')</span>';
        if (p.papel === 'representante_legal' && p.representa_nome) repInfo = ' <span style="font-size:.68rem;color:#6366f1;">(representa ' + esc(p.representa_nome) + ')</span>';
        var clienteBadge = p.client_id ? ' <span style="font-size:.58rem;background:#B87333;color:#fff;padding:1px 5px;border-radius:3px;font-weight:700;">NOSSO CLIENTE</span>' : '';
        html += '<tr style="border-bottom:1px solid var(--border);">'
            + '<td style="padding:6px 8px;"><span style="display:inline-block;padding:1px 6px;border-radius:4px;font-size:.68rem;font-weight:700;color:#fff;background:' + cor + ';">' + (papelLabels[p.papel]||p.papel) + '</span></td>'
            + '<td style="font-weight:600;">' + esc(nome) + repInfo + clienteBadge + '</td>'
            + '<td style="font-family:monospace;font-size:.78rem;">' + esc(doc) + '</td>'
            + '<td>' + tipo + '</td>'
            + '<td><button onclick="editarParte(' + p.id + ')" class="btn btn-outline btn-sm" style="font-size:.68rem;padding:2px 6px;">Editar</button></td>'
            + '</tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function esc(s) { if(!s) return ''; var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

function abrirModalParte() {
    document.getElementById('parteTitModal').textContent = 'Adicionar Parte';
    document.getElementById('parteId').value = '0';
    document.getElementById('partePapel').value = 'reu';
    document.getElementById('parteTipo').value = 'fisica';
    document.getElementById('parteBtnDel').style.display = 'none';
    ['parteNome','parteCpf','parteRg','parteNasc','parteEC','parteProf','parteEmail','parteCnpj','parteRazao','parteFantasia','parteRepNome','parteRepCpf','parteTel','parteCep','parteEnd','parteCid','parteUf','parteObs','parteEmailPJ','parteClientId'].forEach(function(id) {
        var el = document.getElementById(id); if(el) el.value = '';
    });
    document.getElementById('parteRepId').value = '';
    document.getElementById('parteEhCliente').checked = false;
    document.getElementById('parteClienteBusca').style.display = 'none';
    document.getElementById('parteClienteNome').textContent = '';
    document.getElementById('parteClienteBuscaInput').value = '';
    mudouTipoPessoa();
    mudouPapel();
    document.getElementById('parteOverlay').style.display = 'flex';
}

function editarParte(id) {
    var x = new XMLHttpRequest();
    x.open('GET', PARTES_API + '?action=get&id=' + id);
    x.onload = function() {
        try {
            var p = JSON.parse(x.responseText);
            if (p.error) { alert(p.error); return; }
            document.getElementById('parteTitModal').textContent = 'Editar Parte';
            document.getElementById('parteId').value = p.id;
            document.getElementById('partePapel').value = p.papel;
            document.getElementById('parteTipo').value = p.tipo_pessoa;
            document.getElementById('parteNome').value = p.nome || '';
            document.getElementById('parteCpf').value = p.cpf || '';
            document.getElementById('parteRg').value = p.rg || '';
            document.getElementById('parteNasc').value = p.nascimento || '';
            document.getElementById('parteEC').value = p.estado_civil || '';
            document.getElementById('parteProf').value = p.profissao || '';
            document.getElementById('parteEmail').value = p.email || '';
            document.getElementById('parteCnpj').value = p.cnpj || '';
            document.getElementById('parteRazao').value = p.razao_social || '';
            document.getElementById('parteFantasia').value = p.nome_fantasia || '';
            document.getElementById('parteRepNome').value = p.representante_nome || '';
            document.getElementById('parteRepCpf').value = p.representante_cpf || '';
            document.getElementById('parteEmailPJ').value = p.email || '';
            document.getElementById('parteTel').value = p.telefone || '';
            document.getElementById('parteCep').value = p.cep || '';
            document.getElementById('parteEnd').value = p.endereco || '';
            document.getElementById('parteCid').value = p.cidade || '';
            document.getElementById('parteUf').value = p.uf || '';
            document.getElementById('parteObs').value = p.observacoes || '';
            document.getElementById('parteRepId').value = p.representa_parte_id || '';
            // Vincular cliente
            var cliId = p.client_id || 0;
            document.getElementById('parteClientId').value = cliId;
            document.getElementById('parteEhCliente').checked = !!cliId;
            document.getElementById('parteClienteBusca').style.display = cliId ? 'block' : 'none';
            document.getElementById('parteClienteBuscaInput').value = '';
            document.getElementById('parteClienteNome').textContent = cliId ? '✓ Cliente vinculado (ID ' + cliId + ')' : '';
            document.getElementById('parteBtnDel').style.display = 'inline-block';
            mudouTipoPessoa();
            mudouPapel();
            document.getElementById('parteOverlay').style.display = 'flex';
        } catch(e) { alert('Erro ao carregar'); }
    };
    x.send();
}

function fecharModalParte() { document.getElementById('parteOverlay').style.display = 'none'; }
document.getElementById('parteOverlay').addEventListener('click', function(e) { if(e.target===this) fecharModalParte(); });

function mudouTipoPessoa() {
    var t = document.getElementById('parteTipo').value;
    document.getElementById('partePF').style.display = t === 'fisica' ? 'block' : 'none';
    document.getElementById('partePJ').style.display = t === 'juridica' ? 'block' : 'none';
}

function mudouPapel() {
    var p = document.getElementById('partePapel').value;
    var box = document.getElementById('parteRepBox');
    if (p === 'representante_legal') {
        box.style.display = 'block';
        var checks = document.getElementById('parteRepChecks');
        var html = '';
        var editId = parseInt(document.getElementById('parteId').value) || 0;
        partesData.forEach(function(pt) {
            if (pt.papel !== 'representante_legal' && pt.id != editId) {
                var checked = (pt.representa_parte_id && pt.representa_parte_id == editId) ? ' checked' : '';
                html += '<label style="display:flex;align-items:center;gap:5px;padding:3px 0;font-size:.82rem;cursor:pointer;">'
                    + '<input type="checkbox" class="repCheck" value="' + pt.id + '"' + checked + '> '
                    + '<span style="display:inline-block;padding:0 4px;border-radius:3px;font-size:.62rem;font-weight:700;color:#fff;background:' + (papelCores[pt.papel]||'#888') + ';">' + (papelLabels[pt.papel]||pt.papel) + '</span> '
                    + (pt.nome || pt.razao_social || '?') + '</label>';
            }
        });
        checks.innerHTML = html || '<span style="font-size:.78rem;color:var(--text-muted);">Cadastre as partes primeiro</span>';
    } else {
        box.style.display = 'none';
    }
}

function salvarParte() {
    var tipo = document.getElementById('parteTipo').value;
    var nome = tipo === 'juridica' ? document.getElementById('parteRazao').value : document.getElementById('parteNome').value;
    if (!nome.trim()) { alert('Preencha o nome/razão social.'); return; }

    var fd = new FormData();
    fd.append('action', 'salvar');
    fd.append('csrf_token', PARTES_CSRF);
    fd.append('id', document.getElementById('parteId').value);
    fd.append('case_id', PARTES_CASE);
    fd.append('papel', document.getElementById('partePapel').value);
    fd.append('tipo_pessoa', tipo);
    fd.append('nome', document.getElementById('parteNome').value);
    fd.append('cpf', document.getElementById('parteCpf').value);
    fd.append('rg', document.getElementById('parteRg').value);
    fd.append('nascimento', document.getElementById('parteNasc').value);
    fd.append('estado_civil', document.getElementById('parteEC').value);
    fd.append('profissao', document.getElementById('parteProf').value);
    fd.append('email', tipo === 'juridica' ? document.getElementById('parteEmailPJ').value : document.getElementById('parteEmail').value);
    fd.append('razao_social', document.getElementById('parteRazao').value);
    fd.append('cnpj', document.getElementById('parteCnpj').value);
    fd.append('nome_fantasia', document.getElementById('parteFantasia').value);
    fd.append('representante_nome', document.getElementById('parteRepNome').value);
    fd.append('representante_cpf', document.getElementById('parteRepCpf').value);
    fd.append('telefone', document.getElementById('parteTel').value);
    fd.append('cep', document.getElementById('parteCep').value);
    fd.append('endereco', document.getElementById('parteEnd').value);
    fd.append('cidade', document.getElementById('parteCid').value);
    fd.append('uf', document.getElementById('parteUf').value);
    // Representações múltiplas
    var repIds = [];
    document.querySelectorAll('.repCheck:checked').forEach(function(cb) { repIds.push(cb.value); });
    fd.append('representa_ids', repIds.join(','));
    fd.append('observacoes', document.getElementById('parteObs').value);
    var cliId = document.getElementById('parteClientId').value;
    if (cliId && cliId !== '0') fd.append('client_id', cliId);

    var x = new XMLHttpRequest(); x.open('POST', PARTES_API);
    x.onload = function() {
        try { var r = JSON.parse(x.responseText); if(r.csrf) PARTES_CSRF=r.csrf;
            if(r.error) { alert(r.error); return; }
            fecharModalParte(); carregarPartes();
        } catch(e) { alert('Erro ao salvar'); }
    };
    x.send(fd);
}

function excluirParte() {
    if (!confirm('Remover esta parte do processo?')) return;
    var fd = new FormData();
    fd.append('action', 'excluir'); fd.append('csrf_token', PARTES_CSRF);
    fd.append('id', document.getElementById('parteId').value);
    var x = new XMLHttpRequest(); x.open('POST', PARTES_API);
    x.onload = function() {
        try { var r = JSON.parse(x.responseText); if(r.csrf) PARTES_CSRF=r.csrf; }
        catch(e) {}
        fecharModalParte(); carregarPartes();
    };
    x.send(fd);
}

var _buscaNomeTimer = null;
function buscarNomeParte(q) {
    var box = document.getElementById('parteNomeSugestoes');
    if (q.length < 3) { box.style.display = 'none'; return; }
    clearTimeout(_buscaNomeTimer);
    _buscaNomeTimer = setTimeout(function() {
        var x = new XMLHttpRequest();
        x.open('GET', PARTES_API + '?action=buscar_nome_parte&q=' + encodeURIComponent(q));
        x.onload = function() {
            try {
                var res = JSON.parse(x.responseText);
                if (!res.length) { box.style.display = 'none'; return; }
                box.innerHTML = '';
                res.forEach(function(p) {
                    var div = document.createElement('div');
                    div.style.cssText = 'padding:8px 10px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f3f4f6;';
                    var label = p.nome || p.razao_social || '';
                    var isCliente = p.fonte === 'cliente' || p.client_id;
                    var sub = p.cpf ? ' — CPF: ' + p.cpf : (p.cnpj ? ' — CNPJ: ' + p.cnpj : '');
                    if (isCliente) sub += ' <span style="background:#ecfdf5;color:#059669;padding:1px 5px;border-radius:3px;font-size:.6rem;font-weight:700;">CLIENTE</span>';
                    div.innerHTML = '<strong>' + label + '</strong><span style="color:#6b7280;font-size:.72rem;">' + sub + '</span>';
                    div.onmouseenter = function() { this.style.background = 'rgba(215,171,144,.15)'; };
                    div.onmouseleave = function() { this.style.background = ''; };
                    div.onclick = function() {
                        // Preencher todos os campos
                        if (p.tipo_pessoa === 'juridica') {
                            document.getElementById('parteTipo').value = 'juridica';
                            mudouTipoPessoa();
                            if (p.cnpj) document.getElementById('parteCnpj').value = p.cnpj;
                            if (p.razao_social) document.getElementById('parteRazao').value = p.razao_social;
                            if (p.nome_fantasia) document.getElementById('parteFantasia').value = p.nome_fantasia;
                            if (p.email) document.getElementById('parteEmailPJ').value = p.email;
                        } else {
                            document.getElementById('parteNome').value = p.nome || '';
                            if (p.cpf) document.getElementById('parteCpf').value = p.cpf;
                            if (p.rg) document.getElementById('parteRg').value = p.rg;
                            if (p.nascimento) document.getElementById('parteNasc').value = p.nascimento;
                            if (p.estado_civil) document.getElementById('parteEC').value = p.estado_civil;
                            if (p.profissao) document.getElementById('parteProf').value = p.profissao;
                            if (p.email) document.getElementById('parteEmail').value = p.email;
                            try {
                                if (p.telefone) document.getElementById('parteTel').value = p.telefone;
                                if (p.endereco) document.getElementById('parteEnd').value = p.endereco;
                                if (p.cidade) document.getElementById('parteCid').value = p.cidade;
                                if (p.uf) document.getElementById('parteUf').value = p.uf;
                                if (p.cep) document.getElementById('parteCep').value = p.cep;
                            } catch(e) {}
                        }
                        // Vincular client_id se veio da base de clientes
                        if (p.client_id) {
                            document.getElementById('parteClientId').value = p.client_id;
                            document.getElementById('parteEhCliente').checked = true;
                            document.getElementById('parteClienteBusca').style.display = 'block';
                            document.getElementById('parteClienteNome').textContent = '✓ ' + (p.nome || '');
                            document.getElementById('parteClienteBuscaInput').value = p.nome || '';
                        }
                        box.style.display = 'none';
                    };
                    box.appendChild(div);
                });
                box.style.display = 'block';
            } catch(e) { box.style.display = 'none'; }
        };
        x.send();
    }, 300);
}
// Fechar ao clicar fora
document.addEventListener('click', function(e) {
    var box = document.getElementById('parteNomeSugestoes');
    if (box && !box.contains(e.target) && e.target.id !== 'parteNome') box.style.display = 'none';
});

function mascaraCpfParte(el) {
    var v = el.value.replace(/\D/g, '');
    if (v.length > 11) v = v.substr(0, 11);
    if (v.length > 9) v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
    else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
    else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
    el.value = v;
}

var _cpfParteTimer = null;
function autoBuscarCpfParte() {
    clearTimeout(_cpfParteTimer);
    var cpf = document.getElementById('parteCpf').value.replace(/\D/g, '');
    if (cpf.length === 11) {
        _cpfParteTimer = setTimeout(function() { buscarCpfParte(); }, 400);
    }
}

function buscarCpfParte() {
    var cpf = document.getElementById('parteCpf').value.replace(/\D/g,'');
    if (cpf.length < 11) return;
    var st = document.getElementById('parteCpfStatus');
    st.textContent = 'Buscando...'; st.style.color = '#d97706';
    var x = new XMLHttpRequest();
    x.open('GET', PARTES_API + '?action=buscar_cpf&q=' + cpf);
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            if (r.found) {
                var d = r.data;
                if (d.name || d.nome) document.getElementById('parteNome').value = d.name || d.nome || '';
                if (d.rg) document.getElementById('parteRg').value = d.rg || '';
                if (d.birth_date || d.nascimento) document.getElementById('parteNasc').value = d.birth_date || d.nascimento || '';
                if (d.profession || d.profissao) document.getElementById('parteProf').value = d.profession || d.profissao || '';
                if (d.marital_status || d.estado_civil) document.getElementById('parteEC').value = d.marital_status || d.estado_civil || '';
                if (d.email) document.getElementById('parteEmail').value = d.email || '';
                if (d.phone || d.telefone) document.getElementById('parteTel').value = d.phone || d.telefone || '';
                if (d.address_street || d.endereco) document.getElementById('parteEnd').value = d.address_street || d.endereco || '';
                if (d.address_city || d.cidade) document.getElementById('parteCid').value = d.address_city || d.cidade || '';
                if (d.address_state || d.uf) document.getElementById('parteUf').value = d.address_state || d.uf || '';
                if (d.address_zip || d.cep) document.getElementById('parteCep').value = d.address_zip || d.cep || '';
                // Vincular client_id se veio da base interna
                if (d.id || d.client_id) document.getElementById('parteClientId').value = d.id || d.client_id || '0';
                st.textContent = 'Dados encontrados! (' + r.source + ')'; st.style.color = '#059669';
            } else {
                st.textContent = 'Não encontrado. Preencha manualmente.'; st.style.color = '#94a3b8';
            }
        } catch(e) { st.textContent = ''; }
        setTimeout(function(){st.textContent=''},4000);
    };
    x.send();
}

function buscarCnpjParte() {
    var cnpj = document.getElementById('parteCnpj').value.replace(/\D/g,'');
    if (cnpj.length < 14) return;
    var st = document.getElementById('parteCnpjStatus');
    st.textContent = 'Buscando na Receita...'; st.style.color = '#d97706';
    var x = new XMLHttpRequest();
    x.open('GET', PARTES_API + '?action=buscar_cnpj&q=' + cnpj);
    x.onload = function() {
        try {
            var r = JSON.parse(x.responseText);
            if (r.found) {
                var d = r.data;
                if (d.razao_social) document.getElementById('parteRazao').value = d.razao_social;
                if (d.nome_fantasia) document.getElementById('parteFantasia').value = d.nome_fantasia;
                if (d.email) document.getElementById('parteEmailPJ').value = d.email;
                if (d.telefone) document.getElementById('parteTel').value = d.telefone;
                if (d.endereco) document.getElementById('parteEnd').value = d.endereco;
                if (d.cidade) document.getElementById('parteCid').value = d.cidade;
                if (d.uf) document.getElementById('parteUf').value = d.uf;
                if (d.cep) document.getElementById('parteCep').value = d.cep;
                st.textContent = 'Dados encontrados!'; st.style.color = '#059669';
            } else {
                st.textContent = 'CNPJ não encontrado.'; st.style.color = '#94a3b8';
            }
        } catch(e) { st.textContent = ''; }
        setTimeout(function(){st.textContent=''},4000);
    };
    x.send();
}

var andCsrf = '<?= generate_csrf_token() ?>';

function toggleVisibilidade(andId, btn) {
    var atual = parseInt(btn.getAttribute('data-vis'));
    var novo = atual ? 0 : 1;
    var x = new XMLHttpRequest();
    x.open('POST', '<?= module_url("operacional", "api.php") ?>');
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    x.onload = function() {
        try { var r = JSON.parse(x.responseText);
            if (r.csrf) andCsrf = r.csrf;
            if (r.ok) {
                btn.setAttribute('data-vis', novo);
                if (novo) {
                    btn.innerHTML = '&#128065; Visível';
                    btn.style.background = '#ecfdf5'; btn.style.color = '#059669';
                    btn.title = 'Visível ao cliente — clique para ocultar';
                } else {
                    btn.innerHTML = '&#128274; Interno';
                    btn.style.background = '#fef2f2'; btn.style.color = '#dc2626';
                    btn.title = 'Oculto do cliente — clique para tornar visível';
                }
            } else if (r.error) { alert(r.error); }
        } catch(e) {}
    };
    x.send('action=toggle_visibilidade&andamento_id=' + andId + '&visivel=' + novo + '&<?= CSRF_TOKEN_NAME ?>=' + andCsrf);
}

function editarAndamento(andId) {
    var descEl = document.getElementById('andDesc' + andId);
    var textoAtual = descEl.textContent;
    // Pegar hora e data atuais do andamento
    var item = descEl.closest('.and-item');
    var horaSpan = item ? item.querySelector('[data-hora]') : null;
    var horaAtual = horaSpan ? horaSpan.getAttribute('data-hora') : '';
    var dataSpan = item ? item.querySelector('[data-data]') : null;
    var dataAtual = dataSpan ? dataSpan.getAttribute('data-data') : '';

    var wrapper = document.createElement('div');
    wrapper.id = 'andEditWrap' + andId;

    // Pegar tipo atual
    var tipoSpan = item ? item.querySelector('[data-tipo-val]') : null;
    var tipoAtual = tipoSpan ? tipoSpan.getAttribute('data-tipo-val') : '';

    var tipoOpcoes = [
        ['movimentacao','Movimentação'],['despacho','Despacho'],['decisao','Decisão'],['sentenca','Sentença'],
        ['audiencia','Audiência'],['peticao_juntada','Petição juntada'],['intimacao','Intimação'],['citacao','Citação'],
        ['acordo','Acordo'],['recurso','Recurso'],['cumprimento','Cumprimento'],['diligencia','Diligência'],['observacao','Observação']
    ];

    var horaRow = document.createElement('div');
    horaRow.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;';
    var tipoSelectHtml = '<label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Tipo:</label><select id="andTipo' + andId + '" style="font-size:.8rem;padding:3px 6px;border:1.5px solid #e5e7eb;border-radius:5px;font-family:inherit;">';
    tipoOpcoes.forEach(function(o) { tipoSelectHtml += '<option value="' + o[0] + '"' + (o[0] === tipoAtual ? ' selected' : '') + '>' + o[1] + '</option>'; });
    tipoSelectHtml += '</select>';
    horaRow.innerHTML = tipoSelectHtml
        + '<label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Data:</label><input type="date" id="andData' + andId + '" value="' + dataAtual + '" style="font-size:.8rem;padding:3px 6px;border:1.5px solid #e5e7eb;border-radius:5px;font-family:inherit;">'
        + '<label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Horário:</label><input type="time" id="andHora' + andId + '" value="' + horaAtual + '" style="font-size:.8rem;padding:3px 6px;border:1.5px solid #e5e7eb;border-radius:5px;font-family:inherit;">';

    var input = document.createElement('textarea');
    input.value = textoAtual;
    input.style.cssText = 'width:100%;font-size:.85rem;padding:6px;border:2px solid #B87333;border-radius:6px;min-height:60px;font-family:inherit;resize:vertical;';
    input.id = 'andEdit' + andId;

    var btns = document.createElement('div');
    btns.style.cssText = 'display:flex;gap:4px;margin-top:4px;';
    btns.id = 'andBtns' + andId;
    btns.innerHTML = '<button onclick="salvarAndamento(' + andId + ')" style="background:#059669;color:#fff;border:none;padding:3px 10px;border-radius:4px;font-size:.72rem;font-weight:600;cursor:pointer;">Salvar</button>'
        + '<button onclick="cancelarEdicaoAnd(' + andId + ')" style="background:#f3f4f6;border:none;padding:3px 10px;border-radius:4px;font-size:.72rem;cursor:pointer;">Cancelar</button>';

    wrapper.appendChild(horaRow);
    wrapper.appendChild(input);
    wrapper.appendChild(btns);

    descEl.style.display = 'none';
    descEl.parentNode.insertBefore(wrapper, descEl.nextSibling);
    input.focus();
}

function cancelarEdicaoAnd(andId) {
    var wrap = document.getElementById('andEditWrap' + andId);
    if (wrap) wrap.remove();
    // Fallback para versão antiga sem wrapper
    var input = document.getElementById('andEdit' + andId);
    var btns = document.getElementById('andBtns' + andId);
    if (input) input.remove();
    if (btns) btns.remove();
    document.getElementById('andDesc' + andId).style.display = '';
}

function salvarAndamento(andId) {
    var input = document.getElementById('andEdit' + andId);
    if (!input) return;
    var novoTexto = input.value.trim();
    if (!novoTexto) { alert('Descrição não pode ser vazia.'); return; }
    var horaEl = document.getElementById('andHora' + andId);
    var novaHora = horaEl ? horaEl.value : '';
    var dataEl = document.getElementById('andData' + andId);
    var novaData = dataEl ? dataEl.value : '';
    var tipoEl = document.getElementById('andTipo' + andId);
    var novoTipo = tipoEl ? tipoEl.value : '';
    var x = new XMLHttpRequest();
    x.open('POST', '<?= module_url("operacional", "api.php") ?>');
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    x.onload = function() {
        try { var r = JSON.parse(x.responseText);
            if (r.csrf) andCsrf = r.csrf;
            if (r.ok) {
                // Se tipo mudou, recarregar para atualizar cor e ícone
                var tipoSpan = document.querySelector('[data-and-tipo="' + andId + '"]');
                if (novoTipo && tipoSpan && tipoSpan.getAttribute('data-tipo-val') !== novoTipo) {
                    location.reload();
                    return;
                }
                document.getElementById('andDesc' + andId).textContent = novoTexto;
                var horaSpan = document.querySelector('[data-and-hora="' + andId + '"]');
                if (horaSpan && novaHora) horaSpan.textContent = novaHora;
                var dataSpan = document.querySelector('[data-and-data="' + andId + '"]');
                if (dataSpan && novaData) {
                    var p = novaData.split('-');
                    dataSpan.textContent = p[2] + '/' + p[1] + '/' + p[0];
                    dataSpan.setAttribute('data-data', novaData);
                }
                cancelarEdicaoAnd(andId);
            } else if (r.error) { alert(r.error); }
        } catch(e) { alert('Erro ao salvar'); }
    };
    x.send('action=edit_andamento&andamento_id=' + andId + '&descricao=' + encodeURIComponent(novoTexto) + '&hora=' + encodeURIComponent(novaHora) + '&data=' + encodeURIComponent(novaData) + '&tipo=' + encodeURIComponent(novoTipo) + '&case_id=<?= $caseId ?>&<?= CSRF_TOKEN_NAME ?>=' + andCsrf);
}

function toggleVincularCliente(checked) {
    var busca = document.getElementById('parteClienteBusca');
    busca.style.display = checked ? 'block' : 'none';
    if (!checked) {
        document.getElementById('parteClientId').value = '0';
        document.getElementById('parteClienteNome').textContent = '';
        document.getElementById('parteClienteBuscaInput').value = '';
        document.getElementById('parteClienteResultados').style.display = 'none';
    } else {
        // Auto-buscar pelo nome da parte já preenchido
        var nome = document.getElementById('parteNome').value.trim();
        if (nome.length >= 3) {
            document.getElementById('parteClienteBuscaInput').value = nome;
            buscarClienteParaVincular(nome);
        }
    }
}

var _vincClienteTimer = null;
function buscarClienteParaVincular(q) {
    clearTimeout(_vincClienteTimer);
    var box = document.getElementById('parteClienteResultados');
    if (q.length < 2) { box.style.display = 'none'; return; }
    _vincClienteTimer = setTimeout(function() {
        var x = new XMLHttpRequest();
        x.open('GET', '<?= module_url("operacional", "caso_novo.php") ?>?ajax_busca_cliente=1&q=' + encodeURIComponent(q));
        x.onload = function() {
            try {
                var clientes = JSON.parse(x.responseText);
                box.innerHTML = '';
                if (clientes.length === 0) {
                    var btn = document.createElement('div');
                    btn.style.cssText = 'padding:.7rem .85rem;cursor:pointer;font-size:.8rem;background:#eff6ff;color:#1e40af;border-left:4px solid #3b82f6;font-weight:600;';
                    btn.innerHTML = '➕ Cadastrar <strong>' + q + '</strong> como novo cliente (usa os dados preenchidos)';
                    btn.onmouseover = function(){ this.style.background = '#dbeafe'; };
                    btn.onmouseout  = function(){ this.style.background = '#eff6ff'; };
                    btn.onclick = function() { cadastrarClienteDaParte(); };
                    box.innerHTML = '';
                    box.appendChild(btn);
                } else {
                    clientes.forEach(function(c) {
                        var div = document.createElement('div');
                        div.style.cssText = 'padding:.5rem .75rem;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f1f5f9;';
                        div.innerHTML = '<strong>' + c.name + '</strong>' + (c.cpf ? ' <span style="color:var(--text-muted);font-size:.72rem;">' + c.cpf + '</span>' : '');
                        div.onmouseover = function() { this.style.background = '#f8f6f2'; };
                        div.onmouseout = function() { this.style.background = ''; };
                        div.onclick = function() {
                            document.getElementById('parteClientId').value = c.id;
                            document.getElementById('parteClienteNome').textContent = '✓ ' + c.name;
                            document.getElementById('parteClienteBuscaInput').value = c.name;
                            box.style.display = 'none';
                            // Preencher campos com dados do cliente
                            if (c.cpf) {
                                var cpfLimpo = c.cpf.replace(/\D/g, '');
                                if (cpfLimpo.length >= 11) {
                                    document.getElementById('parteCpf').value = c.cpf;
                                    buscarCpfParte(); // busca completa e preenche tudo
                                }
                            }
                            // Preencher nome se vazio
                            var nomeEl = document.getElementById('parteNome');
                            if (!nomeEl.value) nomeEl.value = c.name;
                        };
                        box.appendChild(div);
                    });
                }
                box.style.display = 'block';
            } catch(e) {}
        };
        x.send();
    }, 300);
}

// Fechar sugestões ao clicar fora
document.addEventListener('click', function(e) {
    var box = document.getElementById('parteClienteResultados');
    if (box && !box.contains(e.target) && e.target.id !== 'parteClienteBuscaInput') box.style.display = 'none';
});

// Cadastra um novo cliente com os dados preenchidos no form da parte
function cadastrarClienteDaParte() {
    var nome = (document.getElementById('parteNome').value || document.getElementById('parteClienteBuscaInput').value).trim();
    if (!nome) { alert('Informe o nome da parte antes.'); return; }
    var dados = new FormData();
    dados.append('action', 'criar_cliente_da_parte');
    dados.append('name', nome);
    dados.append('cpf', (document.getElementById('parteCpf') || {value:''}).value || '');
    dados.append('rg', (document.getElementById('parteRg') || {value:''}).value || '');
    dados.append('phone', (document.getElementById('parteTelefone') || {value:''}).value || '');
    dados.append('email', (document.getElementById('parteEmail') || {value:''}).value || '');
    dados.append('birth_date', (document.getElementById('parteNascimento') || {value:''}).value || '');
    dados.append('profession', (document.getElementById('parteProfissao') || {value:''}).value || '');
    dados.append('marital_status', (document.getElementById('parteEstadoCivil') || {value:''}).value || '');
    dados.append('address_street', (document.getElementById('parteEndereco') || {value:''}).value || '');
    dados.append('address_city', (document.getElementById('parteCidade') || {value:''}).value || '');
    dados.append('address_state', (document.getElementById('parteUf') || {value:''}).value || '');
    dados.append('address_zip', (document.getElementById('parteCep') || {value:''}).value || '');
    dados.append('csrf_token', '<?= generate_csrf_token() ?>');
    fetch('<?= module_url('operacional', 'api.php') ?>', {
            method: 'POST', body: dados, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r){ return r.text().then(function(t){ try { return JSON.parse(t); } catch(e){ return { error: 'Resposta inválida do servidor: ' + t.substring(0, 200) }; } }); })
        .then(function(d){
            if (d && d.ok && d.client_id) {
                document.getElementById('parteClientId').value = d.client_id;
                document.getElementById('parteClienteNome').textContent = '✓ ' + nome + ' (cadastrado agora)';
                document.getElementById('parteClienteBuscaInput').value = nome;
                document.getElementById('parteClienteResultados').style.display = 'none';
                alert('✅ Cliente cadastrado! Agora é só salvar a parte.');
            } else {
                alert('❌ Falha ao cadastrar: ' + ((d && d.error) || 'erro desconhecido'));
            }
        })
        .catch(function(err){ alert('Erro: ' + err); });
}

function buscarCepParte() {
    var cep = document.getElementById('parteCep').value.replace(/\D/g,'');
    if (cep.length !== 8) return;
    fetch('https://viacep.com.br/ws/' + cep + '/json/')
        .then(function(r){return r.json()})
        .then(function(d){
            if (!d.erro) {
                document.getElementById('parteEnd').value = d.logradouro || '';
                document.getElementById('parteCid').value = d.localidade || '';
                document.getElementById('parteUf').value = d.uf || '';
            }
        }).catch(function(){});
}

// ══════════════════════════════════════
// PROCESSOS INCIDENTAIS
// ══════════════════════════════════════
var _incTab = 'existente';

function toggleIncTab(tab) {
    _incTab = tab;
    document.getElementById('incTabExistente').style.display = tab === 'existente' ? 'block' : 'none';
    document.getElementById('incTabNovo').style.display = tab === 'novo' ? 'block' : 'none';
    document.getElementById('btnIncExistente').style.background = tab === 'existente' ? 'var(--petrol-900)' : '#fff';
    document.getElementById('btnIncExistente').style.color = tab === 'existente' ? '#fff' : 'var(--petrol-900)';
    document.getElementById('btnIncNovo').style.background = tab === 'novo' ? 'var(--petrol-900)' : '#fff';
    document.getElementById('btnIncNovo').style.color = tab === 'novo' ? '#fff' : 'var(--petrol-900)';
    document.getElementById('btnIncConfirmar').textContent = tab === 'existente' ? 'Vincular →' : 'Criar novo →';
}

// Carregar casos do mesmo cliente
(function() {
    var clientId = <?= (int)$case['client_id'] ?>;
    if (!clientId) return;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '<?= module_url("operacional", "api.php") ?>?action=buscar_casos_cliente&client_id=' + clientId + '&exclude_id=<?= $caseId ?>');
    xhr.onload = function() {
        try {
            var casos = JSON.parse(xhr.responseText);
            var sel = document.getElementById('incCasoSelect');
            sel.innerHTML = '<option value="">— Selecione um processo —</option>';
            casos.forEach(function(c) {
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.title + (c.case_number ? ' — ' + c.case_number : '') + ' (' + c.status + ')';
                sel.appendChild(opt);
            });
            if (casos.length === 0) sel.innerHTML = '<option value="">Nenhum outro processo deste cliente</option>';
        } catch(e) {}
    };
    xhr.send();
})();

function confirmarIncidental() {
    var tipo = document.getElementById('incTipoRelacao').value;
    if (!tipo) { document.getElementById('incTipoRelacao').style.borderColor = '#ef4444'; return; }

    if (_incTab === 'existente') {
        var casoId = document.getElementById('incCasoSelect').value;
        if (!casoId) { document.getElementById('incCasoSelect').style.borderColor = '#ef4444'; return; }

        // Vincular existente via AJAX
        var fd = new FormData();
        fd.append('action', 'vincular_incidental');
        fd.append('principal_id', '<?= $caseId ?>');
        fd.append('incidental_id', casoId);
        fd.append('tipo_relacao', tipo);
        fd.append('<?= CSRF_TOKEN_NAME ?>', andCsrf);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            try { var r = JSON.parse(xhr.responseText); if (r.csrf) andCsrf = r.csrf; } catch(e){}
            location.reload();
        };
        xhr.send(fd);
    } else {
        // Criar novo → redirecionar para caso_novo com pré-vínculo
        var url = '<?= module_url("operacional", "caso_novo.php") ?>?client_id=<?= (int)$case['client_id'] ?>&principal_id=<?= $caseId ?>&tipo_relacao=' + encodeURIComponent(tipo);
        window.location.href = url;
    }
}

// ── Recursos ──
var _recTab = 'existente';

function toggleRecTab(tab) {
    _recTab = tab;
    document.getElementById('recTabExistente').style.display = tab === 'existente' ? 'block' : 'none';
    document.getElementById('recTabNovo').style.display = tab === 'novo' ? 'block' : 'none';
    document.getElementById('btnRecExistente').style.background = tab === 'existente' ? '#b45309' : '#fff';
    document.getElementById('btnRecExistente').style.color = tab === 'existente' ? '#fff' : '#b45309';
    document.getElementById('btnRecNovo').style.background = tab === 'novo' ? '#b45309' : '#fff';
    document.getElementById('btnRecNovo').style.color = tab === 'novo' ? '#fff' : '#b45309';
    document.getElementById('btnRecConfirmar').textContent = tab === 'existente' ? 'Vincular →' : 'Criar novo →';
}

// Carregar casos do mesmo cliente para recurso
(function() {
    var clientId = <?= (int)$case['client_id'] ?>;
    if (!clientId) return;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '<?= module_url("operacional", "api.php") ?>?action=buscar_casos_cliente&client_id=' + clientId + '&exclude_id=<?= $caseId ?>');
    xhr.onload = function() {
        try {
            var casos = JSON.parse(xhr.responseText);
            var sel = document.getElementById('recCasoSelect');
            sel.innerHTML = '<option value="">— Selecione um processo —</option>';
            casos.forEach(function(c) {
                var opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.title + (c.case_number ? ' — ' + c.case_number : '') + ' (' + c.status + ')';
                sel.appendChild(opt);
            });
            if (casos.length === 0) sel.innerHTML = '<option value="">Nenhum outro processo deste cliente</option>';
        } catch(e) {}
    };
    xhr.send();
})();

function confirmarRecurso() {
    var tipo = document.getElementById('recTipoRelacao').value;
    if (!tipo) { document.getElementById('recTipoRelacao').style.borderColor = '#ef4444'; return; }

    if (_recTab === 'existente') {
        var casoId = document.getElementById('recCasoSelect').value;
        if (!casoId) { document.getElementById('recCasoSelect').style.borderColor = '#ef4444'; return; }

        var fd = new FormData();
        fd.append('action', 'vincular_recurso');
        fd.append('principal_id', '<?= $caseId ?>');
        fd.append('recurso_id', casoId);
        fd.append('tipo_relacao', tipo);
        fd.append('<?= CSRF_TOKEN_NAME ?>', andCsrf);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '<?= module_url("operacional", "api.php") ?>');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            try { var r = JSON.parse(xhr.responseText); if (r.csrf) andCsrf = r.csrf; } catch(e){}
            location.reload();
        };
        xhr.send(fd);
    } else {
        var url = '<?= module_url("operacional", "caso_novo.php") ?>?client_id=<?= (int)$case['client_id'] ?>&principal_id=<?= $caseId ?>&tipo_relacao=' + encodeURIComponent(tipo) + '&tipo_vinculo=recurso';
        window.location.href = url;
    }
}

// ── Excluir processo ──
function confirmarExclusao() {
    var titulo = <?= json_encode($case['title'] ?: 'Processo #' . $caseId) ?>;
    if (!confirm('ATENÇÃO: Excluir permanentemente "' + titulo + '"?\n\nIsso apagará DESTE PROCESSO:\n- Tarefas vinculadas\n- Andamentos\n- Partes do processo (não exclui o cadastro do cliente)\n- Documentos pendentes\n- Prazos vinculados\n\nOs cadastros dos clientes NÃO serão afetados.\n\nEsta ação NÃO pode ser desfeita!')) return;
    if (!confirm('Tem CERTEZA ABSOLUTA? Digite OK na próxima caixa para confirmar.')) return;
    var resp = prompt('Digite EXCLUIR para confirmar a exclusão permanente:');
    if (resp !== 'EXCLUIR') { alert('Exclusão cancelada.'); return; }

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?= module_url("operacional", "api.php") ?>';
    function addH(n, v) { var i = document.createElement('input'); i.type='hidden'; i.name=n; i.value=v; form.appendChild(i); }
    addH('<?= CSRF_TOKEN_NAME ?>', '<?= generate_csrf_token() ?>');
    addH('action', 'delete_case');
    addH('case_id', '<?= $caseId ?>');
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php if ($case['client_id'] && can_access('cobranca_honorarios')): ?>
<!-- Modal Inadimplência -->
<div id="modalInadimplencia" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:480px;width:95%;box-shadow:0 20px 40px rgba(0,0,0,.2);">
    <h3 style="font-size:1rem;margin-bottom:1rem;color:#052228;">⚠️ Marcar Inadimplência</h3>
    <form method="POST" action="<?= module_url('cobranca_honorarios', 'api.php') ?>">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="criar_cobranca">
        <input type="hidden" name="client_id" value="<?= $case['client_id'] ?>">
        <input type="hidden" name="case_id" value="<?= $caseId ?>">

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Tipo do débito *</label>
            <select name="tipo_debito" class="form-select" required>
                <option value="Honorários advocatícios">Honorários advocatícios</option>
                <option value="Honorários contratuais">Honorários contratuais</option>
                <option value="Honorários de êxito">Honorários de êxito</option>
                <option value="Custas processuais">Custas processuais</option>
                <option value="Outro">Outro</option>
            </select>
        </div>

        <div style="display:flex;gap:.5rem;margin-bottom:.6rem;">
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Valor (R$) *</label>
                <input type="text" name="valor_total" class="form-input input-reais" required placeholder="0,00">
            </div>
            <div style="flex:1;">
                <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Vencimento *</label>
                <input type="date" name="vencimento" class="form-input" required>
            </div>
        </div>

        <div style="margin-bottom:.6rem;">
            <label style="font-size:.75rem;font-weight:700;display:block;margin-bottom:.15rem;">Observação</label>
            <textarea name="observacoes" rows="2" class="form-input" style="resize:vertical;" placeholder="Detalhes adicionais..."></textarea>
        </div>

        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border);">
            <button type="button" onclick="document.getElementById('modalInadimplencia').style.display='none';" class="btn btn-outline btn-sm">Cancelar</button>
            <button type="submit" class="btn btn-primary btn-sm" style="background:#dc2626;">Registrar Inadimplência</button>
        </div>
    </form>
</div></div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     MODAL: IMPORTAÇÃO EM LOTE DE ANDAMENTOS
     Passo 1: colar bloco pipe-delimited
     Passo 2: prévia com status por linha + checkbox
     Passo 3: gravar selecionados (transação atômica)
═══════════════════════════════════════════════════════ -->
<div id="modalImportAnd" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99999;align-items:flex-start;justify-content:center;padding:1.5rem;overflow-y:auto;">
    <div style="background:#fff;border-radius:14px;padding:1.5rem;max-width:1100px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);margin-top:1rem;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;">
            <h3 style="margin:0;color:#052228;font-size:1.1rem;">📋 Importar Andamentos em Lote</h3>
            <button type="button" onclick="fecharImportAndamentos()" style="background:#f3f4f6;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:1.2rem;">×</button>
        </div>

        <!-- Passo 1: textarea -->
        <div id="impAndStep1">
            <p style="margin:0 0 .6rem;font-size:.82rem;color:#475569;">
                Cole abaixo o bloco gerado pela IA a partir dos autos. Formato: <strong>DATA|HORA|TIPO|DESCRICAO</strong> (uma linha por andamento).
                <a href="#" onclick="document.getElementById('impAndExemplo').style.display = document.getElementById('impAndExemplo').style.display === 'block' ? 'none' : 'block'; return false;" style="color:#1e40af;">Ver formato esperado ▾</a>
            </p>
            <pre id="impAndExemplo" style="display:none;background:#f1f5f9;padding:.6rem .8rem;border-radius:6px;font-size:.72rem;overflow-x:auto;margin:0 0 .6rem;border:1px solid #e2e8f0;">DATA|HORA|TIPO|DESCRICAO
2026-02-22|16:25|PROTOCOLO|Protocolo da petição inicial da Ação de Alimentos com pedido de fixação de alimentos provisórios.
2026-02-25|16:53|DECISAO|Decisão deferindo gratuidade de justiça e arbitrando alimentos provisórios.
2026-02-26||PUBLICACAO_DJEN|Disponibilização da decisão no DJEN (data fictícia).

Tipos aceitos (lowercase, CAPS também funciona):
protocolo, distribuicao, decisao, despacho, sentenca, acordao, ato_ordinatorio,
certidao, intimacao, citacao, publicacao_djen, peticao_parte_autora, peticao_parte_re,
manifestacao_mp, audiencia, mandado_expedido, cumprimento, recurso, acordo,
diligencia, movimentacao, observacao

HORA é opcional — use `||` se não tiver (aparecerá sem prefixo).
Se usar hora, vira '[HH:MM] descrição...' no registro.</pre>
            <textarea id="impAndTextarea" rows="18" placeholder="DATA|HORA|TIPO|DESCRICAO&#10;2026-02-22|16:25|PROTOCOLO|Texto da descrição..." style="width:100%;padding:.6rem;border:1.5px solid #d1d5db;border-radius:8px;font-family:'Courier New',monospace;font-size:.78rem;resize:vertical;"></textarea>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:.75rem;">
                <button type="button" onclick="fecharImportAndamentos()" style="background:#fff;border:1.5px solid #d1d5db;padding:.5rem 1rem;border-radius:8px;cursor:pointer;font-size:.85rem;">Cancelar</button>
                <button type="button" id="impAndBtnAnalisar" onclick="analisarImportAndamentos()" style="background:#1e40af;color:#fff;border:none;padding:.5rem 1.3rem;border-radius:8px;cursor:pointer;font-weight:700;font-size:.85rem;">🔎 Analisar</button>
            </div>
        </div>

        <!-- Passo 2: prévia -->
        <div id="impAndStep2" style="display:none;">
            <div id="impAndResumo" style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:.6rem .8rem;margin-bottom:.6rem;font-size:.82rem;color:#334155;"></div>
            <div style="max-height:50vh;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;">
                <table id="impAndTabela" style="width:100%;border-collapse:collapse;font-size:.78rem;">
                    <thead style="background:#f1f5f9;position:sticky;top:0;z-index:2;">
                        <tr>
                            <th style="padding:.5rem;text-align:center;width:28px;"><input type="checkbox" id="impAndCheckAll" onchange="impAndToggleAll(this)"></th>
                            <th style="padding:.5rem;text-align:left;width:50px;">#</th>
                            <th style="padding:.5rem;text-align:left;width:95px;">Data</th>
                            <th style="padding:.5rem;text-align:left;width:60px;">Hora</th>
                            <th style="padding:.5rem;text-align:left;width:150px;">Tipo</th>
                            <th style="padding:.5rem;text-align:left;">Descrição</th>
                            <th style="padding:.5rem;text-align:center;width:80px;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="impAndTbody"></tbody>
                </table>
            </div>
            <div style="display:flex;justify-content:space-between;gap:.5rem;margin-top:.75rem;align-items:center;">
                <button type="button" onclick="impAndVoltar()" style="background:#fff;border:1.5px solid #d1d5db;padding:.5rem 1rem;border-radius:8px;cursor:pointer;font-size:.85rem;">← Voltar editar</button>
                <div>
                    <button type="button" onclick="fecharImportAndamentos()" style="background:#fff;border:1.5px solid #d1d5db;padding:.5rem 1rem;border-radius:8px;cursor:pointer;font-size:.85rem;margin-right:.4rem;">Cancelar</button>
                    <button type="button" id="impAndBtnGravar" onclick="gravarImportAndamentos()" style="background:#059669;color:#fff;border:none;padding:.5rem 1.3rem;border-radius:8px;cursor:pointer;font-weight:700;font-size:.85rem;">✓ Cadastrar selecionados (0)</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    var caseId = <?= (int)$caseId ?>;
    var apiUrl = '<?= module_url('operacional', 'api.php') ?>';
    var csrfTok = '<?= generate_csrf_token() ?>';
    var parseados = [];  // resultado do analisar

    window.abrirImportAndamentos = function() {
        document.getElementById('impAndTextarea').value = '';
        document.getElementById('impAndStep1').style.display = 'block';
        document.getElementById('impAndStep2').style.display = 'none';
        document.getElementById('modalImportAnd').style.display = 'flex';
    };
    window.fecharImportAndamentos = function() {
        document.getElementById('modalImportAnd').style.display = 'none';
    };
    window.impAndVoltar = function() {
        document.getElementById('impAndStep2').style.display = 'none';
        document.getElementById('impAndStep1').style.display = 'block';
    };

    window.analisarImportAndamentos = function() {
        var texto = document.getElementById('impAndTextarea').value;
        if (!texto.trim()) { alert('Cole o bloco de andamentos primeiro.'); return; }
        var btn = document.getElementById('impAndBtnAnalisar');
        btn.disabled = true; btn.textContent = 'Analisando...';

        var fd = new FormData();
        fd.append('action', 'andamentos_importar_analisar');
        fd.append('case_id', caseId);
        fd.append('bloco', texto);
        fd.append('<?= CSRF_TOKEN_NAME ?>', csrfTok);

        fetch(apiUrl, { method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'}, body: fd })
            .then(function(r){ return r.text().then(function(t){ try { return JSON.parse(t); } catch(e) { return { error: 'HTTP ' + r.status + ': ' + t.substring(0,200) }; } }); })
            .then(function(d){
                btn.disabled = false; btn.textContent = '🔎 Analisar';
                if (d.error) { alert('Falha: ' + d.error); return; }
                if (d.csrf) csrfTok = d.csrf;
                parseados = d.linhas || [];
                renderImpAndPreview(d);
            })
            .catch(function(e){ btn.disabled = false; btn.textContent = '🔎 Analisar'; alert('Erro: ' + e.message); });
    };

    function escImpAnd(s) { return (s||'').replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

    function renderImpAndPreview(d) {
        document.getElementById('impAndStep1').style.display = 'none';
        document.getElementById('impAndStep2').style.display = 'block';

        var resumo = document.getElementById('impAndResumo');
        resumo.innerHTML = '<strong>' + d.total + '</strong> linha(s) processada(s): '
                         + '<span style="color:#059669;font-weight:700;">✓ ' + d.total_ok + ' pronta(s)</span> · '
                         + '<span style="color:#b45309;font-weight:700;">⚠ ' + d.total_warn + ' com aviso</span> · '
                         + '<span style="color:#dc2626;font-weight:700;">✗ ' + d.total_err + ' com erro</span>';

        var tbody = document.getElementById('impAndTbody');
        var html = '';
        parseados.forEach(function(p, idx) {
            var statusCor = p.status === 'ok' ? '#059669' : (p.status === 'warn' ? '#b45309' : '#dc2626');
            var statusIcon = p.status === 'ok' ? '✓' : (p.status === 'warn' ? '⚠' : '✗');
            var statusLabel = p.status === 'ok' ? 'Pronto' : (p.status === 'warn' ? 'Atenção' : 'Erro');
            var bg = p.status === 'ok' ? '#f0fdf4' : (p.status === 'warn' ? '#fffbeb' : '#fef2f2');
            var canCheck = (p.status === 'ok' || p.status === 'warn');
            html += '<tr style="border-bottom:1px solid #f3f4f6;background:' + bg + ';" data-idx="' + idx + '">';
            html += '<td style="padding:.4rem;text-align:center;"><input type="checkbox" class="imp-and-cb" ' + (canCheck ? 'checked' : 'disabled') + ' onchange="impAndAtualizarContador()"></td>';
            html += '<td style="padding:.4rem;color:#64748b;">#' + p.n + '</td>';
            if (p.status !== 'erro') {
                html += '<td style="padding:.4rem;font-family:monospace;">' + escImpAnd(p.data) + '</td>';
                html += '<td style="padding:.4rem;font-family:monospace;">' + (p.hora ? escImpAnd(p.hora) : '<span style="color:#94a3b8;">—</span>') + '</td>';
                var tipoHtml = '<code style="background:#e0e7ff;color:#1e40af;padding:1px 6px;border-radius:3px;">' + escImpAnd(p.tipo) + '</code>';
                if (p.tipo_original && p.tipo_original.toLowerCase().replace(/\s+/g,'_') !== p.tipo) {
                    tipoHtml += '<div style="font-size:.65rem;color:#94a3b8;margin-top:2px;">← ' + escImpAnd(p.tipo_original) + '</div>';
                }
                html += '<td style="padding:.4rem;">' + tipoHtml + '</td>';
                html += '<td style="padding:.4rem;max-width:400px;"><div style="max-height:3em;overflow:hidden;line-height:1.5em;">' + escImpAnd(p.descricao) + '</div>';
                if (p.aviso) html += '<div style="font-size:.68rem;color:#b45309;margin-top:2px;">⚠ ' + escImpAnd(p.aviso) + '</div>';
                html += '</td>';
            } else {
                html += '<td colspan="4" style="padding:.4rem;color:#991b1b;">' + escImpAnd(p.motivo || 'Erro desconhecido') + '<div style="font-size:.68rem;color:#94a3b8;font-family:monospace;margin-top:2px;">' + escImpAnd(p.bruto || '') + '</div></td>';
            }
            html += '<td style="padding:.4rem;text-align:center;"><span style="color:' + statusCor + ';font-weight:700;">' + statusIcon + ' ' + statusLabel + '</span></td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
        impAndAtualizarContador();
    }

    window.impAndToggleAll = function(cb) {
        document.querySelectorAll('.imp-and-cb').forEach(function(c){ if (!c.disabled) c.checked = cb.checked; });
        impAndAtualizarContador();
    };
    window.impAndAtualizarContador = function() {
        var n = document.querySelectorAll('.imp-and-cb:checked').length;
        document.getElementById('impAndBtnGravar').textContent = '✓ Cadastrar selecionados (' + n + ')';
        document.getElementById('impAndBtnGravar').disabled = (n === 0);
    };

    window.gravarImportAndamentos = function() {
        var selecionados = [];
        document.querySelectorAll('#impAndTbody tr').forEach(function(tr) {
            var cb = tr.querySelector('.imp-and-cb');
            if (cb && cb.checked) {
                var idx = parseInt(tr.getAttribute('data-idx'), 10);
                var p = parseados[idx];
                if (p && p.status !== 'erro') {
                    selecionados.push({ data: p.data, hora: p.hora || '', tipo: p.tipo, descricao: p.descricao });
                }
            }
        });
        if (!selecionados.length) { alert('Nenhum andamento selecionado.'); return; }
        if (!confirm('Cadastrar ' + selecionados.length + ' andamento(s) neste processo?')) return;

        var btn = document.getElementById('impAndBtnGravar');
        btn.disabled = true; btn.textContent = 'Gravando...';

        var fd = new FormData();
        fd.append('action', 'andamentos_importar_gravar');
        fd.append('case_id', caseId);
        fd.append('selecionados', JSON.stringify(selecionados));
        fd.append('<?= CSRF_TOKEN_NAME ?>', csrfTok);

        fetch(apiUrl, { method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With':'XMLHttpRequest'}, body: fd })
            .then(function(r){ return r.text().then(function(t){ try { return JSON.parse(t); } catch(e) { return { error: 'HTTP ' + r.status }; } }); })
            .then(function(d){
                if (d.error) { alert('Falha: ' + d.error); btn.disabled = false; btn.textContent = '✓ Cadastrar selecionados'; return; }
                if (d.csrf) csrfTok = d.csrf;
                // Toast de sucesso
                var toast = document.createElement('div');
                toast.textContent = '✓ ' + d.gravados + ' andamento(s) cadastrado(s) com sucesso.';
                toast.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#059669;color:#fff;padding:12px 20px;border-radius:8px;font-weight:700;z-index:100001;box-shadow:0 8px 24px rgba(0,0,0,.25);';
                document.body.appendChild(toast);
                setTimeout(function(){ toast.remove(); }, 3500);
                fecharImportAndamentos();
                // Recarrega pra listar os andamentos novos
                setTimeout(function(){ location.reload(); }, 1200);
            })
            .catch(function(e){ btn.disabled = false; btn.textContent = '✓ Cadastrar selecionados'; alert('Erro: ' + e.message); });
    };
})();
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
