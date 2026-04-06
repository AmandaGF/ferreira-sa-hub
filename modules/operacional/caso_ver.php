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

$statusLabels = array(
    'em_andamento' => 'Processo em Andamento',
    'suspenso'     => 'Processo Suspenso',
    'arquivado'    => 'Processo Finalizado / Arquivado',
    'renunciamos'  => 'Renunciamos',
);

$statusCores = array(
    'em_andamento' => '#059669',  // verde
    'suspenso'     => '#d97706',  // amarelo/laranja
    'arquivado'    => '#dc2626',  // vermelho
    'renunciamos'  => '#6b7280',  // cinza
);

$clientPhone = $case['client_phone'] ? preg_replace('/\D/', '', $case['client_phone']) : '';
$clientWhatsapp = $clientPhone ? 'https://wa.me/55' . $clientPhone : '';

// Processos incidentais
$incidentais = array();
$processoPrincipal = null;
try {
    // Se este é o principal → buscar incidentais vinculados
    $stmtInc = $pdo->prepare("SELECT id, title, case_number, case_type, status, tipo_relacao FROM cases WHERE processo_principal_id = ? ORDER BY created_at DESC");
    $stmtInc->execute(array($caseId));
    $incidentais = $stmtInc->fetchAll();

    // Se este é incidental → buscar o principal
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
</style>

<div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap;">
    <?php
    $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $fromProcessos = (strpos($referer, '/processos') !== false);
    $voltarUrl = $fromProcessos ? module_url('processos') : module_url('operacional');
    $voltarLabel = $fromProcessos ? 'Processos' : 'Operacional';
    ?>
    <a href="<?= $voltarUrl ?>" class="btn btn-outline btn-sm">&larr; <?= $voltarLabel ?></a>
    <a href="<?= module_url('peticoes', 'index.php?case_id=' . $caseId) ?>" class="btn btn-primary btn-sm" style="background:#B87333;">📝 Fábrica de Petições</a>
    <a href="<?= module_url('documentos') . '?client_id=' . ($case['client_id'] ?: '') . '&case_id=' . $caseId ?>" class="btn btn-primary btn-sm" style="background:#052228;">📄 Documentos</a>
    <?php if ($case['client_id']): ?>
        <a href="<?= module_url('operacional', 'caso_novo.php?client_id=' . $case['client_id']) ?>" class="btn btn-outline btn-sm">+ Novo Processo</a>
    <?php endif; ?>
    <a href="<?= module_url('helpdesk', 'novo.php?caso_id=' . $caseId . '&from_case=' . $caseId) ?>" class="btn btn-primary btn-sm" style="background:#dc2626;">🎫 Abrir Chamado</a>
    <?php if ($case['client_id'] && can_access('financeiro')): ?>
        <a href="<?= module_url('financeiro', 'cliente.php?id=' . $case['client_id'] . '&from_case=' . $caseId) ?>" class="btn btn-outline btn-sm">Financeiro</a>
    <?php endif; ?>
    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="margin-left:auto;" data-confirm="Excluir este caso permanentemente? Esta ação não pode ser desfeita.">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete_case">
        <input type="hidden" name="case_id" value="<?= $caseId ?>">
        <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#dc2626;">🗑️ Excluir Caso</button>
    </form>
</div>

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
            <span onclick="copiarNumero(this)" style="font-family:monospace;font-size:.85rem;background:rgba(255,255,255,.15);padding:2px 8px;border-radius:4px;cursor:pointer;transition:all .2s;" title="Clique para copiar o nº do processo"><?= e($case['case_number']) ?></span>
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
        <?php if ($clientWhatsapp):
            $msgCaso = "Olá " . ($case['client_name'] ?: '') . ", tudo bem? Aqui é do escritório Ferreira & Sá Advocacia. Entramos em contato sobre o seu processo" . ($case['title'] ? " (" . $case['title'] . ")" : "") . ".";
        ?>
            <a href="<?= $clientWhatsapp ?>?text=<?= urlencode($msgCaso) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
        <?php if ($case['client_id']): ?>
            <a href="<?= module_url('clientes', 'ver.php?id=' . $case['client_id']) ?>" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);">👤 Ver cliente</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($processoPrincipal): ?>
<!-- Banner: Este é um processo incidental -->
<div style="background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-radius:var(--radius-lg);padding:.75rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
    <span style="font-size:1.1rem;">🔗</span>
    <div style="flex:1;">
        <span style="font-size:.82rem;font-weight:700;">Processo incidental de:</span>
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

<!-- Atalhos rápidos -->
<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <a href="<?= module_url('tarefas') ?>?case_id=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#6366f1;">+ Criar Tarefa</a>
    <a href="<?= module_url('agenda') ?>?novo=1&tipo=audiencia&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>&voltar_caso=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#052228;">Agendar Audiência</a>
    <a href="<?= module_url('agenda') ?>?novo=1&tipo=reuniao_cliente&modalidade=online&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>&voltar_caso=<?= $caseId ?>" class="btn btn-primary btn-sm" style="font-size:.78rem;background:#059669;">Reunião + Meet</a>
    <a href="<?= module_url('agenda') ?>?novo=1&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>&voltar_caso=<?= $caseId ?>" class="btn btn-outline btn-sm" style="font-size:.78rem;">+ Compromisso</a>
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
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.8rem;">
            <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:.2rem;">Papel</label>
                <select id="partePapel" class="form-select" style="font-size:.85rem;" onchange="mudouPapel()">
                    <option value="autor">Autor</option>
                    <option value="reu">Réu</option>
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
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">CPF</label><input id="parteCpf" class="form-input" style="font-size:.85rem;" placeholder="000.000.000-00" onblur="buscarCpfParte()"><span id="parteCpfStatus" style="font-size:.65rem;"></span></div>
                <div><label style="font-size:.72rem;font-weight:600;color:var(--text-muted);">Nome Completo</label><input id="parteNome" class="form-input" style="font-size:.85rem;"></div>
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

<!-- Checklist de Tarefas -->
<div class="card mb-2">
    <div class="card-header">
        <h3>Tarefas (<?= count($tasks) ?>)</h3>
    </div>
    <div class="card-body">
        <?php if (empty($tasks)): ?>
            <p class="text-muted text-sm">Nenhuma tarefa cadastrada.</p>
        <?php else: ?>
            <ul class="task-list">
                <?php foreach ($tasks as $task): ?>
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
                        <?php if ($task['assigned_name']): ?><?= e($task['assigned_name']) ?><?php endif; ?>
                        <?php if ($task['due_date']): ?> · <?= data_br($task['due_date']) ?><?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <!-- Adicionar tarefa -->
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:flex;gap:.5rem;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--border);">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_task">
            <input type="hidden" name="case_id" value="<?= $caseId ?>">
            <input type="text" name="title" class="form-input" placeholder="Nova tarefa..." required style="flex:1;">
            <select name="assigned_to" class="form-select" style="width:140px;">
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
                <span style="font-size:.8rem;font-weight:700;color:#dc2626;">Lancar Publicacao / Intimacao</span>
                <span id="pubFormArrow" style="font-size:.7rem;color:#dc2626;">&#9660;</span>
            </div>
            <div id="pubFormWrap" style="display:none;">
                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>">
                    <?= csrf_input() ?>
                    <input type="hidden" name="action" value="add_publicacao">
                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                    <div style="display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap;">
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Data Disponibilizacao *</label>
                            <input type="date" name="data_disponibilizacao" class="form-input" value="<?= date('Y-m-d') ?>" required style="width:160px;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Data Publicacao</label>
                            <input type="date" name="data_publicacao" class="form-input" style="width:160px;">
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Tipo</label>
                            <select name="tipo_publicacao" class="form-select" style="width:150px;" id="tipoPubSelect" onchange="sugerirPrazo(this.value)">
                                <option value="intimacao">Intimacao</option>
                                <option value="citacao">Citacao</option>
                                <option value="despacho">Despacho</option>
                                <option value="decisao">Decisao</option>
                                <option value="sentenca">Sentenca</option>
                                <option value="acordao">Acordao</option>
                                <option value="edital">Edital</option>
                                <option value="outro">Outro</option>
                            </select>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:2px;">
                            <label style="font-size:.68rem;color:var(--text-muted);font-weight:600;">Prazo (dias uteis)</label>
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
                        placeholder="Cole aqui o texto completo da publicacao/intimacao..."
                        required style="width:100%;font-size:.83rem;margin-bottom:.5rem;border-color:#fca5a5;"></textarea>
                    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <label style="font-size:.75rem;display:flex;align-items:center;gap:4px;cursor:pointer;">
                            <input type="checkbox" name="visivel_cliente" value="1"> Visivel ao cliente
                        </label>
                        <button type="submit" class="btn btn-sm" style="background:#dc2626;color:#fff;border:none;">
                            Registrar Publicacao
                        </button>
                        <span style="font-size:.68rem;color:var(--text-muted);">
                            A data de disponibilizacao e o marco legal do prazo (art. 224 CPC)
                        </span>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Formulario de novo andamento -->
        <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="margin-bottom:1rem;padding-bottom:1rem;border-bottom:1px solid var(--border);">
            <?= csrf_input() ?>
            <input type="hidden" name="action" value="add_andamento">
            <input type="hidden" name="case_id" value="<?= $caseId ?>">
            <div style="display:flex;gap:.5rem;margin-bottom:.5rem;flex-wrap:wrap;">
                <input type="date" name="data_andamento" class="form-input" value="<?= date('Y-m-d') ?>" required style="width:150px;">
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
                Publicacoes (<?= count($publicacoes) ?>)
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
                    'acordo'=>'🤝','recurso'=>'📑','cumprimento'=>'✅','diligencia'=>'🔍','observacao'=>'💬'
                );
                $tipoCores = array(
                    'movimentacao'=>'#888','despacho'=>'#B87333','decisao'=>'#052228','sentenca'=>'#052228',
                    'audiencia'=>'#6B4C9A','peticao_juntada'=>'#059669','intimacao'=>'#dc2626','citacao'=>'#dc2626',
                    'acordo'=>'#2D7A4F','recurso'=>'#1a3a7a','cumprimento'=>'#059669','diligencia'=>'#B87333','observacao'=>'#888'
                );
                $tipoLabels = array(
                    'movimentacao'=>'Movimentação','despacho'=>'Despacho','decisao'=>'Decisão','sentenca'=>'Sentença',
                    'audiencia'=>'Audiência','peticao_juntada'=>'Petição juntada','intimacao'=>'Intimação','citacao'=>'Citação',
                    'acordo'=>'Acordo','recurso'=>'Recurso','cumprimento'=>'Cumprimento','diligencia'=>'Diligência','observacao'=>'Observação'
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
                                <span style="font-size:.72rem;font-weight:700;color:<?= $cor ?>;text-transform:uppercase;letter-spacing:.5px;"><?= $lbl ?></span>
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
                                <?php endif; ?>
                                <span style="font-size:.7rem;color:var(--text-muted);"><?= date('d/m/Y', strtotime($and['data_andamento'])) ?><?php if (!empty($and['created_at'])): ?> <span style="color:#94a3b8;"><?= date('H:i', strtotime($and['created_at'])) ?></span><?php endif; ?></span>
                                <?php
                                $visivel = isset($and['visivel_cliente']) ? (int)$and['visivel_cliente'] : 1;
                                $sigilo = isset($and['segredo_justica']) ? (int)$and['segredo_justica'] : 0;
                                ?>
                                <button onclick="toggleVisibilidade(<?= $and['id'] ?>, this)" title="<?= $visivel ? 'Visível ao cliente — clique para ocultar' : 'Oculto do cliente — clique para tornar visível' ?>" style="background:none;border:none;cursor:pointer;font-size:.68rem;padding:1px 5px;border-radius:3px;<?= $visivel ? 'background:#ecfdf5;color:#059669;' : 'background:#fef2f2;color:#dc2626;' ?>" data-vis="<?= $visivel ?>"><?= $visivel ? '&#128065; Visível' : '&#128274; Interno' ?></button>
                                <?php if ($sigilo): ?><span style="font-size:.6rem;background:#fef2f2;color:#dc2626;padding:1px 4px;border-radius:3px;font-weight:600;">Segredo</span><?php endif; ?>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:.68rem;color:var(--text-muted);"><?= e($and['user_name'] ?: '') ?></span>
                                <?php if ($clientWhatsapp):
                                    $refProcesso = $case['case_number'] ? " (Proc. " . $case['case_number'] . ")" : ($case['title'] ? " (" . $case['title'] . ")" : "");
                                    $msgAnd = "Ola " . ($case['client_name'] ?: '') . ", informamos sobre o andamento do seu processo" . $refProcesso . ":\n\n*" . $lbl . "* - " . date('d/m/Y', strtotime($and['data_andamento'])) . "\n" . $and['descricao'] . "\n\nQualquer duvida, estamos a disposicao.\nFerreira e Sa Advocacia";
                                    $waFullUrl = $clientWhatsapp . '?text=' . rawurlencode($msgAnd);
                                    $jaEnviou = !empty($and['whatsapp_enviado_em']);
                                ?>
                                <span id="waBtnWrap<?= $and['id'] ?>" style="display:inline-flex;align-items:center;gap:4px;">
                                    <a href="<?= e($waFullUrl) ?>" target="_blank" onclick="logWhatsApp(<?= $and['id'] ?>)" style="background:#25D366;color:#fff;border-radius:4px;font-size:.7rem;padding:2px 8px;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:3px;" title="Enviar ao cliente via WhatsApp" id="waBtn<?= $and['id'] ?>">Enviar</a>
                                    <?php if ($jaEnviou): ?>
                                        <span style="font-size:.65rem;color:#059669;font-weight:600;" title="Enviado em <?= date('d/m/Y H:i', strtotime($and['whatsapp_enviado_em'])) ?>">✓ <?= date('d/m H:i', strtotime($and['whatsapp_enviado_em'])) ?></span>
                                    <?php endif; ?>
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

<!-- Observações -->
<?php if ($case['notes']): ?>
<div class="card">
    <div class="card-header"><h3>Observações</h3></div>
    <div class="card-body">
        <p style="white-space:pre-wrap;font-size:.88rem;"><?= e($case['notes']) ?></p>
    </div>
</div>
<?php endif; ?>

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
var papelLabels = {autor:'Autor',reu:'Réu',representante_legal:'Rep. Legal',terceiro_interessado:'3º Interessado',litisconsorte_ativo:'Litis. Ativo',litisconsorte_passivo:'Litis. Passivo'};
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
    ['parteNome','parteCpf','parteRg','parteNasc','parteEC','parteProf','parteEmail','parteCnpj','parteRazao','parteFantasia','parteRepNome','parteRepCpf','parteTel','parteCep','parteEnd','parteCid','parteUf','parteObs','parteEmailPJ'].forEach(function(id) {
        var el = document.getElementById(id); if(el) el.value = '';
    });
    document.getElementById('parteRepId').value = '';
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
    var input = document.createElement('textarea');
    input.value = textoAtual;
    input.style.cssText = 'width:100%;font-size:.85rem;padding:6px;border:2px solid #B87333;border-radius:6px;min-height:60px;font-family:inherit;resize:vertical;';
    var btns = document.createElement('div');
    btns.style.cssText = 'display:flex;gap:4px;margin-top:4px;';
    btns.innerHTML = '<button onclick="salvarAndamento(' + andId + ')" style="background:#059669;color:#fff;border:none;padding:3px 10px;border-radius:4px;font-size:.72rem;font-weight:600;cursor:pointer;">Salvar</button>'
        + '<button onclick="cancelarEdicaoAnd(' + andId + ')" style="background:#f3f4f6;border:none;padding:3px 10px;border-radius:4px;font-size:.72rem;cursor:pointer;">Cancelar</button>';
    descEl.style.display = 'none';
    descEl.parentNode.insertBefore(input, descEl.nextSibling);
    descEl.parentNode.insertBefore(btns, input.nextSibling);
    input.focus();
    input.id = 'andEdit' + andId;
    btns.id = 'andBtns' + andId;
}

function cancelarEdicaoAnd(andId) {
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
    var x = new XMLHttpRequest();
    x.open('POST', '<?= module_url("operacional", "api.php") ?>');
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    x.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    x.onload = function() {
        try { var r = JSON.parse(x.responseText);
            if (r.csrf) andCsrf = r.csrf;
            if (r.ok) {
                document.getElementById('andDesc' + andId).textContent = novoTexto;
                cancelarEdicaoAnd(andId);
            } else if (r.error) { alert(r.error); }
        } catch(e) { alert('Erro ao salvar'); }
    };
    x.send('action=edit_andamento&andamento_id=' + andId + '&descricao=' + encodeURIComponent(novoTexto) + '&case_id=<?= $caseId ?>&<?= CSRF_TOKEN_NAME ?>=' + andCsrf);
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
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
