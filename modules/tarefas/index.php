<?php
/**
 * Ferreira & Sá Hub — Kanban de Tarefas
 * Gestão visual do trabalho diário do operacional
 */
require_once __DIR__ . '/../../core/middleware.php';
require_login();
require_access('operacional');

$pageTitle = 'Tarefas';
$pdo = db();

$users = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

// KPIs
$kpis = array('pendentes' => 0, 'vencidas' => 0, 'concluidas_mes' => 0, 'prazos_7d' => 0);
try {
    $kpis['pendentes'] = (int)$pdo->query("SELECT COUNT(*) FROM case_tasks WHERE status IN ('a_fazer','em_andamento') AND tipo IS NOT NULL AND tipo != ''")->fetchColumn();
    $kpis['vencidas'] = (int)$pdo->query("SELECT COUNT(*) FROM case_tasks WHERE due_date < CURDATE() AND status NOT IN ('concluido') AND tipo IS NOT NULL AND tipo != ''")->fetchColumn();
    $kpis['concluidas_mes'] = (int)$pdo->query("SELECT COUNT(*) FROM case_tasks WHERE status='concluido' AND DATE_FORMAT(completed_at,'%Y-%m')='" . date('Y-m') . "' AND tipo IS NOT NULL AND tipo != ''")->fetchColumn();
    $kpis['prazos_7d'] = (int)$pdo->query("SELECT COUNT(*) FROM case_tasks WHERE tipo='prazo' AND status NOT IN ('concluido') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();
} catch (Exception $e) {}

require_once APP_ROOT . '/templates/layout_start.php';
echo voltar_ao_processo_html();
?>

<style>
.tk-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;margin-bottom:1.2rem}
.tk-kpi{background:var(--bg-card,#fff);border:1.5px solid var(--border,#e5e7eb);border-radius:12px;padding:.8rem 1rem;text-align:center}
.tk-kpi-n{font-size:1.6rem;font-weight:800;color:var(--petrol-900,#052228)}
.tk-kpi-l{font-size:.72rem;color:var(--text-muted,#6b7280);text-transform:uppercase;letter-spacing:.3px}
.tk-kpi.vermelho .tk-kpi-n{color:#dc2626}
.tk-kpi.verde .tk-kpi-n{color:#059669}
.tk-kpi.azul .tk-kpi-n{color:#0284c7}
.tk-topo{display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;flex-wrap:wrap}
.tk-filtros{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.tk-filtros select{font-size:.78rem;padding:4px 8px;border:1.5px solid var(--border);border-radius:6px;background:var(--bg-card,#fff)}
.tk-board{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;align-items:start}
@media(max-width:1024px){.tk-board{grid-template-columns:repeat(2,1fr)}}
@media(max-width:600px){.tk-board{grid-template-columns:1fr}}
.tk-col{background:var(--bg,#f8f9fa);border-radius:12px;min-height:200px}
.tk-col-hd{padding:.7rem .8rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px;border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center}
.tk-col-bd{padding:.4rem .5rem;min-height:150px}
.tk-card{background:var(--bg-card,#fff);border:1.5px solid var(--border,#e5e7eb);border-radius:10px;padding:.65rem .75rem;margin-bottom:.5rem;cursor:pointer;transition:box-shadow .2s;border-left:4px solid #94a3b8}
.tk-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08)}
.tk-card-titulo{font-size:.82rem;font-weight:700;color:var(--text,#052228);margin-bottom:.3rem}
.tk-card-meta{font-size:.68rem;color:var(--text-muted,#6b7280);display:flex;flex-wrap:wrap;gap:.4rem}
.tk-badge{display:inline-block;padding:1px 6px;border-radius:4px;font-size:.6rem;font-weight:700;color:#fff}
.tk-prazo{font-size:.67rem;padding:1px 5px;border-radius:3px;font-weight:600}
.tk-prazo.vencido{background:#fef2f2;color:#dc2626}
.tk-prazo.alerta{background:#fffbeb;color:#d97706}
.tk-prazo.ok{color:var(--text-muted)}
.tk-avatar{width:22px;height:22px;border-radius:50%;background:var(--petrol-300,#2a5a66);color:#fff;font-size:.55rem;display:flex;align-items:center;justify-content:center;font-weight:700}
/* Modal */
.tk-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center}
.tk-overlay.aberto{display:flex}
.tk-modal{background:var(--bg-card,#fff);border-radius:14px;max-width:560px;width:95%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.tk-modal-hd{background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1rem 1.2rem;border-radius:14px 14px 0 0;display:flex;justify-content:space-between;align-items:center}
.tk-modal-bd{padding:1.2rem}
.tk-fg{margin-bottom:.8rem}
.tk-fl{display:block;font-size:.72rem;font-weight:600;color:var(--text-muted);margin-bottom:.25rem;text-transform:uppercase;letter-spacing:.3px}
.tk-fi{width:100%;padding:7px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.85rem;background:var(--bg-card,#fff);color:var(--text)}
.tk-fi:focus{border-color:#B87333;outline:none;box-shadow:0 0 0 3px rgba(184,115,51,.15)}
.tk-fr{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.tk-modal-ft{padding:.8rem 1.2rem;border-top:1px solid var(--border);display:flex;justify-content:space-between}
.tk-btn-cancel{background:none;border:1.5px solid var(--border);border-radius:8px;padding:7px 16px;font-size:.82rem;cursor:pointer;color:var(--text-muted)}
.tk-btn-save{background:var(--petrol-900,#052228);color:#fff;border:none;border-radius:8px;padding:7px 20px;font-size:.82rem;font-weight:600;cursor:pointer}
.tk-btn-del{background:none;border:1.5px solid #dc2626;color:#dc2626;border-radius:8px;padding:7px 12px;font-size:.78rem;cursor:pointer}
.tk-tipos-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem}
.tk-tipo-btn{padding:6px;border:1.5px solid var(--border);border-radius:8px;background:none;cursor:pointer;font-size:.72rem;text-align:center;transition:all .2s}
.tk-tipo-btn:hover{border-color:#B87333}
.tk-tipo-btn.sel{background:#052228;color:#fff;border-color:#052228}
</style>

<!-- KPIs -->
<div class="tk-kpis">
    <div class="tk-kpi"><div class="tk-kpi-n"><?= $kpis['pendentes'] ?></div><div class="tk-kpi-l">Pendentes</div></div>
    <div class="tk-kpi vermelho"><div class="tk-kpi-n"><?= $kpis['vencidas'] ?></div><div class="tk-kpi-l">Vencidas</div></div>
    <div class="tk-kpi verde"><div class="tk-kpi-n"><?= $kpis['concluidas_mes'] ?></div><div class="tk-kpi-l">Concluidas (mes)</div></div>
    <div class="tk-kpi azul"><div class="tk-kpi-n"><?= $kpis['prazos_7d'] ?></div><div class="tk-kpi-l">Prazos em 7 dias</div></div>
</div>

<!-- Topo -->
<div class="tk-topo">
    <div class="tk-filtros">
        <select id="fResp" onchange="reload()"><option value="">Todos</option><?php foreach($users as $u): ?><option value="<?=$u['id']?>"><?=e($u['name'])?></option><?php endforeach; ?></select>
        <select id="fTipo" onchange="reload()">
            <option value="">Tipo</option>
            <option value="peticionar">Peticionar</option>
            <option value="juntar_documento">Juntar doc</option>
            <option value="prazo">Prazo</option>
            <option value="oficio">Oficio</option>
            <option value="acordo">Acordo</option>
            <option value="outros">Outros</option>
        </select>
        <select id="fPrio" onchange="reload()"><option value="">Prioridade</option><option value="urgente">Urgente</option><option value="alta">Alta</option><option value="normal">Normal</option><option value="baixa">Baixa</option></select>
    </div>
    <div style="display:flex;gap:.5rem;">
        <button class="btn btn-outline btn-sm" style="font-size:.78rem;" onclick="toggleHistorico()">Histórico</button>
        <button class="btn btn-primary btn-sm" style="font-size:.82rem;" onclick="abrirModal()">+ Nova Tarefa</button>
    </div>
</div>

<!-- Kanban Board -->
<div class="tk-board" id="tkBoard">
    <div class="tk-col" data-status="a_fazer"><div class="tk-col-hd" style="background:#e0e7ff;color:#3730a3;">A Fazer <span id="cnt_a_fazer">0</span></div><div class="tk-col-bd" id="col_a_fazer"></div></div>
    <div class="tk-col" data-status="em_andamento"><div class="tk-col-hd" style="background:#dbeafe;color:#1d4ed8;">Em Andamento <span id="cnt_em_andamento">0</span></div><div class="tk-col-bd" id="col_em_andamento"></div></div>
    <div class="tk-col" data-status="aguardando"><div class="tk-col-hd" style="background:#fef3c7;color:#92400e;">Aguardando <span id="cnt_aguardando">0</span></div><div class="tk-col-bd" id="col_aguardando"></div></div>
    <div class="tk-col" data-status="concluido"><div class="tk-col-hd" style="background:#d1fae5;color:#065f46;">Concluido <span id="cnt_concluido">0</span></div><div class="tk-col-bd" id="col_concluido"></div></div>
</div>

<!-- Modal -->
<div class="tk-overlay" id="tkOverlay">
<div class="tk-modal">
    <div class="tk-modal-hd">
        <h3 style="margin:0;font-size:1rem;" id="tkModalTit">Nova Tarefa</h3>
        <button onclick="fecharModal()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer">X</button>
    </div>
    <div class="tk-modal-bd">
        <input type="hidden" id="tkId" value="0">

        <div class="tk-fg"><label class="tk-fl">Tipo</label>
            <div class="tk-tipos-grid">
                <button type="button" class="tk-tipo-btn" data-t="peticionar" onclick="selTipo(this)">Peticionar</button>
                <button type="button" class="tk-tipo-btn" data-t="juntar_documento" onclick="selTipo(this)">Juntar Doc</button>
                <button type="button" class="tk-tipo-btn" data-t="prazo" onclick="selTipo(this)">Prazo Processual</button>
                <button type="button" class="tk-tipo-btn" data-t="oficio" onclick="selTipo(this)">Oficio</button>
                <button type="button" class="tk-tipo-btn" data-t="acordo" onclick="selTipo(this)">Acordo</button>
                <button type="button" class="tk-tipo-btn" data-t="outros" onclick="selTipo(this)">Outros</button>
            </div>
        </div>

        <div class="tk-fg" id="tkOutroBox" style="display:none;">
            <label class="tk-fl">Especifique</label>
            <input type="text" class="tk-fi" id="tkTipoOutro" placeholder="Descreva o tipo...">
        </div>

        <div class="tk-fg" id="tkSubtipoBox" style="display:none;">
            <label class="tk-fl">Subtipo do Prazo</label>
            <select class="tk-fi" id="tkSubtipo">
                <option value="">Selecione...</option>
                <option value="Contestacao">Contestacao</option>
                <option value="Replica">Replica</option>
                <option value="Memoriais">Memoriais / Alegações Finais</option>
                <option value="Apelacao">Apelacao</option>
                <option value="Embargos de Declaracao">Embargos de Declaracao</option>
                <option value="Contrarrazoes">Contrarrazoes</option>
                <option value="outro">Outro prazo</option>
            </select>
            <input type="text" class="tk-fi" id="tkSubtipoOutro" style="display:none;margin-top:.4rem;" placeholder="Descreva o prazo...">
        </div>

        <div class="tk-fg"><label class="tk-fl">Título</label>
            <input type="text" class="tk-fi" id="tkTitulo" placeholder="Ex: Elaborar contestação do caso Silva">
        </div>

        <div class="tk-fg"><label class="tk-fl">Processo vinculado</label>
            <input type="text" class="tk-fi" id="tkCasoBusca" placeholder="Buscar processo..." autocomplete="off">
            <input type="hidden" id="tkCaseId">
            <div style="position:relative;"><div id="tkCasoList" style="display:none;position:absolute;top:0;left:0;right:0;z-index:10;background:#fff;border:1.5px solid var(--border);border-radius:0 0 8px 8px;max-height:180px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.1);"></div></div>
        </div>

        <div class="tk-fg"><label class="tk-fl">Descrição</label>
            <textarea class="tk-fi" id="tkDesc" rows="2" placeholder="Detalhes opcionais..."></textarea>
        </div>

        <div class="tk-fr">
            <div class="tk-fg"><label class="tk-fl">Responsavel</label>
                <select class="tk-fi" id="tkResp"><?php foreach($users as $u): ?><option value="<?=$u['id']?>" <?=(int)$u['id']===current_user_id()?'selected':''?>><?=e($u['name'])?></option><?php endforeach; ?></select>
            </div>
            <div class="tk-fg"><label class="tk-fl">Prioridade</label>
                <select class="tk-fi" id="tkPrio"><option value="normal">Normal</option><option value="urgente">Urgente</option><option value="alta">Alta</option><option value="baixa">Baixa</option></select>
            </div>
        </div>

        <div class="tk-fr">
            <div class="tk-fg"><label class="tk-fl">Data Fatal / Prazo</label>
                <input type="date" class="tk-fi" id="tkDueDate">
            </div>
            <div class="tk-fg" id="tkAlertaBox" style="display:none;">
                <label class="tk-fl">Data de Alerta</label>
                <input type="date" class="tk-fi" id="tkPrazoAlerta">
            </div>
        </div>
    </div>
    <div class="tk-modal-ft">
        <button class="tk-btn-del" id="tkBtnDel" style="display:none;" onclick="excluirTarefa()">Excluir</button>
        <div style="display:flex;gap:.5rem;margin-left:auto;">
            <button class="tk-btn-cancel" onclick="fecharModal()">Cancelar</button>
            <button class="tk-btn-save" onclick="salvar()">Salvar</button>
        </div>
    </div>
</div>
</div>

<script>
var API = '<?= module_url('tarefas','api.php') ?>';
var CSRF = '<?= generate_csrf_token() ?>';
var BASE = '<?= BASE_URL ?>';
var tarefas = [];
var tipoSel = '';
var showHistorico = false;

var TIPO_CORES = {peticionar:'#6366f1',juntar_documento:'#0ea5e9',prazo:'#dc2626',oficio:'#8b5cf6',acordo:'#059669',outros:'#94a3b8'};
var TIPO_LABELS = {peticionar:'Peticionar',juntar_documento:'Juntar Doc',prazo:'Prazo',oficio:'Oficio',acordo:'Acordo',outros:'Outros'};
var PRIO_CORES = {urgente:'#dc2626',alta:'#d97706',normal:'#059669',baixa:'#94a3b8'};

// ── LOAD ──
reload();

function reload() {
    var params = '?action=listar';
    var r = document.getElementById('fResp').value; if(r) params += '&responsavel='+r;
    var t = document.getElementById('fTipo').value; if(t) params += '&tipo='+t;
    var p = document.getElementById('fPrio').value; if(p) params += '&prioridade='+p;

    var scrollY = window.scrollY || window.pageYOffset;
    var x = new XMLHttpRequest();
    x.open('GET', API + params);
    x.onload = function() {
        try { tarefas = JSON.parse(x.responseText); } catch(e) { tarefas = []; }
        renderBoard();
        window.scrollTo(0, scrollY);
    };
    x.send();
}

function renderBoard() {
    var cols = {a_fazer:[],em_andamento:[],aguardando:[],concluido:[]};
    var mesAtual = new Date().toISOString().substring(0,7);
    var hoje = new Date().toISOString().substring(0,10);

    tarefas.forEach(function(t) {
        var st = t.status || 'a_fazer';
        if (!cols[st]) st = 'a_fazer';
        // Concluído: só mostrar no mês da conclusão (ou histórico)
        if (st === 'concluido' && !showHistorico) {
            var mesConcl = t.completed_at ? t.completed_at.substring(0,7) : '';
            if (mesConcl && mesConcl !== mesAtual) return;
        }
        cols[st].push(t);
    });

    ['a_fazer','em_andamento','aguardando','concluido'].forEach(function(st) {
        var el = document.getElementById('col_'+st);
        document.getElementById('cnt_'+st).textContent = cols[st].length;
        if (!cols[st].length) { el.innerHTML = '<div style="text-align:center;padding:1.5rem;color:#94a3b8;font-size:.78rem;">Nenhuma</div>'; return; }
        el.innerHTML = cols[st].map(function(t) { return renderCard(t, hoje); }).join('');
    });
}

function renderCard(t, hoje) {
    var tipoCor = TIPO_CORES[t.tipo] || '#94a3b8';
    var tipoLabel = TIPO_LABELS[t.tipo] || t.tipo || '';
    var prioCor = PRIO_CORES[t.prioridade] || '';
    var initials = t.assigned_name ? t.assigned_name.split(' ').map(function(w){return w[0]}).join('').substring(0,2).toUpperCase() : '?';

    // Prazo
    var prazoHtml = '';
    if (t.due_date) {
        var cls = 'ok';
        if (t.status !== 'concluido') {
            if (t.due_date < hoje) cls = 'vencido';
            else {
                var diff = Math.ceil((new Date(t.due_date+'T23:59:59') - new Date()) / 86400000);
                if (diff <= 2) cls = 'alerta';
            }
        }
        var dtFmt = t.due_date.substring(8,10)+'/'+t.due_date.substring(5,7);
        prazoHtml = '<span class="tk-prazo '+cls+'">' + dtFmt + (cls==='vencido'?' VENCIDO':'') + '</span>';
    }

    // Alerta prazo
    var alertaHtml = '';
    if (t.tipo === 'prazo' && t.prazo_alerta && t.status !== 'concluido') {
        alertaHtml = '<span style="font-size:.6rem;color:#d97706;">Alerta: '+t.prazo_alerta.substring(8,10)+'/'+t.prazo_alerta.substring(5,7)+'</span>';
    }

    // Mover select
    var moveHtml = '<select onchange="mover('+t.id+',this.value,this)" style="font-size:.62rem;padding:1px 3px;border:1px solid #e5e7eb;border-radius:3px;margin-top:3px;">'
        + '<option value="">Mover...</option>'
        + '<option value="a_fazer">A Fazer</option>'
        + '<option value="em_andamento">Em Andamento</option>'
        + '<option value="aguardando">Aguardando</option>'
        + '<option value="concluido">Concluido</option></select>';

    var isDone = (t.status === 'concluido');
    var checkBtn = '<button onclick="event.stopPropagation();marcarConcluido('+t.id+',this)" style="background:none;border:none;cursor:pointer;font-size:1.1rem;padding:0;line-height:1;flex-shrink:0" title="'+(isDone?'Reabrir':'Concluir')+'">'
        + (isDone ? '<span style="color:#059669">&#9745;</span>' : '<span style="color:#d1d5db">&#9744;</span>') + '</button>';

    return '<div class="tk-card" style="border-left-color:'+tipoCor+(isDone?';opacity:.6':'')+'" draggable="true" ondragstart="dragStart(event,'+t.id+')" onclick="editarTarefa('+t.id+')">'
        + '<div style="display:flex;gap:6px;align-items:start">'
        + checkBtn
        + '<div style="flex:1"><div class="tk-card-titulo" style="'+(isDone?'text-decoration:line-through;color:#94a3b8':'')+'">'+esc(t.title)+'</div></div>'
        + '<div class="tk-avatar" title="'+(t.assigned_name||'')+'">'+initials+'</div>'
        + '</div>'
        + '<div class="tk-card-meta">'
        + (tipoLabel ? '<span class="tk-badge" style="background:'+tipoCor+'">'+tipoLabel+'</span>' : '')
        + (t.prioridade !== 'normal' ? '<span class="tk-badge" style="background:'+prioCor+'">'+t.prioridade.toUpperCase()+'</span>' : '')
        + prazoHtml + alertaHtml
        + '</div>'
        + (t.case_title ? '<div style="font-size:.67rem;color:#6b7280;margin-top:.2rem;cursor:pointer;" onclick="event.stopPropagation();location.href=\''+BASE+'/modules/operacional/caso_ver.php?id='+t.case_id+'\'">' + esc(t.case_title) + (t.client_name ? ' — '+esc(t.client_name) : '') + '</div>' : '')
        + '<div onclick="event.stopPropagation()">'+moveHtml+'</div>'
        + '</div>';
}

function esc(s) { if(!s) return ''; var d=document.createElement('div'); d.textContent=s; return d.innerHTML; }

function mover(id, novoStatus, sel) {
    if (!novoStatus) return;
    var fd = new FormData();
    fd.append('action','mover');fd.append('csrf_token',CSRF);fd.append('id',id);fd.append('status',novoStatus);
    var x = new XMLHttpRequest();
    x.open('POST',API);x.setRequestHeader('X-Requested-With','XMLHttpRequest');
    x.onload = function() {
        try { var r=JSON.parse(x.responseText); if(r.csrf) CSRF=r.csrf; } catch(e) {}
        reload();
    };
    x.send(fd);
}

function marcarConcluido(id, btn) {
    // Descobrir status atual
    var task = tarefas.filter(function(t){return t.id==id})[0];
    var novoStatus = (task && task.status === 'concluido') ? 'a_fazer' : 'concluido';
    mover(id, novoStatus);
}

function toggleHistorico() { showHistorico = !showHistorico; renderBoard(); }

// ── DRAG AND DROP ──
var dragTaskId = null;

function dragStart(e, taskId) {
    dragTaskId = taskId;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', taskId);
    e.target.style.opacity = '.5';
    setTimeout(function() { e.target.style.opacity = '.5'; }, 0);
}

// Inicializar drop zones nas colunas
document.querySelectorAll('.tk-col-bd').forEach(function(col) {
    col.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        col.style.background = 'rgba(184,115,51,.08)';
        col.style.outline = '2px dashed #B87333';
        col.style.outlineOffset = '-2px';
    });
    col.addEventListener('dragleave', function(e) {
        col.style.background = '';
        col.style.outline = '';
    });
    col.addEventListener('drop', function(e) {
        e.preventDefault();
        col.style.background = '';
        col.style.outline = '';
        if (!dragTaskId) return;
        var novoStatus = col.id.replace('col_', '');
        mover(dragTaskId, novoStatus);
        dragTaskId = null;
    });
});

document.addEventListener('dragend', function(e) {
    document.querySelectorAll('.tk-card').forEach(function(c) { c.style.opacity = ''; });
    document.querySelectorAll('.tk-col-bd').forEach(function(c) { c.style.background = ''; c.style.outline = ''; });
});

// ── MODAL ──
function abrirModal(caseId, caseTitle) {
    document.getElementById('tkModalTit').textContent = 'Nova Tarefa';
    document.getElementById('tkId').value = '0';
    document.getElementById('tkTitulo').value = '';
    document.getElementById('tkDesc').value = '';
    document.getElementById('tkDueDate').value = '';
    document.getElementById('tkPrazoAlerta').value = '';
    document.getElementById('tkTipoOutro').value = '';
    document.getElementById('tkSubtipo').value = '';
    document.getElementById('tkSubtipoOutro').value = '';
    document.getElementById('tkSubtipoOutro').style.display = 'none';
    document.getElementById('tkPrio').value = 'normal';
    document.getElementById('tkBtnDel').style.display = 'none';
    tipoSel = '';
    document.querySelectorAll('.tk-tipo-btn').forEach(function(b){b.classList.remove('sel')});
    document.getElementById('tkOutroBox').style.display = 'none';
    document.getElementById('tkSubtipoBox').style.display = 'none';
    document.getElementById('tkAlertaBox').style.display = 'none';

    if (caseId) {
        document.getElementById('tkCaseId').value = caseId;
        document.getElementById('tkCasoBusca').value = caseTitle || '';
    } else {
        document.getElementById('tkCaseId').value = '';
        document.getElementById('tkCasoBusca').value = '';
    }

    document.getElementById('tkOverlay').classList.add('aberto');
}

function editarTarefa(id) {
    var x = new XMLHttpRequest();
    x.open('GET', API+'?action=get&id='+id);
    x.onload = function() {
        try {
            var t = JSON.parse(x.responseText);
            if (t.error) { alert(t.error); return; }
            document.getElementById('tkModalTit').textContent = 'Editar Tarefa';
            document.getElementById('tkId').value = t.id;
            document.getElementById('tkTitulo').value = t.title || '';
            document.getElementById('tkDesc').value = t.descricao || '';
            document.getElementById('tkDueDate').value = t.due_date || '';
            document.getElementById('tkPrazoAlerta').value = t.prazo_alerta || '';
            document.getElementById('tkTipoOutro').value = t.tipo_outro || '';
            document.getElementById('tkSubtipo').value = t.subtipo || '';
            document.getElementById('tkPrio').value = t.prioridade || 'normal';
            document.getElementById('tkCaseId').value = t.case_id || '';
            document.getElementById('tkCasoBusca').value = t.case_title || '';
            document.getElementById('tkBtnDel').style.display = 'inline-block';

            tipoSel = t.tipo || '';
            document.querySelectorAll('.tk-tipo-btn').forEach(function(b){b.classList.remove('sel')});
            var btn = document.querySelector('.tk-tipo-btn[data-t="'+tipoSel+'"]');
            if(btn) btn.classList.add('sel');
            document.getElementById('tkOutroBox').style.display = tipoSel==='outros'?'block':'none';
            document.getElementById('tkSubtipoBox').style.display = tipoSel==='prazo'?'block':'none';
            document.getElementById('tkAlertaBox').style.display = tipoSel==='prazo'?'block':'none';

            document.getElementById('tkOverlay').classList.add('aberto');
        } catch(e) { alert('Erro ao carregar tarefa'); }
    };
    x.send();
}

function fecharModal() { document.getElementById('tkOverlay').classList.remove('aberto'); }
document.getElementById('tkOverlay').addEventListener('click', function(e) { if(e.target===this) fecharModal(); });
document.addEventListener('keydown', function(e) { if(e.key==='Escape') fecharModal(); });

function selTipo(btn) {
    tipoSel = btn.getAttribute('data-t');
    document.querySelectorAll('.tk-tipo-btn').forEach(function(b){b.classList.remove('sel')});
    btn.classList.add('sel');
    document.getElementById('tkOutroBox').style.display = tipoSel==='outros'?'block':'none';
    document.getElementById('tkSubtipoBox').style.display = tipoSel==='prazo'?'block':'none';
    document.getElementById('tkSubtipoOutro').style.display = 'none';
    document.getElementById('tkAlertaBox').style.display = tipoSel==='prazo'?'block':'none';

    // Toggle campo "Outro prazo"
    document.getElementById('tkSubtipo').onchange = function() {
        document.getElementById('tkSubtipoOutro').style.display = this.value === 'outro' ? 'block' : 'none';
        if (this.value === 'outro') document.getElementById('tkSubtipoOutro').focus();
    };

    // Auto-preencher alerta = data fatal - 3 dias
    if (tipoSel==='prazo') {
        document.getElementById('tkDueDate').addEventListener('change', function() {
            var dt = this.value;
            if (dt && !document.getElementById('tkPrazoAlerta').value) {
                var d = new Date(dt+'T12:00:00');
                d.setDate(d.getDate()-3);
                document.getElementById('tkPrazoAlerta').value = d.toISOString().substring(0,10);
            }
        });
    }
}

function salvar() {
    var titulo = document.getElementById('tkTitulo').value.trim();
    var caseId = document.getElementById('tkCaseId').value;
    if (!titulo) { document.getElementById('tkTitulo').style.borderColor='#ef4444'; return; }
    if (!caseId) { document.getElementById('tkCasoBusca').style.borderColor='#ef4444'; alert('Selecione um processo.'); return; }

    var fd = new FormData();
    fd.append('action','salvar');
    fd.append('csrf_token', CSRF);
    fd.append('id', document.getElementById('tkId').value);
    fd.append('case_id', caseId);
    fd.append('title', titulo);
    fd.append('tipo', tipoSel);
    fd.append('tipo_outro', document.getElementById('tkTipoOutro').value);
    var subtipoVal = document.getElementById('tkSubtipo').value;
    if (subtipoVal === 'outro') subtipoVal = document.getElementById('tkSubtipoOutro').value.trim() || 'Outro';
    fd.append('subtipo', subtipoVal);
    fd.append('descricao', document.getElementById('tkDesc').value);
    fd.append('assigned_to', document.getElementById('tkResp').value);
    fd.append('prioridade', document.getElementById('tkPrio').value);
    fd.append('due_date', document.getElementById('tkDueDate').value);
    fd.append('prazo_alerta', document.getElementById('tkPrazoAlerta').value);

    var x = new XMLHttpRequest();
    x.open('POST', API); x.setRequestHeader('X-Requested-With','XMLHttpRequest');
    x.onload = function() {
        try { var r=JSON.parse(x.responseText); if(r.csrf) CSRF=r.csrf; if(r.error){alert(r.error);return;} }
        catch(e) { alert('Erro ao salvar'); return; }
        fecharModal(); reload();
    };
    x.send(fd);
}

function excluirTarefa() {
    if (!confirm('Excluir esta tarefa?')) return;
    var fd = new FormData();
    fd.append('action','excluir');fd.append('csrf_token',CSRF);fd.append('id',document.getElementById('tkId').value);
    var x = new XMLHttpRequest();
    x.open('POST',API);x.setRequestHeader('X-Requested-With','XMLHttpRequest');
    x.onload = function() {
        try { var r=JSON.parse(x.responseText); if(r.csrf) CSRF=r.csrf; } catch(e){}
        fecharModal(); reload();
    };
    x.send(fd);
}

// ── AUTOCOMPLETE PROCESSO ──
var acTimer;
document.getElementById('tkCasoBusca').addEventListener('input', function() {
    clearTimeout(acTimer);
    var q = this.value.trim();
    if (q.length < 2) { document.getElementById('tkCasoList').style.display='none'; return; }
    acTimer = setTimeout(function() {
        var x = new XMLHttpRequest();
        x.open('GET', API+'?action=busca_caso&q='+encodeURIComponent(q));
        x.onload = function() {
            try {
                var r = JSON.parse(x.responseText);
                var list = document.getElementById('tkCasoList');
                if (!r.length) { list.style.display='none'; return; }
                list.innerHTML = r.map(function(c) {
                    return '<div style="padding:6px 10px;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f3f4f6;" onclick="selCaso('+c.id+',\''+esc(c.title||'')+'\')">'+esc(c.title||'Caso #'+c.id)+' <span style="color:#94a3b8;font-size:.7rem;">'+esc(c.case_number||'')+' '+esc(c.client_name||'')+'</span></div>';
                }).join('');
                list.style.display = 'block';
            } catch(e) {}
        };
        x.send();
    }, 300);
});

function selCaso(id, title) {
    document.getElementById('tkCaseId').value = id;
    document.getElementById('tkCasoBusca').value = title;
    document.getElementById('tkCasoList').style.display = 'none';
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('#tkCasoList') && !e.target.closest('#tkCasoBusca')) {
        document.getElementById('tkCasoList').style.display = 'none';
    }
});

<?php
// Auto-abrir modal se veio com ?case_id
$preCaseId = (int)($_GET['case_id'] ?? 0);
if ($preCaseId):
    $stmt = $pdo->prepare("SELECT title FROM cases WHERE id=?");
    $stmt->execute(array($preCaseId));
    $preCase = $stmt->fetch();
?>
setTimeout(function() { abrirModal(<?= $preCaseId ?>, <?= json_encode($preCase ? $preCase['title'] : '') ?>); }, 300);
<?php endif; ?>
</script>

<?php require_once APP_ROOT . '/templates/layout_end.php'; ?>
