/**
 * Ações sobre cobranças Asaas — alterar vencimento, dar baixa, cancelar.
 * Requer window._COB_CSRF e window._COB_API_URL definidos antes do carregamento.
 * Usado em modules/financeiro/cobrancas.php e modules/financeiro/cliente.php.
 */
(function(){
    window.cobAcao = function(cobId, tipo, vencimentoAtual, clienteNome, valorCobranca) {
        var nomeCli = clienteNome ? ' — ' + clienteNome : '';
        if (tipo === 'cancelar') {
            if (!confirm('⚠️ CANCELAR esta cobrança' + nomeCli + '?\n\nIsto vai cancelar no Asaas também.\nA cobrança DEIXARÁ de aparecer no Kanban de Cobrança e na Proposta de Acordo.\n\nTem certeza?')) return;
            _cobSend(cobId, 'cobranca_cancelar', {});
        } else if (tipo === 'vencto') {
            var nova = prompt('Nova data de vencimento' + nomeCli + '\n(AAAA-MM-DD, ex: ' + new Date().toISOString().slice(0,10) + ')\n\nVencimento atual: ' + (vencimentoAtual || '—'), vencimentoAtual || '');
            if (!nova) return;
            if (!/^\d{4}-\d{2}-\d{2}$/.test(nova)) { alert('Data inválida. Use o formato AAAA-MM-DD.'); return; }
            _cobSend(cobId, 'cobranca_alterar_vencimento', { nova_data: nova });
        } else if (tipo === 'baixa') {
            var hoje = new Date().toISOString().slice(0,10);
            var data = prompt('Dar BAIXA MANUAL' + nomeCli + '\n(marca como paga em dinheiro/transferência — fora do Asaas)\n\nData do pagamento (AAAA-MM-DD):', hoje);
            if (!data) return;
            if (!/^\d{4}-\d{2}-\d{2}$/.test(data)) { alert('Data inválida.'); return; }
            var valorDefault = valorCobranca ? Number(valorCobranca).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}) : '';
            var valor = prompt('Valor recebido (pode ser diferente do cobrado, ex: desconto):', valorDefault);
            if (valor === null) return;
            _cobSend(cobId, 'cobranca_dar_baixa', { data_pagamento: data, valor: valor });
        }
    };

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
            return r.json();
        })
        .then(function(j){
            if (!j) return;
            if (j.csrf_expired) { alert('Sessão expirou. Recarregue a página.'); return; }
            if (j.error) { alert('Erro: ' + j.error); return; }
            location.reload();
        })
        .catch(function(e){ alert('Erro de conexão: ' + e.message); });
    }
})();
