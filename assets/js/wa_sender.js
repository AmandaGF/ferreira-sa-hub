/**
 * Modal global pra enviar mensagem via WhatsApp pelo Hub (substitui wa.me/...)
 *
 * Uso:
 *   waSenderOpen({
 *     telefone: '24999998888',           // obrigatório
 *     nome:     'Amanda Ferreira',       // opcional, só pra exibição
 *     mensagem: 'Olá, segue o link...',  // obrigatório (pode editar no modal)
 *     clientId: 123,                     // opcional — vincula a conversa
 *     leadId:   0,                       // opcional
 *     canal:    '24',                    // default: 24 (CX). Use '21' (Comercial) quando for comercial
 *     onSuccess: function(data){...}     // opcional callback
 *   });
 */
(function(){
    if (window.waSenderOpen) return; // já carregado

    function esc(s) { return (s||'').replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }

    var WA_API_URL  = (window.FSA_WHATSAPP_API_URL) || '/conecta/modules/whatsapp/api.php';
    var WA_CSRF     = (window.FSA_CSRF) || '';

    window.waSenderOpen = function(opts) {
        opts = opts || {};
        var tel      = (opts.telefone || '').toString().replace(/\D/g, '');
        var nome     = opts.nome || '';
        var mensagem = opts.mensagem || '';
        var canal    = opts.canal || '24';
        var clientId = opts.clientId || 0;
        var leadId   = opts.leadId || 0;
        var onSuccess = typeof opts.onSuccess === 'function' ? opts.onSuccess : null;

        if (!tel) { alert('Telefone não informado.'); return; }

        // Garante DDI 55 se faltar (BR)
        if (tel.length < 12 && !tel.startsWith('55')) tel = '55' + tel;

        var id = 'waSenderModal_' + Date.now();
        var html = ''
          + '<div id="'+id+'" style="position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem;">'
          +   '<div style="background:#fff;border-radius:14px;padding:1.25rem 1.5rem;max-width:540px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.35);max-height:92vh;overflow-y:auto;font-family:inherit;">'
          +     '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;">'
          +       '<h3 style="margin:0;font-size:1.05rem;color:#0f2140;">💬 Enviar via WhatsApp (pelo Hub)</h3>'
          +       '<button type="button" data-act="close" style="background:#f3f4f6;border:none;border-radius:50%;width:30px;height:30px;cursor:pointer;font-size:1rem;">✕</button>'
          +     '</div>'
          +     '<div style="font-size:.8rem;color:#6b7280;margin-bottom:.75rem;">'
          +       'Para: <strong style="color:#0f2140;">'+esc(nome || 'Contato')+'</strong> &middot; '+esc(formatTelBr(tel))
          +     '</div>'
          +     '<label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Canal do WhatsApp</label>'
          +     '<select data-field="canal" style="width:100%;padding:6px 10px;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:.75rem;font-size:.88rem;">'
          +       '<option value="24" '+(canal==='24'?'selected':'')+'>📞 DDD 24 (CX / Operacional)</option>'
          +       '<option value="21" '+(canal==='21'?'selected':'')+'>💼 DDD 21 (Comercial)</option>'
          +     '</select>'
          +     '<label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.3rem;">Modelos rápidos (clique pra preencher)</label>'
          +     '<div data-field="tpls" style="display:flex;flex-wrap:wrap;gap:.35rem;margin-bottom:.75rem;"></div>'
          +     '<label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Mensagem (edite antes de enviar)</label>'
          +     '<textarea data-field="msg" rows="14" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:.88rem;font-family:inherit;resize:vertical;min-height:240px;max-height:60vh;line-height:1.45;">'+esc(mensagem)+'</textarea>'
          +     '<div style="font-size:.68rem;color:#9ca3af;margin-top:.3rem;">A mensagem fica registrada no histórico do WhatsApp do Hub e o cliente pode responder por lá.</div>'
          +     '<div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;">'
          +       '<button type="button" data-act="close" style="background:#f3f4f6;border:1px solid #d1d5db;padding:8px 16px;border-radius:6px;cursor:pointer;">Cancelar</button>'
          +       '<button type="button" data-act="send" style="background:#25d366;color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-weight:700;">✓ Enviar</button>'
          +     '</div>'
          +   '</div>'
          + '</div>';

        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var modal = wrap.firstChild;
        document.body.appendChild(modal);

        function close() { if (modal.parentNode) modal.parentNode.removeChild(modal); }

        modal.querySelectorAll('[data-act="close"]').forEach(function(b){ b.addEventListener('click', close); });
        modal.addEventListener('click', function(e){ if (e.target === modal) close(); });

        // ── Modelos rápidos (chips) ───────────────────────────────────────
        // Primeira palavra do nome cabe nos templates pra personalização leve.
        // Se nome veio vazio, omitimos a saudação inicial (não fica "Olá ! ").
        var primeiroNome = ((nome||'').trim().split(/\s+/)[0] || '');
        var ola = primeiroNome ? ('Olá ' + primeiroNome + '!') : 'Olá!';
        var assin = '\n\n_Equipe Ferreira & Sá Advocacia_';

        var templates = [];
        if (clientId > 0) {
            templates.push({
                key:'vip', label:'🔑 Link Central VIP',
                bg:'#6366f1', color:'#fff',
                async:true,
                title:'Gera link de ativação de 72h e preenche a mensagem pronta. Cliente precisa ter CPF cadastrado.'
            });
        }
        templates.push({
            key:'saudacao', label:'👋 Saudação',
            bg:'#f3f4f6', color:'#374151',
            text: ola + ' Tudo bem com você?\n\nAqui é da Ferreira & Sá Advocacia.' + assin
        });
        templates.push({
            key:'docs', label:'📋 Pedir documentos',
            bg:'#fef3c7', color:'#92400e',
            text: ola + '\n\nPara darmos seguimento ao seu processo, precisamos dos seguintes documentos:\n\n• [listar aqui]\n\nPode nos enviar por aqui mesmo, em PDF ou foto. Qualquer dúvida, estamos à disposição!' + assin
        });
        templates.push({
            key:'compromisso', label:'📅 Confirmar compromisso',
            bg:'#dbeafe', color:'#1e40af',
            text: ola + '\n\nConfirmando seu compromisso:\n\n📅 Data: [data]\n🕐 Horário: [hora]\n📍 Local/Modo: [local]\n\nPor favor, confirme se está tudo certo. Em caso de imprevisto, nos avise com antecedência.' + assin
        });
        templates.push({
            key:'acompanhamento', label:'📞 Acompanhamento',
            bg:'#dcfce7', color:'#166534',
            text: ola + '\n\nTudo bem com você e sua família?\n\nEstamos passando para avisar que continuamos acompanhando seu(s) processo(s) com atenção. Até o momento não temos novidades relevantes a comunicar, mas seguimos monitorando todos os andamentos de perto.\n\nAssim que houver qualquer atualização importante, entraremos em contato imediatamente.\n\nQualquer dúvida, estamos à disposição.' + assin
        });

        var tplsBox = modal.querySelector('[data-field="tpls"]');
        var msgEl = modal.querySelector('[data-field="msg"]');
        templates.forEach(function(t){
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = t.label;
            if (t.title) b.title = t.title;
            b.style.cssText = 'background:'+t.bg+';color:'+t.color+';border:1px solid '+t.bg+';padding:5px 10px;border-radius:14px;cursor:pointer;font-size:.75rem;font-weight:600;font-family:inherit;';
            b.addEventListener('click', function(){
                // Se já tem texto, confirma substituição
                if (msgEl.value.trim() && !confirm('Substituir o texto atual pelo modelo "'+t.label+'"?')) return;

                if (t.async && t.key === 'vip') {
                    var orig = b.textContent;
                    b.textContent = '⏳ Gerando link...';
                    b.disabled = true;
                    var fd = new FormData();
                    fd.append('action', 'gerar_link_salavip_por_cliente');
                    fd.append('client_id', clientId);
                    fd.append('csrf_token', WA_CSRF);
                    fetch(WA_API_URL, { method:'POST', body:fd, credentials:'same-origin' })
                        .then(function(r){ return r.json(); })
                        .then(function(d){
                            b.textContent = orig; b.disabled = false;
                            if (d && d.ok && d.mensagem) {
                                msgEl.value = d.mensagem;
                                msgEl.focus();
                            } else {
                                alert('Falha ao gerar link: ' + ((d && d.error) || 'erro desconhecido'));
                            }
                        })
                        .catch(function(err){
                            b.textContent = orig; b.disabled = false;
                            alert('Erro: ' + err);
                        });
                    return;
                }
                msgEl.value = t.text;
                msgEl.focus();
            });
            tplsBox.appendChild(b);
        });

        modal.querySelector('[data-act="send"]').addEventListener('click', function(e){
            var btn = e.currentTarget;
            var canalSel = modal.querySelector('[data-field="canal"]').value;
            var msg = modal.querySelector('[data-field="msg"]').value.trim();
            if (!msg) { alert('Mensagem vazia.'); return; }

            btn.disabled = true; btn.textContent = 'Enviando...';

            var fd = new FormData();
            fd.append('action', 'enviar_rapido');
            fd.append('telefone', tel);
            fd.append('mensagem', msg);
            fd.append('canal', canalSel);
            if (clientId) fd.append('client_id', clientId);
            if (leadId)   fd.append('lead_id', leadId);
            if (nome)     fd.append('nome', nome);
            fd.append('csrf_token', WA_CSRF);

            fetch(WA_API_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d && d.ok) {
                        close();
                        if (onSuccess) onSuccess(d);
                        waSenderToast('✓ Mensagem enviada pelo Hub');
                    } else {
                        btn.disabled = false; btn.textContent = '✓ Enviar';
                        alert('Falha: ' + ((d && d.error) || 'erro desconhecido'));
                    }
                })
                .catch(function(err){
                    btn.disabled = false; btn.textContent = '✓ Enviar';
                    alert('Erro: ' + err);
                });
        });
    };

    function formatTelBr(t) {
        if (!t) return '';
        var n = t.replace(/\D/g, '');
        if (n.length >= 12) return '+' + n.substr(0,2) + ' (' + n.substr(2,2) + ') ' + n.substr(4,5) + '-' + n.substr(9);
        if (n.length === 11) return '(' + n.substr(0,2) + ') ' + n.substr(2,5) + '-' + n.substr(7);
        return t;
    }

    function waSenderToast(msg) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#059669;color:#fff;padding:12px 18px;border-radius:8px;font-weight:600;z-index:100000;box-shadow:0 8px 24px rgba(0,0,0,.25);font-family:inherit;';
        document.body.appendChild(t);
        setTimeout(function(){ t.style.transition = 'opacity .4s'; t.style.opacity = '0'; }, 2500);
        setTimeout(function(){ if (t.parentNode) t.parentNode.removeChild(t); }, 3000);
    }
})();
