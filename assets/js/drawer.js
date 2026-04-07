/*
 * Ferreira & Sa Hub - Card Drawer
 * Carregado globalmente via footer.php
 * Intercepta cliques nos cards dos Kanbans
 */
(function(){

// Criar HTML do drawer
var html = '<div id="cdOv" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:998" onclick="cdClose()"></div>';
html += '<div id="cdPn" style="position:fixed;top:0;right:-520px;width:510px;max-width:95vw;height:100vh;background:#fff;z-index:999;box-shadow:-8px 0 30px rgba(0,0,0,.15);transition:right .3s;display:none;flex-direction:column">';
html += '<div id="cdHd" style="background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1rem 1.25rem;flex-shrink:0"></div>';
html += '<div id="cdTb" style="display:flex;border-bottom:2px solid #e5e7eb;background:#fafafa;flex-shrink:0;overflow-x:auto"></div>';
html += '<div id="cdBd" style="flex:1;overflow-y:auto;padding:1rem 1.25rem;font-size:.82rem"></div>';
html += '</div>';

var st = document.createElement('style');
st.textContent = '.cr:hover .cd-pencil{opacity:1!important}.cd-pencil:hover{color:#052228!important}.ct{padding:.5rem .8rem;font-size:.74rem;font-weight:600;color:#94a3b8;background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;white-space:nowrap}.ct:hover{color:#052228}.ct.on{color:#052228;border-bottom-color:#B87333}.cr{display:flex;justify-content:space-between;padding:.25rem 0;border-bottom:1px solid #f3f4f6;min-height:24px;align-items:center}.cr .l{color:#6b7280;font-size:.73rem}.cr .v{font-weight:600;color:#052228;font-size:.77rem;text-align:right;max-width:60%}.cs{margin-bottom:.8rem}.cs h5{font-size:.7rem;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;font-weight:700;margin-bottom:.3rem}.cb{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.64rem;font-weight:700;color:#fff}';
document.head.appendChild(st);

var wrap = document.createElement('div');
wrap.innerHTML = html;
while(wrap.firstChild) document.body.appendChild(wrap.firstChild);

// API URL - detectar base
var base = '/conecta';
var scripts = document.querySelectorAll('script[src]');
for(var i=0;i<scripts.length;i++){var m=scripts[i].src.match(/(.*)\/assets\/js\/drawer\.js/);if(m){var u=new URL(m[1]);base=u.pathname;break}}
var AU = base + '/modules/shared/card_api.php';
var XU = base + '/modules/shared/card_actions.php';
var OU = base + '/modules/operacional/api.php';

var D=null,T='geral';

function E(s){if(!s&&s!==0)return'\u2014';var d=document.createElement('div');d.textContent=s;return d.innerHTML}
function FD(s){if(!s)return'\u2014';var p=s.split(/[-T :]/);if(p.length>=5)return p[2]+'/'+p[1]+'/'+p[0]+' '+p[3]+':'+p[4];if(p.length>=3)return p[2]+'/'+p[1]+'/'+p[0];return s}
function R(l,v){return'<div class="cr"><span class="l">'+l+'</span><span class="v">'+E(v)+'</span></div>'}
function RE(l,v,ent,eid,fld){
var id='e_'+ent+'_'+fld;
var dv=v||'';
return'<div class="cr"><span class="l">'+l+'</span>'
+'<span style="display:flex;align-items:center;gap:4px;max-width:60%;justify-content:flex-end">'
+'<span class="v" id="'+id+'_v" onclick="cdEdit(\''+id+'\',\''+ent+'\','+eid+',\''+fld+'\')" style="cursor:pointer" title="Clique para editar">'+E(v)+'</span>'
+'<span onclick="cdEdit(\''+id+'\',\''+ent+'\','+eid+',\''+fld+'\')" style="cursor:pointer;font-size:.65rem;color:#B87333;opacity:.5" class="cd-pencil" title="Editar">&#9998;</span>'
+'<span id="'+id+'_i" style="display:none;align-items:center;gap:3px"><input id="'+id+'" value="'+dv.replace(/"/g,'&quot;')+'" style="width:150px;font-size:.77rem;padding:3px 6px;border:1.5px solid #B87333;border-radius:4px" onkeydown="if(event.key===\'Enter\')cdSave(\''+id+'\',\''+ent+'\','+eid+',\''+fld+'\')">'
+'<button onclick="cdSave(\''+id+'\',\''+ent+'\','+eid+',\''+fld+'\')" style="background:#059669;color:#fff;border:none;padding:3px 8px;border-radius:4px;font-size:.65rem;cursor:pointer;font-weight:600">Salvar</button>'
+'<button onclick="cdCancelEdit(\''+id+'\')" style="background:#f3f4f6;border:none;color:#6b7280;cursor:pointer;font-size:.65rem;padding:3px 6px;border-radius:4px">Cancelar</button></span>'
+'<span id="'+id+'_ok" style="display:none;font-size:.6rem;color:#059669;font-weight:600">Salvo!</span>'
+'</span></div>'
}
window.cdEdit=function(id){
var v=document.getElementById(id+'_v');if(v)v.style.display='none';
var p=v?v.nextElementSibling:null;if(p&&p.classList.contains('cd-pencil'))p.style.display='none';
var i=document.getElementById(id+'_i');if(i)i.style.display='flex';
var inp=document.getElementById(id);if(inp)setTimeout(function(){inp.focus();inp.select()},50);
};
window.cdCancelEdit=function(id){
var v=document.getElementById(id+'_v');if(v)v.style.display='';
var p=v?v.nextElementSibling:null;if(p&&p.classList.contains('cd-pencil'))p.style.display='';
var i=document.getElementById(id+'_i');if(i)i.style.display='none';
};
window.cdSave=function(id,ent,eid,fld){
var inp=document.getElementById(id);if(!inp)return;
var val=inp.value;
var x=new XMLHttpRequest();x.open('POST',XU);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
x.onload=function(){
try{var r=JSON.parse(x.responseText);
if(r.ok){
document.getElementById(id+'_v').textContent=val||'\u2014';
document.getElementById(id+'_v').style.display='';
document.getElementById(id+'_i').style.display='none';
var ok=document.getElementById(id+'_ok');if(ok){ok.style.display='inline';setTimeout(function(){ok.style.display='none'},2000)}
}else{alert(r.error||'Erro')}
}catch(e){alert('Erro ao salvar')}
};
x.send('action=update_field&entity='+ent+'&entity_id='+eid+'&field='+fld+'&value='+encodeURIComponent(val))
};

window.cdOpen=function(p){
document.getElementById('cdOv').style.display='block';
var pn=document.getElementById('cdPn');pn.style.display='flex';
setTimeout(function(){pn.style.right='0'},10);
document.getElementById('cdBd').innerHTML='<div style="text-align:center;padding:3rem;color:#94a3b8">Carregando...</div>';
var x=new XMLHttpRequest();
x.open('GET',AU+'?'+p);
x.onload=function(){
try{D=JSON.parse(x.responseText);if(D.error){document.getElementById('cdBd').textContent=D.error;return}bld()}
catch(e){document.getElementById('cdBd').textContent='Erro: '+e.message}
};x.onerror=function(){document.getElementById('cdBd').textContent='Erro de rede'};x.send()
};

window.cdClose=function(){
var pn=document.getElementById('cdPn');pn.style.right='-520px';
setTimeout(function(){document.getElementById('cdOv').style.display='none';pn.style.display='none'},300)
};

function bld(){
var c=D.client||{},l=D.lead,s=D.caso,sl=D.stage_labels||{},stl=D.status_labels||{};
var hd='<div style="display:flex;justify-content:space-between"><div><div style="font-size:1.05rem;font-weight:800">'+E(c.name)+'</div>';
var m=[];if(c.cpf)m.push('CPF: '+c.cpf);if(c.phone)m.push(c.phone);
hd+='<div style="font-size:.73rem;color:rgba(255,255,255,.6);margin-top:.15rem">'+m.join(' \u00B7 ')+'</div></div>';
hd+='<button onclick="cdClose()" style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer">X</button></div>';
var bg='';if(l)bg+='<span class="cb" style="background:#6366f1">'+(sl[l.stage]||l.stage)+'</span> ';
if(s)bg+='<span class="cb" style="background:#059669">'+(stl[s.status]||s.status)+'</span>';
hd+='<div style="margin-top:.4rem">'+bg+'</div>';
var bt='';
if(c.phone)bt+='<a href="https://wa.me/55'+c.phone.replace(/\D/g,'')+'" target="_blank" style="background:#25D366;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none">WhatsApp</a> ';
if(s)bt+='<a href="'+base+'/modules/operacional/caso_ver.php?id='+D.case_id+'" style="background:#B87333;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none">Pasta</a> ';
bt+='<a href="'+base+'/modules/clientes/ver.php?id='+D.client_id+'" style="background:#052228;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none">Perfil</a> ';
if(s)bt+='<button onclick="window._cdArchive()" style="background:#6b7280;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;border:none;cursor:pointer">Arquivar</button> ';
if(s&&D.can_comercial)bt+='<button onclick="window._cdMerge()" style="background:#5B2D8E;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;border:none;cursor:pointer">Juntar</button> ';
bt+='<button onclick="window._cdDuplicate()" style="background:#6366f1;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;border:none;cursor:pointer">Duplicar</button> ';
bt+='<button onclick="window._cdDelete()" style="background:#dc2626;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;border:none;cursor:pointer">Excluir</button>';
hd+='<div style="margin-top:.4rem;display:flex;gap:.3rem;flex-wrap:wrap">'+bt+'</div>';
document.getElementById('cdHd').innerHTML=hd;
var tabs=['geral','comercial','operacional','docs','agenda','historico'];
var tl={geral:'Geral',comercial:'Comercial',operacional:'Operacional',docs:'Doc. Faltantes',agenda:'Agenda',historico:'Hist.'};
var th='';tabs.forEach(function(t){if(t==='comercial'&&!D.can_comercial)return;th+='<button class="ct'+(t===T?' on':'')+'" onclick="window._cdST(\''+t+'\')">'+tl[t]+'</button>'});
document.getElementById('cdTb').innerHTML=th;
rtab()
}

window._cdST=function(t){T=t;document.querySelectorAll('.ct').forEach(function(b){b.classList.remove('on')});var a=document.querySelector('.ct[onclick*="\''+t+'\'"]');if(a)a.classList.add('on');rtab()};

function rtab(){
var c=D.client||{},l=D.lead,s=D.caso,sl=D.stage_labels||{},stl=D.status_labels||{},h='';
if(T==='geral'){
var ci=D.client_id;
h+='<div class="cs"><h5>Dados do Cliente</h5>'+RE('Nome',c.name,'client',ci,'name')+RE('CPF',c.cpf,'client',ci,'cpf')+RE('Telefone',c.phone,'client',ci,'phone')+RE('E-mail',c.email,'client',ci,'email')+RE('Endereco',c.address_street,'client',ci,'address_street')+RE('Cidade',c.address_city,'client',ci,'address_city')+RE('UF',c.address_state,'client',ci,'address_state')+RE('CEP',c.address_zip,'client',ci,'address_zip')+'</div>';
if(l||s){h+='<div class="cs"><h5>Status</h5>';if(l)h+=R('Pipeline',sl[l.stage]||l.stage);if(s)h+=R('Operacional',stl[s.status]||s.status)+R('Tipo',s.case_type);h+='</div>'}
if(D.form_data){h+='<div class="cs"><h5>Formulario</h5>';var sk='nome,name,client_name,client_phone,client_email,email,celular,phone,cpf,form_type,protocol_original,protocol,protocolo,id,created_at,updated_at,ip,ip_address,user_agent,data_envio,payload_json,totais'.split(',');for(var k in D.form_data){if(sk.indexOf(k)>=0)continue;var fv=D.form_data[k];if(fv===null||fv===''||typeof fv==='object')continue;h+=R(k.replace(/_/g,' '),fv)}h+='</div>'}
h+='<div class="cs"><h5>Comentarios</h5><textarea id="cdCm" style="width:100%;font-size:.8rem;padding:6px;border:1.5px solid #e5e7eb;border-radius:6px;min-height:45px;resize:vertical" placeholder="Escrever..."></textarea><button onclick="window._cdCom()" style="background:#B87333;color:#fff;border:none;padding:3px 12px;border-radius:5px;font-size:.7rem;font-weight:600;cursor:pointer;margin-top:3px">Comentar</button>';
(D.comments||[]).forEach(function(cm){h+='<div style="padding:5px 0;border-top:1px solid #f3f4f6;margin-top:3px"><strong style="font-size:.73rem">'+E(cm.user_name)+'</strong> <span style="font-size:.6rem;color:#94a3b8">'+FD(cm.created_at)+'</span><div style="font-size:.78rem;margin-top:1px">'+E(cm.message)+'</div></div>'});h+='</div>'
}else if(T==='comercial'&&l){
var li=D.lead_id;
h+='<div class="cs"><h5>Contrato</h5>'+RE('Honorários (R$)',l.honorarios_cents?'R$ '+(l.honorarios_cents/100).toLocaleString('pt-BR',{minimumFractionDigits:2}):l.valor_acao,'lead',li,'valor_acao')+RE('Êxito (%)',l.exito_percentual?l.exito_percentual+'%':null,'lead',li,'exito_percentual')+RE('Forma Pgto',l.forma_pagamento,'lead',li,'forma_pagamento')+RE('Vencimento',l.vencimento_parcela,'lead',li,'vencimento_parcela')+RE('Pasta',l.nome_pasta,'lead',li,'nome_pasta')+RE('Pendencias',l.pendencias,'lead',li,'pendencias')+R('Convertido',l.converted_at?FD(l.converted_at):null)+'</div>';
// Checklist de documentos (tarefas sem tipo = checklist)
var chkTasks=(D.tasks||[]).filter(function(t){return !t.tipo});
if(chkTasks.length){var doneC=chkTasks.filter(function(t){return t.status==='concluido'}).length;
h+='<div class="cs"><h5>Checklist Documentos ('+doneC+'/'+chkTasks.length+')</h5>';
chkTasks.forEach(function(t){var dn=t.status==='concluido';
h+='<div style="display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid #f3f4f6">'
+'<button onclick="event.stopPropagation();window._cdToggleTask('+t.id+',\''+t.status+'\')" style="background:none;border:none;cursor:pointer;font-size:.9rem;padding:0;line-height:1">'+(dn?'<span style="color:#059669">&#9745;</span>':'<span style="color:#d1d5db">&#9744;</span>')+'</button>'
+'<span style="font-size:.77rem;'+(dn?'text-decoration:line-through;color:#94a3b8':'')+'">'+E(t.title)+'</span></div>'});
h+='</div>'}
h+='<div class="cs"><h5>Histórico Pipeline</h5>';(D.pipeline_history||[]).forEach(function(ph){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;font-size:.75rem"><strong>'+E(ph.user_name?ph.user_name.split(' ')[0]:'')+'</strong> moveu para <strong>'+(sl[ph.to_stage]||ph.to_stage)+'</strong> <span style="color:#94a3b8;font-size:.62rem">'+FD(ph.created_at)+'</span></div>'});if(!(D.pipeline_history||[]).length)h+='<div style="color:#94a3b8">Nenhuma</div>';h+='</div>'
}else if(T==='operacional'&&s){
var si=D.case_id;
h+='<div class="cs"><h5>Processo</h5>'+RE('Pasta',s.title,'case',si,'title')+RE('Nr Processo',s.case_number,'case',si,'case_number')+RE('Vara',s.court,'case',si,'court')+RE('Comarca',s.comarca,'case',si,'comarca')+RE('UF',s.comarca_uf,'case',si,'comarca_uf')+RE('Regional',s.regional,'case',si,'regional')+RE('Sistema',s.sistema_tribunal,'case',si,'sistema_tribunal')+RE('Parte Re',s.parte_re_nome,'case',si,'parte_re_nome')+RE('CPF Parte Re',s.parte_re_cpf_cnpj,'case',si,'parte_re_cpf_cnpj')+RE('Link Drive',s.drive_folder_url,'case',si,'drive_folder_url')+'</div>';
var opTasks=(D.tasks||[]).filter(function(t){return !t.tipo});var opDone=opTasks.filter(function(t){return t.status==='concluido'}).length;
h+='<div class="cs"><h5>Checklist ('+opDone+'/'+opTasks.length+')</h5>';opTasks.forEach(function(t){var dn=t.status==='concluido';
h+='<div style="display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid #f3f4f6">'
+'<button onclick="event.stopPropagation();window._cdToggleTask('+t.id+',\''+t.status+'\')" style="background:none;border:none;cursor:pointer;font-size:.9rem;padding:0;line-height:1">'+(dn?'<span style="color:#059669">&#9745;</span>':'<span style="color:#d1d5db">&#9744;</span>')+'</button>'
+'<span style="font-size:.77rem;'+(dn?'text-decoration:line-through;color:#94a3b8':'')+'">'+E(t.title)+'</span></div>'});h+='</div>';
h+='<div class="cs"><h5>Andamentos</h5>';(D.andamentos||[]).slice(0,5).forEach(function(a){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;font-size:.75rem"><strong>'+FD(a.data_andamento)+'</strong> '+E(a.tipo)+' <span style="color:#6b7280">'+E(a.descricao?a.descricao.substring(0,100):'')+'</span></div>'});h+='</div>'
}else if(T==='docs'){
var pend=(D.docs_pendentes||[]).filter(function(x){return x.status==='pendente'}),recv=(D.docs_pendentes||[]).filter(function(x){return x.status!=='pendente'});
if(pend.length){h+='<div class="cs"><h5 style="color:#dc2626">Pendentes ('+pend.length+')</h5>';pend.forEach(function(dp){h+='<div style="display:flex;align-items:center;gap:6px;padding:5px;margin-bottom:3px;background:#fef2f2;border-radius:5px;border-left:3px solid #dc2626"><div style="flex:1;font-weight:600;color:#dc2626;font-size:.8rem">'+E(dp.descricao)+'</div><button onclick="window._cdDoc('+dp.id+')" id="db'+dp.id+'" style="background:#059669;color:#fff;border:none;padding:3px 8px;border-radius:4px;font-size:.68rem;font-weight:600;cursor:pointer">Recebido</button></div>'});h+='</div>'}
if(recv.length){h+='<div class="cs"><h5 style="color:#059669">Recebidos ('+recv.length+')</h5>';recv.forEach(function(dp){h+='<div style="padding:2px 0;border-bottom:1px solid #f3f4f6;opacity:.6;text-decoration:line-through;font-size:.77rem">'+E(dp.descricao)+'</div>'});h+='</div>'}
h+='<div class="cs"><h5>Pecas ('+(D.pecas||[]).length+')</h5>';(D.pecas||[]).forEach(function(p){h+='<div style="padding:2px 0;border-bottom:1px solid #f3f4f6;font-size:.77rem">'+E(p.titulo||'Peca')+'</div>'});if(!(D.pecas||[]).length)h+='<div style="color:#94a3b8;font-size:.77rem">Nenhuma</div>';h+='</div>'
}else if(T==='agenda'){
h+='<div class="cs"><h5>Compromissos</h5>';(D.compromissos||[]).forEach(function(ev){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6"><div style="font-weight:600;font-size:.77rem">'+E(ev.titulo)+'</div><div style="font-size:.67rem;color:#6b7280">'+FD(ev.data_inicio)+' - '+E(ev.tipo)+'</div></div>'});if(!(D.compromissos||[]).length)h+='<div style="color:#94a3b8;font-size:.77rem">Nenhum</div>';h+='</div>'
}else if(T==='historico'){
(D.historico||[]).forEach(function(hi){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;font-size:.75rem"><strong>'+FD(hi.date)+'</strong> '+hi.icon+' '+E(hi.text)+'</div>'});if(!(D.historico||[]).length)h+='<div style="color:#94a3b8;text-align:center;padding:1rem">Nenhum</div>'
}else{h='<div style="color:#94a3b8;padding:2rem;text-align:center">Sem dados</div>'}
document.getElementById('cdBd').innerHTML=h
}

window._cdCom=function(){var t=document.getElementById('cdCm');if(!t||!t.value.trim())return;var x=new XMLHttpRequest();x.open('POST',XU);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');x.onload=function(){try{var r=JSON.parse(x.responseText);if(r.ok&&r.comment){if(!D.comments)D.comments=[];D.comments.unshift(r.comment);t.value='';rtab()}}catch(e){}};x.send('action=add_comment&client_id='+D.client_id+'&case_id='+(D.case_id||0)+'&lead_id='+(D.lead_id||0)+'&message='+encodeURIComponent(t.value))};
window._cdDoc=function(id){if(!confirm('Confirmar recebimento?'))return;var b=document.getElementById('db'+id);if(b){b.textContent='...';b.disabled=true}
var x=new XMLHttpRequest();x.open('POST',OU);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
x.setRequestHeader('X-Requested-With','XMLHttpRequest');
x.onload=function(){
try{var r=JSON.parse(x.responseText);
if(r.error){alert('Erro: '+r.error);if(b){b.textContent='Recebido';b.disabled=false}return}
if(!r.ok){alert('Falha ao processar documento. Recarregue a pagina.');if(b){b.textContent='Recebido';b.disabled=false}return}
console.log('[Drawer] resolve_doc OK: pending='+r.pending+' case_id='+r.case_id+' restored_to='+r.restored_to);
}catch(e){console.error('[Drawer] resolve_doc parse error',x.responseText);alert('Erro de comunicacao. Atualize a pagina e tente novamente.');if(b){b.textContent='Recebido';b.disabled=false}return}
var reopen=D.lead_id?'lead_id='+D.lead_id:(D.case_id?'case_id='+D.case_id:'client_id='+D.client_id);
cdOpen(reopen);setTimeout(function(){T='docs';rtab()},600)};
x.onerror=function(){alert('Erro de rede. Verifique a conexão.');if(b){b.textContent='Recebido';b.disabled=false}};
x.send('action=resolve_doc&doc_id='+id+'&case_id='+(D.case_id||0)+'&csrf_token='+(D.csrf||''))};

window._cdToggleTask=function(taskId,currentStatus){
var newStatus=(currentStatus==='concluido')?'a_fazer':'concluido';
var x=new XMLHttpRequest();x.open('POST',XU);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
x.onload=function(){
try{var r=JSON.parse(x.responseText);if(r.error){alert(r.error);return;}
// Atualizar D.tasks localmente
(D.tasks||[]).forEach(function(t){if(t.id==taskId){t.status=newStatus;if(newStatus==='concluido')t.completed_at=new Date().toISOString()}});
rtab();
}catch(e){}
};
x.send('action=update_field&entity=task&entity_id='+taskId+'&field=status&value='+newStatus)};

window._cdArchive=function(){
if(!D.case_id){alert('Este card não tem processo vinculado.');return}
if(!confirm('Ocultar este processo do Kanban?\nO processo continua inalterado, só sai desta visualização.'))return;
var x=new XMLHttpRequest();x.open('POST',base+'/modules/operacional/api.php');x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
x.onload=function(){cdClose();location.reload()};
x.send('action=ocultar_kanban&case_id='+D.case_id+'&csrf_token='+(D.csrf||''))};


window._cdMerge=function(){
if(!D.case_id){alert('Este card n\u00e3o tem processo vinculado.');return}
// Buscar outros casos do mesmo cliente
var x=new XMLHttpRequest();x.open('POST',base+'/modules/operacional/api.php');
x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
x.onload=function(){
try{
var r=JSON.parse(x.responseText);
if(r.csrf)D.csrf=r.csrf;
var casos=r.casos||[];
if(!casos.length){alert('Nenhum outro caso deste cliente para juntar. O cliente precisa ter mais de um processo.');return}
// Montar modal inline
var opts='';
for(var i=0;i<casos.length;i++){
var c=casos[i];
opts+='<option value="'+c.id+'">'+c.title+(c.case_number?' — '+c.case_number:'')+ ' ['+c.status+']</option>';
}
var caseTitulo=D.caso?D.caso.title:'Caso #'+D.case_id;
var html='<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center" id="mergeOverlay">'
+'<div style="background:#fff;border-radius:16px;padding:1.5rem;max-width:460px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)">'
+'<h3 style="font-size:1rem;font-weight:700;color:#5B2D8E;margin:0 0 .5rem">Juntar com outra pasta</h3>'
+'<p style="font-size:.78rem;color:#6b7280;margin:0 0 .5rem">O caso selecionado ser\u00e1 <b>absorvido</b> por: <b>'+caseTitulo+'</b></p>'
+'<div style="margin-bottom:.6rem"><label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem">Caso a ser absorvido (vai desaparecer)</label>'
+'<select id="mergeSelDr" style="width:100%;padding:.5rem .7rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit"><option value="">\u2014 Selecionar \u2014</option>'+opts+'</select></div>'
+'<div style="margin-bottom:.6rem"><label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem">Novo t\u00edtulo (opcional)</label>'
+'<input type="text" id="mergeTitDr" value="'+caseTitulo+'" style="width:100%;padding:.5rem .7rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit"></div>'
+'<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.4rem .6rem;margin-bottom:.6rem;font-size:.72rem;color:#dc2626;font-weight:600">Esta a\u00e7\u00e3o n\u00e3o pode ser desfeita.</div>'
+'<div style="display:flex;gap:.4rem;justify-content:flex-end">'
+'<button onclick="document.getElementById(\'mergeOverlay\').remove()" style="padding:.4rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.8rem">Cancelar</button>'
+'<button onclick="window._cdMergeConfirm()" style="padding:.4rem 1rem;border:none;border-radius:8px;background:#5B2D8E;color:#fff;cursor:pointer;font-family:inherit;font-size:.8rem;font-weight:700">Confirmar</button>'
+'</div></div></div>';
document.body.insertAdjacentHTML('beforeend',html);
}catch(e){alert('Erro ao carregar casos')}
};
x.send('action=buscar_casos_cliente&case_id='+D.case_id+'&csrf_token='+(D.csrf||''));
};

window._cdMergeConfirm=function(){
var sel=document.getElementById('mergeSelDr');
var tit=document.getElementById('mergeTitDr');
if(!sel||!sel.value){if(sel)sel.style.borderColor='#ef4444';return}
if(!confirm('Tem certeza? O caso selecionado ser\u00e1 absorvido e arquivado. Esta a\u00e7\u00e3o N\u00c3O pode ser desfeita.'))return;
var form=document.createElement('form');form.method='POST';form.action=base+'/modules/operacional/api.php';
function af(n,v){var i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;form.appendChild(i)}
af('csrf_token',D.csrf||'');af('action','merge_cases');af('case_principal',D.case_id);af('case_absorvido',sel.value);af('novo_titulo',tit?tit.value:'');
document.body.appendChild(form);form.submit();
};

window._cdDuplicate=function(){
var clientName=D.client?D.client.name:(D.lead?D.lead.name:'Cliente');
var tipos=['Alimentos','Revis\u00e3o de Alimentos','Execu\u00e7\u00e3o de Alimentos','Exonera\u00e7\u00e3o de Alimentos',
'Div\u00f3rcio','Div\u00f3rcio Consensual','Div\u00f3rcio Litigioso','Guarda','Guarda Compartilhada',
'Regulamenta\u00e7\u00e3o de Conviv\u00eancia','Conviv\u00eancia','Investiga\u00e7\u00e3o de Paternidade',
'Medida Protetiva','Tutela de Urg\u00eancia','Invent\u00e1rio','Usucapi\u00e3o',
'Indeniza\u00e7\u00e3o','Consignat\u00f3ria','Trabalhista','Outro'];
var opts='';for(var i=0;i<tipos.length;i++){opts+='<option value="'+tipos[i]+'">'+tipos[i]+'</option>';}
var html='<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center" id="dupOverlay">'
+'<div style="background:#fff;border-radius:16px;padding:1.5rem;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3)">'
+'<h3 style="font-size:1rem;font-weight:700;color:#6366f1;margin:0 0 .5rem">Duplicar para nova a\u00e7\u00e3o</h3>'
+'<p style="font-size:.78rem;color:#6b7280;margin:0 0 .75rem">Cliente: <b>'+clientName+'</b></p>'
+'<div style="margin-bottom:.6rem"><label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem">Tipo de a\u00e7\u00e3o *</label>'
+'<select id="dupTipoAcao" style="width:100%;padding:.5rem .7rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit"><option value="">\u2014 Selecione \u2014</option>'+opts+'</select></div>'
+'<div style="margin-bottom:.6rem"><label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem">T\u00edtulo da pasta</label>'
+'<input type="text" id="dupTitulo" value="'+clientName+' x " style="width:100%;padding:.5rem .7rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit" placeholder="Ex: Fulano x Alimentos"></div>'
+'<div style="display:flex;gap:.4rem;justify-content:flex-end">'
+'<button onclick="document.getElementById(\'dupOverlay\').remove()" style="padding:.4rem .8rem;border:1.5px solid #e5e7eb;border-radius:8px;background:#fff;cursor:pointer;font-family:inherit;font-size:.8rem">Cancelar</button>'
+'<button onclick="window._cdDupConfirm()" style="padding:.4rem 1rem;border:none;border-radius:8px;background:#6366f1;color:#fff;cursor:pointer;font-family:inherit;font-size:.8rem;font-weight:700">Criar</button>'
+'</div></div></div>';
document.body.insertAdjacentHTML('beforeend',html);
// Auto-preencher título ao selecionar tipo
document.getElementById('dupTipoAcao').addEventListener('change',function(){
var t=this.value;if(t)document.getElementById('dupTitulo').value=clientName+' x '+t;
});
};

window._cdDupConfirm=function(){
var tipo=document.getElementById('dupTipoAcao').value;
if(!tipo){document.getElementById('dupTipoAcao').style.borderColor='#ef4444';return;}
var titulo=document.getElementById('dupTitulo').value.trim();
if(!titulo)titulo=(D.client?D.client.name:'')+ ' x '+tipo;
var el=document.getElementById('dupOverlay');if(el)el.remove();

// Buscar CSRF fresco antes de submeter
var xhr=new XMLHttpRequest();
xhr.open('GET',base+'/modules/shared/card_api.php?client_id='+(D.client_id||1));
xhr.onload=function(){
var freshCsrf=D.csrf||'';
try{var r=JSON.parse(xhr.responseText);if(r.csrf)freshCsrf=r.csrf;}catch(e){}

var form=document.createElement('form');form.method='POST';form.action=base+'/modules/operacional/api.php';
function af(n,v){var i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;form.appendChild(i)}
af('csrf_token',freshCsrf);
if(D.lead_id){
af('action','duplicate');af('lead_id',D.lead_id);af('case_type',tipo);af('titulo',titulo);
form.action=base+'/modules/pipeline/api.php';
}else{
af('action','duplicate_case');af('case_id',D.case_id||'0');af('client_id',D.client_id||'0');af('lead_id','0');af('case_type',tipo);af('titulo',titulo);
}
document.body.appendChild(form);form.submit();
};
xhr.send();
};

window._cdDelete=function(){
var msg='Remover este card do fluxo?\n\n';
if(D.lead_id&&D.case_id)msg+='O lead sai do Pipeline e o caso é arquivado no Operacional.\nOs dados (processo, documentos, histórico) continuam salvos.';
else if(D.lead_id)msg+='O lead sai do Pipeline Comercial.\nOs dados do cliente continuam salvos.';
else if(D.case_id)msg+='O caso é arquivado no Operacional.\nOs dados (processo, documentos, histórico) continuam salvos.';
if(!confirm(msg))return;
var x=new XMLHttpRequest();x.open('POST',XU);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
x.onload=function(){
try{var r=JSON.parse(x.responseText);
if(r.error){alert('Erro: '+r.error);return}
cdClose();
location.reload();
}catch(e){alert('Erro ao remover')}
};
x.send('action=delete_card&lead_id='+(D.lead_id||0)+'&case_id='+(D.case_id||0))};

// ESC fecha
document.addEventListener('keydown',function(e){if(e.key==='Escape')cdClose()});

// Interceptar cliques nos cards
document.addEventListener('click',function(e){
var op=e.target.closest('.op-card[data-case-id]');
if(op&&!e.target.closest('select,form,.op-card-move,a')){e.stopImmediatePropagation();e.preventDefault();cdOpen('case_id='+op.getAttribute('data-case-id'));return}
var lc=e.target.closest('.lead-card[data-lead-id]');
if(lc&&!e.target.closest('.lead-actions,select,form,a')){e.stopImmediatePropagation();e.preventDefault();cdOpen('lead_id='+lc.getAttribute('data-lead-id'));return}
},true);

console.log('[Drawer] OK');
})();
