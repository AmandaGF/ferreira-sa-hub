<?php
/**
 * Ferreira & Sá Hub — Detalhe do Caso (Operacional)
 */

require_once __DIR__ . '/../../core/middleware.php';
require_login();

$pdo = db();
$caseId = (int)($_GET['id'] ?? 0);
$userId = current_user_id();
$isColaborador = has_role('colaborador');

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
    'aguardando_docs' => 'Contrato Assinado — Aguardando Docs',
    'em_elaboracao' => 'Pasta Apta',
    'em_andamento' => 'Em Execução',
    'doc_faltante' => 'Documento Faltante',
    'aguardando_prazo' => 'Aguardando Distribuição / Extrajudicial',
    'distribuido' => 'Processo Distribuído',
    'concluido' => 'Concluído',
    'arquivado' => 'Arquivado',
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
</style>

<div style="display:flex;gap:.5rem;margin-bottom:.75rem;flex-wrap:wrap;">
    <a href="<?= module_url('operacional') ?>" class="btn btn-outline btn-sm">← Voltar</a>
    <a href="<?= module_url('peticoes', 'index.php?case_id=' . $caseId) ?>" class="btn btn-primary btn-sm" style="background:#B87333;">📝 Fábrica de Petições</a>
    <a href="<?= module_url('documentos') . '?client_id=' . ($case['client_id'] ?: '') ?>" class="btn btn-primary btn-sm" style="background:#052228;">📄 Documentos</a>
    <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="margin-left:auto;" data-confirm="Excluir este caso permanentemente? Esta ação não pode ser desfeita.">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="delete_case">
        <input type="hidden" name="case_id" value="<?= $caseId ?>">
        <button type="submit" class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#dc2626;">🗑️ Excluir Caso</button>
    </form>
</div>

<!-- Header do caso -->
<div class="caso-header">
    <h2 style="display:flex;align-items:center;gap:.5rem;">
        <span id="casoTitulo" onclick="editarTitulo()" style="cursor:pointer;" title="Clique para editar o nome da pasta"><?= e($case['title']) ?></span>
        <span onclick="editarTitulo()" style="cursor:pointer;font-size:.7rem;opacity:.6;" title="Editar nome">✏️</span>
    </h2>
    <form id="formTitulo" method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:none;margin-bottom:.5rem;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="update_title">
        <input type="hidden" name="case_id" value="<?= $caseId ?>">
        <div style="display:flex;gap:.35rem;align-items:center;">
            <input type="text" name="title" id="inputTitulo" value="<?= e($case['title']) ?>" style="flex:1;padding:.4rem .6rem;font-size:1rem;font-weight:700;border:2px solid rgba(255,255,255,.4);border-radius:8px;background:rgba(255,255,255,.15);color:#fff;font-family:inherit;">
            <button type="submit" style="background:#059669;color:#fff;border:none;padding:.4rem .8rem;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;">Salvar</button>
            <button type="button" onclick="cancelarTitulo()" style="background:rgba(255,255,255,.15);color:#fff;border:none;padding:.4rem .6rem;border-radius:8px;font-size:.78rem;cursor:pointer;">✕</button>
        </div>
    </form>
    <div class="meta">
        👤 <?= e($case['client_name'] ?? 'Sem cliente') ?>
        · <?= e($case['case_type']) ?>
        · <?= e($case['responsible_name'] ?: 'Sem responsável') ?>
        <?php if ($case['deadline']): ?> · Prazo: <?= data_br($case['deadline']) ?><?php endif; ?>
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
            · <?= e($case['comarca']) ?>
        <?php endif; ?>
        <?php if ($case['distribution_date']): ?>
            · Distribuído em <?= data_br($case['distribution_date']) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="actions">
        <?php if ($case['client_phone']): ?>
            <a href="https://wa.me/55<?= preg_replace('/\D/', '', $case['client_phone']) ?>" target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
        <?php if ($case['client_id']): ?>
            <a href="<?= module_url('clientes', 'ver.php?id=' . $case['client_id']) ?>" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.3);">👤 Ver cliente</a>
        <?php endif; ?>
    </div>
</div>

<!-- Atalhos rápidos -->
<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <a href="<?= module_url('agenda') ?>?novo=1&case_id=<?= $caseId ?>&client_id=<?= $case['client_id'] ?: '' ?>" class="btn btn-outline btn-sm" style="font-size:.78rem;">📅 + Compromisso</a>
    <a href="<?= module_url('prazos') ?>?novo=1&case_id=<?= $caseId ?>" class="btn btn-outline btn-sm" style="font-size:.78rem;">⏰ + Prazo</a>
    <a href="#tarefas" class="btn btn-outline btn-sm" style="font-size:.78rem;" onclick="document.querySelector('[name=title]').focus();">✓ + Tarefa</a>
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
                    <select name="status" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($statusLabels as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $case['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
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
                        <button type="submit" class="task-check <?= $task['status'] === 'feito' ? 'done' : '' ?>" title="<?= $task['status'] === 'feito' ? 'Desfazer' : 'Concluir' ?>">
                            <?= $task['status'] === 'feito' ? '✓' : '' ?>
                        </button>
                    </form>
                    <span class="task-text <?= $task['status'] === 'feito' ? 'done' : '' ?>"><?= e($task['title']) ?></span>
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
    </div>
    <div class="card-body">
        <!-- Formulário de novo andamento -->
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

        <?php if (empty($andamentos)): ?>
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
                <div style="position:relative;margin-bottom:16px;padding-left:20px;">
                    <!-- Bolinha da timeline -->
                    <div style="position:absolute;left:-20px;top:6px;width:18px;height:18px;border-radius:50%;background:<?= $cor ?>;display:flex;align-items:center;justify-content:center;font-size:10px;z-index:1;"><?= $icon ?></div>

                    <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 16px;border-left:3px solid <?= $cor ?>;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px;">
                            <div>
                                <span style="font-size:.72rem;font-weight:700;color:<?= $cor ?>;text-transform:uppercase;letter-spacing:.5px;"><?= $lbl ?></span>
                                <span style="font-size:.7rem;color:var(--text-muted);margin-left:8px;"><?= date('d/m/Y', strtotime($and['data_andamento'])) ?></span>
                            </div>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:.68rem;color:var(--text-muted);"><?= e($and['user_name'] ?: '') ?></span>
                                <?php if (has_min_role('gestao') || (int)($and['created_by'] ?? 0) === $userId): ?>
                                <form method="POST" action="<?= module_url('operacional', 'api.php') ?>" style="display:inline;" data-confirm="Excluir este andamento?">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="action" value="delete_andamento">
                                    <input type="hidden" name="andamento_id" value="<?= $and['id'] ?>">
                                    <input type="hidden" name="case_id" value="<?= $caseId ?>">
                                    <button type="submit" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:.7rem;padding:2px 4px;" title="Excluir">✕</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p style="font-size:.85rem;margin:0;white-space:pre-wrap;line-height:1.5;"><?= e($and['descricao']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
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
function editarTitulo() {
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
</script>
<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
