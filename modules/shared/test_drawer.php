<div id="cdOv" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:998;" onclick="cdClose()"></div>
<div id="cdPn" style="position:fixed;top:0;right:-520px;width:510px;max-width:95vw;height:100vh;background:#fff;z-index:999;box-shadow:-8px 0 30px rgba(0,0,0,.15);transition:right .3s;display:none;flex-direction:column;"
 data-api="<?= url('modules/shared/card_api.php') ?>"
 data-act="<?= url('modules/shared/card_actions.php') ?>"
 data-op="<?= url('modules/operacional/api.php') ?>"
 data-tk="<?= CSRF_TOKEN_NAME ?>=<?= csrf_token() ?>">
<div id="cdHd" style="background:linear-gradient(135deg,#052228,#0d3640);color:#fff;padding:1rem 1.25rem;flex-shrink:0;"></div>
<div id="cdTb" style="display:flex;border-bottom:2px solid #e5e7eb;background:#fafafa;flex-shrink:0;overflow-x:auto;"></div>
<div id="cdBd" style="flex:1;overflow-y:auto;padding:1rem 1.25rem;font-size:.82rem;"></div>
</div>
<style>
.ct{padding:.5rem .8rem;font-size:.74rem;font-weight:600;color:#94a3b8;background:none;border:none;border-bottom:2px solid transparent;cursor:pointer;white-space:nowrap}
.ct:hover{color:#052228}.ct.on{color:#052228;border-bottom-color:#B87333}
.cr{display:flex;justify-content:space-between;padding:.25rem 0;border-bottom:1px solid #f3f4f6;min-height:24px;align-items:center}
.cr .l{color:#6b7280;font-size:.73rem}.cr .v{font-weight:600;color:#052228;font-size:.77rem;text-align:right;max-width:60%}
.cs{margin-bottom:.8rem}.cs h5{font-size:.7rem;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8;font-weight:700;margin-bottom:.3rem}
.cb{display:inline-block;padding:2px 7px;border-radius:4px;font-size:.64rem;font-weight:700;color:#fff}
</style>
<script>
(function(){
var pn=document.getElementById('cdPn');
var AU=pn.getAttribute('data-api');
var XU=pn.getAttribute('data-act');
var OU=pn.getAttribute('data-op');
var TK=pn.getAttribute('data-tk');
var D=null;
var T='geral';

window.cdOpen=function(p){
document.getElementById('cdOv').style.display='block';
pn.style.display='flex';
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
pn.style.right='-520px';
setTimeout(function(){document.getElementById('cdOv').style.display='none';pn.style.display='none'},300)
};

function E(s){if(!s&&s!==0)return'\u2014';var d=document.createElement('div');d.textContent=s;return d.innerHTML}
function FD(s){if(!s)return'\u2014';var p=s.split(/[-T ]/);return p.length>=3?p[2]+'/'+p[1]+'/'+p[0]:s}
function R(l,v){return'<div class="cr"><span class="l">'+l+'</span><span class="v">'+E(v)+'</span></div>'}

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
if(s)bt+='<a href="/conecta/modules/operacional/caso_ver.php?id='+D.case_id+'" style="background:#B87333;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none">Pasta</a> ';
bt+='<a href="/conecta/modules/clientes/ver.php?id='+D.client_id+'" style="background:#052228;color:#fff;padding:3px 10px;border-radius:5px;font-size:.7rem;font-weight:600;text-decoration:none">Perfil</a>';
hd+='<div style="margin-top:.4rem;display:flex;gap:.3rem;flex-wrap:wrap">'+bt+'</div>';
document.getElementById('cdHd').innerHTML=hd;
var tabs=['geral','comercial','operacional','docs','agenda','historico'];
var tl={geral:'Geral',comercial:'Comercial',operacional:'Operacional',docs:'Docs',agenda:'Agenda',historico:'Hist.'};
var th='';tabs.forEach(function(t){if(t==='comercial'&&!D.can_comercial)return;th+='<button class="ct'+(t===T?' on':'')+'" onclick="window._cdT=\''+t+'\';document.querySelectorAll(\'.ct\').forEach(function(b){b.classList.remove(\'on\')});this.classList.add(\'on\');window._cdRTab()">'+tl[t]+'</button>'});
document.getElementById('cdTb').innerHTML=th;
rtab()
}

function rtab(){
var c=D.client||{},l=D.lead,s=D.caso,sl=D.stage_labels||{},stl=D.status_labels||{},h='';
if(T==='geral'){
h+='<div class="cs"><h5>Dados do Cliente</h5>'+R('Nome',c.name)+R('CPF',c.cpf)+R('Telefone',c.phone)+R('E-mail',c.email)+R('Endereco',[c.address_street,c.address_city,c.address_state].filter(Boolean).join(', '))+R('CEP',c.address_zip)+'</div>';
if(l||s){h+='<div class="cs"><h5>Status</h5>';if(l)h+=R('Pipeline',sl[l.stage]||l.stage);if(s)h+=R('Operacional',stl[s.status]||s.status)+R('Tipo',s.case_type);h+='</div>'}
if(D.form_data){h+='<div class="cs"><h5>Formulario</h5>';var sk='nome,name,client_name,client_phone,client_email,email,celular,phone,cpf,form_type,protocol_original,protocol,protocolo,id,created_at,updated_at,ip,ip_address,user_agent,data_envio,payload_json,totais'.split(',');for(var k in D.form_data){if(sk.indexOf(k)>=0)continue;var fv=D.form_data[k];if(fv===null||fv===''||typeof fv==='object')continue;h+=R(k.replace(/_/g,' '),fv)}h+='</div>'}
h+='<div class="cs"><h5>Comentarios</h5><textarea id="cdCm" style="width:100%;font-size:.8rem;padding:6px;border:1.5px solid #e5e7eb;border-radius:6px;min-height:45px;resize:vertical" placeholder="Escrever..."></textarea><button onclick="window._cdCom()" style="background:#B87333;color:#fff;border:none;padding:3px 12px;border-radius:5px;font-size:.7rem;font-weight:600;cursor:pointer;margin-top:3px">Comentar</button>';
(D.comments||[]).forEach(function(cm){h+='<div style="padding:5px 0;border-top:1px solid #f3f4f6;margin-top:3px"><strong style="font-size:.73rem">'+E(cm.user_name)+'</strong> <span style="font-size:.6rem;color:#94a3b8">'+FD(cm.created_at)+'</span><div style="font-size:.78rem;margin-top:1px">'+E(cm.message)+'</div></div>'});h+='</div>'
}else if(T==='comercial'&&l){
h+='<div class="cs"><h5>Contrato</h5>'+R('Valor',l.valor_acao)+R('Forma Pgto',l.forma_pagamento)+R('Vencimento',l.vencimento_parcela)+R('Pasta',l.nome_pasta)+R('Pendencias',l.pendencias)+R('Convertido',l.converted_at?FD(l.converted_at):null)+'</div>';
h+='<div class="cs"><h5>Historico Pipeline</h5>';(D.pipeline_history||[]).forEach(function(ph){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;font-size:.75rem"><strong>'+E(ph.user_name?ph.user_name.split(' ')[0]:'')+'</strong> moveu para <strong>'+(sl[ph.to_stage]||ph.to_stage)+'</strong> <span style="color:#94a3b8;font-size:.62rem">'+FD(ph.created_at)+'</span></div>'});if(!(D.pipeline_history||[]).length)h+='<div style="color:#94a3b8">Nenhuma</div>';h+='</div>'
}else if(T==='operacional'&&s){
h+='<div class="cs"><h5>Processo</h5>'+R('Pasta',s.title)+R('Nr Processo',s.case_number)+R('Vara',s.court)+R('Comarca',(s.comarca||'')+(s.comarca_uf?'/'+s.comarca_uf:'')+(s.regional?' - Regional '+s.regional:''))+R('Sistema',s.sistema_tribunal)+R('Parte Re',s.parte_re_nome)+'</div>';
h+='<div class="cs"><h5>Tarefas ('+(D.tasks||[]).length+')</h5>';(D.tasks||[]).forEach(function(t){var dn=t.status==='feito';h+='<div style="display:flex;align-items:center;gap:5px;padding:2px 0;font-size:.77rem"><span style="color:'+(dn?'#059669':'#d1d5db')+'">'+(dn?'[x]':'[ ]')+'</span><span style="'+(dn?'text-decoration:line-through;color:#94a3b8':'')+'">'+E(t.title)+'</span></div>'});h+='</div>';
h+='<div class="cs"><h5>Andamentos</h5>';(D.andamentos||[]).slice(0,5).forEach(function(a){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;font-size:.75rem"><strong>'+FD(a.data_andamento)+'</strong> '+E(a.tipo)+' <span style="color:#6b7280">'+E(a.descricao?a.descricao.substring(0,100):'')+'</span></div>'});h+='</div>'
}else if(T==='docs'){
var pn2=(D.docs_pendentes||[]).filter(function(x){return x.status==='pendente'}),rv=(D.docs_pendentes||[]).filter(function(x){return x.status!=='pendente'});
if(pn2.length){h+='<div class="cs"><h5 style="color:#dc2626">Pendentes ('+pn2.length+')</h5>';pn2.forEach(function(dp){h+='<div style="display:flex;align-items:center;gap:6px;padding:5px;margin-bottom:3px;background:#fef2f2;border-radius:5px;border-left:3px solid #dc2626"><div style="flex:1;font-weight:600;color:#dc2626;font-size:.8rem">'+E(dp.descricao)+'</div><button onclick="window._cdDoc('+dp.id+')" id="db'+dp.id+'" style="background:#059669;color:#fff;border:none;padding:3px 8px;border-radius:4px;font-size:.68rem;font-weight:600;cursor:pointer">Recebido</button></div>'});h+='</div>'}
if(rv.length){h+='<div class="cs"><h5 style="color:#059669">Recebidos ('+rv.length+')</h5>';rv.forEach(function(dp){h+='<div style="padding:2px 0;border-bottom:1px solid #f3f4f6;opacity:.6;text-decoration:line-through;font-size:.77rem">'+E(dp.descricao)+'</div>'});h+='</div>'}
h+='<div class="cs"><h5>Pecas ('+(D.pecas||[]).length+')</h5>';(D.pecas||[]).forEach(function(p){h+='<div style="padding:2px 0;border-bottom:1px solid #f3f4f6;font-size:.77rem">'+E(p.titulo||'Peca')+'</div>'});if(!(D.pecas||[]).length)h+='<div style="color:#94a3b8;font-size:.77rem">Nenhuma</div>';h+='</div>'
}else if(T==='agenda'){
h+='<div class="cs"><h5>Compromissos</h5>';(D.compromissos||[]).forEach(function(ev){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6"><div style="font-weight:600;font-size:.77rem">'+E(ev.titulo)+'</div><div style="font-size:.67rem;color:#6b7280">'+FD(ev.data_inicio)+' - '+E(ev.tipo)+'</div></div>'});if(!(D.compromissos||[]).length)h+='<div style="color:#94a3b8;font-size:.77rem">Nenhum</div>';h+='</div>'
}else if(T==='historico'){
(D.historico||[]).forEach(function(hi){h+='<div style="padding:3px 0;border-bottom:1px solid #f3f4f6;font-size:.75rem"><strong>'+FD(hi.date)+'</strong> '+hi.icon+' '+E(hi.text)+'</div>'});if(!(D.historico||[]).length)h+='<div style="color:#94a3b8;text-align:center;padding:1rem">Nenhum</div>'
}else{h='<div style="color:#94a3b8;padding:2rem;text-align:center">Sem dados</div>'}
document.getElementById('cdBd').innerHTML=h
}

window._cdRTab=function(){T=window._cdT||T;rtab()};
window._cdCom=function(){var t=document.getElementById('cdCm');if(!t||!t.value.trim())return;var x=new XMLHttpRequest();x.open('POST',XU);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');x.onload=function(){try{var r=JSON.parse(x.responseText);if(r.ok&&r.comment){if(!D.comments)D.comments=[];D.comments.unshift(r.comment);t.value='';rtab()}}catch(e){}};x.send('action=add_comment&client_id='+D.client_id+'&case_id='+(D.case_id||0)+'&lead_id='+(D.lead_id||0)+'&message='+encodeURIComponent(t.value))};
window._cdDoc=function(id){if(!confirm('Confirmar recebimento?'))return;var b=document.getElementById('db'+id);if(b){b.textContent='...';b.disabled=true}var x=new XMLHttpRequest();x.open('POST',OU);x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');x.onload=function(){cdOpen('case_id='+D.case_id);setTimeout(function(){T='docs';rtab()},500)};x.send('action=resolve_doc&doc_id='+id+'&case_id='+D.case_id+'&'+TK)};

document.addEventListener('keydown',function(e){if(e.key==='Escape')cdClose()});
document.addEventListener('click',function(e){
var op=e.target.closest('.op-card[data-case-id]');
if(op&&!e.target.closest('select,form,.op-card-move,a')){e.stopImmediatePropagation();e.preventDefault();cdOpen('case_id='+op.getAttribute('data-case-id'));return}
var lc=e.target.closest('.lead-card[data-lead-id]');
if(lc&&!e.target.closest('.lead-actions,select,form,a')){e.stopImmediatePropagation();e.preventDefault();cdOpen('lead_id='+lc.getAttribute('data-lead-id'));return}
},true);
console.log('[CD] ok');
})();
</script>
