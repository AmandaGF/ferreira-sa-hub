/**
 * Ações sobre cobranças Asaas — alterar vencimento, dar baixa, cancelar.
 * Requer window._COB_CSRF e window._COB_API_URL definidos antes do carregamento.
 * Usado em modules/financeiro/cobrancas.php e modules/financeiro/cliente.php.
 *
 * IMPORTANTE: usa modais HTML (não confirm/prompt nativos) — PWA instalado no Windows
 * pode bloquear dialogs nativos sem avisar, fazendo parecer que "nada acontece".
 */
(function(){
    // ─── Modal genérico ───
    function _cobModal(opts) {
        return new Promise(function(resolve){
            var ov = document.createElement('div');
            ov.className = '_cob-overlay';
            ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:999999;display:flex;align-items:center;justify-content:center;padding:1rem;';

            var btnsHtml = '';
            (opts.buttons || []).forEach(function(b, i){
                btnsHtml += '<button data-idx="' + i + '" style="padding:.55rem 1.1rem;border:none;border-radius:8px;font-weight:700;font-size:.82rem;cursor:pointer;font-family:inherit;background:' + (b.bg || '#e5e7eb') + ';color:' + (b.color || '#111') + ';margin-left:.5rem;">' + b.label + '</button>';
            });

            ov.innerHTML =
                '<div style="background:#fff;border-radius:12px;padding:1.5rem;max-width:460px;width:100%;box-shadow:0 20px 50px rgba(0,0,0,.3);font-family:inherit;">' +
                '  <h3 style="font-size:1rem;font-weight:800;color:#052228;margin:0 0 .5rem 0;">' + (opts.title || 'Confirmar') + '</h3>' +
                '  <div style="font-size:.85rem;color:#374151;line-height:1.5;margin-bottom:1rem;white-space:pre-line;">' + (opts.body || '') + '</div>' +
                   (opts.inputHtml || '') +
                '  <div style="display:flex;justify-content:flex-end;margin-top:1rem;padding-top:.75rem;border-top:1px solid #e5e7eb;">' + btnsHtml + '</div>' +
                '</div>';

            document.body.appendChild(ov);
            // Foca o primeiro input se houver
            var firstInput = ov.querySelector('input,select,textarea');
            if (firstInput) setTimeout(function(){ firstInput.focus(); firstInput.select && firstInput.select(); }, 50);

            ov.addEventListener('click', function(e){
                var t = e.target.closest('button[data-idx]');
                if (t) {
                    var idx = parseInt(t.getAttribute('data-idx'), 10);
                    var btn = opts.buttons[idx];
                    var payload = btn.value;
                    if (btn.collect && firstInput) {
                        payload = {};
                        ov.querySelectorAll('input,select').forEach(function(el){ if (el.name) payload[el.name] = el.value; });
                    }
                    document.body.removeChild(ov);
                    resolve(payload);
                } else if (e.target === ov) {
                    // Clique no overlay = cancelar
                    document.body.removeChild(ov);
                    resolve(null);
                }
            });
            // Enter no input = confirmar
            if (firstInput) {
                ov.addEventListener('keydown', function(e){
                    if (e.key === 'Enter') {
                        var confirmBtn = ov.querySelector('button[data-idx]:last-child');
                        if (confirmBtn) confirmBtn.click();
                    } else if (e.key === 'Escape') {
                        document.body.removeChild(ov);
                        resolve(null);
                    }
                });
            }
        });
    }

    window.cobAcao = function(cobId, tipo, vencimentoAtual, clienteNome, valorCobranca) {
        var nomeCli = clienteNome ? ' — ' + clienteNome : '';
        if (tipo === 'cancelar') {
            _cobModal({
                title: '⚠️ Cancelar cobrança?',
                body: 'Cobrança' + nomeCli + '\n\nIsto vai cancelar no Asaas também.\nA cobrança deixará de aparecer no Kanban de Cobrança e na Proposta de Acordo.\n\nTem certeza?',
                buttons: [
                    { label: 'Cancelar', bg: '#e5e7eb', color: '#374151', value: false },
                    { label: 'Sim, cancelar', bg: '#dc2626', color: '#fff', value: true }
                ]
            }).then(function(ok){
                if (ok === true) _cobSend(cobId, 'cobranca_cancelar', {});
            });
        } else if (tipo === 'vencto') {
            var input = '<label style="display:block;font-size:.75rem;font-weight:700;color:#6b7280;margin-bottom:.25rem;">Nova data de vencimento</label>' +
                        '<input type="date" name="nova_data" value="' + (vencimentoAtual || '') + '" style="width:100%;padding:.55rem .75rem;font-size:.9rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">';
            _cobModal({
                title: '📅 Alterar vencimento',
                body: 'Cobrança' + nomeCli + '\nVencimento atual: ' + (vencimentoAtual ? _cobFmtBR(vencimentoAtual) : '—'),
                inputHtml: input,
                buttons: [
                    { label: 'Cancelar', bg: '#e5e7eb', color: '#374151', value: null },
                    { label: 'Salvar', bg: '#3730a3', color: '#fff', collect: true }
                ]
            }).then(function(res){
                if (!res || !res.nova_data) return;
                if (!/^\d{4}-\d{2}-\d{2}$/.test(res.nova_data)) { alert('Data inválida.'); return; }
                _cobSend(cobId, 'cobranca_alterar_vencimento', { nova_data: res.nova_data });
            });
        } else if (tipo === 'executar') {
            // 29/06/2026 Amanda: encaminhar cobrança vencida pro Kanban de
            // Cobrança de Honorários, escolhendo estágio. 'judicial' = execução.
            var input =
                '<div style="font-size:.8rem;color:#374151;margin-bottom:.6rem;">Em qual estágio entrar no Kanban?</div>' +
                '<label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;margin-bottom:.35rem;">' +
                '  <input type="radio" name="estagio" value="atrasado" style="margin-top:3px;">' +
                '  <div><div style="font-weight:700;color:#dc2626;font-size:.82rem;">⚠️ Atrasado</div><div style="font-size:.7rem;color:#6b7280;">Entra no Kanban ainda no início — sem notificação automática</div></div>' +
                '</label>' +
                '<label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;margin-bottom:.35rem;">' +
                '  <input type="radio" name="estagio" value="notificado_1" style="margin-top:3px;">' +
                '  <div><div style="font-weight:700;color:#d97706;font-size:.82rem;">📱 Notificação amigável</div><div style="font-size:.7rem;color:#6b7280;">Mandar 1ª cobrança amigável (WhatsApp/email)</div></div>' +
                '</label>' +
                '<label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:1.5px solid #e5e7eb;border-radius:8px;cursor:pointer;margin-bottom:.35rem;">' +
                '  <input type="radio" name="estagio" value="notificado_extrajudicial" style="margin-top:3px;">' +
                '  <div><div style="font-weight:700;color:#8b5cf6;font-size:.82rem;">📄 Notificação extrajudicial</div><div style="font-size:.7rem;color:#6b7280;">Notificação formal antes da execução</div></div>' +
                '</label>' +
                '<label style="display:flex;align-items:flex-start;gap:.45rem;padding:.55rem .65rem;border:2px solid #1e40af;background:#eff6ff;border-radius:8px;cursor:pointer;margin-bottom:.55rem;">' +
                '  <input type="radio" name="estagio" value="judicial" checked style="margin-top:3px;">' +
                '  <div><div style="font-weight:700;color:#1e40af;font-size:.82rem;">⚖️ Direto pra EXECUÇÃO judicial</div><div style="font-size:.7rem;color:#6b7280;">Cliente não respondeu — partir pra ação. Gestão é notificada.</div></div>' +
                '</label>' +
                '<label style="display:block;font-size:.72rem;font-weight:700;color:#6b7280;margin-bottom:.2rem;">Observação (opcional)</label>' +
                '<textarea name="observacao" rows="2" placeholder="Ex: não pagou nada do contrato, ignorou WhatsApp..." style="width:100%;padding:.55rem .75rem;font-size:.85rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;resize:vertical;"></textarea>';
            _cobModal({
                title: '⚖️ Encaminhar pra cobrança / execução',
                body: 'Cobrança' + nomeCli + ' — R$ ' + (valorCobranca ? Number(valorCobranca).toLocaleString('pt-BR', {minimumFractionDigits:2}) : '?') +
                      '\n\nEste cliente vai pro Kanban de Cobrança de Honorários no estágio que você escolher abaixo.\nVocê acompanha lá com o histórico completo de ações.',
                inputHtml: input,
                buttons: [
                    { label: 'Cancelar', bg: '#e5e7eb', color: '#374151', value: null },
                    { label: 'Encaminhar', bg: '#1e40af', color: '#fff', collect: true }
                ]
            }).then(function(res){
                if (!res) return;
                if (!res.estagio) { alert('Escolha um estágio.'); return; }
                _cobSendExec(cobId, res.estagio, res.observacao || '');
            });
        } else if (tipo === 'valor') {
            var vFmt = valorCobranca ? Number(valorCobranca).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}) : '';
            var inputV =
                '<label style="display:block;font-size:.75rem;font-weight:700;color:#6b7280;margin-bottom:.25rem;">Novo valor (R$)</label>' +
                '<input type="text" name="novo_valor" value="' + vFmt + '" placeholder="0,00" inputmode="decimal" style="width:100%;padding:.55rem .75rem;font-size:.9rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">';
            _cobModal({
                title: '💲 Alterar valor da cobrança',
                body: 'Cobrança' + nomeCli + '\nValor atual: R$ ' + (vFmt || '?') +
                      '\n\nIsto ATUALIZA o valor no Asaas (o cliente vê o novo valor no link). Só funciona em cobrança pendente/vencida. Use pra registrar um acordo/renegociação.',
                inputHtml: inputV,
                buttons: [
                    { label: 'Cancelar', bg: '#e5e7eb', color: '#374151', value: null },
                    { label: 'Salvar no Asaas', bg: '#B87333', color: '#fff', collect: true }
                ]
            }).then(function(res){
                if (!res || !res.novo_valor) return;
                _cobSend(cobId, 'cobranca_alterar_valor', { novo_valor: res.novo_valor });
            });
        } else if (tipo === 'baixa') {
            var hoje = new Date().toISOString().slice(0,10);
            var valorFmt = valorCobranca ? Number(valorCobranca).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}) : '';
            var input =
                '<label style="display:block;font-size:.75rem;font-weight:700;color:#6b7280;margin-bottom:.25rem;">Data do pagamento</label>' +
                '<input type="date" name="data_pagamento" value="' + hoje + '" style="width:100%;padding:.55rem .75rem;font-size:.9rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;margin-bottom:.6rem;">' +
                '<label style="display:block;font-size:.75rem;font-weight:700;color:#6b7280;margin-bottom:.25rem;">Valor recebido (R$)</label>' +
                '<input type="text" name="valor" value="' + valorFmt + '" placeholder="0,00" style="width:100%;padding:.55rem .75rem;font-size:.9rem;border:1.5px solid #e5e7eb;border-radius:8px;font-family:inherit;">';
            _cobModal({
                title: '✓ Dar baixa manual',
                body: 'Cobrança' + nomeCli + '\nMarca como paga em dinheiro/transferência (fora do Asaas). O cliente NÃO recebe notificação.',
                inputHtml: input,
                buttons: [
                    { label: 'Cancelar', bg: '#e5e7eb', color: '#374151', value: null },
                    { label: 'Confirmar baixa', bg: '#059669', color: '#fff', collect: true }
                ]
            }).then(function(res){
                if (!res) return;
                if (!/^\d{4}-\d{2}-\d{2}$/.test(res.data_pagamento || '')) { alert('Data inválida.'); return; }
                if (!res.valor) { alert('Informe o valor recebido.'); return; }
                _cobSend(cobId, 'cobranca_dar_baixa', { data_pagamento: res.data_pagamento, valor: res.valor });
            });
        }
    };

    function _cobFmtBR(iso) {
        if (!iso || iso.length < 10) return iso;
        var p = iso.substr(0,10).split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }

    function _cobSendExec(cobId, estagio, observacao) {
        var body = 'action=cobranca_encaminhar_execucao&cobranca_id=' + cobId
                 + '&estagio=' + encodeURIComponent(estagio)
                 + '&observacao=' + encodeURIComponent(observacao || '')
                 + '&csrf_token=' + encodeURIComponent(window._COB_CSRF || '');
        fetch(window._COB_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        })
        .then(function(r){
            if (r.status === 401 && window.fsaMostrarSessaoExpirada) { window.fsaMostrarSessaoExpirada(); return null; }
            return r.json().catch(function(){ return { error: 'Resposta inválida (HTTP ' + r.status + ')' }; });
        })
        .then(function(j){
            if (!j) return;
            if (j.error) { alert('Erro: ' + j.error); return; }
            var msgEstagio = ({
                atrasado: '⚠️ Atrasado',
                notificado_1: '📱 Notificação amigável',
                notificado_extrajudicial: '📄 Notificação extrajudicial',
                judicial: '⚖️ Execução judicial'
            })[j.estagio] || j.estagio;
            var msg = (j.ja_existia ? 'Cobrança ATUALIZADA' : 'Cobrança ENCAMINHADA')
                    + ' para o estágio:\n\n' + msgEstagio
                    + (j.estagio === 'judicial' ? '\n\nA gestão foi notificada.' : '');
            _cobModal({
                title: '✓ Feito!',
                body: msg,
                buttons: [
                    { label: 'Fechar', bg: '#e5e7eb', color: '#374151', value: null },
                    { label: 'Abrir Kanban →', bg: '#1e40af', color: '#fff', value: true }
                ]
            }).then(function(abrir){
                if (abrir === true && j.kanban_url) window.open(j.kanban_url, '_blank');
                location.reload();
            });
        })
        .catch(function(e){ alert('Erro de conexão: ' + e.message); });
    }

    function _cobSend(cobId, action, extra) {
        var body = 'action=' + encodeURIComponent(action) + '&cobranca_id=' + cobId + '&csrf_token=' + encodeURIComponent(window._COB_CSRF || '');
        for (var k in extra) body += '&' + k + '=' + encodeURIComponent(extra[k]);
        fetch(window._COB_API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        })
        .then(function(r){
            if (r.status === 401 && window.fsaMostrarSessaoExpirada) { window.fsaMostrarSessaoExpirada(); return null; }
            return r.json().catch(function(){ return { error: 'Resposta inválida do servidor (HTTP ' + r.status + ')' }; });
        })
        .then(function(j){
            if (!j) return;
            if (j.csrf_expired) { alert('Sessão expirou. Recarregue a página.'); return; }
            if (j.error) { alert('Erro: ' + j.error); return; }
            _cobToast('✓ Feito!');
            setTimeout(function(){ location.reload(); }, 500);
        })
        .catch(function(e){ alert('Erro de conexão: ' + e.message); });
    }

    function _cobToast(msg) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#059669;color:#fff;padding:10px 18px;border-radius:8px;font-weight:700;z-index:999999;box-shadow:0 8px 24px rgba(0,0,0,.25);font-family:inherit;font-size:.85rem;';
        document.body.appendChild(t);
        setTimeout(function(){ t.remove(); }, 2000);
    }
})();
