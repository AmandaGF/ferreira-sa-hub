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
          +     '<label style="font-size:.72rem;font-weight:700;color:#6b7280;display:block;margin-bottom:.2rem;">Mensagem (edite antes de enviar)</label>'
          +     '<textarea data-field="msg" rows="10" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:.88rem;font-family:inherit;resize:vertical;">'+esc(mensagem)+'</textarea>'
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
