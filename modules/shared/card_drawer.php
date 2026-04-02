<?php
/**
 * Drawer lateral unificado — Card do Cliente
 * Incluído por pipeline/index.php e operacional/index.php
 */
$_cdApiUrl = url('modules/shared/card_api.php');
$_cdActUrl = url('modules/shared/card_actions.php');
$_cdOpApiUrl = url('modules/operacional/api.php');
$_cdCsrf = csrf_token();
$_cdCsrfName = CSRF_TOKEN_NAME;
?>

<div id="cdOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:998;" onclick="cdFechar()"></div>
<div id="cdPanel" style="position:fixed;top:0;right:-520px;width:510px;max-width:95vw;height:100vh;background:#fff;z-index:999;box-shadow:-8px 0 30px rgba(0,0,0,.15);transition:right .3s;overflow:hidden;flex-direction:column;display:none;">
  <div id="cdHead" style="background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1rem 1.25rem;flex-shrink:0;">
    <div style="display:flex;justify-content:space-between;"><div><div id="cdNome" style="font-size:1.05rem;font-weight:800;"></div><div id="cdMeta" style="font-size:.75rem;color:rgba(255,255,255,.6);margin-top:.2rem;"></div></div><button onclick="cdFechar()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;">X</button></div>
    <div id="cdBadges" style="display:flex;gap:.4rem;margin-top:.5rem;flex-wrap:wrap;"></div>
    <div id="cdBtns" style="display:flex;gap:.35rem;margin-top:.5rem;flex-wrap:wrap;"></div>
  </div>
  <div id="cdTabs" style="display:flex;border-bottom:2px solid #e5e7eb;background:#fafafa;flex-shrink:0;overflow-x:auto;"></div>
  <div id="cdBody" style="flex:1;overflow-y:auto;padding:1rem 1.25rem;">
    <div id="cdLoading" style="text-align:center;padding:3rem;color:#94a3b8;">Carregando...</div>
  </div>
</div>

<style>
.cd-tab{padding:.5rem .85rem;font-size:.75rem;font-weight:600;color:#94a3b8;background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;white-space:nowrap}
.cd-tab:hover{color:#052228}.cd-tab.active{color:#052228;border-bottom-color:#B87333}
.cd-s{margin-bottom:1rem}.cd-s h5{font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;font-weight:700;margin-bottom:.4rem}
.cd-r{display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid #f3f4f6;position:relative;align-items:center;min-height:26px}
.cd-r .l{color:#6b7280;font-size:.75rem}.cd-r .v{font-weight:600;color:#052228;font-size:.78rem;text-align:right;max-width:60%;cursor:pointer;border-bottom:1px dashed transparent}
.cd-r .v:hover{border-bottom-color:#B87333}
.cd-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:.65rem;font-weight:700;color:#fff}
.cd-tl{position:relative;padding-left:16px}.cd-tl::before{content:'';position:absolute;left:4px;top:0;bottom:0;width:2px;background:#e5e7eb}
.cd-ti{position:relative;margin-bottom:.6rem;padding-left:12px}.cd-ti::before{content:'';position:absolute;left:-14px;top:6px;width:8px;height:8px;border-radius:50%;background:#B87333}
.cd-ti .dt{font-size:.65rem;color:#94a3b8}.cd-ti .tx{font-size:.78rem;color:#052228}.cd-ti .dl{font-size:.7rem;color:#6b7280}
</style>

<script>
console.log('[CardDrawer] Init');
var _cd = null, _cdTab = 'geral';
var _cdApiUrl = '<?php echo $_cdApiUrl; ?>';
var _cdActUrl = '<?php echo $_cdActUrl; ?>';
var _cdOpApi = '<?php echo $_cdOpApiUrl; ?>';
var _cdCsrf = '<?php echo $_cdCsrfName; ?>=<?php echo $_cdCsrf; ?>';

function cdAbrir(params) {
    console.log('[CardDrawer] Abrindo:', params);
    document.getElementById('cdOverlay').style.display = 'block';
    var p = document.getElementById('cdPanel');
    p.style.display = 'flex';
    setTimeout(function(){ p.style.right = '0'; }, 10);
    document.getElementById('cdLoading').style.display = 'block';
    document.getElementById('cdBody').innerHTML = '<div id="cdLoading" style="text-align:center;padding:3rem;color:#94a3b8;">Carregando...</div>';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', _cdApiUrl + '?' + params);
    xhr.onload = function() {
        try {
            _cd = JSON.parse(xhr.responseText);
            if (_cd.error) { document.getElementById('cdLoading').textContent = _cd.error; return; }
            cdRender();
        } catch(e) { document.getElementById('cdLoading').textContent = 'Erro: ' + e.message; console.error(e); }
    };
    xhr.onerror = function() { document.getElementById('cdLoading').textContent = 'Erro de rede'; };
    xhr.send();
}

function cdFechar() {
    document.getElementById('cdPanel').style.right = '-520px';
    setTimeout(function(){ document.getElementById('cdOverlay').style.display = 'none'; document.getElementById('cdPanel').style.display = 'none'; }, 300);
}

function cdMudarTab(tab) {
    _cdTab = tab;
    document.querySelectorAll('.cd-tab').forEach(function(t){ t.classList.remove('active'); });
    var btn = document.querySelector('.cd-tab[data-tab="' + tab + '"]');
    if (btn) btn.classList.add('active');
    cdRenderTab();
}

function _e(s) { if (!s && s !== 0) return '\u2014'; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
function _d(s) { if (!s) return '\u2014'; var p = s.split(/[-T ]/); return p.length >= 3 ? p[2]+'/'+p[1]+'/'+p[0] : s; }
function _row(l, v) { return '<div class="cd-r"><span class="l">' + l + '</span><span class="v">' + _e(v) + '</span></div>'; }

function cdRender() {
    var d = _cd, c = d.client || {}, l = d.lead, cs = d.caso;
    var sl = d.stage_labels || {}, stl = d.status_labels || {};

    // Header
    document.getElementById('cdNome').textContent = c.name || 'Sem nome';
    var meta = [];
    if (c.cpf) meta.push('CPF: ' + c.cpf);
    if (c.phone) meta.push(c.phone);
    document.getElementById('cdMeta').textContent = meta.join(' \u00B7 ');

    var badges = '';
    if (l) badges += '<span class="cd-badge" style="background:#6366f1;">' + (sl[l.stage] || l.stage) + '</span>';
    if (cs) badges += '<span class="cd-badge" style="background:#059669;">' + (stl[cs.status] || cs.status) + '</span>';
    document.getElementById('cdBadges').innerHTML = badges;

    var btns = '';
    if (c.phone) btns += '<a href="https://wa.me/55' + c.phone.replace(/\D/g,'') + '" target="_blank" style="background:#25D366;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none;">WhatsApp</a>';
    if (cs) btns += '<a href="/conecta/modules/operacional/caso_ver.php?id=' + d.case_id + '" style="background:#B87333;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none;">Pasta</a>';
    btns += '<a href="/conecta/modules/clientes/ver.php?id=' + d.client_id + '" style="background:#052228;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none;">Perfil</a>';
    document.getElementById('cdBtns').innerHTML = btns;

    // Tabs
    var tabs = ['geral','comercial','operacional','docs','financeiro','agenda','historico'];
    var tabLabels = {geral:'Geral',comercial:'Comercial',operacional:'Operacional',docs:'Docs',financeiro:'Financeiro',agenda:'Agenda',historico:'Hist.'};
    var tabsHtml = '';
    tabs.forEach(function(t) {
        if (t === 'comercial' && !d.can_comercial) return;
        if (t === 'financeiro' && !d.can_financeiro) return;
        tabsHtml += '<button class="cd-tab' + (t === _cdTab ? ' active' : '') + '" data-tab="' + t + '" onclick="cdMudarTab(\'' + t + '\')">' + tabLabels[t] + '</button>';
    });
    document.getElementById('cdTabs').innerHTML = tabsHtml;

    cdRenderTab();
}

function cdRenderTab() {
    var d = _cd, c = d.client || {}, l = d.lead, cs = d.caso;
    var sl = d.stage_labels || {}, stl = d.status_labels || {};
    var h = '';

    if (_cdTab === 'geral') {
        h += '<div class="cd-s"><h5>Dados do Cliente</h5>';
        h += _row('Nome', c.name) + _row('CPF', c.cpf) + _row('Telefone', c.phone) + _row('E-mail', c.email);
        h += _row('Endereco', [c.address_street, c.address_city, c.address_state].filter(Boolean).join(', '));
        h += _row('CEP', c.address_zip);
        h += '</div>';
        if (l || cs) {
            h += '<div class="cd-s"><h5>Status</h5>';
            if (l) h += _row('Pipeline', sl[l.stage] || l.stage);
            if (cs) h += _row('Operacional', stl[cs.status] || cs.status);
            if (cs) h += _row('Tipo', cs.case_type);
            h += '</div>';
        }
        // Formulario
        if (d.form_data) {
            h += '<div class="cd-s"><h5>Formulario de Cadastro</h5>';
            var skip = ['nome','name','client_name','client_phone','client_email','email','celular','phone','cpf','form_type','protocol_original','protocol','protocolo','id','created_at','updated_at','ip','ip_address','user_agent','data_envio','payload_json','totais'];
            for (var fk in d.form_data) {
                if (skip.indexOf(fk) >= 0) continue;
                var fv = d.form_data[fk];
                if (fv === null || fv === '' || typeof fv === 'object') continue;
                h += _row(fk.replace(/_/g,' '), fv);
            }
            h += '</div>';
        }
        // Comentarios
        h += '<div class="cd-s"><h5>Comentarios</h5>';
        h += '<textarea id="cdNewComment" style="width:100%;font-size:.82rem;padding:8px;border:1.5px solid #e5e7eb;border-radius:8px;resize:vertical;min-height:50px;" placeholder="Escrever..."></textarea>';
        h += '<button onclick="cdAddComment()" style="background:#B87333;color:#fff;border:none;padding:4px 14px;border-radius:6px;font-size:.72rem;font-weight:600;cursor:pointer;margin-top:4px;">Comentar</button>';
        (d.comments || []).forEach(function(cm) {
            h += '<div style="padding:6px 0;border-top:1px solid #f3f4f6;margin-top:4px;"><strong style="font-size:.75rem;">' + _e(cm.user_name) + '</strong> <span style="font-size:.62rem;color:#94a3b8;">' + _d(cm.created_at) + '</span><div style="font-size:.8rem;margin-top:2px;">' + _e(cm.message) + '</div></div>';
        });
        h += '</div>';

    } else if (_cdTab === 'comercial' && l) {
        h += '<div class="cd-s"><h5>Contrato</h5>';
        h += _row('Valor', l.valor_acao) + _row('Forma Pagamento', l.forma_pagamento) + _row('Vencimento Parcela', l.vencimento_parcela);
        h += _row('Nome Pasta', l.nome_pasta) + _row('Pendencias', l.pendencias);
        h += _row('Convertido em', l.converted_at ? _d(l.converted_at) : null);
        h += '</div>';
        h += '<div class="cd-s"><h5>Historico Pipeline</h5><div class="cd-tl">';
        (d.pipeline_history || []).forEach(function(ph) {
            h += '<div class="cd-ti"><div class="dt">' + _d(ph.created_at) + '</div><div class="tx">' + _e(ph.user_name ? ph.user_name.split(' ')[0] : 'Sistema') + ' > ' + (sl[ph.to_stage]||ph.to_stage) + '</div></div>';
        });
        if (!d.pipeline_history || !d.pipeline_history.length) h += '<div style="color:#94a3b8;padding:.5rem;">Nenhuma</div>';
        h += '</div></div>';

    } else if (_cdTab === 'operacional' && cs) {
        h += '<div class="cd-s"><h5>Processo</h5>';
        h += _row('Pasta', cs.title) + _row('Nr Processo', cs.case_number) + _row('Vara', cs.court);
        h += _row('Comarca', (cs.comarca||'') + (cs.comarca_uf ? '/'+cs.comarca_uf : '') + (cs.regional ? ' - Regional de '+cs.regional : ''));
        h += _row('Sistema', cs.sistema_tribunal) + _row('Parte Re', cs.parte_re_nome);
        h += '</div>';
        h += '<div class="cd-s"><h5>Tarefas (' + (d.tasks||[]).length + ')</h5>';
        (d.tasks||[]).forEach(function(t) {
            var done = t.status === 'feito';
            h += '<div style="display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid #f9fafb;font-size:.78rem;"><span style="' + (done?'color:#059669;':'color:#d1d5db;') + '">' + (done?'[x]':'[ ]') + '</span><span style="' + (done?'text-decoration:line-through;color:#94a3b8;':'') + '">' + _e(t.title) + '</span></div>';
        });
        h += '</div>';
        h += '<div class="cd-s"><h5>Andamentos</h5><div class="cd-tl">';
        (d.andamentos||[]).slice(0,5).forEach(function(a) {
            h += '<div class="cd-ti"><div class="dt">' + _d(a.data_andamento) + ' - ' + _e(a.tipo) + '</div><div class="tx">' + _e(a.descricao ? a.descricao.substring(0,120) : '') + '</div></div>';
        });
        h += '</div></div>';

    } else if (_cdTab === 'docs') {
        var pend = (d.docs_pendentes||[]).filter(function(x){return x.status==='pendente';});
        var recv = (d.docs_pendentes||[]).filter(function(x){return x.status!=='pendente';});
        if (pend.length) {
            h += '<div class="cd-s"><h5 style="color:#dc2626;">Pendentes (' + pend.length + ')</h5>';
            pend.forEach(function(dp) {
                h += '<div style="display:flex;align-items:center;gap:8px;padding:6px;margin-bottom:4px;background:#fef2f2;border-radius:6px;border-left:3px solid #dc2626;">';
                h += '<div style="flex:1;font-weight:600;color:#dc2626;font-size:.82rem;">' + _e(dp.descricao) + '</div>';
                h += '<button onclick="cdMarcarDoc(' + dp.id + ')" style="background:#059669;color:#fff;border:none;padding:4px 10px;border-radius:5px;font-size:.7rem;font-weight:600;cursor:pointer;" id="docBtn' + dp.id + '">Recebido</button>';
                h += '</div>';
            });
            h += '</div>';
        }
        if (recv.length) {
            h += '<div class="cd-s"><h5 style="color:#059669;">Recebidos (' + recv.length + ')</h5>';
            recv.forEach(function(dp) {
                h += '<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;opacity:.6;text-decoration:line-through;font-size:.78rem;">' + _e(dp.descricao) + '</div>';
            });
            h += '</div>';
        }
        h += '<div class="cd-s"><h5>Pecas Geradas (' + (d.pecas||[]).length + ')</h5>';
        (d.pecas||[]).forEach(function(p) {
            h += '<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;font-size:.78rem;">' + _e(p.titulo || 'Peca #'+p.id) + ' <span style="font-size:.65rem;color:#94a3b8;">' + _e(p.tipo_peca) + '</span></div>';
        });
        if (!(d.pecas||[]).length) h += '<div style="color:#94a3b8;font-size:.78rem;">Nenhuma</div>';
        h += '</div>';

    } else if (_cdTab === 'financeiro') {
        h += '<div class="cd-s"><h5>Cobrancas</h5>';
        var statusCor = {PENDING:'#f59e0b',RECEIVED:'#059669',CONFIRMED:'#059669',OVERDUE:'#dc2626',CANCELED:'#6b7280'};
        (d.cobrancas||[]).forEach(function(cb) {
            var cor = statusCor[cb.status] || '#888';
            h += '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;border-bottom:1px solid #f3f4f6;"><div style="width:8px;height:8px;border-radius:50%;background:' + cor + ';flex-shrink:0;"></div><div style="flex:1;font-weight:600;">R$ ' + parseFloat(cb.valor||0).toFixed(2).replace('.',',') + '</div><div style="font-size:.68rem;color:#6b7280;">Venc: ' + _d(cb.vencimento) + '</div></div>';
        });
        if (!(d.cobrancas||[]).length) h += '<div style="color:#94a3b8;font-size:.78rem;">Nenhuma cobranca</div>';
        h += '</div>';

    } else if (_cdTab === 'agenda') {
        h += '<div class="cd-s"><h5>Compromissos</h5>';
        (d.compromissos||[]).forEach(function(ev) {
            h += '<div style="padding:4px 0;border-bottom:1px solid #f3f4f6;"><div style="font-weight:600;font-size:.78rem;">' + _e(ev.titulo) + '</div><div style="font-size:.68rem;color:#6b7280;">' + _d(ev.data_inicio) + ' - ' + _e(ev.tipo) + '</div></div>';
        });
        if (!(d.compromissos||[]).length) h += '<div style="color:#94a3b8;font-size:.78rem;">Nenhum</div>';
        h += '</div>';

    } else if (_cdTab === 'historico') {
        h += '<div class="cd-tl">';
        (d.historico||[]).forEach(function(hi) {
            h += '<div class="cd-ti"><div class="dt">' + _d(hi.date) + ' ' + hi.icon + '</div><div class="tx">' + _e(hi.text) + '</div>';
            if (hi.detail) h += '<div class="dl">' + _e(hi.detail) + '</div>';
            h += '</div>';
        });
        if (!(d.historico||[]).length) h += '<div style="color:#94a3b8;padding:1rem;text-align:center;">Nenhum registro</div>';
        h += '</div>';

    } else {
        h = '<div style="color:#94a3b8;padding:2rem;text-align:center;">Sem dados</div>';
    }

    document.getElementById('cdBody').innerHTML = h;
}

function cdAddComment() {
    var ta = document.getElementById('cdNewComment');
    if (!ta || !ta.value.trim()) return;
    var xhr = new XMLHttpRequest();
    xhr.open('POST', _cdActUrl);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var r = JSON.parse(xhr.responseText);
            if (r.ok && r.comment) {
                if (!_cd.comments) _cd.comments = [];
                _cd.comments.unshift(r.comment);
                ta.value = '';
                cdRenderTab();
            }
        } catch(e) {}
    };
    xhr.send('action=add_comment&client_id=' + _cd.client_id + '&case_id=' + (_cd.case_id||0) + '&lead_id=' + (_cd.lead_id||0) + '&message=' + encodeURIComponent(ta.value));
}

function cdMarcarDoc(docId) {
    if (!confirm('Confirmar recebimento?')) return;
    var btn = document.getElementById('docBtn' + docId);
    if (btn) { btn.textContent = '...'; btn.disabled = true; }
    var xhr = new XMLHttpRequest();
    xhr.open('POST', _cdOpApi);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() { cdAbrir('case_id=' + _cd.case_id); setTimeout(function(){ cdMudarTab('docs'); }, 500); };
    xhr.send('action=resolve_doc&doc_id=' + docId + '&case_id=' + _cd.case_id + '&' + _cdCsrf);
}

document.addEventListener('keydown', function(e) { if (e.key === 'Escape') cdFechar(); });

// Interceptar cliques nos cards
document.addEventListener('click', function(e) {
    var op = e.target.closest('.op-card[data-case-id]');
    if (op && !e.target.closest('select,form,.op-card-move,a')) {
        e.stopImmediatePropagation(); e.preventDefault();
        cdAbrir('case_id=' + op.getAttribute('data-case-id'));
        return;
    }
    var lc = e.target.closest('.lead-card[data-lead-id]');
    if (lc && !e.target.closest('.lead-actions,select,form,a')) {
        e.stopImmediatePropagation(); e.preventDefault();
        cdAbrir('lead_id=' + lc.getAttribute('data-lead-id'));
        return;
    }
}, true);

console.log('[CardDrawer] OK');
</script>
