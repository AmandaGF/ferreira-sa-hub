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
    var cid = d.client_id;
    var h = '<div class="cd-section"><h5>Dados do Cliente <span style="font-size:.55rem;color:#B87333;font-weight:400;text-transform:none;">(editável)</span></h5>';
    h += editField('Nome', c.name, 'client', cid, 'name');
    h += editField('CPF', c.cpf, 'client', cid, 'cpf');
    h += editField('Telefone', c.phone, 'client', cid, 'phone');
    h += editField('E-mail', c.email, 'client', cid, 'email');
    h += editField('Endereço', c.address_street, 'client', cid, 'address_street');
    h += editField('Cidade', c.address_city, 'client', cid, 'address_city');
    h += editField('UF', c.address_state, 'client', cid, 'address_state');
    h += editField('CEP', c.address_zip, 'client', cid, 'address_zip');
    h += '</div>';
    if (l || cs) {
        h += '<div class="cd-section"><h5>Status</h5>';
        if (l) h += field('Pipeline', sl[l.stage] || l.stage) + field('Responsável Comercial', l.assigned_name);
        if (cs) h += field('Operacional', stl[cs.status] || cs.status) + field('Responsável Operacional', cs.responsible_name);
        if (cs) h += field('Tipo de Ação', cs.case_type) + field('Departamento', cs.departamento);
        h += '</div>';
    }
    // Dados do formulário de cadastro
    if (d.form_data) {
        var fd = d.form_data;
        var formLabels = {
            'profissao':'Profissão','estado_civil':'Estado Civil','rg':'RG','nascimento':'Nascimento',
            'cep':'CEP','endereco':'Endereço','cidade':'Cidade','uf':'UF',
            'pix':'PIX','conta_bancaria':'Conta Bancária',
            'imposto_renda':'Declara IR?','clt':'CLT?','filhos':'Tem filhos?','nome_filhos':'Nome dos filhos',
            'tipo_atendimento':'Tipo Atendimento','autoriza_contato':'Autoriza contato?',
            'fam_saude':'Saúde (família)','fam_escola':'Escola','fam_pensao_atual':'Pensão atual',
            'fam_trabalho_genitor':'Trabalho do genitor','fam_contato_genitor':'Contato do genitor',
            'fam_endereco_genitor':'Endereço do genitor',
            'nome_responsavel':'Responsável','cpf_responsavel':'CPF Responsável',
            'whatsapp':'WhatsApp','nome_filho_referente':'Filho referente',
            'tea_status':'TEA?','faz_tratamento_especifico':'Tratamento específico?',
            'detalhe_tratamento':'Detalhe tratamento','qtd_filhos':'Qtd filhos',
            'fonte_renda':'Fonte de renda','obs_renda':'Obs. renda',
            'quem_paga':'Quem paga','moradores':'Moradores',
            'situacao':'Situação','porcentagem':'Porcentagem','idade_filhos':'Idade dos filhos',
        };
        var skipForm = ['nome','name','client_name','client_phone','client_email','email','celular','phone',
            'cpf','form_type','protocol_original','protocol','protocolo','id','created_at','updated_at',
            'ip','ip_address','user_agent','data_envio','seconds','nanoseconds','payload_json','totais'];
        h += '<div class="cd-section"><h5>Formulário de Cadastro</h5>';
        for (var fk in fd) {
            if (skipForm.indexOf(fk) >= 0) continue;
            var val = fd[fk];
            if (val === null || val === '' || typeof val === 'object') continue;
            // Converter centavos
            if (fk.indexOf('_cents') >= 0 && !isNaN(val)) val = fmtR(val / 100);
            var lbl = formLabels[fk] || fk.replace(/_/g,' ').replace(/\b\w/g, function(c){return c.toUpperCase();});
            h += field(lbl, String(val));
        }
        if (d.form_date) h += '<div style="font-size:.62rem;color:#94a3b8;margin-top:.3rem;">Preenchido em ' + fmt(d.form_date) + '</div>';
        h += '</div>';
    }

    if (d.casos_todos && d.casos_todos.length > 1) {
        h += '<div class="cd-section"><h5>Processos (' + d.casos_todos.length + ')</h5>';
        d.casos_todos.forEach(function(ct) {
            h += '<div style="padding:.25rem 0;border-bottom:1px solid #f3f4f6;"><a href="<?= url("modules/operacional/caso_ver.php") ?>?id=' + ct.id + '" style="font-weight:600;color:#052228;text-decoration:none;font-size:.78rem;">' + esc(ct.title) + '</a> <span class="cd-badge" style="background:#6b7280;font-size:.58rem;">' + (stl[ct.status]||ct.status) + '</span></div>';
        });
        h += '</div>';
    }
    // Comentários
    h += '<div class="cd-section cd-comments-section"><h5>💬 Comentários</h5>' + renderComentarios() + '</div>';

    document.getElementById('cdPanelGeral').innerHTML = h;

    // ── ABA COMERCIAL ──
    if (d.can_comercial && l) {
        var lid = d.lead_id;
        h = '<div class="cd-section"><h5>Contrato <span style="font-size:.55rem;color:#B87333;font-weight:400;text-transform:none;">(editável)</span></h5>';
        h += editField('Valor', l.valor_acao, 'lead', lid, 'valor_acao');
        h += editField('Forma Pagamento', l.forma_pagamento, 'lead', lid, 'forma_pagamento');
        h += editField('Vencimento Parcela', l.vencimento_parcela, 'lead', lid, 'vencimento_parcela');
        h += editField('Nome Pasta', l.nome_pasta, 'lead', lid, 'nome_pasta');
        h += editField('Pendências', l.pendencias, 'lead', lid, 'pendencias');
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
        var csid = d.case_id;
        h = '<div class="cd-section"><h5>Processo <span style="font-size:.55rem;color:#B87333;font-weight:400;text-transform:none;">(editável)</span></h5>';
        h += editField('Pasta', cs.title, 'case', csid, 'title');
        h += editField('Nº Processo', cs.case_number, 'case', csid, 'case_number');
        h += editField('Vara', cs.court, 'case', csid, 'court');
        h += editField('Comarca', cs.comarca, 'case', csid, 'comarca');
        h += editField('UF', cs.comarca_uf, 'case', csid, 'comarca_uf');
        h += editField('Regional', cs.regional, 'case', csid, 'regional');
        h += editField('Sistema', cs.sistema_tribunal, 'case', csid, 'sistema_tribunal');
        h += editField('Parte Ré', cs.parte_re_nome, 'case', csid, 'parte_re_nome');
        h += editField('CPF/CNPJ Parte Ré', cs.parte_re_cpf_cnpj, 'case', csid, 'parte_re_cpf_cnpj');
        h += editField('Link Drive', cs.drive_folder_url, 'case', csid, 'drive_folder_url');
        h += editField('Observações', cs.notes, 'case', csid, 'notes', 'textarea');
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
    var pendentes = docsPend.filter(function(dp){ return dp.status === 'pendente'; });
    var recebidos = docsPend.filter(function(dp){ return dp.status !== 'pendente'; });

    if (pendentes.length) {
        h += '<div class="cd-section"><h5 style="color:#dc2626;">⚠️ Documentos Pendentes (' + pendentes.length + ')</h5>';
        pendentes.forEach(function(dp) {
            h += '<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #fecaca;background:#fef2f2;border-radius:6px;padding:8px;margin-bottom:4px;">';
            h += '<span style="font-size:1rem;">📄</span>';
            h += '<div style="flex:1;"><div style="font-weight:600;color:#dc2626;font-size:.82rem;">' + esc(dp.descricao) + '</div>';
            h += '<div style="font-size:.65rem;color:#94a3b8;">Solicitado por ' + esc(dp.solicitante_name || '—') + '</div></div>';
            h += '<button onclick="marcarDocRecebido(' + dp.id + ',' + d.case_id + ')" style="background:#059669;color:#fff;border:none;padding:4px 10px;border-radius:5px;font-size:.7rem;font-weight:600;cursor:pointer;white-space:nowrap;" id="docBtn' + dp.id + '">✓ Recebido</button>';
            h += '</div>';
        });
        h += '</div>';
    }
    if (recebidos.length) {
        h += '<div class="cd-section"><h5 style="color:#059669;">✓ Documentos Recebidos (' + recebidos.length + ')</h5>';
        recebidos.forEach(function(dp) {
            h += '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f3f4f6;opacity:.6;">';
            h += '<span style="color:#059669;">✓</span>';
            h += '<span style="text-decoration:line-through;font-size:.78rem;">' + esc(dp.descricao) + '</span>';
            if (dp.recebido_em) h += '<span style="font-size:.6rem;color:#94a3b8;">em ' + fmt(dp.recebido_em) + '</span>';
            h += '</div>';
        });
        h += '</div>';
    }
    if (!docsPend.length) {
        h += '<div class="cd-section"><h5>Documentos Pendentes</h5><div style="color:#059669;font-size:.82rem;padding:.5rem 0;">Nenhum documento pendente 🎉</div></div>';
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

// Campo editável inline
function editField(label, value, entity, entityId, fieldName, type) {
    type = type || 'text';
    var id = 'ef_' + entity + '_' + fieldName;
    var val = value || '';
    var inputHtml = '';
    if (type === 'textarea') {
        inputHtml = '<textarea id="' + id + '" style="width:100%;font-size:.78rem;padding:3px 6px;border:1px solid var(--border);border-radius:4px;resize:vertical;min-height:40px;">' + esc(val) + '</textarea>';
    } else {
        inputHtml = '<input type="' + type + '" id="' + id + '" value="' + esc(val).replace(/"/g,'&quot;') + '" style="width:100%;font-size:.78rem;padding:3px 6px;border:1px solid var(--border);border-radius:4px;">';
    }
    return '<div class="cd-field" style="flex-direction:column;align-items:stretch;gap:2px;">'
        + '<div style="display:flex;justify-content:space-between;align-items:center;">'
        + '<span class="label">' + label + '</span>'
        + '<button onclick="salvarCampo(\'' + entity + '\',' + entityId + ',\'' + fieldName + '\',\'' + id + '\')" style="background:#B87333;color:#fff;border:none;padding:1px 8px;border-radius:4px;font-size:.62rem;font-weight:600;cursor:pointer;">Salvar</button>'
        + '</div>'
        + inputHtml
        + '<span id="' + id + '_ok" style="display:none;font-size:.6rem;color:#059669;font-weight:600;">✓ Salvo!</span>'
        + '</div>';
}

function salvarCampo(entity, entityId, fieldName, inputId) {
    var input = document.getElementById(inputId);
    if (!input) return;
    var value = input.value;
    var ok = document.getElementById(inputId + '_ok');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= url("modules/shared/card_actions.php") ?>');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.ok) {
                input.style.borderColor = '#059669';
                if (ok) { ok.style.display = 'inline'; setTimeout(function(){ ok.style.display = 'none'; }, 2000); }
                setTimeout(function(){ input.style.borderColor = ''; }, 2000);
            } else {
                input.style.borderColor = '#dc2626';
                alert(resp.error || 'Erro ao salvar');
            }
        } catch(e) { alert('Erro ao salvar'); }
    };
    xhr.send('action=update_field&entity=' + entity + '&entity_id=' + entityId + '&field=' + fieldName + '&value=' + encodeURIComponent(value));
}

// ═══ COMENTÁRIOS ═══
function renderComentarios() {
    var d = cdData;
    var h = '<div class="cd-section">';
    // Form novo comentário
    h += '<div style="margin-bottom:.75rem;">';
    h += '<textarea id="cdNovoComentario" style="width:100%;font-size:.82rem;padding:8px;border:1.5px solid var(--border);border-radius:8px;resize:vertical;min-height:60px;" placeholder="Escrever comentário..."></textarea>';
    h += '<button onclick="enviarComentario()" style="background:#B87333;color:#fff;border:none;padding:5px 16px;border-radius:6px;font-size:.75rem;font-weight:600;cursor:pointer;margin-top:4px;">Comentar</button>';
    h += '</div>';
    // Lista de comentários
    var comments = d.comments || [];
    comments.forEach(function(c) {
        var nome = c.user_name ? c.user_name.split(' ')[0] : 'Sistema';
        var initials = c.user_name ? c.user_name.substring(0,2).toUpperCase() : '??';
        h += '<div style="display:flex;gap:8px;padding:8px 0;border-top:1px solid #f3f4f6;">';
        h += '<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#052228,#0d3640);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:700;flex-shrink:0;">' + initials + '</div>';
        h += '<div style="flex:1;"><div style="font-size:.72rem;"><strong>' + esc(nome) + '</strong> <span style="color:#94a3b8;">' + fmt(c.created_at) + '</span></div>';
        h += '<div style="font-size:.8rem;margin-top:2px;white-space:pre-wrap;">' + esc(c.message) + '</div></div>';
        h += '</div>';
    });
    if (!comments.length) h += '<div style="color:#94a3b8;font-size:.78rem;padding:.5rem 0;">Nenhum comentário ainda</div>';
    h += '</div>';
    return h;
}

function enviarComentario() {
    var ta = document.getElementById('cdNovoComentario');
    if (!ta || !ta.value.trim()) return;
    var msg = ta.value.trim();
    var d = cdData;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= url("modules/shared/card_actions.php") ?>');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var resp = JSON.parse(xhr.responseText);
            if (resp.ok) {
                // Adicionar o comentário no topo da lista
                if (!d.comments) d.comments = [];
                d.comments.unshift(resp.comment);
                // Re-renderizar a aba atual se for geral
                var panel = document.getElementById('cdPanelGeral');
                if (panel) {
                    // Atualizar seção de comentários
                    var comDiv = panel.querySelector('.cd-comments-section');
                    if (comDiv) comDiv.innerHTML = renderComentarios();
                }
                ta.value = '';
                ta.style.borderColor = '#059669';
                setTimeout(function(){ ta.style.borderColor = ''; }, 1500);
            }
        } catch(e) {}
    };
    xhr.send('action=add_comment&client_id=' + d.client_id + '&case_id=' + (d.case_id||0) + '&lead_id=' + (d.lead_id||0) + '&message=' + encodeURIComponent(msg));
}

// ═══ MARCAR DOC COMO RECEBIDO ═══
function marcarDocRecebido(docId, caseId) {
    if (!confirm('Confirmar recebimento deste documento?')) return;

    var btn = document.getElementById('docBtn' + docId);
    if (btn) { btn.textContent = '...'; btn.disabled = true; }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '<?= url("modules/operacional/api.php") ?>');
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        // Recarregar o drawer para atualizar o status
        if (cdData) {
            abrirDrawer('case_id=' + caseId);
            // Trocar para aba Docs
            setTimeout(function(){ cdTab('docs'); }, 500);
        }
    };
    xhr.send('action=resolve_doc&doc_id=' + docId + '&case_id=' + caseId + '&<?= CSRF_TOKEN_NAME ?>=<?= csrf_token() ?>');
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
