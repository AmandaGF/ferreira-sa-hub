<?php
/**
 * Drawer lateral unificado — Card do Cliente (modelo Trello)
 * Incluído por pipeline/index.php e operacional/index.php
 * Usa card_api.php para buscar dados via AJAX
 */
$drawerApiUrl = url('modules/shared/card_api.php');
$drawerOrigin = isset($drawerOriginKanban) ? $drawerOriginKanban : 'operacional'; // pipeline ou operacional
?>

<!-- Overlay + Drawer -->
<div id="cardDrawerOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:998;" onclick="fecharDrawer()"></div>
<div id="cardDrawer" style="display:none;position:fixed;top:0;right:-520px;width:510px;max-width:95vw;height:100vh;background:#fff;z-index:999;box-shadow:-8px 0 30px rgba(0,0,0,.15);transition:right .3s;overflow:hidden;display:flex;flex-direction:column;">

    <!-- Header -->
    <div id="cdHeader" style="background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1rem 1.25rem;flex-shrink:0;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;">
            <div>
                <div id="cdNome" style="font-size:1.05rem;font-weight:800;"></div>
                <div id="cdMeta" style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:.2rem;"></div>
            </div>
            <button onclick="fecharDrawer()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;padding:0 4px;">✕</button>
        </div>
        <div id="cdBadges" style="display:flex;gap:.4rem;margin-top:.5rem;flex-wrap:wrap;"></div>
        <div id="cdActions" style="display:flex;gap:.35rem;margin-top:.6rem;flex-wrap:wrap;"></div>
    </div>

    <!-- Abas -->
    <div id="cdTabs" style="display:flex;border-bottom:2px solid #e5e7eb;background:#fafafa;flex-shrink:0;overflow-x:auto;">
        <button class="cd-tab active" onclick="cdTab('geral')">Geral</button>
        <button class="cd-tab" onclick="cdTab('comercial')" id="cdTabComercial" style="display:none;">Comercial</button>
        <button class="cd-tab" onclick="cdTab('operacional')">Operacional</button>
        <button class="cd-tab" onclick="cdTab('docs')">Docs</button>
        <button class="cd-tab" onclick="cdTab('financeiro')" id="cdTabFinanceiro" style="display:none;">Financeiro</button>
        <button class="cd-tab" onclick="cdTab('agenda')">Agenda</button>
        <button class="cd-tab" onclick="cdTab('historico')">Histórico</button>
    </div>

    <!-- Conteúdo scrollável -->
    <div id="cdBody" style="flex:1;overflow-y:auto;padding:1rem 1.25rem;">
        <div id="cdLoading" style="text-align:center;padding:3rem;color:#94a3b8;">Carregando...</div>

        <!-- Aba Geral -->
        <div class="cd-panel" id="cdPanelGeral" style="display:none;"></div>
        <!-- Aba Comercial -->
        <div class="cd-panel" id="cdPanelComercial" style="display:none;"></div>
        <!-- Aba Operacional -->
        <div class="cd-panel" id="cdPanelOperacional" style="display:none;"></div>
        <!-- Aba Docs -->
        <div class="cd-panel" id="cdPanelDocs" style="display:none;"></div>
        <!-- Aba Financeiro -->
        <div class="cd-panel" id="cdPanelFinanceiro" style="display:none;"></div>
        <!-- Aba Agenda -->
        <div class="cd-panel" id="cdPanelAgenda" style="display:none;"></div>
        <!-- Aba Histórico -->
        <div class="cd-panel" id="cdPanelHistorico" style="display:none;"></div>
    </div>
</div>

<style>
.cd-tab { padding:.5rem .85rem; font-size:.75rem; font-weight:600; color:#94a3b8; background:none; border:none; border-bottom:2px solid transparent; cursor:pointer; white-space:nowrap; }
.cd-tab:hover { color:#052228; }
.cd-tab.active { color:#052228; border-bottom-color:#B87333; }
.cd-panel { font-size:.82rem; }
.cd-section { margin-bottom:1rem; }
.cd-section h5 { font-size:.72rem; text-transform:uppercase; letter-spacing:.5px; color:#94a3b8; font-weight:700; margin-bottom:.4rem; }
.cd-field { display:flex; justify-content:space-between; padding:.3rem 0; border-bottom:1px solid #f3f4f6; }
.cd-field .label { color:#6b7280; font-size:.75rem; }
.cd-field .value { font-weight:600; color:#052228; font-size:.78rem; text-align:right; max-width:60%; }
.cd-badge { display:inline-block; padding:2px 8px; border-radius:4px; font-size:.65rem; font-weight:700; color:#fff; }
.cd-timeline { position:relative; padding-left:16px; }
.cd-timeline::before { content:''; position:absolute; left:4px; top:0; bottom:0; width:2px; background:#e5e7eb; }
.cd-tl-item { position:relative; margin-bottom:.6rem; padding-left:12px; }
.cd-tl-item::before { content:''; position:absolute; left:-14px; top:6px; width:8px; height:8px; border-radius:50%; background:#B87333; }
.cd-tl-item .date { font-size:.65rem; color:#94a3b8; }
.cd-tl-item .text { font-size:.78rem; color:#052228; }
.cd-tl-item .detail { font-size:.7rem; color:#6b7280; }
.cd-task { display:flex; align-items:center; gap:.5rem; padding:.3rem 0; border-bottom:1px solid #f9fafb; font-size:.78rem; }
.cd-task .check { width:16px; height:16px; border-radius:4px; border:2px solid #d1d5db; display:flex; align-items:center; justify-content:center; font-size:.6rem; flex-shrink:0; }
.cd-task .check.done { background:#059669; border-color:#059669; color:#fff; }
.cd-cob { display:flex; align-items:center; gap:.5rem; padding:.35rem 0; border-bottom:1px solid #f3f4f6; }
.cd-cob .dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
</style>

<script>
var cdData = null;
var cdCurrentTab = 'geral';

function abrirDrawer(params) {
    var url = '<?= $drawerApiUrl ?>?' + params;
    document.getElementById('cardDrawerOverlay').style.display = 'block';
    var drawer = document.getElementById('cardDrawer');
    drawer.style.display = 'flex';
    setTimeout(function(){ drawer.style.right = '0'; }, 10);

    document.getElementById('cdLoading').style.display = 'block';
    document.querySelectorAll('.cd-panel').forEach(function(p){ p.style.display = 'none'; });

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url);
    xhr.onload = function() {
        document.getElementById('cdLoading').style.display = 'none';
        try {
            cdData = JSON.parse(xhr.responseText);
            if (cdData.error) { document.getElementById('cdLoading').textContent = cdData.error; document.getElementById('cdLoading').style.display = 'block'; return; }
            renderDrawer();
        } catch(e) { document.getElementById('cdLoading').textContent = 'Erro ao carregar'; document.getElementById('cdLoading').style.display = 'block'; }
    };
    xhr.send();
}

function fecharDrawer() {
    document.getElementById('cardDrawer').style.right = '-520px';
    setTimeout(function(){ document.getElementById('cardDrawerOverlay').style.display = 'none'; }, 300);
}

function cdTab(tab) {
    cdCurrentTab = tab;
    document.querySelectorAll('.cd-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.cd-panel').forEach(function(p){ p.style.display = 'none'; });
    var btn = document.querySelector('.cd-tab[onclick*="' + tab + '"]');
    if (btn) btn.classList.add('active');
    var panel = document.getElementById('cdPanel' + tab.charAt(0).toUpperCase() + tab.slice(1));
    if (panel) panel.style.display = 'block';
}

function esc(s) { if (!s) return '—'; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function fmt(d) { if (!d) return '—'; var p = d.split(/[-T ]/); return p[2]+'/'+p[1]+'/'+p[0]; }
function fmtR(v) { return 'R$ ' + parseFloat(v||0).toLocaleString('pt-BR', {minimumFractionDigits:2}); }

var statusCores = {PENDING:'#f59e0b',RECEIVED:'#059669',CONFIRMED:'#059669',OVERDUE:'#dc2626',CANCELED:'#6b7280',REFUNDED:'#6b7280'};
var statusLabels = {PENDING:'Pendente',RECEIVED:'Pago',CONFIRMED:'Confirmado',OVERDUE:'Vencido',CANCELED:'Cancelado',REFUNDED:'Reembolsado'};

function renderDrawer() {
    var d = cdData;
    var c = d.client || {};
    var l = d.lead;
    var cs = d.caso;
    var sl = d.stage_labels || {};
    var stl = d.status_labels || {};

    // Header
    document.getElementById('cdNome').textContent = c.name || 'Sem nome';
    var meta = [];
    if (c.cpf) meta.push('CPF: ' + c.cpf);
    if (c.phone) meta.push(c.phone);
    document.getElementById('cdMeta').textContent = meta.join(' · ');

    // Badges
    var badges = '';
    if (l) badges += '<span class="cd-badge" style="background:#6366f1;">' + (sl[l.stage] || l.stage) + '</span>';
    if (cs) badges += '<span class="cd-badge" style="background:#059669;">' + (stl[cs.status] || cs.status) + '</span>';
    document.getElementById('cdBadges').innerHTML = badges;

    // Actions
    var acts = '';
    if (c.phone) acts += '<a href="https://wa.me/55' + c.phone.replace(/\D/g,'') + '" target="_blank" style="background:#25D366;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none;">💬 WhatsApp</a>';
    acts += '<a href="<?= url("modules/clientes/ver.php") ?>?id=' + d.client_id + '" style="background:#052228;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none;">👤 Perfil</a>';
    if (cs) acts += '<a href="<?= url("modules/operacional/caso_ver.php") ?>?id=' + d.case_id + '" style="background:#B87333;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none;">📂 Pasta</a>';
    document.getElementById('cdActions').innerHTML = acts;

    // Tab visibility
    document.getElementById('cdTabComercial').style.display = d.can_comercial ? '' : 'none';
    document.getElementById('cdTabFinanceiro').style.display = d.can_financeiro ? '' : 'none';

    // ── ABA GERAL ──
    var h = '<div class="cd-section"><h5>Dados do Cliente</h5>';
    h += field('Nome', c.name) + field('CPF', c.cpf) + field('Telefone', c.phone) + field('E-mail', c.email);
    h += field('Endereço', [c.address_street, c.address_city, c.address_state].filter(Boolean).join(', '));
    h += '</div>';
    if (l || cs) {
        h += '<div class="cd-section"><h5>Status</h5>';
        if (l) h += field('Pipeline', sl[l.stage] || l.stage) + field('Responsável Comercial', l.assigned_name);
        if (cs) h += field('Operacional', stl[cs.status] || cs.status) + field('Responsável Operacional', cs.responsible_name);
        if (cs) h += field('Tipo de Ação', cs.case_type) + field('Departamento', cs.departamento);
        h += '</div>';
    }
    if (d.casos_todos && d.casos_todos.length > 1) {
        h += '<div class="cd-section"><h5>Processos (' + d.casos_todos.length + ')</h5>';
        d.casos_todos.forEach(function(ct) {
            h += '<div style="padding:.25rem 0;border-bottom:1px solid #f3f4f6;"><a href="<?= url("modules/operacional/caso_ver.php") ?>?id=' + ct.id + '" style="font-weight:600;color:#052228;text-decoration:none;font-size:.78rem;">' + esc(ct.title) + '</a> <span class="cd-badge" style="background:#6b7280;font-size:.58rem;">' + (stl[ct.status]||ct.status) + '</span></div>';
        });
        h += '</div>';
    }
    document.getElementById('cdPanelGeral').innerHTML = h;

    // ── ABA COMERCIAL ──
    if (d.can_comercial && l) {
        h = '<div class="cd-section"><h5>Contrato</h5>';
        h += field('Valor', l.valor_acao || '—') + field('Forma Pagamento', l.forma_pagamento || '—') + field('Vencimento Parcela', l.vencimento_parcela || '—');
        h += field('Nome Pasta', l.nome_pasta || '—') + field('Pendências', l.pendencias || '—');
        h += field('Convertido em', l.converted_at ? fmt(l.converted_at) : '—');
        h += '</div>';
        h += '<div class="cd-section"><h5>Histórico Pipeline</h5><div class="cd-timeline">';
        (d.pipeline_history || []).forEach(function(ph) {
            h += '<div class="cd-tl-item"><div class="date">' + fmt(ph.created_at) + '</div><div class="text">' + esc(ph.user_name ? ph.user_name.split(' ')[0] : 'Sistema') + ' → <strong>' + (sl[ph.to_stage]||ph.to_stage) + '</strong></div>';
            if (ph.notes) h += '<div class="detail">' + esc(ph.notes) + '</div>';
            h += '</div>';
        });
        if (!d.pipeline_history || !d.pipeline_history.length) h += '<div style="color:#94a3b8;padding:.5rem;">Nenhuma movimentação</div>';
        h += '</div></div>';
    } else { h = '<div style="color:#94a3b8;padding:2rem;text-align:center;">Sem dados comerciais</div>'; }
    document.getElementById('cdPanelComercial').innerHTML = h;

    // ── ABA OPERACIONAL ──
    if (cs) {
        h = '<div class="cd-section"><h5>Processo</h5>';
        h += field('Pasta', cs.title) + field('Nº Processo', cs.case_number) + field('Vara', cs.court) + field('Comarca', cs.comarca + (cs.comarca_uf ? '/'+cs.comarca_uf : '') + (cs.regional ? ' — Regional de '+cs.regional : ''));
        h += field('Sistema', cs.sistema_tribunal) + field('Segredo', cs.segredo_justica == 1 ? 'Sim' : 'Não');
        h += field('Parte Ré', cs.parte_re_nome);
        h += '</div>';
        // Tarefas
        h += '<div class="cd-section"><h5>Tarefas (' + (d.tasks||[]).length + ')</h5>';
        (d.tasks || []).forEach(function(t) {
            h += '<div class="cd-task"><div class="check ' + (t.status==='feito'?'done':'') + '">' + (t.status==='feito'?'✓':'') + '</div><span style="' + (t.status==='feito'?'text-decoration:line-through;color:#94a3b8;':'') + '">' + esc(t.title) + '</span></div>';
        });
        h += '</div>';
        // Andamentos (últimos 5)
        h += '<div class="cd-section"><h5>Andamentos</h5><div class="cd-timeline">';
        (d.andamentos || []).slice(0,5).forEach(function(a) {
            h += '<div class="cd-tl-item"><div class="date">' + fmt(a.data_andamento) + ' · ' + esc(a.tipo) + '</div><div class="text">' + esc(a.descricao ? a.descricao.substring(0,120) : '') + '</div></div>';
        });
        if (d.andamentos && d.andamentos.length > 5) h += '<div style="text-align:center;padding:.3rem;"><a href="<?= url("modules/operacional/caso_ver.php") ?>?id=' + d.case_id + '#andamentos" style="font-size:.72rem;color:#B87333;">Ver todos →</a></div>';
        h += '</div></div>';
    } else { h = '<div style="color:#94a3b8;padding:2rem;text-align:center;">Nenhum caso no operacional</div>'; }
    document.getElementById('cdPanelOperacional').innerHTML = h;

    // ── ABA DOCS ──
    h = '';
    var docsPend = d.docs_pendentes || [];
    if (docsPend.length) {
        h += '<div class="cd-section"><h5>Documentos Pendentes (' + docsPend.length + ')</h5>';
        docsPend.forEach(function(dp) {
            var cor = dp.status === 'pendente' ? '#dc2626' : '#059669';
            h += '<div style="padding:.3rem 0;border-bottom:1px solid #f3f4f6;border-left:3px solid '+cor+';padding-left:8px;"><span style="font-weight:600;color:'+cor+';">' + esc(dp.descricao) + '</span> <span style="font-size:.65rem;color:#94a3b8;">' + dp.status + '</span></div>';
        });
        h += '</div>';
    }
    var pecas = d.pecas || [];
    h += '<div class="cd-section"><h5>Peças Geradas (' + pecas.length + ')</h5>';
    if (pecas.length) {
        pecas.forEach(function(p) {
            h += '<div style="padding:.3rem 0;border-bottom:1px solid #f3f4f6;"><a href="<?= url("modules/peticoes/ver.php") ?>?id=' + p.id + '" target="_blank" style="font-weight:600;color:#052228;text-decoration:none;">' + esc(p.titulo || 'Peça #'+p.id) + '</a> <span style="font-size:.65rem;color:#94a3b8;">' + esc(p.tipo_peca) + ' · ' + fmt(p.created_at) + '</span></div>';
        });
    } else { h += '<div style="color:#94a3b8;">Nenhuma peça gerada</div>'; }
    h += '</div>';
    if (cs && cs.drive_folder_url) h += '<a href="' + esc(cs.drive_folder_url) + '" target="_blank" style="display:inline-block;padding:4px 12px;background:#052228;color:#fff;border-radius:6px;font-size:.72rem;font-weight:600;text-decoration:none;margin-top:.5rem;">📁 Pasta no Drive</a>';
    document.getElementById('cdPanelDocs').innerHTML = h;

    // ── ABA FINANCEIRO ──
    h = '<div class="cd-section"><h5>Cobranças</h5>';
    var cobs = d.cobrancas || [];
    if (cobs.length) {
        cobs.forEach(function(cb) {
            var cor = statusCores[cb.status] || '#888';
            h += '<div class="cd-cob"><div class="dot" style="background:'+cor+';"></div><div style="flex:1;"><div style="font-weight:600;">' + fmtR(cb.valor) + '</div><div style="font-size:.68rem;color:#6b7280;">Venc: ' + fmt(cb.vencimento) + ' · ' + (statusLabels[cb.status]||cb.status) + '</div></div></div>';
        });
    } else { h += '<div style="color:#94a3b8;">Nenhuma cobrança</div>'; }
    h += '</div>';
    document.getElementById('cdPanelFinanceiro').innerHTML = h;

    // ── ABA AGENDA ──
    h = '<div class="cd-section"><h5>Compromissos</h5>';
    var comps = d.compromissos || [];
    if (comps.length) {
        comps.forEach(function(ev) {
            h += '<div style="padding:.35rem 0;border-bottom:1px solid #f3f4f6;"><div style="font-weight:600;font-size:.78rem;">' + esc(ev.titulo) + '</div><div style="font-size:.68rem;color:#6b7280;">' + fmt(ev.data_inicio) + ' · ' + esc(ev.tipo) + (ev.responsavel_name ? ' · '+esc(ev.responsavel_name) : '') + '</div></div>';
        });
    } else { h += '<div style="color:#94a3b8;">Nenhum compromisso</div>'; }
    h += '</div>';
    document.getElementById('cdPanelAgenda').innerHTML = h;

    // ── ABA HISTÓRICO ──
    h = '<div class="cd-timeline">';
    (d.historico || []).forEach(function(hi) {
        var cores = {pipeline:'#6366f1',andamento:'#052228',documento:'#B87333',agenda:'#059669'};
        h += '<div class="cd-tl-item" style="--dot-color:' + (cores[hi.type]||'#888') + ';"><div class="date">' + fmt(hi.date) + ' ' + hi.icon + '</div><div class="text">' + esc(hi.text) + '</div>';
        if (hi.detail) h += '<div class="detail">' + esc(hi.detail) + '</div>';
        h += '</div>';
    });
    if (!d.historico || !d.historico.length) h += '<div style="color:#94a3b8;padding:1rem;text-align:center;">Nenhum registro</div>';
    h += '</div>';
    document.getElementById('cdPanelHistorico').innerHTML = h;

    // Mostrar aba inicial
    cdTab(cdCurrentTab);
}

function field(label, value) {
    return '<div class="cd-field"><span class="label">' + label + '</span><span class="value">' + esc(value) + '</span></div>';
}

// Fechar com ESC
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') fecharDrawer(); });

// ── Interceptar cliques nos cards SEM modificar o HTML ──
// Captura o clique antes do onclick inline e abre o drawer em vez de navegar
document.addEventListener('click', function(e) {
    // Card do Operacional
    var opCard = e.target.closest('.op-card[data-case-id]');
    if (opCard && !e.target.closest('select,form,.op-card-move,a')) {
        e.stopImmediatePropagation();
        e.preventDefault();
        abrirDrawer('case_id=' + opCard.getAttribute('data-case-id'));
        return false;
    }
    // Card do Pipeline
    var leadCard = e.target.closest('.lead-card[data-lead-id]');
    if (leadCard && !e.target.closest('.lead-actions,select,form,a')) {
        e.stopImmediatePropagation();
        e.preventDefault();
        abrirDrawer('lead_id=' + leadCard.getAttribute('data-lead-id'));
        return false;
    }
}, true); // true = fase de captura (antes do onclick inline)
</script>
